<?php

beforeEach(fn () => config(['services.account.invitation_ownership_enabled' => true]));

use App\Actions\People\AdoptPeopleIntoAccount;
use App\Enums\ComplianceEventType;
use App\Enums\Permission;
use App\Models\AccountCompanyBinding;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\PersonAccountLink;
use App\Models\Role;
use App\Models\User;
use App\Models\UserTrainingAssignment;
use App\Services\Compliance\ComplianceEventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

it('preserves local evidence and invitation state across repeated adoption', function () {
    Http::preventStrayRequests();
    config(['services.account.url' => 'https://account.example.test', 'services.account.token' => 'synthetic-token', 'services.account.actor_assertion_secret' => 'synthetic-actor-proof']);
    $company = currentCompany();
    $company->update(['workos_organization_id' => 'org_adopt']);
    AccountCompanyBinding::create(['account_company_uuid' => (string) Str::uuid(), 'compliance_company_id' => $company->id, 'workos_organization_id' => 'org_adopt']);
    $actor = userWithPermissions([Permission::PeopleManage]);
    $actor->forceFill(['workos_user_id' => 'user_adopt_actor'])->save();
    $person = User::factory()->create();
    $person->forceFill(['workos_invitation_id' => 'inv_existing', 'invitation_generation' => 3])->save();
    seedAccessCatalog();
    $person->roles()->syncWithoutDetaching(Role::where('key', 'employee')->firstOrFail()->id);
    $course = Course::factory()->create();
    $assignment = UserTrainingAssignment::factory()->forCourse($course)->completed()->create(['user_id' => $person->id]);
    Certificate::factory()->create(['user_id' => $person->id, 'assignment_id' => $assignment->id, 'course_id' => $assignment->course_id, 'course_version_id' => $assignment->course_version_id]);
    app(ComplianceEventRecorder::class)->record(ComplianceEventType::CourseCompleted, $person->id, ['assignment_id' => $assignment->id, 'course_version_id' => $assignment->course_version_id]);
    $before = DB::table('users')->orderBy('id')->get()->toJson();
    $evidence = [];
    foreach (['user_training_assignments', 'certificates', 'compliance_events', 'role_user'] as $table) {
        $evidence[$table] = DB::table($table)->get()->toJson();
    }
    $adopt = app(AdoptPeopleIntoAccount::class);
    expect($adopt->handle($actor, personIds: [$person->id])[0]['outcome'])->toBe('ready');
    expect(PersonAccountLink::count())->toBe(0);
    Http::assertNothingSent();
    Http::fake(['account.example.test/*' => function ($request) {
        return Http::response(array_intersect_key($request->data(), array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence'])) + ['outcome' => 'confirmed', 'confirmed_state' => ['mapping_complete' => true, 'local_person_id' => (string) $request['payload']['local_person_id'], 'shared_person_id' => (string) Str::uuid(), 'company_member_id' => (string) Str::uuid()]]);
    }]);
    expect($adopt->handle($actor, dryRun: false, personIds: [$person->id])[0]['outcome'])->toBe('confirmed');
    expect($adopt->handle($actor, dryRun: false, personIds: [$person->id])[0]['outcome'])->toBe('already_linked');
    expect(DB::table('users')->orderBy('id')->get()->toJson())->toBe($before)->and(PersonAccountLink::count())->toBe(1);
    foreach ($evidence as $table => $rows) {
        expect(DB::table($table)->get()->toJson())->toBe($rows);
    }
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.workos.com'));
});
