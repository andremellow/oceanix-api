# Remediation Round 1: Course Editor Reliability

## Frozen scope

- Run: `course-editor-reliability-20260910`
- Scope ID: `course-editor-reliability-scope-v1`
- Assurance: standard
- Remediation checkpoint: `course-editor-reliability-impl-r1-20260910`
- Finding ledger status was not changed by the Worker; directed specialists retain responsibility for resolution decisions.

## Consolidated correction batch

| Finding | Correction and regression evidence |
| --- | --- |
| CR-001 | Publication now fails closed when the direct-lesson compatibility mirror is incomplete. Preview continues to expose every canonical direct lesson. The regression verifies preview completeness, the exact publication problem, and unchanged draft state. |
| CR-002 | Mixed-draft reusable rows are rebuilt after the preserved direct-mirror position range, making repeated one-at-a-time removal collision-free. The regression removes two modules successively and compares exact lesson, question, and option snapshots. |
| CR-003 | Save state now observes only persisted editor paths. Browser edits carry a monotonically increasing revision and unsaved flag; successful structural and scalar writes acknowledge that exact revision. Search, upload selection, and publication-option state do not claim saves. The regression proves transient-state exclusion, structural acknowledgement, and dirty publication denial. |
| CR-004 | Scalar writes lock the owning course before the draft version, matching publication lock order. Both publication entry points reject dirty, saving, validation-error, and network-error state, and the publish control is disabled while browser state is dirty or saving. |
| CR-005 | Drag payloads identify their content level and parent scope. Drag start, drag over, and drop stop propagation; a drop runs only for the matching type and parent and marks the editor dirty before the one reorder request. Render regressions verify the scoped handlers. |
| TA-TEST-001 | Invalid lesson, question, and option reorder payloads must throw the exact stale-order validation error and preserve exact snapshots. Successful lesson ordering is reloaded through lessons, compatibility mirrors, and preview; all levels assert contiguous positions. |
| TA-TEST-002 | A composed published module retains its question and options through preview and employee training. Mixed-to-direct recovery preserves exact direct lesson/question/option rows, and conflict tests retain unchanged content. |
| TA-TEST-003 | Direct tests exercise every new write Action with an authorized editor, a user lacking update authority, and foreign identifiers where applicable, asserting zero writes after each denial. |
| TA-TEST-004 | The forced course-code race asserts the exact `code` error and no losing audit/partial aggregate. A mocked unrelated database failure propagates and rolls back. The editor render asserts no writable binding exists for the permanent code. |
| TA-TEST-005 | Default publication asserts zero replacement assignments, events, and replacement audits. Explicit replacement proves predecessor links, frozen obligation fields, cancellation/creation events, audits, and untouched completed/waived assignments. A downstream replacement failure proves transaction rollback. |
| TA-TEST-006 | Threshold values 1 and 100 persist; 0, 101, and a non-integer report validation-error and leave the stored value unchanged. Existing employee tests continue to prove immediate assessment availability below the tracking threshold. |

Deferred follow-ups CR-006 and CR-007 were not implemented because neither was necessary to correct the blocking findings.

## Verification

- Focused remediation: `php artisan test --compact tests/Feature/Courses/CourseEditorTest.php tests/Feature/Courses/HybridCourseCompositionTest.php tests/Feature/Courses/CourseAuthoringTest.php tests/Feature/Courses/CourseEditorActionAuthorizationTest.php` — 74 passed, 331 assertions.
- `git diff --check` — passed.
- Scoped Pint check/fix followed by the focused suite — passed.
- Default `composer verify` — tests progressed successfully until the known host 128 MB PHP memory ceiling; no test assertion failed.
- Canonical verification at the frozen checkpoint: `PHPRC=/tmp/oceanix-course-editor-readiness.ZO6Qay/php.ini composer verify` — Pest 695 passed, 13 intentionally skipped, 3,254 assertions in 34.19 seconds; preview JavaScript 25 passed; Pint passed; production build passed in 581 ms. Vite emitted only the existing optional-font and chunk-size advisories.

## Remaining work

No Worker remediation issue remains. Directed Code Review and Test Analyst verification, executable QA, and architecture conformance remain the independent gates.
