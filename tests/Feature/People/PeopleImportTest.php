<?php

use App\Actions\People\ImportPeople;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\JobFunction;
use App\Models\User;
use App\Services\People\PeopleImportPreview;
use App\Services\People\PeopleSpreadsheetParser;
use Illuminate\Support\Str;

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
