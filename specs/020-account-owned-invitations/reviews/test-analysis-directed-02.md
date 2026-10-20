# Directed Test Analyst verification — remediation 02

**APPROVE** — the remaining Compliance TA-01 source-binding gap is verified closed. The smallest causal regression checks for the round-two retry and receipt-recovery changes pass. No additional finding remains in this assigned directed scope.

Scope `account-owned-invitations-v1`; checkpoint `account-owned-invitations-v1-remediation2-9a02b0261bbf387d`; architecture SHA-256 `490eb8c361fc7cbdb60f3ae8d0b33843fb25c1c140a243df5e2a9f8294310ecb`. Round 2, phase remediation, review-mode directed, context-mode inherited, sourceRole test-analyst.

## Scope and checkpoint

Inspected the exact correction manifest and raw changes independently, without Worker conclusions or other reviewer verdicts:

- Account `33375e42da9f5c33b2761686229cbfcc90787559..8cb3a44f330c4675f11acf1ee3a6fbbde51ea6f2`; actual raw diff SHA-256 `b2520172853012670f87dbbc9cd8c5072d55935d33e87305c4d29cb0182d97fa`, matching the manifest.
- Compliance `5ff9d2089115dee98f9d8c18ec0819289d207ca1..462f6b5604268904393fa8aebee2bb771ca4beaf`; actual raw diff SHA-256 `a786f13c7177f9e512e6206415ffef2417ddcdf30eefdd258d3b206e9b1cba56`, matching the manifest.

Both current HEADs match the checkpoint commits. Review was limited to remaining TA-01 and regression risks causally introduced by these corrections on the already frozen invitation/retry surfaces. Documentation changes do not introduce an automated behavior boundary requiring further tests. Original specification, architecture and QA scope remain unchanged. The rejected Account tooling amendment remains rejected.

## TA-01 — VERIFIED CLOSED

Retained basis: AC-02/AC-04, INV-05, SC/QA-09,15,20,21; original failureStage `test-implementation`; classification `in-contract-test-gap`.

Compliance `tests/Feature/ControlPlane/SystemPrincipalTest.php / TA-01 verifies the real persisted mapping source callback and current permission` now creates six separate persisted source/mapping fixture pairs. Each submitted mapping envelope has exactly the hash stored on its mapping operation, explicitly asserted before the real authorization POST. The referenced source varies one relation: payload subject, source hash, actor, company, target user or kind. The principal/company/actor/permission and outer envelope remain otherwise valid.

This reaches `AuthorizeProductActor`'s source relation comparisons after the mapping-hash condition, closing the earlier false-positive path. For example, the source-hash case keeps the submitted and stored mapping hashes equal while changing only the referenced source's persisted hash; the subject case changes the referenced source's payload subject while the mapping envelope remains valid. Removing those respective relation checks would make their forbidden assertions fail. The unchanged valid-source success control and post-revocation denial remain in the same test. Every invalid relation asserts the complete operation table is unchanged, and the suite forbids outbound HTTP.

Account's TA-01 local authority approval from directed round one is retained: this delta does not alter `AuthorizeAccountOperator`, its endpoint or its authority tests. No Account authority review was reopened.

## Smallest causal correction checks

### Account retry-reference rearm — effective regression coverage

Basis retained: AC-03, INV-05; SC/QA-17–19. `ReconcileCompanyInvitation::reconcileReference` now locks and consumes a reference's persisted `retry_started_at` authorization before conditionally rearming an exhausted canonical operation. The changed test in `CompanyUsers/InvitationObservationDeliveryTest.php / CR-02 keeps each product HTTP identity while sharing one canonical Account reconciliation` covers both canonical-first and reference-first processing order.

Representative persisted canonical work and two product aliases share one real provider invitation read. After manual alias retry and transient 503 delivery failures, the test advances time through exactly seven automatic attempts. Repeated later reference/canonical processing must retain attention state and produce no additional delivery or provider read. A later explicit authorized manual retry then confirms the work, preserving protocol identity and observation sequence. These assertions distinguish consuming one retry authorization from the prior behavior that could repeatedly rearm exhausted work. Existing immediate-success and simple manual-recovery controls remain in the parameterized test.

### Compliance receipt recovery — effective regression coverage

Basis retained: AC-02/AC-03, INV-03/05; SC/QA-13,17,19. `SendWorkosInvitation::recover` now skips rewriting the person's projection when the confirmed send receipt's invitation ID and generation are already applied, while completing the durable operation/attempt.

`People/AccountInvitationTest.php / recovers an accepted Account invitation receipt without sending again or changing first access` adds accepted/expired/revoked fixtures at the receipt's current generation and invitation ID, with a newer observation sequence/hash, accepted history and verification time. The recovered receipt describes older pending invitation evidence. The test asserts the entire persisted user attributes remain exactly equal while the queued attempt completes successfully; repeated execution adds no request. The original not-yet-applied, committed receipt and same-intent missing-receipt recovery cases remain positive controls. Removing the new skip guard would overwrite the representative later lifecycle and timestamps and fail the exact snapshot assertion.

## Independent targeted execution

Executed only the causal suites against explicit isolated SQLite using `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory:`. WorkOS/product HTTP was faked; no live QA database, normal database or provider was used.

| Repository | Executed scope | Result | Raw log |
| --- | --- | --- | --- |
| Compliance | `ControlPlane/SystemPrincipalTest.php` and `People/AccountInvitationTest.php` | 15 passed, 122 assertions | `/private/tmp/owned-invitations-ta-directed-02-c.log` |
| Account | `CompanyUsers/InvitationObservationDeliveryTest.php --filter=CR-02` | 4 passed, 136 assertions | `/private/tmp/owned-invitations-ta-directed-02-a.log` |

Pest required its local socket/cache permissions, granted through the authorized escalation. Canonical pair exit-zero results are retained in the checkpoint; this report does not substitute counts for the branch/assertion evidence above. No new PostgreSQL/browser/full QA execution was needed for these specific deltas, which do not change the previously verified PostgreSQL contention or browser interaction boundaries.

## Retained approvals and verdict

TA-02–TA-08 retain their verified-closed outcomes and evidence in `test-analysis-directed-01.md`: their mapping, receiver concurrency, delivery, recovery-command, browser, platform and partial-import test boundaries were not reopened. The only overlapping retry/projection risks from this delta were checked above. Original complete and directed-one reports remain immutable.

Local Account verdict: **APPROVE**, original local TA-01 authority approval retained plus causal retry check passed. Local Compliance verdict: **APPROVE**, remaining TA-01 closed plus causal recovery check passed. Outstanding findings in this directed assignment: **0**. No source/tests, ledger or tooling changes were made, no new QA scenario or product requirement was introduced, and no independent fresh-context gate is claimed by this directed result.
