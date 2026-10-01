# Worker verification — invitation reconciliation

Scope: workos-invitation-sync-v1. This records Worker implementation evidence, not independent approval. Approved scenario requirements remain unchanged. Independent QA instructions are in qa-operator.md and contain no Worker verdict.

## Executed checks

- Focused affected Feature suites: exit 0, 115 tests with missing-dotenv warnings, 428 assertions. Raw log: /private/tmp/invite-affected-final.log. Includes People, tenant access, Requirements, materialization, requirement eligibility, account linkage and platform administration regressions.
- Recovery and access files executed independently: exit 0, 15 tests with baseline warnings, 63 assertions (/private/tmp/invite-independent-files.log).
- Real browser PeopleInvitationBrowserTest: exit 0, 10 assertions, desktop 1440px and mobile 320px, filter/empty/reset/confirmation/Escape behavior and JavaScript error checks.
- Full composer test executed with process-only 512M PHP override: exit 1, one failure, 1002 tests with baseline dotenv warnings, 5555 assertions (/private/tmp/invite-suite-final.log). SharedCourseDraftIntegrityTest line 866 fails for shared propagation draft preview; root reproduced it on intact origin/main. Owner deferred that unrelated fix (BASELINE-01). The full suite is not green; no test was filtered or weakened to bypass this failure.
- Full vendor/bin/pint and subsequent vendor/bin/pint --test: exit 0 (/private/tmp/invite-pint-final.log and /private/tmp/invite-pint-check.log). git diff --check: exit 0.
- npm run build: exit 0 with existing bundle-size warning. Root separately executed editor unit checks (30 pass) and preview checks (25 pass), raw logs /private/tmp/invite-editor-unit.log and /private/tmp/invite-preview-tests.log.
- Isolated PostgreSQL lock probe: competing processes waited behind the held person row lock, returned one intent and one overlap rejection, with exactly one durable attempt. Probe uses only disposable oceanix_invitation_probe database. Operator command is documented in qa-operator.md.

## Scenario-to-test execution map

All following named checks ran in the affected suite above; browser evidence supplements SC-018. Dataset instances are included in the counts. These are automated checks, not independent QA completion.

| Scenario | File (under tests/) and named check |
| --- | --- |
| SC-001 | Feature/People/WorkosInvitationSyncTest.php — maps supported invitation states without sending mail |
| SC-002 | Feature/People/WorkosInvitationSyncTest.php — paginates and selects current invitations retaining accepted history |
| SC-003 | Feature/People/WorkosInvitationSyncTest.php — matches invitation email and company exactly |
| SC-004 | Feature/People/WorkosInvitationSyncTest.php — rejects unavailable and contradictory stored records |
| SC-005 | Feature/People/WorkosInvitationSyncTest.php — retains evidence on partial provider failure with bounded retries; rejects malformed snapshots and repeated cursors instead of proving absence |
| SC-006 | Feature/People/WorkosInvitationSyncTest.php — keeps external signin separate from tenant access |
| SC-007 | Feature/People/WorkosInvitationSyncTest.php — enforces sync profile authorization and durable overlap prevention; Feature/People/PeopleInvitationProjectionTest.php — reauthorizes hydrated pages and direct sync actions |
| SC-008 | Feature/People/WorkosInvitationSyncTest.php — preserves newer access and invitation writes during a provider read; restores tenant context after sync job success and denied actor; rolls back the snapshot when its atomic audit write fails; isolated PostgreSQL probe |
| SC-009 | Feature/People/WorkosInvitationRecoveryTest.php — resends pending and reissues expired invitations |
| SC-010 | Feature/People/WorkosInvitationRecoveryTest.php — skips accepted members and blocked people with an eligible positive control |
| SC-011 | Feature/People/WorkosInvitationRecoveryTest.php — distinguishes default and explicit revoked recovery |
| SC-012 | Feature/People/WorkosInvitationRecoveryTest.php — guards repeated sends retries and revoked actors; records uncertain remote delivery and never blindly replays a POST; preserves a newer invitation after a successful but stale POST and restores job context; isolated PostgreSQL probe |
| SC-013 | Feature/Auth/TenantFirstAccessTest.php — records first and last authorized tenant access |
| SC-014 | Feature/Auth/TenantFirstAccessTest.php — rejects blocked callback without activation; rejects inactive accounts and invalid identity without recording access; denies blocked platform entry without access evidence |
| SC-015 | Feature/Auth/TenantFirstAccessTest.php — records authorized platform entry and tenant switching without treating grants as access |
| SC-016 | Feature/People/InvitationStatusTransitionTest.php — preserves obligation evidence during legacy transition; imports new people invited without changing existing protected statuses; grants a new tenant administrator invited and preserves existing suspended status |
| SC-017 | Feature/Requirements/AllStatusAssignmentEligibilityTest.php — assigns all statuses with target and frozen version constraints; preserves all-status effective windows recurrence and existing obligation history |
| SC-018 | Feature/People/PeopleInvitationProjectionTest.php — combines filters with consistent counts and separate access evidence; orders and paginates deterministically clearing selection on filters and page changes; Browser/PeopleInvitationBrowserTest.php — exercises invitation filters and recovery confirmation at mobile and desktop widths |

## Defect sensitivity and safety

The invited first-access check failed before implementation because the existing callback rejected invited people. Recovery checks initially failed before durable intent/overlap behavior existed. For all-status assignment checks, a controlled temporary mutation restored active-only eligibility: six cases failed while two active positive controls passed; final code was restored immediately (/private/tmp/invite-defect-sensitivity.log). Snapshot tests assert persistence, audit rollback and forbidden mutation/send effects rather than merely duplicating the projection.

WorkOS calls in tests and the harness are fake; stray requests are prevented. No real provider, email, deployment or existing database was used. No .env was created, copied or modified. Harness controls require testing mode, isolated flag and the exact disposable SQLite path. Worker smoke exercised real routes/actions for synchronization, callback activation, platform entry, renewal/idempotency and import fixture generation. This is harness readiness evidence only; every frozen QA-001–018 still requires fresh independent execution.

Automatic review rejected an attempted broader platform invitation adaptation. Root established existing approval for minimal synchronous tenant-company administrator compatibility under architecture §3. The final accepted implementation preserves that flow with the shared validated client; it does not redesign global Account invitations or add platform durable attempts. Approved architecture/spec/design/contract were not amended.
