# PDF implementation evidence

Run `pdf-editor-20260914`, scope `pdf-links-v1`. Worker implementation evidence only; no review or independent QA approval is claimed.

Implemented private immutable metadata, restrictive retained associations, content-based PDF validation up to 10240 KB, reauthorized draft uploads, strict canonical reference membership, two copy seams, and five contextual read/render paths. Unified modal inserts selected/custom/filename labels with stable operation/record identity, restores focus, stages explicit Save and ends the stored link mark. English/PT-BR strings included.

Verification:

- `vendor/bin/pest tests/Feature/Documents/LessonDocumentTest.php --compact`: 21 passed, 137 assertions (11.2 seconds). Real Livewire/action/database boundaries cover all editors, invalid/oversized inputs and retry, storage failure, forged references, unsaved denial, learner access/revocation, author preview contexts, expired token preview and retained copies.
- `node --test tests/JavaScript/lesson-documents.browser.test.mjs`: passed (6.9 seconds). Real isolated Laravel server and Playwright cover all three selection/upload/save/reload journeys, selected/custom labels, cancellation, narrow layout, keyboard opening, invalid-upload recovery, focus, ordinary typing after link insertion, surrounding formatting and ordinary links. Real learner click created a popup and returned HTTP 200 application/pdf while preserving the original page. Headless Chromium's PDF viewer/download preference does not provide a navigated popup URL; actual popup and response are asserted.
- `npm run build`: passed. Existing optional fontaine and chunk-size warnings remain.
- `vendor/bin/pint --dirty --format agent`: passed/formatted changed files.

Defect sensitivity: the real browser test initially failed because `unsetLink` erased the newly inserted PDF anchor. Replacing that operation with an empty stored-mark set retained the link and kept subsequent typing ordinary. The same test then failed because programmatic insertion did not activate explicit Save; the dedicated PDF-inserted event now stages the existing editor dirty contract. These failures occurred against the actual insertion/UI path before their fixes. Invalid/oversized, unattached and revoked cases have passing authorized controls and persistence assertions. No expectation was weakened to accept missing PDF insertion or an inactive Save.

Pest Browser's installed remote bridge rejected local file attachment and then returned empty multipart uploads. The browser suite therefore follows the repository's existing standalone Playwright/server approach; it does not stub uploads or the changed insertion path. Composer's required browser command now includes it.

Independent executable setup: `tests/Support/Documents/README.md` and the `QaFixture.php` / `QaRouter.php` harness. Synthetic data, SQLite, sessions, temporary uploads and document bytes remain in a newly created `/tmp/oceanix-pdf-qa-*` directory. No `.env` or development database changes. Frozen QA-08 and unrelated-document/expiry controls have explicit fixture commands. Canonical verification and independent review/QA/conformance remain the orchestrator's next gates.
