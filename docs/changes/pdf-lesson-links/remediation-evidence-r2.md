# PDF remediation round 2 evidence

Scope: `pdf-links-v1`. Finding: `PDF-DES-01`; fixture follow-up: `QA-FU-01`.
Source checkpoint: `pdf-links-20260914-r2`. Intended final checkpoint: `pdf-links-20260914-r3`.

## Cause and correction

The PDF insertion transaction correctly selected position 18, but the course editor's request-success callback subsequently reapplied the staged content overlay through the Flux control's value setter. Flux calls `setContent`, replacing the document and moving the selection to its end. In the company fixture this was position 783, inside the unrelated final Saved PDF guide anchor. A delayed `focus()` only focused that incorrect position. Transaction tracing identified `reapplyOperationOverlay → UIEditor.set → setContent` as the late reset. Trace instrumentation was removed from the final tests.

The shared request-success callback now emits an overlay-completed DOM event after reapplication. PDF insertion retains its exact intended endpoint and waits for both this event and native modal closure before restoring the text selection, clearing stored marks and placing the DOM caret outside anchors. At a boundary immediately before another anchor, the caret remains before that anchor. Cancelled or stale uploads still have no accepted insertion state, so the completion event does nothing. The root Blade file is byte-identical to its round-2 starting state. Adjacent media insertion code and overlay behavior are unchanged; the shared hook only announces completion.

The disposable fixture now initializes tenant context before revocation and records/uses the shared module ID in its manifest instead of resolving a mutable title.

## Defect sensitivity

Before changing production code, the browser assertion was strengthened to wait for modal closure and 500 ms of asynchronous settling, assert exact text before the caret, collapsed selection, editor focus and no enclosing anchor. The unchanged implementation failed: expected prefix `Before Read guide` and no anchor, observed the full document prefix through `Saved PDF guide` and that unrelated anchor. This was a behavioral assertion failure, separate from an initial sandbox socket denial. The same assertions pass after the correction.

Final browser coverage applies selected-text and no-selection custom-label insertion to all three editors. It asserts exact continuation location, no enclosing anchor, actual plain keyboard input, unchanged PDF labels, surrounding text and save/reload persistence. Unique labels and the original paragraph account for the shared course and module editing the same fixture module. Existing cancellation, stale-response, validation, busy-state and learner popup coverage remains active.

## Executed checks

- `node --test tests/JavaScript/lesson-documents.browser.test.mjs`: PASS, 1 integrated browser test, 19.4 seconds. Six selected/custom editor cases plus existing cancellation (Cancel, Escape, X, outside), held stale completion, narrow upload, duplicate guard, validation, save/reload and real learner popup. Disposable evidence directory: `/tmp/oceanix-pdf-qa-j9zRH3`.
- `php artisan test --compact tests/Feature/Documents`: PASS, 37 tests / 282 assertions. Initial sandbox run could not bind the Pest browser plugin socket; rerun with localhost permission passed.
- `node --test tests/JavaScript/course-editor.test.mjs`: PASS, 30 tests.
- `npm run build`: PASS. Existing optional fontaine and chunk-size warnings remain.
- `./vendor/bin/pint tests/Support/Documents/QaFixture.php`: PASS.
- `git diff --check`: PASS.
- Disposable fixture `prepare-copy`: PASS using manifest module ID 2; copied draft 4 from source 3 with retained PDF references.
- Disposable fixture `revoke 1`: PASS; SQLite confirms assignment 1 is cancelled. Both fixture commands used `OCEANIX_PDF_QA_DIR=/tmp/oceanix-pdf-qa-j9zRH3`.

Canonical verification and directed specialist gates remain owned by the parent orchestrator; no full-suite completion is claimed here.

## Reviewable delta

Only these production/test files changed in this correction:

- `resources/js/content-editor.js`
- `resources/js/course-editor.js`
- `tests/JavaScript/lesson-documents.browser.test.mjs`
- `tests/Support/Documents/QaFixture.php`

Pre-correction copies are retained in `/tmp/pdf-r2-before-v4ujR5/`. Exact raw correction delta: `/tmp/pdf-r2-before-v4ujR5/remediation-r2.diff`. No Toscanini gate or policy file was changed.
