# Approved test amendment 01 — implementation evidence

The owner approved exactly the two Account paths recorded in test-scope-amendment-01-approval.md. The approval-bound proposal is unchanged.

`tests/Feature/Access/CompanyUserAuthorizationTest.php` retains all six atomic actions, every original denial dataset, retry prerequisite checks and no-side-effect assertions. Invite cases explicitly use the ready registry/mapping/queue fixture; the valid invite positive control asserts the approved pending queue boundary rather than synchronous confirmation. Other action positive controls remain confirmed.

`tests/Feature/CompanyUsers/PrReviewRecoveryTest.php` retains every signature/expiry/nonce/actor/principal/capability/payload/different-principal case. The former UUID-list-only company revocation instead creates an actual company/organization binding mismatch using a synthetic database fixture fault. Production Company identity immutability remains enforced; no model or production authorization change was made for the test.

Observed HTTP detail: an initial binding mismatch is rejected by ProductActorAuthorization with 403. An interleaved mismatch after its first successful positive authorization reaches the existing locked exact-company lookup in `app/Actions/CompanyUsers/ChangeUserProductAccess.php`, line 35 (`where(uuid)->where(workos_organization_id)->lockForUpdate()->firstOrFail()`), before the second authorization call. Its fail-closed outcome is 404. Only this interleaved binding dataset uses exact `assertNotFound()`; principal/capability/actor interleavings retain `assertForbidden()`. The test proves the first authorization succeeded, and every case retains byte-for-byte unchanged durable operation/targets and no outgoing HTTP. This adapts the tenant-denial stimulus to the approved real binding, preserving the accepted security outcome rather than changing production to force a status code.

The complete two files are executed together after formatting; actual terminal count is appended when available. No Compliance out-of-inventory test was changed under this approval.

Actual result after feature-only Pint: **83 passed, 362 assertions** (both complete files). The valid invite control and all original rejection/recovery datasets execute. Full canonical verification remains a separate parent-run gate.
