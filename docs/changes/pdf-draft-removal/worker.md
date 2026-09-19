# Draft lesson PDF removal implementation evidence

Run: `pdf-draft-removal-20260919`. Scope: `pdf-draft-removal-v1`. Round 1, implementation.

## Changed files

- `app/Actions/Courses/RemoveDirectCourseLesson.php`
- `tests/Feature/Documents/LessonDocumentDraftRemovalTest.php`
- `docs/changes/pdf-draft-removal/worker.md` (this evidence)

The existing Action now detaches the selected lesson's document associations immediately before deleting the lesson, inside the existing transaction and after authorization and revision validation. No metadata, stored bytes, other associations, schema, model lifecycle, permissions or UI code changed.

## Verification

Before the production edit, `php artisan test --compact tests/Feature/Documents/LessonDocumentDraftRemovalTest.php` returned 3 failed and 3 passed (11 assertions). Both uploaded and reused positive cases failed with SQLite foreign-key constraint errors at the existing lesson deletion. The rollback test failed because the association had not been detached before the simulated deletion failure. Revoked permission, published version and stale revision cases passed.

After the one-line correction, the same command passed all 6 tests (42 assertions). A first sandboxed green attempt encountered the installed Pest browser plugin's local socket restriction; rerunning with approved local socket access passed. This was an environment limitation, not an application failure.

`./vendor/bin/pint app/Actions/Courses/RemoveDirectCourseLesson.php tests/Feature/Documents/LessonDocumentDraftRemovalTest.php` passed without changes.

AC-01 / INV-02: uploaded and reused document cases exercise actual upload/reuse Actions, remove the middle direct draft lesson, and verify remaining lesson and composition identities and positions.

AC-02 / INV-01: both cases verify retained source lesson attributes and pivot, unchanged shared and exclusively attached document metadata and bytes, and successful contextual PDF response through the surviving lesson.

AC-03 / INV-02: revoked permission, published version and stale revision preserve snapshots of lessons, PDF associations, metadata, compositions, questions and options. A temporary isolated model event verifies associations are detached before throwing a simulated deletion failure; the transaction restores the entire snapshot. The original dispatcher is restored in a finally block.

No implementation blockers remain. Canonical verification, independent reviews, executable QA and PR update are owned by the orchestrator and are not claimed by this report.
