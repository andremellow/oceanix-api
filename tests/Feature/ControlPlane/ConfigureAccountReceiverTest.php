<?php

use App\Models\AccountCompanyBinding;
use App\Models\ServicePrincipal;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\AccountIntegrationFixture as Fixture;

it('accepts the SDK managed receiver on existing Compliance routes without replacing identities or bindings', function () {
    [$company, $person, $legacy, $legacyToken, $input] = Fixture::receiver();
    $token = str_repeat('a', 64);
    config(['oceanix-platform.receiver.token' => $token]);
    $companyBefore = $company->fresh()->getAttributes();
    $personBefore = $person->fresh()->getAttributes();
    $bindingBefore = AccountCompanyBinding::sole()->getAttributes();
    $legacyBefore = $legacy->fresh()->getAttributes();
    $userCount = User::withoutGlobalScopes()->count();

    $this->artisan('oceanix:configure-receiver')->assertSuccessful();
    $managed = ServicePrincipal::where('name', 'accounts')->sole();
    $tokenId = $managed->tokens()->sole()->id;
    $this->artisan('oceanix:configure-receiver')->assertSuccessful();

    expect($managed->tokens()->sole()->id)->toBe($tokenId)
        ->and($managed->product)->toBe('compliance')
        ->and($managed->environment)->toBe('testing')
        ->and($company->fresh()->getAttributes())->toBe($companyBefore)
        ->and($person->fresh()->getAttributes())->toBe($personBefore)
        ->and(AccountCompanyBinding::sole()->getAttributes())->toBe($bindingBefore)
        ->and($legacy->fresh()->getAttributes())->toBe($legacyBefore)
        ->and(User::withoutGlobalScopes()->count())->toBe($userCount);

    $url = '/api/control-plane/v2/operations/'.$input['operation_id'];
    $response = $this->withToken($token)->withHeader('Idempotency-Key', $input['operation_id'])
        ->putJson($url, $input)->assertCreated()->assertJsonPath('outcome', 'confirmed')->json();
    $this->getJson($url)->assertOk()->assertExactJson($response);
    // Receipts remain principal-bound; the old credential can still submit its own next operation.
    $next = array_replace($input, ['operation_id' => (string) Str::uuid(), 'kind' => 'person.access', 'resource_sequence' => 2, 'payload' => array_replace($input['payload'], ['enabled' => false])]);
    $this->withToken($legacyToken)->withHeader('Idempotency-Key', $next['operation_id'])
        ->putJson('/api/control-plane/v2/operations/'.$next['operation_id'], $next)->assertCreated();
    $this->withToken('invalid-receiver')->getJson($url)->assertUnauthorized();
    Http::assertNothingSent();
});

it('refuses to silently replace the managed receiver credential', function () {
    config(['oceanix-platform.receiver.token' => str_repeat('b', 64)]);
    $this->artisan('oceanix:configure-receiver')->assertSuccessful();
    $before = ServicePrincipal::where('name', 'accounts')->sole()->tokens()->sole()->getAttributes();

    config(['oceanix-platform.receiver.token' => str_repeat('c', 64)]);
    $this->artisan('oceanix:configure-receiver')->assertFailed();

    expect(ServicePrincipal::where('name', 'accounts')->sole()->tokens()->sole()->getAttributes())->toBe($before);
});
