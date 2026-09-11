# Quickstart Validation: Reliable Course Authoring

## Prerequisites

- Use the existing local development/test environment; do not change `.env`.
- Use SQLite-backed automated tests and a local authenticated administrator fixture for browser QA.
- Fake video-provider calls in automated tests; browser QA may use the configured local fake provider.

## Automated validation

1. Run focused course authoring tests:
   - `php artisan test tests/Feature/Courses/CourseEditorTest.php tests/Feature/Courses/CourseAuthoringTest.php tests/Feature/Courses/HybridCourseCompositionTest.php`
2. Run the canonical project verification:
   - `composer verify`

Expected: all commands exit successfully; no test contacts WorkOS or Cloudflare.

## Executable QA scenarios

1. Create a course using a code that exists only in another company; verify success and normalized display.
2. Attempt a case/whitespace duplicate in the same company; verify an inline modal error and one persisted course.
3. Open the draft; verify code is read-only, watch threshold is editable, its copy says tracking/reporting only, and assessment remains available below the threshold.
4. Create direct lessons/questions, then attempt to add a module; verify rejection and preserved questions.
5. Open a pre-existing mixed draft; verify both inventories remain visible and publication is blocked.
6. Reorder lessons, questions, and alternatives with pointer and keyboard controls; reload and preview the order.
7. Exercise save success, validation error, and simulated offline/network failure; verify accurate state and guarded navigation.
8. Exercise video uploading/processing/ready/failed states; verify a visible text status for the affected lesson.
9. Publish a new version with pending and in-progress assignments; verify keeping them is the default, then separately verify explicit replacement.
10. Repeat the primary authoring flow at 320-pixel width with keyboard-only navigation; verify labels, focus, actions, and absence of horizontal page overflow.

## Cleanup

- Use only disposable local/test fixtures.
- Remove QA-created course and assignment fixtures through test database reset, not production deletion paths.
