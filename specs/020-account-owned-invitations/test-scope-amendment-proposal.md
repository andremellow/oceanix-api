# Proposed test-only scope amendment

Status: PROPOSED, pending explicit owner approval. No test/source changes authorized by this proposal. Scope `account-owned-invitations-v1`; original architecture and product rules unchanged.

The canonical Account verification found three failures in two existing tests outside the frozen mutation inventory. They encode behavior expressly changed by the approved feature. Do not restore UUID-list authorization or bypass the default-off readiness flag to make them pass.

## Exact proposed additional paths

1. Account `tests/Feature/Access/CompanyUserAuthorizationTest.php`.
2. Account `tests/Feature/CompanyUsers/PrReviewRecoveryTest.php`.

No production path, requirement, QA scenario, dependency, configuration file or environment mutation is added. The rejected Account configuration expansion remains rejected.

## Minimal proposed changes

### CompanyUserAuthorizationTest

Within the existing `enforces isolated delegated atomic actions and their prerequisites on direct mutation` dataset test, after creating the target and before registering the broad provider fake:

```php
if ($action === 'invite') {
    Fixture::readyInvitations($target);
}
```

This approved shared fixture explicitly enables the synthetic Compliance registry flag, creates the reciprocal mapping prerequisite, and fakes the queue. It does not enable the production/default flag globally. Other action cases retain their existing setup.

Replace only the valid-case aggregate assertion:

```php
expect($invoke()->state)->toBe($action === 'invite' ? 'pending' : 'confirmed');
```

The valid invitation remains a positive authorization control with a persisted operation; its provider send/read/projection proceeds through the new durable queue boundary. All six action types, all valid/unrelated/missing/revoked/foreign datasets, the original-permission retry case and every denial/no-mutation/no-extra-request assertion remain.

### PrReviewRecoveryTest

Both currently failing `company` datasets revoke only `ServicePrincipal.company_uuids`, which FR-013 explicitly removes as an authorization control. Preserve the dataset and all expected 403/no-HTTP/immutable-evidence assertions; change the revocation stimulus to the real company binding instead:

```php
$company->update(['workos_organization_id' => 'org_revoked_binding']);
```

Apply this in `PR3 rejects unauthorized or conflicting exhausted replays without touching durable evidence` and the interleaving callback in `PR3 rechecks a revocation occurring after initial replay authorization before rearming`. In the second test, capture the already returned `$company` from `pr3ExhaustedRemoval()` and include it in the callback's `use` list. The persisted envelope still identifies the original organization, so exact real-binding authorization must deny it both before execution and after the interleaved binding revocation. Signature, expiry, nonce, actor, principal, capability, payload and different-principal datasets remain unchanged.

## Validation after approval

Execute these two complete test files, then rerun Account's canonical verification because the earlier canonical run stopped before build/browser. This is pre-review author verification, not a new specialist review/remediation round. Do not claim canonical success until the amended tests and all canonical stages actually pass.
