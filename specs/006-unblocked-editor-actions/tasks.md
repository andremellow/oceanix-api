# Tasks: Unblocked Editor Actions

## Phase 1: Setup

- [x] T001 Confirm the approved contract, architecture hash, readiness, and SCN-01–SCN-07 mapping in `.toscanini/runtime/runs/unblocked-editor-actions-20260911/` and `specs/006-unblocked-editor-actions/`.

## Phase 2: Foundational

- [x] T002 [P] Add pure stable-identity staged-state rebase tests in `tests/Unit/CourseEditor/EditorStagedStateRebaserTest.php`.
- [x] T003 Add readonly staged-state/result carriers and pure rebaser in `app/Services/CourseEditor/` after T002 fails for the intended missing behavior.

## Phase 3: User Story 1 — Continue assessment authoring

- [x] T004 [US1] Add the failing add–type–add component scenario in `tests/Feature/CourseEditor/` and record red evidence.
- [x] T005 [US1] Add the failing all-context add–type–add browser scenario in `tests/Browser/UnifiedCourseEditorBrowserTest.php` and record red evidence.
- [x] T006 [US1] Integrate request-start capture, canonical refresh/rebase, and refreshed revisions in `app/Livewire/CourseEditor/EditorCoordinator.php`.
- [x] T007 [US1] Remove dirty-only client/server blocking while retaining real blockers in `resources/js/course-editor.js` and `app/Livewire/CourseEditor/EditorCoordinator.php`.
- [x] T008 [US1] Verify the red tests are green and Save/reload remains exact.

## Phase 4: User Story 2 — Continue all draft-editing operations

- [x] T009 [P] [US2] Extend feature coverage for dirty add/remove/reorder/composition/media in `tests/Feature/CourseEditor/`.
- [x] T010 [P] [US2] Extend client generation/overlay coverage in `tests/JavaScript/course-editor.test.mjs`.
- [x] T011 [US2] Add stable field identity/generation wiring in `resources/views/components/course-editor/root.blade.php` and `resources/js/course-editor.js`.
- [x] T012 [US2] Extend browser coverage for retained values, identity, focus, media, and Save/reload in `tests/Browser/UnifiedCourseEditorBrowserTest.php`.

## Phase 5: User Story 3 — Preserve safety boundaries

- [x] T013 [P] [US3] Cover stale, revoked, malformed identity, provider failure, unknown response, and upload applicability in `tests/Unit/CourseEditor/`, `tests/Feature/CourseEditor/`, and `tests/JavaScript/course-editor.test.mjs`.
- [x] T014 [US3] Implement exact failure/drop/remap and accessibility behavior in `app/Services/CourseEditor/`, `app/Livewire/CourseEditor/EditorCoordinator.php`, `resources/js/course-editor.js`, and `resources/views/components/course-editor/root.blade.php`.
- [x] T015 [US3] Run the frozen real-browser failure matrix in `tests/Browser/UnifiedCourseEditorBrowserTest.php`.

## Phase 6: Completion

- [ ] T016 Run focused tests, Pint, build, and canonical `composer verify`; record one implementation checkpoint. Focused checks and checkpoint are complete; the canonical aggregate was interrupted after its editor matrix passed because its browser phase remained silent beyond the bounded interval.
- [ ] T017 Run independent Code Review and Test Analyst at the checkpoint, then QA-01–QA-06, architecture conformance, and Toscanini completion gates.

## Dependencies and strategy

T002 must demonstrate sensitivity before T003. T004–T005 must fail before T006–T007. US1 is the MVP and exact owner scenario. US2 extends the same preservation mechanism to all frozen editing operations. US3 proves safety boundaries. One Worker owns all production and test edits.
