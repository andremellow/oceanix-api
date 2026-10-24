# Independent executable QA — invitation validation repair

Verdict: **PASS_WITH_NON_BLOCKING_FINDINGS**. Six frozen scenarios completed; one non-blocking follow-up, zero blockers.

Run: `20261020-invitation-validation-repair`. Scope: `invitation-validation-repair-v1`. Checkpoint: `invitation-validation-repair-v1-67670919374299c4`. Independent/fresh, round1, phaseQA.

Both real loopback readiness endpoints confirmed testing/private databases and disabled real-provider mutations before writes. Used CUA for UI, actual paired HTTP APIs, supported factories/import command and real queue workers. No app/test/source edits, normal environment changes or runtime RefreshDatabase.

## QA-R01 — PASS

**Setup:** Created a unique pending administrator using the real Compliance platform company form. Provider fixture initially reported pending exact membership; no Account login User existed for the new actor.

**Action:** Drained real membership/invitation queues; accepted the synthetic provider invitation; executed actual AuthKit redirect/state/callback. Used the accepted actor to register a new person in the normal UI and start Compliance synchronization. Rechecked an actual fresh queued reconcile with cached active_provider_membership=false, confirmed_enabled=true, exact active provider membership and zero Account login Users. Temporarily removed only this synthetic actor's source role during another queued delivery, then restored it.

**Expected:** Fresh exact current authority works without Account login; stale local cache is not the sole authority; invalid/revoked/foreign authority fails before operational effects.

**Observed:** AuthKit callback302 landed on authenticated dashboard200. Normal registration created one new Compliance person and confirmed Account intent. Initial and post-import raw snapshots both retained the actor cache=false; the import did not make it active. Fresh queued reconcile d6e720cf completed confirmed with cache still false, confirmed access/provider evidence unchanged, Account login Users0. A queued delivery after current source-role removal became needs_attention; role restored. Foreign actor403, pending/inactive membership403; no unintended people or access changes.

**Cleanup:** Restored the synthetic actor's exact original role list and original cache value(false); removed AuthKit identity selector. Kept synthetic operational evidence in the designated disposable databases.

**Private evidence:** `repair-independent-authkit.json`, `repair-independent-a-state.json`, `repair-independent-queued-a.json`, `repair-independent-stale-a.json`, `repair-independent-stale-final.json`, `repair-independent-fresh-security.json`, `repair-independent-final-a-state.json`.

## QA-R02 — PASS

**Setup:** Created separate unique Compliance people fromc22/froma23 with exact fake provider identities, null invitation baseline, generation0/sequence0/no successful verification; imported their existing provider counterparts locally into Account.

**Action:** Queued both origins through their real UIs. Processed the froma Account transition first through real queue workers/HTTP; separately ran the Compliance queue to obtain mapping metadata before freezing fromc reconcile, then processed that Account intent and delivery.

**Expected:** Exact mappings initialize only untouched baselines; both origins converge without duplicates or access/role/history changes; immutable inputs/old receipts remain unchanged.

**Observed:** Account-origin mapping initialized froma pointer before any successful verification, then delivered expired generation0/sequence1. Compliance-origin mapping initialized fromc pointer while status/time remained null, then delivered expired generation0/sequence1. Later successful checks advanced sequences normally. Original197 Account and88 Compliance intent payloads/hashes and confirmed mapping receipts remained unchanged; original users/access/roles/training preserved.

**Cleanup:** Retained only this run's uniquely named synthetic people and mapping/observation evidence. No deletion/reset or historical intent rewrite.

**Private evidence:** `repair-independent-cohort.json`, `repair-independent-froma-c.json`, `repair-independent-afterc-c.json`, `repair-independent-r02-c.json`, `repair-independent-immutable-preservation.json`, `repair-independent-existing-preservation.json`, `repair-independent-existing-c-preservation.json`.

## QA-R03 — PASS

**Setup:** Used the run's actual persisted froma mapping and observation envelopes, plus the loss case's old generation0 requests. Temporary transport faults were scoped to the invitation-observation HTTP kind.

**Action:** Replayed identical mapping/observation intents and changed their immutable input. Lost a usable acknowledgement after receiver commit with one-shot malformed-after; retried the same actual target through UI and worker. Failed a target repeatedly at real retry deadlines via the private clock. Created an explicit new invitation generation through UI after changing only the target's fake provider precondition, delivered generation1, then executed the older queued canonical reconciliation alias.

**Expected:** Same intent reuses receipt; altered immutable input is rejected; lost acknowledgement does not duplicate effects; retries are finite; old work does not replace newer generation/state.

**Observed:** Same mapping and observation receipts200; changed immutable resource_sequence409. Receiver committed sequence3 despite malformed acknowledgement; manual recovery retained identical target ID/hash/payload and left receiver sequence3 unchanged. Automatic retries stopped at7 with needs_attention and next_attempt_at=null; extra worker cycles did not increase attempts. First send when membership was active correctly returned sent=false; after the target was made inactive in the fake provider, explicit send created generation1/new invitation, which Compliance retained after late generation0 canonical receipt replay. The old alias returned its immutable generation0 receipt without replacing current generation1/sequence3.

**Cleanup:** Removed private clock and transport-fault file; resumed the held worker. No real email/provider mutation occurred. The explicit synthetic send is distinct from status-only retries.

**Private evidence:** `repair-independent-receipts.json`, `repair-independent-bodyloss-a.json`, `repair-independent-bodyloss-c.json`, `repair-independent-bodyloss-recovered-a.json`, `repair-independent-bodyloss-recovered-c.json`, `repair-independent-exhausted-a.json`, `repair-independent-order-before-a.json`, `repair-independent-generation-final-a.json`, `repair-independent-generation-final-c.json`.

## QA-R04 — PASS

**Setup:** Owner-approved schema-prevention equivalent: attempted provider-ID and exact normalized-email duplicate Compliance factory records inside rollback-only PostgreSQL transactions. Prepared uniquely named same-email/different-provider and legacy prior-link cases without removing constraints.

**Action:** Ran real Account-origin sync workers to create actual mapping targets and send their HTTP requests. Replayed the actual failing mapping requests; also submitted wrong-company and absent-binding requests through the real API with the valid test service principal.

**Expected:** Constraints prevent impossible duplicate fixtures; reachable identity conflicts and missing/wrong binding deny reassignment, preserve existing mappings, and permit a valid peer to complete.

**Observed:** Both duplicates raised SQLSTATE23505 and retained no rows. Real prior-link mapping returned409 identity_conflict; same-email/different-provider returned409 mapping_required, with both targets visibly requiring attention. Wrong-company/absent-binding probes returned generic500 and did not reassign identity or create people; these statuses are reported literally, not as specific403/404 binding errors. Valid peer froma/fromc completed. Original reciprocal mappings and protected user fields remained unchanged.

**Cleanup:** Rolled back duplicate candidates. Preserved safe synthetic conflict rows/history for inspection; no constraint alteration, original binding mutation, merge or destructive cleanup.

**Private evidence:** `repair-independent-constraints.json`, `repair-independent-conflict-cases.json`, `repair-independent-conflict-a.json`, `repair-independent-conflict-http.json`, `repair-independent-existing-c-preservation.json`.

## QA-R05 — PASS_WITH_NON_BLOCKING_FINDINGS

**Setup:** Used actual queued/confirmed/failing/exhausted/partially delivered targets from this run, manager and viewer fixture sessions, desktop and390x844 viewport.

**Action:** Interacted with actual list sync and person retry controls; observed loading/disabled copy and keyboard actions. Held a real queue worker with host SIGSTOP only after a persisted claim, inspected Processing, then resumed via SIGCONT. Observed list/detail/Communication, retained date+relative age, partial delivery, seven-attempt exhaustion, manual retry, narrow rendering and hidden viewer controls; inspected browser console.

**Expected:** Before-action no-email explanation; retained absolute date+age beside safe error; honest queued/running/completed/partial/exhausted/retry; accessible interaction/focus, narrow composition, permission-hidden controls.

**Observed:** List and detail explain checks without email. Loading displayed Synchronizing invitations and disabled the initiating control; queued status announced queued count/no email and the initiating button regained focus. Actual held claim rendered Processing and hid retry while claimed. Failed checks retained the successful20Oct18:38 date plus relative age alongside Retry due/Needs attention and safe explanation. Exhaustion displayed7 failed attempts, no scheduled retry, Automatic retries stopped and eligible retry. Keyboard retry showed Retrying disabled, then the same intent recovered confirmed. Partial case displayed successful read separately from unconfirmed Compliance delivery; same-target retry recovered. Narrow list/detail body375px at390px viewport, identity/actions/date remained available. Viewer had0 sync controls. One incidental modal console InvalidStateError was captured; no tested flow failed and causal relation to the repair diff was not established.

**Cleanup:** Worker resumed and exited; viewport reset; no pending global fault/clock/auth/config override. Synthetic evidence retained. Browser UI logs were inspected; no separate complete failed-network export is available from the exposed CUA surface, so no blanket zero-network-error claim is made.

**Private evidence:** `repair-independent-running2-capture.json`, `repair-independent-exhausted-a.json`, `repair-independent-bodyloss-a.json`, `repair-independent-bodyloss-recovered-a.json`, `repair-independent-ui-evidence.json`.

## QA-R06 — PASS

**Setup:** Valid isolated service principal plus pre-existing private negative test credentials; this run's actual ordinary-actor registration, mapping and typed reconcile envelopes. Fresh negative requests used new identities without executing new effects.

**Action:** Submitted inactive/environment/product/expired/revoked/absent/invalid principal cases; fresh invalid/expired proof and foreign actor; pending/inactive/foreign provider membership and provider outage; valid actual registration/mapping/reconcile; current source-role removal during queued delivery. Observed responses/provider-call deltas and final people/access/history.

**Expected:** Current principal, exact proof and actor/provider failures deny before operational effects; proof failures make zero provider requests; valid fresh membership proceeds with source ability; authorization does not persist access/cache or use Compliance operational WorkOS fallback.

**Observed:** Principal cases403/provider delta0. Fresh invalid proof403/provider delta0 across registration, mapping and reconcile; expired proof403/delta0; foreign actor403/delta0. Pending/inactive membership403 after one read; foreign membership/provider failure returned generic500 after one read and created no people/access effects. A manufactured fresh intent with no source-owned durable intent was denied500 after current-authority read; it is not counted as a valid positive. Positive evidence comes from real accepted-actor UI registration, mapping and queue reconcile, with no Account login and unchanged cache/access. Source revocation stopped pending delivery; original user access, entitlements, role assignments and training hashes unchanged. No unexpected security-case people were created. Actual observed C operational paths used Account requests and queues; AuthKit callback remained direct and successful.

**Cleanup:** Restored synthetic source roles/cache, provider outage/membership-failure controls; removed temporary global selectors/faults/clock. Retained negative-operation evidence in disposable runtime only.

**Private evidence:** `repair-independent-security.json`, `repair-independent-fresh-security.json`, `repair-independent-shared-guard.json`, `repair-independent-stale-final.json`, `repair-independent-protected.json`, `repair-independent-original-roles.json`, `repair-independent-final-a-state.json`, `repair-independent-final-c-state.json`.

## Preservation and cleanup

Courses, course versions, assignments, certificates and compliance events each retained count1 and identical before/after SHA-256. Roles14, permissions40, permission-role104 and all19 original role-assignment rows were hash-identical. Original Account members/entitlements/links and original Compliance users/product-access/link protected projections had no missing rows or protected-field changes. All197 original Account and88 original Compliance immutable intent inputs were preserved; confirmed mapping receipts unchanged. Added entitlements/people belong to the explicitly created synthetic cohort; total-table hash changes are not misreported as mutations of original grants.

Private clock/fault/auth/config controls are absent, viewport reset, held worker resumed/exited, synthetic role/cache restored. QA-created operational records are retained in the designated disposable environment for audit and are not deleted.

## Non-blocking follow-up

QA-R-FOLLOWUP-01 — CUA console captured InvalidStateError at2026-10-20T18:38:57Z: showModal called on a dialog already open non-modally. No tested UI flow failed; relation to the current diff not established. Detector: QA. Failure stage: implementation (follow-up investigation); no approved blocking basis or demonstrated causal regression. Investigate separately whether the existing dialog lifecycle can emit duplicate modal-opening errors. Do not enlarge this repair scope.

## Evidence limits

- No code/test review, automated-test substitution or prior reviewer/Worker conclusions were used.
- This is the six-scenario repair matrix, not a new full audit of all original23 scenarios.
- Wrong-company/absent-binding and provider/source failures returned generic500; only denial/no effects are claimed, not a specific HTTP error classification.
- Generation ordering evidence includes the real late canonical alias returning its old receipt while current generation1 remains; it is not described as a newly fabricated stale observation being accepted.
- QA-created operational evidence is retained in the local ephemeral databases because operational records are not deleted. Temporary controls were removed; all original rows/history preserved.
