<?php

use App\Actions\People\RegisterPerson;
use App\Enums\Permission;
use App\Models\AccountCompanyBinding;
use App\Models\AccountRequestOperation;
use App\Models\PersonProductAccess;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function accountRegistrationActor(): User
{
    Http::preventStrayRequests();
    config(['services.account.url' => 'https://account.example.test', 'services.account.token' => 'synthetic-account-token', 'services.account.actor_assertion_secret' => 'synthetic-proof-secret']);
    $company = currentCompany();
    $company->update(['workos_organization_id' => 'org_registration']);
    AccountCompanyBinding::create(['account_company_uuid' => (string) Str::uuid(), 'compliance_company_id' => $company->id, 'workos_organization_id' => $company->workos_organization_id]);
    $actor = userWithPermissions([Permission::PeopleManage]);
    $actor->forceFill(['workos_user_id' => 'user_registration_actor'])->save();

    return $actor;
}

it('completes registration only after Account confirmation', function () {
    $actor = accountRegistrationActor();
    Http::fake(['account.example.test/*' => function ($request) {
        $envelope = $request->data();
        $access = PersonProductAccess::where('user_id', $envelope['payload']['local_person_id'])->firstOrFail();
        $access->update(['confirmed_enabled' => true, 'version' => 1]);

        return Http::response(array_intersect_key($envelope, array_flip(['protocol_version', 'operation_id', 'kind', 'product', 'account_company_uuid', 'workos_organization_id', 'subject_id', 'resource_sequence'])) + ['outcome' => 'confirmed', 'confirmed_state' => ['enabled' => true]]);
    }]);
    $id = (string) Str::uuid();
    $result = app(RegisterPerson::class)->handle($actor, $id, 'Synthetic new person', 'new@example.test');
    expect($result->state)->toBe('confirmed')->and(AccountRequestOperation::count())->toBe(1);
    app(RegisterPerson::class)->handle($actor, $id, 'Synthetic new person', 'new@example.test');
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.workos.com'));
});

it('does not complete unconfirmed registration or removal', function () {
    $actor = accountRegistrationActor();
    Http::fake(['account.example.test/*' => Http::failedConnection()]);
    $operation = app(RegisterPerson::class)->handle($actor, (string) Str::uuid(), 'Pending person', 'pending@example.test');
    expect($operation->state)->toBe('unknown')->and(PersonProductAccess::where('user_id', $operation->user_id)->sole()->confirmed_enabled)->toBeFalse();
    expect(User::where('email', 'pending@example.test')->count())->toBe(1);
    app(RegisterPerson::class)->handle($actor, $operation->id, 'Pending person', 'pending@example.test');
    expect(User::where('email', 'pending@example.test')->count())->toBe(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/invitations'));
});
