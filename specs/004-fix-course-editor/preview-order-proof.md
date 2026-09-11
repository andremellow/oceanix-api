# Direct Course Preview Order Proof

## Scope

- Run: `course-editor-preview-order-proof-20260910`
- Scope ID: `course-editor-preview-order-proof-scope-v1`
- Change type: test-only proof against the existing authenticated preview-link and rendered preview-item HTTP boundaries.
- Production, UI, translation, schema, dependency, and environment files were unchanged.

## Defect-sensitive proof

`tests/Feature/Courses/CoursePreviewContentTest.php` now creates two direct-course questions and two alternatives per question with insertion order deliberately opposite to their persisted positions. An authenticated administrator requests the real company preview-link route, then requests the resulting real preview-item route while authenticated. One response-level `assertSeeInOrder` assertion spans both distinct question prompts and both alternatives under each question.

The test does not sort fixtures or expected records and does not reproduce the production projection algorithm. Ignoring or reversing either persisted question positions or persisted alternative positions changes the rendered sequence and fails the assertion.

## Verification

- Narrow preview file: 6 passed, 54 assertions in 0.53 seconds.
- Focused preview/editor pair: 45 passed, 276 assertions in 5.16 seconds.
- Scoped Pint: passed.
- `git diff --check`: passed with no output.
- Canonical command: `PHPRC=/tmp/oceanix-course-editor-readiness.ZO6Qay/php.ini composer verify` passed.
  - Pest: 696 passed, 13 intentionally skipped, 3,279 assertions in 34.61 seconds.
  - Preview JavaScript: 25 passed.
  - Pint: passed.
  - Production build: passed in 746 ms.
  - Existing Composer host-PHAR deprecation notices and optional-font/chunk-size build advisories remained non-blocking.

## Checkpoint

- Implementation checkpoint: `course-editor-preview-order-proof-impl-r0-20260910`
