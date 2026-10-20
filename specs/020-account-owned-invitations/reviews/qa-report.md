# Independent runtime QA — BLOCKED

Run `20261020-account-owned-invitations` · scope `account-owned-invitations-v1` · checkpoint `account-owned-invitations-v1-remediation2-9a02b0261bbf387d` · round2 · independent/fresh.

22/23 frozen scenarios completed (including failures); QA-11 blocked. Two in-contract implementation findings prevent approval. No automated tests or implementation review were used as runtime proof.

## Setup and execution

Designated disposable local HTTP Account16586/Compliance16587 and separate live QA PostgreSQL account_owned_account/account_owned_compliance were verified by guarded readiness. No normal .env, production/Herd or real provider navigation/API was used. Actual browser controls, HTTP, queues and PostgreSQL were exercised; synthetic provider and private fault/clock controls supplied deterministic external outcomes. Status, errors, receipts, operation IDs, payload hashes and mappings were persisted in the private evidence files below.

## Findings

### QA-F01 — Authorized product-only administrator cannot synchronize

Blocking high; detectedBy QA; failureStage implementation; basis: AC-02, AC-05, SC-15, SC-23.

Setup: Real platform invite, synthetic provider acceptance and real AuthKit callback created active Compliance administrator User12/user_qa_generated_2, reciprocal company mapping; Account login User count remained0. Action: Administrator pressed Compliance invitation sync; actual queues and stored signed intent were exercised. Expected: Current authorized source administrator can perform Account-owned synchronization. Observed: Run4 processed9/9 failures provider_verification_failed; exact signed Account request returned403. Existing manager passed authority with409 generation conflict and subsequently synchronized same accepted person. Nonadmin source grant also succeeded with an existing Account login identity.

Required outcome: Authorize the approved source-bound actor without requiring an incidental Account login record.

Evidence: `qa-accepted-actor-compliance.json`, `qa-accepted-sync-compliance.json`, `qa-product-proof-result.json`, `qa-manager-proof-result.json`, `qa-actor-account.json`, `qa-actor-compliance.json`, `qa-manager-accepted-sync-compliance.json`, `qa-grant-positive-compliance.json`.

### QA-F02 — Exact imported-person mapping cannot bootstrap invitation reconciliation

Blocking high; detectedBy QA; failureStage implementation; basis: AC-02, SC-09, FR-009, FR-010.

Setup: Four imported provider users have real synthetic invitation IDs/gen0; existing Compliance users7–10 have matching WorkOS IDs and no invitation metadata. Action: Real Compliance mapping/sync controls and actual queues mapped existing people, then reconciled. Expected: Exact mapping preserves local people and allows synchronization to proceed. Observed: All four reciprocal mappings confirmed with no duplicates or role changes, but reconcile intents froze invitation_id=null/gen0 while Account held nonnull/gen0. Account409, batch partial3/7, four failures. Account-origin delivery also reported mapping-required despite exact links. Initialized-person lifecycle/retry positive controls succeeded.

Required outcome: Allow approved exact adoption to initialize the invitation baseline safely and converge without replacing people or immutable intents.

Evidence: `qa-mapped-account.json`, `qa-mapped-compliance.json`, `qa-manager-proof-result.json`, `qa-fault503-account.json`.

## Frozen matrix

| ID | Result | Observed outcome / expected comparison | Evidence |
| --- | --- | --- | --- |
| QA-01 | PASS | Preview one and all companies with product HTTP forbidden: no writes or product requests. | qa-import-isolated.json; qa-import-first.json |
| QA-02 | PASS | Paged import retained pending/accepted/expired/revoked identities, including accepted IDs independently of state; repeat created no duplicates. Imported association remained unverified. | qa-import-runtime.json; qa-mapped-account.json |
| QA-03 | PASS | Exact active membership controlled access; pending/inactive did not activate; explicit denial version7 remained unchanged. | qa-denial-preservation.json |
| QA-04 | PASS | Holding administrator succeeded; unknown company, ordinary actor, malformed and foreign identity safely rejected without writes. | qa-import-first.json; qa-import-empty-conflict.json |
| QA-05 | PASS | Legacy confirmed/unknown immutable payloads and outcomes survived import; injected private write failure then fresh process restart completed without replay or duplicates. | qa-legacy-import.json; qa-legacy-restarted-account.json |
| QA-06 | PASS | Empty import returned zero; actual CLI with synthetic 401/429/timeout/malformed later page/repeated cursor failed without guessed or partial identity writes. | qa-import-failures.json; qa-import-empty-conflict.json |
| QA-07 | PASS | Compliance manager used rendered sync control and actual workers; linked pending→accepted projected into existing person without access or training change. | qa-manager-accepted-sync-compliance.json |
| QA-08 | PASS | Account rendered sync control and workers projected expired with accepted history into existing Compliance person, retaining operational access. | qa-expired-sync-account.json; qa-expired-sync-compliance.json |
| QA-09 | FAIL | Four exact provider-ID mappings succeeded without duplicate people or role changes; subsequent reconciliation froze null invitation/gen0 and received409 against imported nonnull invitation/gen0. Sync could not proceed (QA-F02). | qa-mapped-account.json; qa-mapped-compliance.json; qa-manager-proof-result.json |
| QA-10 | PASS | Invitation-only person mapped by exact invitation/org, then expired and revoked projected without fabricated WorkOS user or activation. | qa-only-expired-compliance.json; qa-only-revoked-compliance.json |
| QA-11 | BLOCKED | Supported factory preparation of duplicate provider ID and same-email/different-ID legacy rows was rejected by PostgreSQL23505; transactions rolled back. Did not bypass constraints. Wrong-company/altered mapping requests409 were observed elsewhere; full prior-mapping and missing-binding variants were not independently completed. | qa-mapping-conflict-identities.json; qa-integrity-controls.json; qa-replay-all-kinds.json |
| QA-12 | PASS | Known no-invitation people rendered honest results from both origins, with zero discovery/send; provider failure retained failure rather than empty success. | qa-start-account.json; qa-manager-accepted-sync-compliance.json |
| QA-13 | PASS | Fresh UTC case retained all lifecycle states and accepted identity, older accepted history did not regress, newer history advanced; active/inactive provider membership did not change local access. | qa-normal-send-compliance.json; qa-utc-accepted-compliance.json; qa-utc-expired-compliance.json; qa-utc-revoked-compliance.json |
| QA-14 | PASS | Real Compliance verify/send and linked resend controls executed via Account queues, including auto-mapping unlinked existing person; no direct operational provider call from Compliance. | qa-unlinked-send-compliance.json; qa-linked-resend-compliance.json |
| QA-15 | FAIL | View-only/direct-route/foreign tenant denials, prerequisite denial and queue revocation passed; temporary nonadmin atomic grant succeeded. Active authorized product-only administrator failed source authorization because Account login user was absent (QA-F01). Original grant restored. | qa-revocation-compliance.json; qa-grant-positive-compliance.json; qa-actor-compliance.json; qa-actor-account.json |
| QA-16 | PASS | Actual HTTP503/401/old-release404/malformed receipt kept provider observation separate from failed delivery. Initialized person manual retries converged with same intents; imported uninitialized cases remain affected by QA-F02. | qa-fault503-account.json; qa-http401-account.json; qa-http404-account.json; qa-malformed-account.json |
| QA-17 | PASS | Receiver-commit/body-loss recovered stored receipt for status and mapping. Same send/map/status intent replay200; altered payload409. Synthetic provider committed invitation then failed connection; retry preserved dispatched unknown marker and did not resend. HTTP body loss is not claimed as TCP failure. | qa-bodyloss-account.json; qa-receipt-results.json; qa-map-loss-final-account.json; qa-replay-all-kinds.json; qa-ambiguous-retried-account.json |
| QA-18 | PASS | Persistent503 exhausted at7 attempts and no next retry; person and Communication retry controls resumed same intent. Killed only spawned worker after persisted lease, advanced private clock past lease, recovery completed same intent and cleared claim. | qa-exhausted-account.json; qa-retry-runtime.json; qa-crash-evidence.json; qa-crash-recovered-account.json |
| QA-19 | PASS | Two barrier-synchronized actual Livewire HTTP requests returned same result and one intent. Operational send created generation2; older waiting generation1 delivery became superseded and could not overwrite. Outbound worker observations recorded transaction level0. Import replacement was not treated as generation advancement. | qa-concurrent-results.json; qa-order-new-send-compliance.json; qa-order-old-retried-compliance.json; qa-network-transactions.json |
| QA-20 | PASS | Actual Account product enable established new company binding with original system credential empty UUIDlist; disable/re-enable succeeded when compatibility list excluded company, without credential change; list restored. | qa-provision-binding.json; qa-provision-legacy-list-binding.json |
| QA-21 | PASS | Actual HTTP boundaries rejected missing/revoked/expired/inactive/wrong environment/product principals and altered actor/company/hash. Valid receipts and immutable intent replays were positive controls. | qa-security-boundaries.json; qa-integrity-controls.json; qa-replay-all-kinds.json |
| QA-22 | PASS | Actual legacy queue jobs quarantined without provider mutation; old-release404 and disabled cutover prevented fallback, retained evidence and errors; runtime readiness flags restored. | qa-legacy-jobs.json; qa-legacy-jobs-compliance.json; qa-cutover-off-compliance.json; qa-cutover-restored-account.json |
| QA-23 | FAIL | Real AuthKit redirect/state/callback positive controls, normal registration and separate send, platform grant/invite and stale revoked-platform form403 executed. Wide/narrow changed controls interacted with; source-only administrator created through these flows could not sync (QA-F01). Normal browser console empty and exact fixture token log scan negative. | qa-authkit-results.json; qa-platform-account.json; qa-platform-compliance.json; qa-log-secret-scan.json; qa-product-proof-result.json |

## Browser, network and application observations

Real Account company users/detail and Communication retry, Compliance People/detail sync/send/resend, registration, platform grant/invite and role-aware controls were exercised. Wide and390px narrow screenshots/AX snapshots are retained in this QA tool transcript; no disk screenshot artifact is claimed. Narrow confirmation modal was opened and cancelled; recovery controls were clicked. Normal changed-path console logs were empty. Intentional HTTP failures were distinguished from normal requests and correlated with persisted target errors. Runtime queue logs and transport-fault-observations.jsonl retain the injected failures. The failed403 source actor and409 mapped identities above were independently reproduced through actual HTTP.

## Preservation, cleanup and limits

Final qa-final-preservation.json compares all five protected tables in an isolated inspection connection using the baseline America/New_York timezone: counts1 each and SHA-256 hashes match exactly for courses, course_versions, user_training_assignments, certificates and compliance_events. Baseline was not modified. An earlier UTC/session representation mismatch was an environment limitation; fresh UTC lifecycle cases supplied valid monotonic-history evidence.

Removed private auth identity selector, clock, configuration and transport-fault controls. Provider state has no failure/response-loss control keys. Original viewer permission, platform authority, service compatibility list and product cutover flags were restored during their scenarios. QA-created operational records/evidence remain in the designated disposable databases for audit; no operational history was deleted. Private raw snapshots can include synthetic authentication fields and must not be copied into public/repository reports.

QA-11 remains blocked because supported safe preparation cannot create the mandated duplicate/email legacy preconditions under current PostgreSQL constraints. Prior-mapping and missing-binding subcases were not completed; this is not a product pass or a new product finding. No constraint bypass or production/test source modification was performed. All other23-matrix entries were inspected and their executed outcomes are recorded above. Body-loss injection after commit proves HTTP response-body ambiguity, not a TCP disconnect; provider ambiguous send separately used synthetic failedConnection.

This report is private runtime evidence. Repository mirroring is delegated to the orchestrator because the QA role is read-only for repository files. No delivery approval is implied.
