# Implementation Evidence: Reliable Course Authoring

## Approved scope

- Run: `course-editor-reliability-20260910`
- Scope: `course-editor-reliability-scope-v1`
- Assurance: standard (product owner approved escalation from fast on 2026-09-10 so blocking review findings can be remediated and revalidated without bypassing required gates)
- Change boundary: course Actions and Services, company course index/editor SFCs, English and Portuguese UI translations, focused course feature tests, and this feature's artifacts.
- Non-goals retained: shared-editor redesign, automatic content conversion/deletion, migrations, packages, authentication/permission/scoring/evidence changes, employee assessment gating, and unrelated screens.

## Pre-implementation state (T001)

The worktree already contained uncommitted Toscanini, Spec Kit, AGENTS, operations-documentation, and feature-artifact changes. Those changes were treated as pre-existing user/orchestration work and preserved. The approved production/test boundary was clean before this Worker started. The original `course-editor-reliability-20260909` run was closed after the watch-threshold contract conflict; no implementation event or checkpoint was written to that run.

The product-owner amendment on 2026-09-10 resolved the conflict: the watch threshold is tracking/reporting data only, assessment remains immediately available, and no employee-training Action or view is changed by this implementation.

## Verification log

- T002 focused baseline: `php artisan test tests/Feature/Courses/CourseEditorTest.php tests/Feature/Courses/CourseAuthoringTest.php tests/Feature/Courses/HybridCourseCompositionTest.php` passed with 42 tests and 128 assertions in 4.30 seconds.
- US1 preservation evidence: focused provenance, mixed-state, module-conflict, and direct-conflict tests passed. The module-to-direct conflict snapshot retained the lesson, question, answer, and compatibility pivot; mixed validation and preview failed closed without changing either inventory.
- US2 threshold evidence: the scoped course and training suites passed with 74 tests and 237 assertions in 5.36 seconds. This includes valid persistence, invalid-value rejection, every persisted video state, and the unchanged `allows the assessment before the watch threshold is met` training test.
- US3 identity/publication evidence: normalized cross-company, same-company inline, forced persistence-time collision, default retention, and explicit replacement tests passed. The forced collision left one winning course/version and no partial duplicate.
- US4 ordering evidence: exact-sibling tests passed for contiguous lesson/question/option ordering and unchanged duplicate, incomplete, stale, and cross-parent rejection. Render checks cover positional labels, drag handles, keyboard controls, focus styles, responsive wrapping, live status copy, and targeted loading disabling; real interaction remains assigned to executable QA.
- Focused final regression command covering course editing, preview playback, and immediate assessment behavior passed with 96 tests and 422 assertions in 5.77 seconds.
- `git diff --check`: passed with no output.
- `./vendor/bin/pint` on scoped production and test files: passed.
- `npm run test:preview`: passed all 25 tests.
- `npm run build`: passed in 709 ms. Vite reported only the existing optional `fontaine` recommendation and greater-than-500-kB chunk advisory.
- First canonical run exposed 22 preview playback failures caused by replacing stable compatibility-pivot identifiers for direct lessons. The projection was corrected to retain the historical identifier while using the direct lesson as canonical content; a focused 96-test regression then passed.
- Second canonical run exposed one older preview-content expectation that intentionally omitted a direct lesson after its compatibility mirror was removed. The test was aligned to AC-02 and the approved canonical direct inventory: both direct lessons must be previewed, using the legacy lesson identifier only when a mirror is absent. The focused preview regression passed with 34 tests and 260 assertions.
- Final canonical command: `PHPRC=/tmp/oceanix-course-editor-readiness.ZO6Qay/php.ini composer verify` passed. Pest: 681 passed, 13 intentionally skipped, 3,127 assertions in 32.75 seconds. Preview JavaScript: 25 passed. Pint: passed. Production build: passed in 709 ms with the two non-blocking advisories above. Composer emitted host-PHAR deprecation notices that did not affect the result.

## Remediation round 1

- One consolidated correction batch addressed blocking findings CR-001 through CR-005 and TA-TEST-001 through TA-TEST-006. Detailed finding-to-evidence mapping is recorded in `specs/004-fix-course-editor/remediation-r1.md`.
- Direct publication now fails closed on incomplete direct mirrors; mixed recovery is collision-free; save acknowledgement is path- and revision-scoped; publication is interlocked with dirty/saving state using consistent course-before-version locking; and nested drag events are type/parent scoped with propagation stopped.
- Added direct Action authorization and foreign-record tests, exact invalid reorder assertions, module-assessment preview/training parity, mixed recovery snapshots, selective collision translation/rollback proof, full publication assignment/event/audit/rollback coverage, and inclusive/invalid threshold persistence boundaries.
- Focused remediation suite passed: 74 tests, 331 assertions.
- The first default canonical attempt reached the known 128 MB host limit without an assertion failure. The final approved override command at the frozen checkpoint, `PHPRC=/tmp/oceanix-course-editor-readiness.ZO6Qay/php.ini composer verify`, passed: Pest 695 passed, 13 intentionally skipped, 3,254 assertions in 34.19 seconds; preview JavaScript 25 passed; Pint passed; build passed in 581 ms with only the existing optional-font and chunk-size advisories.
- Deferred follow-ups CR-006 and CR-007 were intentionally left unchanged.

## Remediation round 2

- The final standard-assurance correction batch was test-only and addressed `TA-TEST-001`, `TA-TEST-004`, and `TA-TEST-005`; detailed evidence is recorded in `specs/004-fix-course-editor/remediation-r2.md`.
- Reorder success now reloads the editor and proves nested question/alternative order parity. The permanent-code test proves the exact normalized displayed value and absence of editable bindings. Publication replacement evidence is matched per original/replacement with exact event types, versions, supersession metadata, audit subject, and audit linkage.
- Focused verification passed with 39 tests and 222 assertions. The final canonical command, `PHPRC=/tmp/oceanix-course-editor-readiness.ZO6Qay/php.ini composer verify`, passed: Pest 695 passed, 13 intentionally skipped, 3,276 assertions in 33.26 seconds; preview JavaScript 25 passed; Pint passed; build passed in 584 ms with only the existing advisories.
- Production code, deferred findings `CR-006`/`CR-007`, and finding-ledger statuses were not changed.

## Preview-order proof run

- The separately approved proof-only run `course-editor-preview-order-proof-20260910` adds response-level evidence for the parent `TA-TEST-001` preview-order requirement; detailed evidence is recorded in `specs/004-fix-course-editor/preview-order-proof.md`.
- Distinct direct-course questions and alternatives are inserted opposite to their persisted positions, then verified as one exact text sequence through an authenticated preview-link request and the resulting rendered preview-item response. The test does not reproduce production sorting logic.
- Focused preview/editor verification passed with 45 tests and 276 assertions. Canonical verification passed with 696 Pest tests, 13 intentionally skipped, 3,279 assertions, 25 preview JavaScript tests, Pint, and the production build.
- Proof-run checkpoint: `course-editor-preview-order-proof-impl-r0-20260910`.
- Fresh directed parent revalidation approved the proof at `course-editor-reliability-final-proof-20260910`, resolving `TA-TEST-001` with no production delta.

## Acceptance mapping

| Criterion | Implementation and automated evidence |
| --- | --- |
| AC-01 | `CourseVersionComposition`, guarded direct/module Actions, mixed editor recovery UI, validator and preview conflict tests |
| AC-02 | Canonical composition shared by validation/preview; questions/options preserved and previewed; mixed state blocks omission |
| AC-03 | Labelled 1-100 threshold, explicit non-gating help, persistence/boundary tests, unchanged below-threshold assessment test |
| AC-04 | Double normalization, tenant-scoped uniqueness, named collision translation, read-only code, race/tenant/component tests |
| AC-05 | Revision-aware clean/dirty/saving/saved/error client protocol, server validation state, cancellable navigation guard; browser interaction assigned to QA |
| AC-06 | Per-lesson text status and four-state dataset coverage |
| AC-07 | Transactional exact-sibling reorder Action, pointer and keyboard wiring, contiguous/rejection tests |
| AC-08 | Positional field/control labels, focus-visible controls, min-width/responsive wrapping; 320-pixel interaction assigned to QA |
| AC-09 | Targeted loading disabling and noticeable create/publish text statuses; duplicate-interaction proof assigned to QA |
| AC-10 | Assignment impact projection, `keep_existing` default, explicit replacement, pending/in-progress persistence tests |

## Stable checkpoint

- Implementation checkpoint: `course-editor-reliability-final-proof-20260910`
- The only delta after the round-2 checkpoint is the separately approved, test-only rendered-preview order proof recorded above; production behavior is unchanged.
- Scope ID: `course-editor-reliability-scope-v1`
- Remaining implementation issue: none. Executable browser scenarios remain intentionally pending for the independent QA gate.
