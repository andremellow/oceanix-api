# Tasks: WorkOS invitation reconciliation

Scope workos-invitation-sync-v1; approved artifacts in approval.md. One Worker owns all production code and tests. No parallel production edits. Specialist review/QA remain independent and read-only. Do not amend approved spec/architecture/scope without owner approval.

## Phase 1 — Setup and foundations
- [x] T001 Validate approved spec/plan/architecture/design/contract and actual environment using .toscanini/bin/toscanini_contract.py.
- [x] T002 Prepare isolated main worktree/dependencies/build/browser and temporary PostgreSQL availability; evidence in readiness.md.
- [x] T003 Add feature migration/User fields and WorkosSyncRun/WorkosInvitationAttempt models, enums and immutable allowlisted WorkosInvitationSnapshot (architecture §§3/7). Preserve legacy/role/assignment evidence and reversible transition records. SC-008,012,016.
- [x] T004 Implement Permission::PeopleSyncWorkos prerequisites, UserPolicy/WorkosSyncRunPolicy and catalog integration, preserving Gate admin bypass; tests SC-007/012/014.

## Phase 2 — US3 first access and assignment independence
- [x] T005 Write defect-sensitive Pest checks for invited callbacks, blocked/invalid callbacks, monotonic first/last access and platform/tenant entry in tests/Feature/Auth/TenantFirstAccessTest.php. SC-013–015.
- [x] T006 Implement RecordTenantAccess, explicit canAccessTenant boundary, WorkosController/AuthenticateSocialLogin/SwitchCompany/EnterCompany changes per architecture; new provisioning starts invited. SC-013–016.
- [x] T007 Write migration/provisioning/evidence checks in tests/Feature/People/InvitationStatusTransitionTest.php; apply approved legacy active→invited transition without invented history. SC-016.
- [x] T008 Write all-status manual/requirement/target/recurrence/version checks in tests/Feature/Requirements/AllStatusAssignmentEligibilityTest.php and extend existing direct regressions; remove person-status assignment gating in Services/Models/manual recipient projection. SC-017/018.

## Phase 3 — US1 reliable synchronization
- [x] T009 Write failing WorkosInvitationSyncTest.php for state mapping, pagination/discovery/mismatch, provider errors, external versus local access, permissions and snapshot interleaving. SC-001–008.
- [x] T010 Extend WorkOS HTTP clients with validated/paginated GET/lookup and read-only membership/user evidence; bounded retries, timeouts, no tokens persisted. SC-001–006.
- [x] T011 Implement QueueWorkosSynchronization/ReconcileWorkosInvitations Actions/Job and durable run lifecycle; scoped actor authorization/context restoration, generation-safe snapshot/audit, progress/error counts. SC-001–008.
- [x] T012 Prove lock/open-operation and stale snapshot behavior with isolated PostgreSQL probe/tests, documenting executable commands/evidence in feature verification artifacts. SC-008/012.

## Phase 4 — US2 invitation recovery
- [x] T013 Write failing WorkosInvitationRecoveryTest.php for pending resend, expired creation, current-member/accepted suppression, default/explicit revoked handling, permission revocation/retries/delivery uncertainty/context restoration. SC-009–012.
- [x] T014 Implement durable attempt initiation/explicit send Actions/Jobs with fresh provider verification and protected selected/all candidate projection, no blind POST replay after uncertain delivery. Extend existing single-detail/import send paths. SC-009–012.

## Phase 5 — UI and direct regressions
- [x] T015 Implement PeopleDirectory projection and list/detail controls per design.md; invitation/access filters, stable pagination, sync progress/outcomes, explicit recovery confirmations, training-count titles; views remain thin. SC-001/007/009–012/018.
- [x] T016 Add projection/Livewire tests in PeopleInvitationProjectionTest.php and real browser checks/isolated QA fixtures for PeopleInvitationBrowserTest.php; keyboard/mobile/loading/empty/error/authorization and combined filters. SC-018 and changed controls.
- [x] T017 Add English canonical strings/PT-BR translations and approved Portuguese product-spec changes; update direct existing tests only for explicitly changed expectations; never weaken accepted behavior.

## Phase 6 — Evidence and delivery gates
- [x] T018 Run focused affected suites and deterministic verification; populate scenario named test/result/defect-sensitivity evidence. All SC-001–018; no unexecuted check labelled passed.
- [x] T019 Freeze immutable implementation checkpoint in contract after stabilization; record raw diff/checkpoint/scope artifacts for fresh specialists.
- [x] T020 Independent complete Code Review and Test Analyst at same checkpoint, then consolidate all findings into ledger/correction batch if needed.
- [ ] T021 Independent executable UI/API QA for all QA-001–018 using safe representative data; independent design review against design.md at stable checkpoint.
- [ ] T022 Final Architect conformance after full QA; impact-directed revalidation only if corrections affect architecture.
- [ ] T023 Run final Toscanini contract/gates/verify and create public-safe execution report with directional efficiency deductions, failure-stage attribution and pending learning proposals. Prepare committed reviewable changes; no production sync/email/deployment.

## Dependencies and execution strategy
T001–004 precede write/operation work. T005→006, T007, T008 establish access/status semantics. T009→010→011 and T013→014 build separate read/recovery flows; T015–017 integrate the UI, then T018–023 complete gates. Tests precede fixes where feasible; record alternatives for migrations/structural prerequisites. Only one Worker edits production/tests; read-only review specialists may run concurrently at T020. Frozen scope cannot grow during implementation/review.
