# Directed Code Review — remediation 01

Verdict: **REQUEST_CHANGES**. Original CR-01–CR-05 corrections are verified; two direct regressions introduced by the correction delta remain blocking.

Administrative terminal status: **BLOCKED (context contamination)**. After substantive inspection, isolated probes, focused tests and this report were completed, the parent supplied another specialist's suspected finding outcome. The reviewer contract requires stopping when given another reviewer's conclusions. No further substantive verification was performed after that message. The findings below were written before it arrived and are preserved as evidence; this run's terminal telemetry is blocked and must not be treated as an uncontaminated approval.

- Run `20261020-account-owned-invitations`; scope `account-owned-invitations-v1`.
- Checkpoint `account-owned-invitations-v1-remediation1-cd88c0078bf46580`.
- Mode `directed`; context `inherited`; round 1; phase `remediation`; sourceRole/agent `code-reviewer`.
- Revalidation reason: verify CR-01–CR-05 closures and smallest causal regression risks of their remediation delta.
- Account delta `90ef69866cfd37729d45db15986df86d305f4bd9..33375e42da9f5c33b2761686229cbfcc90787559`; SHA-256 independently matched `26e5229d39174b52c2b73218819dce4f59baa22743975dc4c81dfc3883075cd1`.
- Compliance delta `20e707ff5b8c2861829808ff88fca7701c516578..5ff9d2089115dee98f9d8c18ec0819289d207ca1`; SHA-256 independently matched `7bf174c250c654e98f327c7074e3a458397f7a7719bbc384f9b25e01fd4610eb`.
- Original independent report and verdict remain unchanged. This directed result does not replace independent approval. No tooling amendment, source/test edit, ledger-status edit, or scope expansion was performed.

## Assigned outcomes

| ID | Original correction | Evidence / result |
| --- | --- | --- |
| CR-01 | Verified | Account callback compares the immutable source actor with the envelope and then invokes origin-aware live authorization, with explicit source member/product binding. Focused tenant/platform cases without an Account User pass, with forged actor denial. Account `AuthorizeAccountOperator.php:48-50`; `SystemPrincipalTest.php` CR-01 datasets. |
| CR-02 | Original receipt-identity defect corrected; new causal retry defect CR-06 blocks final approval | Product requests receive their own persisted transition and protocol identity, with a separate reference to one canonical reconciliation. Two HTTP identities, GET recovery, hash conflict and principal isolation are covered by passing CR-02 cases. Account `QueueCompanyInvitationSync.php:54-73`; `InvitationObservationDeliveryTest.php` CR-02 datasets. |
| CR-03 | Original queued/404 recovery defects corrected; new causal ordering defect CR-07 blocks final approval | Recovery accepts queued attempts, retrieves the original receipt, or submits the same immutable intent after 404 with current authority and generation guards. Three passing cases cover Sending receipt, manually requeued committed receipt and missing receipt, retaining first access and preventing replay after completion. Compliance `SendWorkosInvitation.php:139-167`; `AccountInvitationTest.php`. |
| CR-04 | Verified | Account serializes explicit `needs_attention` for affected terminal operations; Compliance only treats terminal outcomes as terminal. `unknown` with safe error remains polled and later completes using the original identity. Account `OperationResource.php:20-23`, `ProductOperationEnvelope.php:61`; Compliance `ReconcileWorkosInvitations.php:156`; passing CR-04 test. |
| CR-05 | Verified | Membership resume checks current registry readiness before claiming or making HTTP calls. Disable-execute preserves the operation and sends nothing; reenable-execute confirms identity/membership once. Account `EnsureProductPersonMembership.php:66-68`; passing CR-05 test. |

Repository attribution follows the parent-provided local ledgers: Account assigned CR-01/02/05; Compliance assigned CR-01/03/04. Cross-repository outcomes are reported together above; this report does not mutate either ledger.

## Verification executed

Inspected committed correction code and the directly relevant changed test cases, transport/resource contracts, retry state transitions and ordering guards. No complete review restart or unrelated defect discovery was performed.

Focused Pest runs used `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory:` and fake HTTP:

- Account: `SystemPrincipalTest.php`, `InvitationObservationDeliveryTest.php`, `LegacyInvitationCutoverTest.php`, filter `CR-01|CR-02|CR-05`: **5 passed, 78 assertions**, exit 0.
- Compliance: `AccountInvitationTest.php`, `WorkosInvitationSyncTest.php`, filter `recovers an accepted Account invitation receipt|CR-04`: **4 passed, 32 assertions**, exit 0.

Initial sandboxed Pest attempts could not bind the browser plugin's local socket; authorized escalated reruns above succeeded. No tooling change was needed. Parent-supplied canonical exits were not substituted for these directed checks. The two probes below booted the actual applications with explicitly selected in-memory SQLite databases, migrated only those disposable databases and faked every external request. They did not modify repository tests or production data. This is directed code verification, not full executable QA.

## CR-06 — A past manual reference retry repeatedly rearms exhausted canonical work

- Severity: **P1 / high**; blocking **yes**; local repository **Account**.
- Classification `IMPLEMENTATION_DEVIATION`; failureStage `implementation`; sourceRole `code-reviewer`.
- Discovery round 1, phase remediation; introduced by CR-02 correction.
- Blocking basis: **INV-05**, **AC-03**, SC-18/19; frozen regression surface **Affected queue/retry/projection order, existing registration and AuthKit login**; approved architecture §7.4 requires bounded retries and a new manual recovery action after exhaustion.
- changedPaths: Account `app/Actions/CompanyUsers/ReconcileCompanyInvitation.php:133-143`.
- changeEvidence: The new reference worker treats any non-null `retry_started_at` as a new manual request whenever its canonical operation is `needs_attention`. That timestamp persists after the first retry. If the canonical job exhausts its renewed seven attempts before the pending reference job observes its terminal state, the reference job resets the canonical targets' attempts to zero and rearms them automatically. It never consumes the retry request or records which canonical retry generation it authorized. The same scheduler ordering can repeat indefinitely. This path did not exist before the remediation.
- Reproduction: Construct the reachable post-manual-retry state: reference `unknown/pending` with its old non-null retry timestamp; canonical delivery `needs_attention`, attempts 7, no next attempt, successful provider read retained. Run the reference processor once, without calling RetryAccessTransition. With product HTTP returning 503, the probe observed `canonicalState=unknown`, `deliveryAttempts=1`, `deliveryState=unknown`, `aliasState=unknown`, `newHttpRequests=1`, `manualRetryInvoked=false`. Thus terminal work resumed and its attempt budget reset without a new operator action. The focused CR-02 test covers immediate recovery success, not re-exhaustion while a reference waits.
- Required outcome: Consume or version a manual retry authorization atomically so one retry rearms the canonical operation at most once. After renewed exhaustion, both canonical and referencing work must remain terminal until another authorized manual action. Cover canonical-before-reference and reference-before-canonical scheduling without repeating confirmed reads/deliveries.

## CR-07 — Recovering a historical send overwrites a newer observation of the same invitation

- Severity: **P1 / high**; blocking **yes**; local repository **Compliance**.
- Classification `IMPLEMENTATION_DEVIATION`; failureStage `implementation`; sourceRole `code-reviewer`.
- Discovery round 1, phase remediation; introduced by CR-03 correction.
- Blocking basis: **INV-03**, **INV-05**, **AC-03**, SC-13/19; frozen regression surface **Affected queue/retry/projection order, existing registration and AuthKit login**; architecture §7.3 requires monotonic ordered observations and preservation of accepted evidence.
- changedPaths: Compliance `app/Actions/People/SendWorkosInvitation.php:161-167`.
- changeEvidence: New `$alreadyApplied` accepts equal generation and invitation ID without checking observation sequence/content. It then unconditionally writes the old receipt's snapshot attributes. A delayed pending send receipt for generation 5 therefore replaces an already accepted generation-5 observation with sequence 2. It also clears current `invitation_accepted_at`, while leaving sequence/hash at the newer value. Previously a generation-4 attempt recovering against current generation 5 was rejected; this overwrite is specifically enabled by the remediation's new shortcut.
- Reproduction: Seed a manually requeued generation-4 attempt with an immutable send intent and a person currently at generation 5, same resulting invitation, accepted state, accepted timestamp, observation sequence 2. Fake the old confirmed send receipt as pending generation 5. Execute the real SendWorkosInvitation Action. Probe output: `attempt=succeeded`, `invitationState=pending`, `acceptedAt=null`, `sequence=2`, `generation=5`. The newer observation is overwritten while its ordering metadata remains. The three passing recovery tests do not seed a newer same-generation observation.
- Required outcome: Finalize attempt/receipt recovery without overwriting a newer invitation projection. Apply returned observations through the approved ordering guard, or preserve already-applied newer state when merely closing the send attempt. Verify pending receipt after accepted/expired/revoked higher-sequence observations and preserve accepted history, access and monotonic metadata.

## Disposition

Retain verified original closures CR-01–CR-05, subject to consolidated remediation of the two newly introduced direct regressions CR-06/07. Account local new finding count: 1 (CR-06); Compliance local new finding count: 1 (CR-07). No unrelated blocking improvements or contract amendments are proposed. Do not mark the review gate approved at this checkpoint.
