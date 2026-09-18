# Tasks: PDF library in the lesson editor

Approved inputs: spec.md, architecture.md, plan.md, design.md, scenarios.md and approval.md. Run `pdf-library-20260916`; scope `pdf-library-v1`. Tests are required by project policy. Only one Worker edits production code/tests; specialist reviews are read-only.

## Phase 1: Setup

- [x] T001 Record owner approval and ready environment in specs/007-pdf-library/approval.md and .toscanini/runtime/runs/pdf-library-20260916/execution-contract.json.
- [x] T002 Inspect existing PDF implementation and disposable fixtures in tests/Support/Documents/README.md; record baseline and defect sensitivity in specs/007-pdf-library/scenarios.md.

## Phase 2: Foundation

- [x] T003 Add permission/ownership denial and prerequisite tests (B-04/05) in tests/Feature/Documents/LessonDocumentLibraryAccessTest.php before implementing capabilities.
- [x] T004 Add retained archive model and additive schema in app/Models/LessonDocumentArchive.php, app/Models/LessonDocument.php and database/migrations/*_create_lesson_document_archives_table.php, with exactly-one-actor constraint and evidence-preserving rollback.
- [x] T005 Add company/platform atomic abilities and additive catalog migration in app/Enums/Permission.php, app/Enums/PlatformPermission.php and database/migrations/*_project_lesson_document_permission_catalog.php; no automatic role grants.
- [x] T006 Implement owner-first fresh authorization in app/Services/Documents/LessonDocumentLibraryAccess.php and app/Policies/LessonDocumentPolicy.php; register Policy in app/Providers/AppServiceProvider.php (B-04/05).

## Phase 3: US1 — Find and open (P1; first demonstrable increment)

Goal: all own-scope PDFs reachable and privately openable without changing editor state. Independent criteria: B-01–05 and B-15, including 65 records, foreign positive controls, literal search and final page.

- [x] T007 [US1] Add projection/private route tests in tests/Feature/Documents/LessonDocumentLibraryTest.php and tests/Feature/Documents/LessonDocumentLibraryAccessTest.php (B-01/02/03/04/05/14/15); demonstrate intended failures first.
- [x] T008 [US1] Implement paginated searchable projection in app/Services/Documents/LessonDocumentLibrary.php and private streaming in app/Services/Documents/LessonDocumentLibraryAccess.php.
- [x] T009 [US1] Add owner-specific routes/controller in routes/web.php and app/Http/Controllers/LessonDocumentLibraryController.php, preserving existing contextual delivery.
- [x] T010 [US1] Integrate list/search/pagination/Open in app/Livewire/CourseEditor/EditorCoordinator.php, app/Livewire/CourseEditor/Contexts/*EditorContext.php and resources/views/components/course-editor/root.blade.php without editor snapshot replacement.

## Phase 4: US2 — Reuse (P1)

Goal: same document retained in an authorized draft and inserted through existing token/caret protocol. Independent criteria: B-06–09; unchanged identity/bytes, actual Save/reload and contextual delivery across all three editors.

- [x] T011 [US2] Add target/revision/published/owner action tests in tests/Feature/Documents/LessonDocumentLibraryTest.php and real insertion assertions in tests/JavaScript/lesson-documents.browser.test.mjs (B-06/07/08/09).
- [x] T012 [US2] Implement target-first/document-last locked attachment in app/Actions/Documents/ReuseLessonDocument.php, preserving current snapshot/lineage authorization and explicit Save.
- [x] T013 [US2] Connect reuse to existing PDF completion and operation guards in app/Livewire/CourseEditor/EditorCoordinator.php, app/Livewire/CourseEditor/Contexts/*EditorContext.php, resources/js/content-editor.js and resources/js/course-editor.js only as required; no image/video refactor.

## Phase 5: US3 — Archive (P2)

Goal: confirmed archive prevents new selection while retaining old uses. Independent criteria: B-10–13, including old published/copied links and both operation orderings.

- [x] T014 [US3] Add archive audit/idempotence/retained-link/pending-Save tests in tests/Feature/Documents/LessonDocumentArchiveTest.php (B-10/11/12/13).
- [x] T015 [US3] Implement document-locked archive in app/Actions/Documents/ArchiveLessonDocument.php; never change bytes, original metadata, pivots or delivery filters.
- [x] T016 [US3] Add named confirmation, cancellation, focus return and page correction in resources/views/components/course-editor/root.blade.php and app/Livewire/CourseEditor/EditorCoordinator.php; child dismissal must not cancel parent token.
- [x] T017 [US3] Implement and execute two-process PostgreSQL probe in tests/Support/Documents/ against only the verified disposable database from specs/007-pdf-library/quickstart.md, proving both lock orders and actual pending Save (B-12).

## Phase 6: Cross-cutting validation and delivery

- [x] T018 Extend safe runtime fixtures in tests/Support/Documents/ and browser coverage in tests/JavaScript/lesson-documents.browser.test.mjs for all B-01–16, especially failure/busy/revocation, EN/PT-BR, keyboard, 390px and existing upload; add source translations in lang/pt_BR.json.
- [x] T019 Execute focused tests, browser tests, build, formatting and canonical verification from specs/007-pdf-library/quickstart.md; map actual test names/results and defect sensitivity into specs/007-pdf-library/scenarios.md.
- [x] T020 Record stable implementation checkpoint and raw diff evidence under .toscanini/runtime/runs/pdf-library-20260916/; independent Code Review and Test Analyst inspect full frozen scope at this checkpoint. Full r1 reviews and directed r2 closures are recorded in docs/changes/pdf-library/; unaffected coverage is explicitly retained.
- [x] T021 Execute independent real UI/API QA-01–08 and design validation against specs/007-pdf-library/design.md, recording evidence under docs/changes/pdf-library/; no unrelated checks or silent scope expansion. All eight executed at r2; QA verdict FAIL, QA-R2-01 remains open. Execution complete does not mean acceptance passed.
- [x] T022 Perform final Architect conformance, contract-aware gate and toscanini verify; record scoped execution report in docs/changes/pdf-library/execution-report.md. R3 all gates pass. No production deployment or PR update; owner separately authorized local Herd setup, two additive migrations applied as batch12.

## Dependencies and execution strategy

T001 → T002 → foundation T003–06 → US1 T007–10 → US2 T011–13 → US3 T014–17 → T018–22. Write relevant tests before the implementation when feasible; otherwise record defect sensitivity. Validate each story increment, then validate the integrated modal. US1 is the first demonstrable increment, not a reduced delivery commitment; all three stories are approved.

Production/test tasks are deliberately serial because only one Worker may edit them. US1 projection tests and route tests could execute concurrently only with independent fixtures; US2 PHP and browser checks likewise; US3 SQLite and disposable PostgreSQL probes have separate state. Do not run shared fixture checks concurrently. After the stable checkpoint Code Review and Test Analyst can run in parallel with read-only independent contexts.

Counts: 22 tasks; setup/foundation6, US1 four, US2 three, US3 four, cross-cutting five. All task entries use sequential IDs, checkboxes, story labels where applicable and concrete paths. Extension hooks absent; none executed.
