# Remediation Round 2: Directed Test Evidence

## Scope

- Run: `course-editor-reliability-20260910`
- Round: 2
- Phase: remediation
- Scope ID: `course-editor-reliability-scope-v1`
- Findings addressed: `TA-TEST-001`, `TA-TEST-004`, `TA-TEST-005`
- Constraint: test-only; no production behavior changed and no deferred finding was implemented.

## Directed evidence

| Finding | Added evidence |
| --- | --- |
| TA-TEST-001 | The successful question/alternative reorder test now remounts the Livewire course editor from persisted state and asserts the complete nested question and alternative ID order before retaining the contiguous database-position assertions. |
| TA-TEST-004 | The permanent-code editor test creates a course from padded lowercase input, asserts the exact normalized `BOSIET-01` rendered value, and excludes blur, default, and live editable code bindings. |
| TA-TEST-005 | The explicit assignment replacement test now matches each original to its sole replacement, asserts the original's exact `AssignmentCancelled` event and replacement's exact `AssignmentCreated` event, verifies version and supersession linkage, and requires the replacement's exact audit subject and `after` linkage. |

## Verification

- Focused command: `php artisan test --compact tests/Feature/Courses/CourseEditorTest.php`
  - Result: 39 passed, 222 assertions, 4.80 seconds after formatting.
- `./vendor/bin/pint tests/Feature/Courses/CourseEditorTest.php`: passed.
- `git diff --check`: passed with no output.
- Canonical command: `PHPRC=/tmp/oceanix-course-editor-readiness.ZO6Qay/php.ini composer verify`
  - Pest: 695 passed, 13 intentionally skipped, 3,276 assertions in 33.26 seconds.
  - Preview JavaScript: 25 passed.
  - Pint: passed.
  - Production build: passed in 584 ms.
  - The existing Composer host-PHAR deprecation notices and optional-font/chunk-size build advisories remained non-blocking.

## Checkpoint

- Implementation checkpoint: `course-editor-reliability-impl-r2-20260910`
- The finding ledger is intentionally unchanged pending directed specialist verification.
