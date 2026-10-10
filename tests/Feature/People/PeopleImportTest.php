<?php

use App\Actions\People\ImportPeople;
use App\Actions\People\RegisterPerson;
use App\Enums\Permission;
use App\Models\AccountCompanyBinding;
use App\Models\AccountRequestOperation;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\JobFunction;
use App\Models\PersonAccountLink;
use App\Models\PersonProductAccess;
use App\Models\User;
use App\Services\People\PeopleImportPreview;
use App\Services\People\PeopleSpreadsheetParser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\Support\AccountIntegrationFixture;

function positionalPeopleWorkbook(array $rows): string
{
    $path = sys_get_temp_dir().'/people-import-'.Str::random(12).'.xlsx';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $xmlRows = collect($rows)->values()->map(function (array $values, int $index): string {
        $row = $index + 1;
        $cells = collect($values)->map(function (?string $value, int $column) use ($row): string {
            $reference = chr(65 + $column).$row;
            $escaped = htmlspecialchars((string) $value, ENT_XML1);

            return "<c r=\"{$reference}\" t=\"inlineStr\"><is><t>{$escaped}</t></is></c>";
        })->join('');

        return "<row r=\"{$row}\">{$cells}</row>";
    })->join('');

    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        .$xmlRows.'</sheetData></worksheet>');
    $zip->close();

    return $path;
}

it('reads the first four columns positionally and always ignores row one', function (): void {
    $path = positionalPeopleWorkbook([
        ['Anything', 'Whatever', 'Not used', 'Ignored as a header', 'Extra'],
        ['  Maria   Costa ', ' MARIA@EXAMPLE.COM ', ' Welder I ', ' Operations ', 'ignored'],
        ['', '', '', ''],
        ['João Silva', 'joao@example.com', '', ''],
    ]);

    $rows = app(PeopleSpreadsheetParser::class)->parse($path);

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toBe([
            'row' => 2,
            'name' => 'Maria Costa',
            'email' => 'maria@example.com',
            'job_function' => 'Welder I',
            'department' => 'Operations',
        ])
        ->and($rows[1]['job_function'])->toBe('')
        ->and($rows[1]['department'])->toBe('');
});

it('parses a workbook the caller read through a disk, without needing a local path', function (): void {
    $contents = file_get_contents(positionalPeopleWorkbook([
        ['Header', 'Header', 'Header', 'Header'],
        ['Maria Costa', 'maria@example.com', 'Welder I', 'Operations'],
    ]));

    $rows = app(PeopleSpreadsheetParser::class)->parseContents($contents);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['email'])->toBe('maria@example.com');
});

it('matches organization values after safe normalization and leaves new values ready to create', function (): void {
    $function = JobFunction::factory()->create(['name' => 'Mecânico de Manutenção III']);
    Department::factory()->create(['name' => 'Operations']);

    $preview = app(PeopleImportPreview::class)->prepare([
        ['row' => 2, 'name' => 'One', 'email' => 'one@example.com', 'job_function' => 'MECANICO DE  MANUTENCAO III', 'department' => 'operations'],
        ['row' => 3, 'name' => 'Two', 'email' => 'two@example.com', 'job_function' => 'New function', 'department' => 'New department'],
    ]);

    expect($preview['errors'])->toBe([])
        ->and($preview['job_functions'][0]['selected'])->toBe((string) $function->id)
        ->and($preview['job_functions'][0]['matched'])->toBeTrue()
        ->and($preview['job_functions'][1]['selected'])->toBe('create');
});

it('imports new people, reuses existing accounts, creates mappings, and preserves blank links', function (): void {
    seedAccessCatalog();
    $operator = userWithPermissions([Permission::PeopleImport]);
    $operator->forceFill(['workos_user_id' => 'user_import_operator'])->save();
    $company = currentCompany();
    $company->update(['workos_organization_id' => 'org_import']);
    AccountCompanyBinding::create(['account_company_uuid' => (string) Str::uuid(), 'compliance_company_id' => $company->id, 'workos_organization_id' => $company->workos_organization_id]);
    AccountIntegrationFixture::fakeAccountConfirmation();
    $existing = User::factory()->create(['email' => 'existing@example.com']);
    $department = Department::factory()->create(['name' => 'Existing department']);
    $this->actingAs($operator);

    $result = app(ImportPeople::class)->handle([
        ['row' => 2, 'name' => 'New Person', 'email' => 'new@example.com', 'job_function' => 'New function', 'department' => 'Mapped department'],
        ['row' => 3, 'name' => 'Do not rename', 'email' => 'existing@example.com', 'job_function' => '', 'department' => ''],
    ], [
        'New function' => 'create',
    ], [
        'Mapped department' => (string) $department->id,
    ]);

    $newUser = User::query()->where('email', 'new@example.com')->firstOrFail();

    expect($result)->toMatchArray(['created' => 1, 'existing' => 1, 'job_functions_created' => 1])
        ->and($newUser->jobFunctions()->where('name', 'New function')->exists())->toBeTrue()
        ->and($newUser->departments()->whereKey($department->id)->exists())->toBeTrue()
        ->and($existing->fresh()->name)->not->toBe('Do not rename')
        ->and(AuditLog::query()->where('action', 'people.imported')->exists())->toBeTrue();
});

it('protects the import screen and action with its own permission', function (): void {
    seedAccessCatalog();

    $this->actingAs(userWithPermissions([Permission::PeopleManage]))
        ->get(route('people.import'))
        ->assertForbidden();

    $this->actingAs(userWithPermissions([Permission::PeopleImport]))
        ->get(route('people.import'))
        ->assertOk();
});

it('revokes import access immediately and preserves the permission prerequisites', function (): void {
    seedAccessCatalog();
    $user = User::factory()->create();
    $role = grantPermissions($user, [Permission::PeopleImport]);

    expect(Permission::withPrerequisites([Permission::PeopleImport]))
        ->toContain(
            Permission::PeopleView->value,
            Permission::DepartmentsView->value,
            Permission::JobFunctionsView->value,
        );

    $this->actingAs($user)->get(route('people.import'))->assertOk();
    $role->permissions()->detach();
    $this->actingAs($user->fresh())->get(route('people.import'))->assertForbidden();
});

it('retains mixed uncertain import rows and reuses pending registration and adoption receipts without duplicate people or email', function () {
    seedAccessCatalog();
    Http::preventStrayRequests();
    config(['services.account.url' => 'https://account.example.test', 'services.account.token' => 'synthetic-token', 'services.account.actor_assertion_secret' => 'synthetic-proof-secret']);
    $actor = userWithPermissions([Permission::PeopleImport]);
    $actor->forceFill(['workos_user_id' => 'user_mixed_import'])->save();
    $company = currentCompany();
    $company->update(['workos_organization_id' => 'org_mixed_import']);
    $binding = AccountCompanyBinding::create(['account_company_uuid' => (string) Str::uuid(), 'compliance_company_id' => $company->id, 'workos_organization_id' => $company->workos_organization_id]);
    $existing = User::factory()->create(['email' => 'adopt-mixed@example.test']);
    $department = Department::factory()->create();
    $recover = false;
    $envelopes = [];
    Http::fake(function ($request) use (&$recover, &$envelopes, $binding) {
        $id = basename(parse_url($request->url(), PHP_URL_PATH));
        if ($request->method() === 'PUT') {
            $envelopes[$id] = $request->data();
        }
        $input = $envelopes[$id];
        if (! $recover && ($input['payload']['email'] ?? '') !== 'confirmed-mixed@example.test') {
            return Http::failedConnection();
        }
        $personId = $input['payload']['local_person_id'];
        $shared = Uuid::uuid5(Uuid::NAMESPACE_URL, 'shared:'.$personId)->toString();
        $member = Uuid::uuid5(Uuid::NAMESPACE_URL, 'member:'.$personId)->toString();
        if ($input['kind'] === 'person.register') {
            PersonProductAccess::where('user_id', $personId)->update(['confirmed_enabled' => true, 'version' => 1]);
            PersonAccountLink::firstOrCreate(['company_id' => $binding->compliance_company_id, 'user_id' => $personId], ['account_company_uuid' => $binding->account_company_uuid, 'shared_person_id' => $shared, 'company_member_id' => $member, 'provenance' => []]);
        }

        return Http::response(array_intersect_key($input, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence'])) + ['outcome' => 'confirmed', 'confirmed_state' => ['shared_person_id' => $shared, 'company_member_id' => $member]]);
    });
    $pending = app(RegisterPerson::class)->handle($actor, (string) Str::uuid(), 'Pending mixed', 'pending-mixed@example.test', import: true);
    expect($pending->state)->toBe('unknown');
    $rows = [
        ['row' => 2, 'name' => 'Confirmed mixed', 'email' => 'confirmed-mixed@example.test', 'job_function' => '', 'department' => 'Mapped'],
        ['row' => 3, 'name' => 'Pending mixed', 'email' => 'pending-mixed@example.test', 'job_function' => '', 'department' => 'Mapped'],
        ['row' => 4, 'name' => 'Adopt mixed', 'email' => $existing->email, 'job_function' => '', 'department' => 'Mapped'],
    ];
    $view = Livewire\Livewire::actingAs($actor)->test('organization.import-people')->set('rows', $rows)->set('departmentTerms', [['value' => 'Mapped', 'selected' => (string) $department->id, 'count' => 3, 'matched' => true]])->call('import')
        ->assertSet('result.pending', 2)->assertSet('result.created', 1)->assertSet('rows', $rows)->assertSee('Some registrations need confirmation')->assertSee('Row 2: Confirmed')->assertSee('Row 3: Pending confirmation')->assertSee('Row 4: Pending confirmation')->assertDontSee('Import completed')->assertSee('Retry the import');
    expect(User::where('email', 'confirmed-mixed@example.test')->sole()->departments()->whereKey($department->id)->exists())->toBeTrue()
        ->and(User::where('email', 'pending-mixed@example.test')->sole()->departments()->count())->toBe(0)->and($existing->departments()->count())->toBe(0);
    $identities = AccountRequestOperation::orderBy('id')->pluck('payload_hash', 'id')->all();
    expect($identities)->toHaveCount(3)->and(AccountRequestOperation::find($pending->id)->state)->toBe('unknown');
    $recover = true;
    $view->call('import')->assertSet('result.pending', 0)->assertSet('rows', [])->assertSee('Import completed');
    expect(AccountRequestOperation::orderBy('id')->pluck('payload_hash', 'id')->all())->toBe($identities)
        ->and(AccountRequestOperation::where('state', 'confirmed')->count())->toBe(3)
        ->and(User::whereIn('email', array_column($rows, 'email'))->count())->toBe(3)
        ->and(User::where('email', 'pending-mixed@example.test')->sole()->departments()->whereKey($department->id)->exists())->toBeTrue()->and($existing->fresh()->departments()->whereKey($department->id)->exists())->toBeTrue();
    $puts = collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'PUT');
    expect($puts->map(fn ($pair) => $pair[0]['operation_id'])->all())->toHaveCount(3);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.workos.com') || str_contains($request->url(), '/invitations'));
});
