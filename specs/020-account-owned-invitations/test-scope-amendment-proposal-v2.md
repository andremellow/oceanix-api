# Proposed supplemental test-only scope amendment, version 2

Status: PROPOSED, pending explicit owner approval for the two Compliance paths below. The two Account paths from version 1 are ALREADY APPROVED in test-scope-amendment-01-approval.md and are not part of this new approval request.

Scope `account-owned-invitations-v1`. Original production inventory, architecture, requirements and QA scenarios remain unchanged. The rejected Account configuration expansion remains rejected.

## Exactly two additional paths requested

1. Compliance `tests/Feature/AccountPeopleMigrationTest.php`.
2. Compliance `tests/Feature/People/InvitationStatusTransitionTest.php`.

Neither file has been edited. No production, configuration, UI, dependency or environment path is added.

## AccountPeopleMigrationTest — retained actual tenant denial

In `rejects tenant capability hash and email-only collisions with no effects`, replace only:

```php
$principal->update(['company_uuids' => []]);
```

with a synthetic mismatch of the actual binding:

```php
AccountCompanyBinding::where('compliance_company_id', $company->id)
    ->update(['workos_organization_id' => 'org_revoked_binding']);
```

Keep the request's original organization and payload hash. Retain the original hash-tampering and email-only collision conflicts, final 403, and zero link/receipt effects. Do not restore deprecated UUID-list authorization in production. If the binding model itself enforces immutability, apply this synthetic fault through the database query builder rather than changing its production invariant.

## InvitationStatusTransitionTest — explicit exact mapping and Account-owned platform work

### Import case

The actual isolated diagnostic result was `created=1, existing=0, pending=1`: the new registration succeeds and the existing protected person returns `mapping_required`. The initial created=0 interpretation came from a chained assertion sharing one line; it was incorrect. No confirmed-first registration defect was found.

The protected fixture has neither a stable WorkOS ID nor an invitation. The new approved mapping-only behavior correctly refuses to adopt it by email. Supply exact existing identity evidence and paired readiness instead:

```php
config(['services.account.invitation_ownership_enabled' => true]);
$protected = User::factory()->suspended()->create([
    'email' => 'protected@example.com',
    'workos_user_id' => 'user_import_status_existing',
]);
```

Extend only the existing fake's kind handling:

```php
if ($data['kind'] === 'person.map-existing') {
    $state += [
        'mapping_complete' => true,
        'local_person_id' => (string) $data['payload']['local_person_id'],
    ];
} elseif (($data['payload']['registration_mode'] ?? null) === 'confirmed-first-mapping') {
    // existing confirmed-first mapping fake unchanged
}
```

The fake's shared/member UUIDs identify the exact mapped target; no email adoption is introduced. Retain all original expectations: new person Invited, existing protected person Suspended, created=1/existing=1/pending=0, three HTTP requests, and no Compliance operational WorkOS request. Keep confirmed-first registration and its reciprocal-mapping fake/assertions intact.

The diagnostic used a private process-only observation bootstrap; no production/test source file was modified and no provider call occurred.

### Platform grant case

Replace the legacy direct WorkOS response sequence with exact Account binding/configuration and confirmed membership receipts using the existing approved `Tests\Support\AccountIntegrationFixture::fakePlatformConnection($company)` setup. Repeated grant retrieves the same persisted Account operation/receipt; fake GET recovery must return that exact receipt, not fabricate a new identity/link.

Retain the original Invited, null first access, admin role, and preservation of Suspended status on the second grant. Add or retain explicit no-operational-WorkOS assertion. No role grant may occur from an unconfirmed/malformed membership receipt.

## Verification

Run both complete affected test files after approval. Keep every case and status/evidence assertion. Then repeat the affected canonical test stage and retain already passing build/browser stages only with an explicit impact reason. Parent is completing the remaining canonical stages to expose any additional outdated fixture assumptions before asking the owner.
