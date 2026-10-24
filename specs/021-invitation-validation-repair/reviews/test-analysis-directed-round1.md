# Directed test verification — round 1

**APPROVE** for assigned findings **TA-R01 and TA-R02: both closed**. No new material test finding in the inspected remediation delta.

Run `20261020-invitation-validation-repair`; scope `invitation-validation-repair-v1`; checkpoint `invitation-validation-repair-v1-67670919374299c4`. Role/sourceRole `test-analyst`; phase remediation; round 1; review-mode directed; context-mode inherited. This is directed closure verification, not a second independent whole-scope audit or a replacement for the original full-check evidence.

Compared the archived round-0 checkpoint with the current checkpoint. Verified current SHA-256 for all six changed files: Account ProductActorAuthorization, ProductIntegrationFixture, RegistrationActorAuthorizationTest and PrReviewRecoveryTest; Compliance ExistingAccountPersonMappingTest and InvitationObservationTest. Inspected their causal delta and the existing receiver predicates and authorization callers needed to assess the assigned outcomes. No other role reports or implementation conclusions were consulted. No application/test files were edited.

## TA-R01 — Closed

Basis retained: QA-R02/R03, AC-02/03, INV-03/05, repair architecture §7.2. Original classification test-gap; sourceRole test-analyst; failureStage test-implementation.

Both receiver files now add four representative cases: sequence-only, hash-only, verification-only and fully observed null invitation. Mapping first succeeds through the authenticated control-plane endpoint with the exact existing identity. Observation first persists an actual authenticated `none` observation. The isolated guard fixtures then retain exactly the selected evidence, making each condition causally distinguishable. The incoming observation sequence is 99, exceeding both zero and one, so rejection reaches the changed same-generation invitation condition instead of passing through an older/equal-pair outcome.

Each negative request uses a fresh operation identity, exact reciprocal link and a different nonnull invitation pointer. It must return conflict. Before/after comparisons include populated users, links, earlier receipts, actual product access, role assignment, completed training assignment and certificate rows. A successful request that corrupts protected state or leaves a failed-operation receipt cannot satisfy these comparisons. Removing any one of sequence/hash/verification conditions from the untouched predicate would incorrectly allow its isolated case and fail the test. Both original untouched-positive initialization tests remain present and passed; the known null-pointer controls therefore do not merely prevent all initialization.

Evidence: Compliance `tests/Feature/ControlPlane/ExistingAccountPersonMappingTest.php`, test `TA-R01 rejects initialized null invitation mapping independently of each untouched guard`; `InvitationObservationTest.php`, test `TA-R01 rejects a higher-sequence new invitation after each initialized null guard`, plus the retained QA-R02 positive tests.

## TA-R02 — Closed

Basis retained: QA-R01/R06, AC-04, INV-05, repair architecture §7.1. Original classification test-gap; sourceRole test-analyst; failureStage test-implementation.

The new real-authorizer test uses exact membership-GET HTTP fakes, a cached-inactive member and four cases: unchanged positive, principal deactivation, company organization rebinding, and module inbound-principal rebinding. It captures matching active provider evidence before mutating the local principal/company/binding inside the network response callback. Consequently the proof and initial checks succeed, and each negative can be rejected only after the network seam has been entered. Exactly one recorded request is required; the positive case succeeds. Removing the corresponding fresh post-read condition would permit that negative case, while no subsequent outer Action guard is available to conceal the omission.

Persisted member attributes remain byte-for-byte unchanged, with zero access transitions and zero Account users. Original invalid-proof cases still require zero provider requests across registration/mapping/reconcile; live-provider denial and member-revocation controls remain intact.

Evidence: Account `tests/Feature/Integration/RegistrationActorAuthorizationTest.php`, test `TA-R02 rejects authority revoked during the exact live provider read` and retained QA-R01/QA-R06 controls.

## Smallest causal regression checks

The production remediation adds request-bound evidence reuse for `consumeNonce:false` local rechecks. Its new regression test requires a successful consuming check before reuse; accepts the same proof and exact envelope inside a transaction without another provider read; rejects a fresh proof, copied envelope, consumed-nonce replay, replaced request context and revoked principal. The helper asserts provider GET runs at the pre-Action transaction depth. Existing PrReviewRecoveryTest still verifies later principal/company/capability/actor revocation before rearming and unchanged durable targets; only the successful receipt-recovery GET count changes from the previous repair checkpoint, consistent with removing redundant provider I/O during local locked checks. No causal test regression identified in this delta.

## Execution

Executed isolated SQLite Pest tests with provider HTTP fakes and required local socket permission:

- Compliance: ExistingAccountPersonMappingTest and InvitationObservationTest — **21 passed, 1 skipped, 146 assertions**. The skipped test is explicitly opt-in disposable PostgreSQL contention; no PostgreSQL execution claimed.
- Account: RegistrationActorAuthorizationTest and PrReviewRecoveryTest — **84 passed, 402 assertions**.

No browser, paired-runtime QA, canonical verification or unrelated review is claimed from this directed check. Those retained or separately required gates are unaffected by this test-analysis verdict. No unresolved assigned decisions, no new scope additions, and zero new findings.

**APPROVE — TA-R01 and TA-R02 closed.**
