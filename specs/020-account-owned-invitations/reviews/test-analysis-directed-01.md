# Directed Test Analyst verification — remediation 01

**REQUEST_CHANGES** — TA-02–TA-08 are verified closed; TA-01 remains partially unresolved in Compliance. This is directed verification, not an independent approval or a reopened whole-scope audit.

Scope: `account-owned-invitations-v1`. Checkpoint: `account-owned-invitations-v1-remediation1-cd88c0078bf46580`. Architecture SHA-256: `490eb8c361fc7cbdb60f3ae8d0b33843fb25c1c140a243df5e2a9f8294310ecb`. Round 1, phase remediation, review-mode directed, context-mode inherited. Source role: test-analyst. Original complete `test-analysis.md` remains unchanged. No ledger entries closed by this report.

## Frozen correction scope and evidence

Inspected only TA-01–TA-08, their linked AC/INV/scenarios, and the correction delta in `remediation-01-checkpoint.json`:

- Account: `90ef69866cfd37729d45db15986df86d305f4bd9..33375e42da9f5c33b2761686229cbfcc90787559`, 13 paths, expected raw diff hash `26e5229d39174b52c2b73218819dce4f59baa22743975dc4c81dfc3883075cd1`.
- Compliance: `20e707ff5b8c2861829808ff88fca7701c516578..5ff9d2089115dee98f9d8c18ec0819289d207ca1`, 10 paths, expected raw diff hash `7bf174c250c654e98f327c7074e3a458397f7a7719bbc384f9b25e01fd4610eb`.

Independent targeted reruns used explicit `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory:` with fake external HTTP: Account **23 passed / 263 assertions** (`/private/tmp/owned-invitations-ta-directed-a.log`); Compliance **58 passed / 372 assertions / 1 skipped** (`/private/tmp/owned-invitations-ta-directed-c.log`). The skipped case is the guarded PostgreSQL receiver test. Escalation allowed Pest's required local socket/cache writes. No `.env` changes, normal/live QA database refreshes, source/test edits, product QA or real provider requests occurred.

Read raw dedicated execution logs:

- `/private/tmp/remediation-c-pg-receiver.log`: 3 passed / 40 assertions, including the actual competing receiver test on the explicitly guarded Compliance `_test` PostgreSQL database.
- `/private/tmp/remediation-a-pg-platform.log`: 3 passed / 35 assertions, including competing platform workers on the explicitly guarded Account `_test` PostgreSQL database.
- `/private/tmp/remediation-c-sync-browser.log`: 6 passed / 96 assertions, covering the added pending/completed/partial/revoked browser cases.

Those PostgreSQL/browser logs are observed execution evidence; this reviewer independently inspected their test implementation and assertion sensitivity but did not rerun those suites. Canonical actual exit-zero evidence is retained in the checkpoint and runtime logs; counts alone are not closure proof. The rejected Account tooling amendment remains rejected; no tooling or scope amendment is proposed here.

## Outcomes by assigned finding

### TA-01 — PARTIALLY UNRESOLVED

Basis retained: AC-02/AC-04, INV-05, SC/QA-09,15,20,21; failureStage `test-implementation`, classification `in-contract-test-gap`, sourceRole `test-analyst`. No new finding ID.

**Verified progress:** Account `tests/Feature/Integration/SystemPrincipalTest.php` now executes the real POST `/api/product-access/v1/actor-authorizations` for persisted provisioning and mapping targets, proves a current holding-admin positive control, bad hash/missing-intent rejection and revocation. Its product-origin observation tests cover tenant/platform actors without an Account login. The source correction compares the persisted actor identity directly and checks member/product binding. Compliance's `ControlPlane/SystemPrincipalTest.php` executes the real mapping-source callback and has a valid stored source/mapping positive control plus permission revocation. Its signed-proof test exercises success, expired/bad signature/hash/actor/nonce and nonce reuse through the real `AccountUserClient` validator with no persistence effects. Targeted reruns pass.

**Remaining defect sensitivity gap:** Compliance `tests/Feature/ControlPlane/SystemPrincipalTest.php:45–50` alters submitted `source_payload_hash`, `subject_id`, `source_operation_id`, actor or company and recomputes the submitted hash, but leaves the stored mapping operation's `payload_hash` at the original value (created at lines 41–42). For the source-hash and subject cases, `AuthorizeProductActor.php` rejects at `hash_equals($mapping->payload_hash, $hash)` before evaluating the source relation checks. The unknown actor/company cases can fail even earlier. Removing the later source actor/company/user/kind/hash/subject checks would therefore leave these negative assertions passing. Missing-source rejection proves source existence, not those bindings. This is the same original TA-01 requirement to prove exact persisted source authority, not an added scenario.

**Required closure:** Add representative fixture combinations with a persisted mapping whose hash equals the submitted immutable envelope and valid outer principal/company/actor/permission controls, but whose referenced stored source has the wrong subject, source hash or actor/company relation. Prove the actual source relation check denies the request while a valid source succeeds and records remain unchanged. Tests may create those fixture combinations directly; no production policy or immutable runtime record needs alteration. Do not merely change submitted payload while retaining a different persisted mapping hash.

**Repository attribution:** Account's local TA-01 portion is verified; Compliance's local TA-01 remains open, so the combined finding cannot be closed.

### TA-02 — VERIFIED CLOSED

Basis: AC-02, INV-03; SC/QA-09–11,14.

Account `CompanyUsers/ExistingPersonMappingTest.php` now parameterizes the real mapping Action for provider identity and null WorkOS-user/exact-invitation identity. A separate expired/revoked automatic-mapping test starts with no ProductPersonLink, checks mapping acknowledgement precedes the real provider read, then verifies exactly one read, the linked local ID and unchanged access/identity fields with no entitlement or provider mutation. Conflict cases cover duplicate invitation identity, occupied link, foreign organization and different stable identity; a valid peer succeeds afterward and changed intent is rejected without rewriting the stored hash. Compliance `ControlPlane/ExistingAccountPersonMappingTest.php` uses null provider identity for exact invitation mapping, then applies expired/revoked observations through the actual HTTP receiver; it checks user/access/role preservation and link counts. Its duplicate/org/binding/stable-ID/occupied-link/local-ID dataset rejects conflicts, preserves users/links/access/receipts, then completes an exact peer. Same-key changed mapping payload now produces 409 followed by unchanged original replay. These reach the previously omitted branches with actual persistence.

### TA-03 — VERIFIED CLOSED

Basis: AC-03, INV-05; SC/QA-19.

Compliance `ControlPlane/InvitationObservationTest.php` adds equal-pair identical-snapshot acceptance under a different UUID, same-pair altered-snapshot conflict, changed same-key intent conflict, new-generation replacement before an older high-sequence result, and same-generation foreign invitation rejection. The real PostgreSQL test launches a separate PHP HTTP-kernel receiver, proves its authority HTTP callback ran at transaction level zero, confirms it remains blocked by held principal/company locks, commits generation 2 while generation 1 waits, then verifies the competing request returns superseded, exactly two receipts exist and protected fields remain equal. The raw PostgreSQL log proves that non-skipped path executed. These distinguish actual contention from the former single-connection fake interleave.

### TA-04 — VERIFIED CLOSED

Basis: AC-03, INV-04/05; SC/QA-16–18.

Account `InvitationObservationDeliveryTest.php / TA-04 retains one real provider read…` starts with a non-null invitation. Its 401/404/malformed/mismatched/lost-response dataset increments a provider GET counter, keeps the read target confirmed and Account observation updated while delivery fails, then manually recovers with the same delivery UUID/hash/payload, one read and one receiver application. Lost-response stores a receipt and returns it on GET, so replay cannot quietly apply twice. No provider mutation is permitted. Compliance mapping and observation tests now assert altered same-key payload 409 and unchanged original receipt/projection. The new canonical-operation alias delta also has real HTTP identity/issuer/replay checks and a failed-delivery retry case proving one provider read and preserved invitation observation sequence across aliases. The targeted suite passes.

### TA-05 — VERIFIED CLOSED

Basis: AC-03/AC-05, INV-05; SC/QA-18,22.

Account `LegacyInvitationCutoverTest.php / TA-05 recovers only due unclaimed committed work…` persists due/expired-claim/live-claim/future/confirmed/terminal operations, clears the queue fake, verifies cutover-off dispatches nothing, then runs the real command and compares the exact pushed scalar operation IDs. Serialized jobs execute the real processor and confirm only the selected read targets. The PostgreSQL reservation test additionally commits representative fixtures, dispatches through the command and executes recovered scalar jobs after real contention. The new membership pause/reenable test checks unchanged persisted work while disabled and exactly one identity plus membership creation after reenable. Compliance's quarantine test proves read-only preview table equality, apply dispositions for queued/running/sending records, retained dispatched timestamp/generation, repeat apply behavior and zero external/queued work. Existing bounded-retry and real-provider delivery tests retain the no-confirmed-effect-replay checks. No dispatcher shortcut substitutes for the command anymore.

### TA-06 — VERIFIED CLOSED

Basis: AC-05/FR-014; SC/QA-07,12,15,18,23.

Compliance `tests/Browser/PeopleAccountAccessTest.php` now clicks synchronization in the real screen at widths 390/1440, executes the actual new-format job, observes queued/running and disabled action, then lets browser polling resolve populated and no-invitation peers to completed or partial failure. It asserts processed counts, last verified evidence retained on failure, unchanged access/accepted history, safe per-person failure text, status live-region, retained search/focus, no horizontal overflow and no JavaScript errors. A second case revokes the synchronization grant after queueing, runs the job and observes failed/revoked feedback with hidden action, no Account operation and no external request. Dedicated raw browser output shows all six cases passed. This is automated browser evidence, not a replacement for independent product QA.

### TA-07 — VERIFIED CLOSED

Basis: AC-03/AC-05, INV-03/05; SC/QA-17,19,23 and architecture clarification 02.

Account `InvitationObservationDeliveryTest.php` now exercises dispatched-but-unconfirmed identity and membership stages separately through the actual writer/processor and replay; assertions require attention state, original hash, one mutation for the ambiguous stage and no reciprocal link or invitation. Existing exact membership/occupied-link cases permit GET only and protect provider identity/local link. `InvitationConcurrencyTest.php / TA-07 serializes competing PostgreSQL platform workers…` launches a second worker against the same committed operation, demonstrates lock blocking, completes the first, then verifies the competitor observes the same confirmed state with exactly two total provider mutations (one identity, one membership), one person/link and two confirmed targets. Raw PostgreSQL output proves execution. Compliance `Platform/AccountOwnedAdministratorTest.php` injects malformed membership receipt, conflicting reciprocal link and local link-write failure after remote confirmation; it asserts no role/success audit/confirmed local operation on failure, then recovers only the same stored receipt for recoverable cases with one PUT and unchanged envelope/hash. Conflict remains rejected. These are representative failure boundaries of the newly added writer, rather than tests of the older registration Action.

### TA-08 — VERIFIED CLOSED

Basis: AC-01, INV-01/03; SC/QA-05.

Account `WorkosPeopleImportTest.php / TA-08 rolls back a later partially materialized source…` imports two distinct invitation-only sources with preexisting immutable unknown evidence for the second. The injected entitlement insert failure asserts the failing source already has person/member and local-baseline writes inside the transaction. Results require one committed success and one attention outcome, rollback of the second person's/member's/entitlement's writes and its baseline update, retention of legacy UUID/hash/input/state/attempts/receipt, then two restarts with exact final counts and unchanged first committed member. Assertions forbid product HTTP and every provider mutation throughout. The targeted rerun passes, closing the prior pre-write-only failure gap.

## Local verdicts and retained scope

Account local IDs TA-01/02/04/05/07/08: **APPROVE**, zero outstanding local findings. Compliance local IDs TA-01/02/03/04/05/06/07: **REQUEST_CHANGES**, one outstanding local finding (TA-01). Combined assigned set TA-01–TA-08: **REQUEST_CHANGES**. TA-08 remains an Account finding referenced from this mirrored report; TA-03/06 remain Compliance findings referenced in Account.

No unrelated findings, new QA scenarios, approval claims, tooling changes or ledger mutations were introduced. The remaining action is the original TA-01 source-binding assertion sensitivity gap. This directed result cannot satisfy the independent fresh-context gate.
