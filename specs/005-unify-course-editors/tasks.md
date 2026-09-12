# Tasks: Unified Course Editors

**Input**: Approved artifacts in `specs/005-unify-course-editors/` and `.toscanini/runtime/runs/unified-course-editor-20260910/execution-contract.json`

**Tests**: Required. The independent test author owns `tests/**`, dependency manifests/locks, test-support scripts, and `.github/workflows/**`. The production owner owns `app/**`, `resources/**`, routes, and translations. Neither owner edits the other stream.

## Phase 1: Approved setup and execution readiness

- [x] T001 Record architecture authoring telemetry, owner approval, architecture hash, and frozen scope in `.toscanini/runtime/runs/unified-course-editor-20260910/execution-contract.json`
- [x] T002 Install the approved Pest Browser and project-owned Playwright development dependencies in `composer.json`, `composer.lock`, `package.json`, and `package-lock.json`
- [x] T003 Align the supported CI JavaScript runtime to Node 22 and prove current private Composer/VCS access without exposing credentials in `.github/workflows/tests.yml` and the execution-contract readiness evidence
- [x] T004 Run a disposable Pest Browser compatibility smoke covering Chrome launch, Laravel fixture visibility, JavaScript evaluation, viewport resize, confirmation handling, controlled request failure, screenshots, and traces; record nonsecret evidence in `.toscanini/runtime/runs/unified-course-editor-20260910/readiness.md`
- [x] T005 Prove the existing SQLite, fake-provider, PostgreSQL 17 CI, build, and focused verification paths and update every readiness category in `.toscanini/runtime/runs/unified-course-editor-20260910/execution-contract.json`
- [x] T006 Validate the approved execution contract with `python3 .toscanini/bin/toscanini_contract.py --run-id unified-course-editor-20260910` before either implementation owner changes production or test behavior

## Phase 2: Foundational shared contracts

- [x] T007 Define the shared stable selector/applicability matrix for company course, shared course, and standalone shared module in `tests/Support/CourseEditor/EditorContextCase.php` and `specs/005-unify-course-editors/contracts/editor-ui.md`
- [x] T008 [P] Create per-test synthetic actor, tenant/platform ownership, draft graph, fake provider, session, port, and artifact support in `tests/Support/CourseEditor/EditorFixture.php` and `tests/Support/CourseEditor/BrowserEnvironment.php`
- [x] T009 [P] Create shared server-side behavior datasets with explicit contextual N/A reasons in `tests/Datasets/CourseEditorContexts.php`
- [x] T010 [P] Production owner exposes the approved semantic hooks and extracted client-state seam in `resources/views/components/course-editor/**` and `resources/js/course-editor.js` without adding test-only authority or behavior
- [x] T011 Establish the current integrated baseline and red-sensitivity record for superseded autosave, positional identity, hidden failure provenance, and cramped field wrappers in `specs/005-unify-course-editors/implementation.md`

## Phase 3: User Story 1 — Edit and save through one reliable experience (P1)

**Goal**: Equivalent authored values use one atomic explicit Save in all applicable contexts while structural/media changes use guarded immediate Actions.

**Independent Test**: Edit distinct scalar, rich-content, and assessment values in each context; prove zero pre-Save writes, atomic Save/reload, stable identities, no-op behavior, and stale whole-graph rejection, alongside bounded immediate structural/media operations.

- [x] T012 [P] [US1] Add shared Pest characterization/red tests for zero blur writes, complete atomic Save, no-op Save, temporary IDs, validation rollback, and stale revision retention in `tests/Feature/CourseEditor/UnifiedEditorSaveContractTest.php`
- [x] T013 [P] [US1] Add server authorization/ownership positive and negative controls for every shared Save context in `tests/Feature/CourseEditor/UnifiedEditorAuthorizationTest.php`
- [x] T014 [US1] Production owner implements the approved coordinator, contexts, DTOs, snapshot/revision services, and whole-draft Save Actions under `app/Livewire/CourseEditor/**`, `app/Services/CourseEditor/**`, and `app/Actions/{Courses,Modules}/**`
- [x] T015 [US1] Production owner moves company, shared-course, and standalone-module entries onto one shared Blade/browser implementation in `resources/views/components/course-editor/**` and the three existing `⚡editor.blade.php` entry files
- [x] T016 [US1] Adapt legacy autosave-focused expectations to the accepted explicit Save contract without deleting effective regression controls in `tests/Feature/Courses/CourseEditorTest.php`, `tests/Feature/Platform/SharedModuleAssessmentEditorTest.php`, and `tests/JavaScript/course-editor.test.mjs`
- [x] T017 [US1] Add extracted client-state tests for clean/dirty/saving/saved/validation/conflict/network generations, no-op blur, stale acknowledgement, navigation guard, confirmation, and failure provenance in `tests/JavaScript/course-editor.test.mjs`

## Phase 4: User Story 2 — Preserve structural, media, ownership, and publication safety (P1)

**Goal**: Structural changes and media associations persist through guarded immediate Actions while upload transfer and context-specific lifecycle invariants remain safe.

**Independent Test**: Decline and accept guarded structural operations, fail/retry unavailable content, transfer/reorder/select media through stable identities, and separately exercise authored Save, authorization, lineage, exact-copy, discard, publication, assignment/history, and mixed-composition controls.

- [x] T018 [P] [US2] Add shared Pest tests proving structural add/remove/reorder/composition and media associations use guarded immediate Actions, reject dirty/upload-unsafe state, and never partially persist on failure in `tests/Feature/CourseEditor/UnifiedEditorStructureMediaTest.php`
- [x] T019 [P] [US2] Add exact failure-provenance and unavailable-reference retry tests through unrelated successful requests in `tests/Feature/CourseEditor/UnifiedEditorFailureRecoveryTest.php`
- [x] T020 [P] [US2] Add transfer-token identity, reordered-target, fake-provider failure/retry, private-reference, and association-on-Save checks in `tests/Feature/CourseEditor/UnifiedEditorMediaTest.php`
- [x] T021 [US2] Production owner integrates guarded immediate structure/media association/transfer/status operations into the coordinator and named context Actions under `app/Livewire/CourseEditor/**` and `app/Actions/{Courses,Modules,Videos}/**`
- [x] T022 [US2] Re-run and adapt, without weakening, the existing composition, authorization, exact-copy, draft-integrity, preview, discard, publication, propagation, assignment, and media suites under `tests/Feature/Courses/**`, `tests/Feature/Platform/**`, `tests/Feature/Modules/**`, and `tests/Feature/SharedContent/**`

## Phase 5: User Story 3 — Use the editor and course details at every supported width (P2)

**Goal**: Assessment inputs meet the approved rendered-width thresholds and both course details use the scoped wide composition.

**Independent Test**: At 1440 and 320 CSS pixels, measure long populated wrappers/containers in all contexts, operate keyboard focus/reorder, and compare both course heroes plus an unrelated control hero.

- [x] T023 [P] [US3] Add browser scenarios measuring the 70% desktop and 90% stacked-mobile question/answer wrapper thresholds, input-to-wrapper fit, focus, labels, and page overflow in `tests/Browser/UnifiedCourseEditorLayoutTest.php`
- [x] T024 [P] [US3] Add browser scenarios for company/shared course text width, action stacking, and an unrelated default-hero negative control in `tests/Browser/CourseDetailWidthTest.php`
- [x] T025 [US3] Production owner applies the approved field-wrapper and scoped course-hero corrections in `resources/views/components/course-editor/**`, `resources/views/components/courses/⚡show.blade.php`, and `resources/views/components/platform/shared-courses/⚡show.blade.php`

## Phase 6: User Story 4 — Run one reproducible regression suite in every context (P2)

**Goal**: Required, isolated, useful browser automation runs locally and in CI with explicit applicability and failure artifacts.

**Independent Test**: Start without prior IDs, cookies, ports, or caches; run the documented matrix, negative setup control, deterministic request failure, and separate real stop/restart recovery job.

- [x] T026 [US4] Port the effective direct Playwright identity/reorder/edit/reload/confirmation evidence into reusable Pest Browser cases in `tests/Browser/UnifiedCourseEditorBrowserTest.php`
- [x] T027 [US4] Add company/shared-course/standalone-module data-driven browser journeys for typing, blur, atomic Save, reload, stale/validation failure, confirmation, focus, and applicability reporting in `tests/Browser/UnifiedCourseEditorBrowserTest.php`
- [x] T028 [US4] Add deterministic controlled request failure/retry coverage and causal negative controls for lost identity, false dirty blur, hidden failure, and width regression in `tests/Browser/UnifiedCourseEditorBrowserTest.php`
- [x] T029 [US4] Add project commands that fail on missing setup and document clean-checkout/local execution and artifacts in `composer.json`, `package.json`, and `specs/005-unify-course-editors/quickstart.md`
- [x] T030 [US4] Add mandatory pull-request browser execution, sanitized screenshot/trace/log uploads, Node 22, and measured timing to `.github/workflows/tests.yml`
- [x] T031 [US4] Add a scheduled and manually triggered actual application stop/restart/recovery browser job using isolated fixtures in `.github/workflows/tests.yml` and `tests/Support/CourseEditor/BrowserEnvironment.php`

## Phase 7: Integrated stabilization and handoff

- [x] T032 Production owner removes obsolete duplicated editor bodies and unused immediate field/structure/media-association Actions only after every entry uses the shared implementation and the cross-context suite is green
- [x] T033 Run focused PHP, Node, Pest Browser, PostgreSQL, formatting, build, `composer verify`, and `toscanini verify --run-id unified-course-editor-20260910`; record counts, durations, skips, artifacts, and one implementation checkpoint in `specs/005-unify-course-editors/implementation.md`
- [x] T034 Reconcile UCE-01–UCE-20 and QA-01–QA-10 to exact test names/results and list any explicitly unautomated case in `specs/005-unify-course-editors/scenarios.md`
- [ ] T035 Freeze the raw final scope/diff and dispatch fresh independent Code Review and Test Analyst at the same checkpoint, then executable QA and architecture conformance using `.toscanini/runtime/runs/unified-course-editor-20260910/finding-ledger.json` (product gates passed, but historical telemetry ordering does not satisfy the administrative gate)
- [ ] T036 Process at most two consolidated critical-assurance remediation rounds by causal impact, rerun affected gates only, then require `toscanini-gate.py` and the visible `toscanini verify` exit gate (visible exit gate passed; the administrative gate still reports the authorized specialist-budget/ordering history)
- [x] T037 Hand the production coordinator the exact final commands, fixture lifecycle, checkpoint, timing, artifact policy, applicability matrix, and remaining limitations in `specs/005-unify-course-editors/implementation.md`

## Dependencies and execution order

- Phase 1 blocks implementation. Phase 2 blocks every story.
- US1 establishes the shared Save contract and precedes US2 integration.
- US3 browser assertions may be authored after T008/T010 and execute after the production width correction.
- US4 reuses the US1–US3 fixture and semantic seams and runs only against one integrated checkout.
- T032 cannot delete old code until T015/T021/T025 and the integrated cross-context tests pass.
- Review, QA, architecture conformance, and completion gates run only at the recorded stable checkpoint.

## Parallel opportunities

- T008, T009, and production-owned T010 touch separate ownership areas after readiness.
- T012/T013 and production-owned T014 may proceed from the approved contract with test expectations independent of production implementation.
- T018–T020 are separate test files and may be authored together by the single test owner; production-owned T021 remains a separate file boundary.
- T023/T024 may be authored independently of production-owned T025.
- Independent Code Review and Test Analyst run concurrently at T035; remediation waits for both complete verdicts.

## Implementation strategy

1. Finish all readiness checks and validate the approved contract.
2. Establish isolated fixtures, shared datasets, semantic hooks, and red-sensitivity evidence.
3. Deliver US1 as the safety MVP: one explicit atomic Save with identity and conflict protection.
4. Add staged structure/media association safety, then responsive rendered dimensions.
5. Require the minimal real-browser matrix in pull requests and keep actual outage proof in scheduled/on-demand CI.
6. Stabilize one integrated checkpoint, complete independent gates, and report only executed evidence.
