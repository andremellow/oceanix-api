# Consolidated PDF remediation evidence

Run `pdf-editor-20260914`, scope `pdf-links-v1`; correction batch 1 against checkpoint `pdf-links-20260914-r1`. Worker evidence only. Findings remain open until directed specialists verify them.

| Finding | Correction / executable evidence |
| --- | --- |
| CR-01 | Coordinator preserves exact supplied selection instead of truncating, trimming or treating `0` as empty. Empty-only fallback; validation aligns with the existing 100000-character content bound. Browser tests accept default whitespace, `0` and >500-character formatted selections and compare all text/formatting before and after insertion and reload. |
| CR-02 / DR-01 | Every close/cancel invalidates the client pending insertion, and every opening captures a new selection/token. Successful insertion closes the modal only after the matching client event inserts/stages content. Real browser tests cover Cancel, Escape, X, outside click, focus return, keyboard reopening and delivery of a held real upload response after X dismissal. |
| TA-01 | Real foreign lesson/document/bytes positive controls; anchor without association; out-of-assignment/out-of-course lesson substitution; cross-company and anonymous denial; revoked author read in all three contexts; supported shared-document assignment; existing foreign PDF refusal through three staged save paths. |
| TA-02 | Actual company/platform course/platform module/token preview and learner pages render linked PDFs, including both sides of a real video marker on split paths; rendered links are followed. These tests exposed PHP arrow callbacks using `compact()` without lexical capture in two PDF mapping callers. Replaced them with explicit route arrays; required pages now pass. |
| TA-03 | Held real multipart transfer proves disabled submission and prevents duplicate activation; one insertion; narrow loaded modal/action bounds; empty-selection filename fallback and custom label persisted through reload; all dismissal focus; Portuguese controls and rendered storage-failure message. |
| TA-04 | Existing failure tests now preserve nonempty formatted content and an ordinary existing link; exact content comparison after invalid/oversized/storage failures. Stale-revision Action test executes successful file transfer then transaction failure, verifies only uncommitted file cleanup and unchanged retained PDF bytes/association/HTML. A real PDF padded to exactly 10240 KB is accepted. Browser invalid upload compares exact editor HTML before/after. |
| TA-05 | Published PDF-bearing targets in all three contexts execute real upload operation and staged save paths, with unchanged HTML, metadata, associations and existing bytes asserted after both denials. |

Focused verification at the correction candidate:

- `vendor/bin/pest tests/Feature/Documents --compact`: 37 passed, 282 assertions, 14.3 seconds.
- `node --test tests/JavaScript/lesson-documents.browser.test.mjs`: expanded real-server browser run passed; includes all three original journeys and the directed cases above.
- `npm run build`: passed; existing optional fontaine/chunk-size warnings remain.
- `vendor/bin/pint --dirty --format agent`: passed/formatted the directed test file.

Only PDF production/tests and the disposable PDF fixture were changed. No `.env` change, no unrelated fixes. Canonical verification, directed finding closure and independent QA/design/conformance remain orchestrator gates. The runtime finding ledger was not modified by the Worker.
