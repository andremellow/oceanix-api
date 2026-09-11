# Tasks: Reliable Course Authoring

**Input**: Approved artifacts in `specs/004-fix-course-editor/` and execution contract `.toscanini/runtime/runs/course-editor-reliability-20260910/execution-contract.json`

**Tests**: Pest regression tests are required and should be written to fail before the corresponding implementation where feasible.

## Phase 1: Setup

**Purpose**: Freeze the approved implementation checkpoint and verify the focused baseline.

- [x] T001 Record the pre-implementation git status and approved scope in `specs/004-fix-course-editor/implementation.md`
- [x] T002 Run the focused existing course tests from `specs/004-fix-course-editor/quickstart.md` and record the baseline in `specs/004-fix-course-editor/implementation.md`

---

## Phase 2: Foundational Composition and Write Boundaries

**Purpose**: Establish the shared interpretation and transactional mutation seams required by every story.

- [x] T003 Write failing provenance and mixed-composition tests in `tests/Feature/Courses/HybridCourseCompositionTest.php`
- [x] T004 Implement `CourseVersionComposition` mode and canonical inventory projection in `app/Services/Courses/CourseVersionComposition.php`
- [x] T005 Integrate the shared composition projection into `app/Services/Courses/CourseVersionValidator.php` and the existing preview resolver under `app/Services/Courses/`
- [x] T006 Add focused draft-only, tenant-authorized scalar and structural write Actions under `app/Actions/Courses/` following `specs/004-fix-course-editor/architecture.md`

**Checkpoint**: Editor, preview, and publication can use one non-destructive composition decision and transactional mutation boundary.

---

## Phase 3: User Story 1 — Preserve Authored Training Content (Priority: P1) 🎯 MVP

**Goal**: Prevent new mixed composition and fail closed for existing mixed drafts without losing lessons, questions, answers, or modules.

**Independent Test**: Attempt both composition conflicts and verify unchanged row snapshots, visible recovery guidance, and blocked preview/publication.

- [x] T007 [US1] Extend failing component and publication scenarios for direct, module, empty, and mixed drafts in `tests/Feature/Courses/CourseEditorTest.php` and `tests/Feature/Courses/HybridCourseCompositionTest.php`
- [x] T008 [US1] Guard first-module and first-direct-lesson mutations without destructive partial writes in `app/Actions/Courses/UpdateCourseModuleComposition.php` and the focused direct-lesson Actions under `app/Actions/Courses/`
- [x] T009 [US1] Render the active mode, disable conflicting creation, and expose both preserved inventories with recovery guidance in `resources/views/components/courses/⚡editor.blade.php`
- [x] T010 [US1] Add English-first mixed-composition and recovery strings in `lang/en/ui.php` with Portuguese mappings in `lang/pt_BR/ui.php`
- [x] T011 [US1] Run the US1 tests and record question-preservation evidence in `specs/004-fix-course-editor/implementation.md`

**Checkpoint**: No active editor content can be silently omitted; a conflict blocks publication before data loss.

---

## Phase 4: User Story 2 — Configure and Save Lesson Rules Reliably (Priority: P1)

**Goal**: Expose every employee-impacting direct-lesson rule and make save and media states truthful.

**Independent Test**: Persist valid lesson rules, reject invalid thresholds unchanged, exercise save failure/success, and render every video state.

- [x] T012 [P] [US2] Write failing watch-threshold, save-state, navigation-guard, and video-status assertions in `tests/Feature/Courses/CourseEditorTest.php`
- [x] T013 [US2] Route scalar lesson/course/question/option persistence through focused Actions in `app/Actions/Courses/UpdateCourseEditorField.php` and `resources/views/components/courses/⚡editor.blade.php`
- [x] T014 [US2] Render the labelled 1–100 tracking/reporting watch threshold, explicit immediate-assessment help, and per-lesson text media status in `resources/views/components/courses/⚡editor.blade.php`
- [x] T015 [US2] Implement the scoped clean/dirty/saving/saved/validation-error/network-error protocol and cancellable navigation guard in `resources/views/components/courses/⚡editor.blade.php`
- [x] T016 [US2] Add English-first save, navigation, threshold, and video-state strings in `lang/en/ui.php` with Portuguese mappings in `lang/pt_BR/ui.php`
- [x] T017 [US2] Prove the threshold persists for tracking/reporting and assessment remains available below it in `tests/Feature/Courses/CourseAuthoringTest.php` and the existing training action tests

**Checkpoint**: All lesson rules are visible and persisted, and the editor never reports a failed mutation as saved.

---

## Phase 5: User Story 3 — Create and Publish Safely (Priority: P1)

**Goal**: Use permanent tenant-correct course codes and retain existing assignments by default during publication.

**Independent Test**: Create normalized equal codes across companies, reject a same-company collision with no partial row, and publish while retaining open assignments unless replacement is explicitly selected.

- [x] T018 [P] [US3] Write failing normalized cross-company, same-company, and forced persistence-collision tests in `tests/Feature/Courses/CourseAuthoringTest.php`
- [x] T019 [P] [US3] Write failing default-retention and explicit-replacement tests with pending and in-progress assignments in `tests/Feature/Courses/CourseEditorTest.php`
- [x] T020 [US3] Normalize and tenant-scope friendly validation and translate only the named database collision in `app/Actions/Courses/CreateCourse.php` and `resources/views/components/courses/⚡index.blade.php`
- [x] T021 [US3] Render the permanent course code read-only and add guarded loading behavior to creation in `resources/views/components/courses/⚡index.blade.php` and `resources/views/components/courses/⚡editor.blade.php`
- [x] T022 [US3] Implement assignment impact projection in `app/Services/Courses/CoursePublicationImpact.php` and make `keep_existing` the publication default in `resources/views/components/courses/⚡editor.blade.php`
- [x] T023 [US3] Revalidate composition under publication locks and invoke assignment replacement only for explicit opt-in in `app/Actions/Courses/PublishCourseVersion.php`
- [x] T024 [US3] Add English-first creation collision, permanent-code, assignment-impact, and publication-option strings in `lang/en/ui.php` with Portuguese mappings in `lang/pt_BR/ui.php`

**Checkpoint**: Course identity is correct per tenant and default publication cannot restart learner work.

---

## Phase 6: User Story 4 — Operate the Editor Accessibly and Efficiently (Priority: P2)

**Goal**: Make repeated assessment controls identifiable, all three content levels reorderable, and operations safe on keyboard and narrow screens.

**Independent Test**: Reorder lessons/questions/options, reload and preview contiguous order, inspect unique accessible names, and complete actions at 320 CSS pixels without overflow or duplicate requests.

- [x] T025 [P] [US4] Write failing stale/duplicate/cross-tenant reorder and contiguous-position tests in `tests/Feature/Courses/CourseEditorTest.php`
- [x] T026 [US4] Implement transactional exact-sibling reordering for lessons, questions, and options in `app/Actions/Courses/ReorderDirectCourseContent.php`
- [x] T027 [US4] Add pointer drag handles and keyboard move controls wired to the same reorder command in `resources/views/components/courses/⚡editor.blade.php`
- [x] T028 [US4] Add unique positional labels, visible focus, wrapped responsive action groups, live statuses, and operation-specific disabling in `resources/views/components/courses/⚡index.blade.php` and `resources/views/components/courses/⚡editor.blade.php`
- [x] T029 [US4] Add English-first ordering and accessibility strings in `lang/en/ui.php` with Portuguese mappings in `lang/pt_BR/ui.php`

**Checkpoint**: All authored levels reorder safely and controls remain understandable and operable across input methods and viewport sizes.

---

## Phase 7: Polish and Worker Verification

**Purpose**: Close cross-story regressions and leave a stable implementation checkpoint for independent review.

- [x] T030 Run focused Pest suites, `git diff --check`, Pint, preview tests, and the production build; record exact results in `specs/004-fix-course-editor/implementation.md`
- [x] T031 Run `composer verify` with the documented temporary PHP memory override if the host remains at 128 MB and record the result in `specs/004-fix-course-editor/implementation.md`
- [x] T032 Verify every task and AC-01–AC-10 mapping, mark completed tasks in `specs/004-fix-course-editor/tasks.md`, and record the implementation checkpoint in `.toscanini/runtime/runs/course-editor-reliability-20260910/execution-contract.json`

---

## Dependencies and Execution Order

- Phase 1 precedes all implementation.
- Phase 2 is foundational and blocks all user-story phases.
- US1 must precede the composition-sensitive portions of US2 and US3.
- US2 and US3 are independently testable after the foundation and may be developed in either order.
- US4 depends on the structural Actions introduced for US1/US2 but is independently verifiable.
- Phase 7 runs only after all selected stories are complete.

## Parallel Opportunities

- T012 and T018/T019 touch separate test concerns and can be prepared independently after Phase 2, but the Toscanini contract permits only one Worker to edit production code or tests.
- Service/Action work in US3 is file-independent from US2 presentation work, while integration into the shared editor remains sequential.
- Independent Code Review and Test Analyst review will run in parallel after the Worker freezes one implementation checkpoint.

## Implementation Strategy

1. Deliver US1 first as the safety MVP because it removes the content-disappearance path.
2. Add truthful rule/save behavior and tenant-safe creation/publication as P1 increments.
3. Complete ordering, accessibility, loading, and narrow-screen behavior as the P2 increment.
4. Freeze one implementation checkpoint, then run deterministic verification and independent Toscanini gates.
