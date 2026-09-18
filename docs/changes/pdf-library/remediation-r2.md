# Directed second remediation: QA-R2-01

Run `pdf-library-20260916`; frozen scope `pdf-library-v1`; remediation round 2. Baseline implementation checkpoint `pdf-library-20260916-r2`. Owner authorization: `remediation-r2-approval.md`. Worker evidence only; this is not independent approval or final delivery.

## Change and causal boundary

The initial `openPdfModal` call was missing from the recoverable PDF request methods. An interrupted opening therefore entered the shared unknown-outcome branch, locking Save and Insert PDF despite being a read/modal-state operation. Add this method to the existing PDF-only recovery branch. Keep its status restriction (transport/5xx), single-method restriction, permission handling and all other operation behavior unchanged.

Expose the existing translated interruption message and Try again action at page level when initial opening fails: the PDF dialog does not yet exist visibly. Retry preserves the captured method parameters, insertion token, text and selection. Consume rejected opening/retry action promises after the request hook reports recovery, avoiding uncaught browser errors. No domain, authorization, coordinator, persistence, translation or migration changes were necessary.

Production delta versus the retained r2 snapshot:

- `resources/js/course-editor.js`: initial opening enters PDF recovery; retry consumes the already-handled rejected action promise.
- `resources/views/components/course-editor/root.blade.php`: opening listener consumes the already-handled rejection; localized page-level alert and retry control use existing Flux and semantic negative-border styling.
- `tests/JavaScript/lesson-documents.browser.test.mjs`: one real-browser regression across company course, shared course and shared module.

The new browser scenario types unsaved content, positively checks normal opening/cancellation, aborts exactly the opening request, verifies exact text and enabled Save/Insert PDF, retries while the network is still interrupted, then retries successfully by keyboard. It verifies retained selected text, formatted reuse, caret outside the anchor, Save/reload persistence and absence of uncaught browser errors. Shared module additionally loses the response after server execution. A further interruption verifies editing and actual Save/reload without first retrying opening. This refines existing B-14/QA-07 and INV-04 coverage; it adds no QA scenario or contract scope.

## Executed evidence

1. `node --test --test-name-pattern='PDF initial opening' tests/JavaScript/lesson-documents.browser.test.mjs` before production edits: **RED**, expected `true`, observed `false` for `companyEditor: opening failure must release Insert PDF`; exact intercepted calls were `["openPdfModal"]`. Initial sandbox attempt could not bind loopback (`EPERM`); authorized execution outside the sandbox then reached this intended assertion.
2. During test development, tightened the save-status locator to its region and used a deterministic end-of-document selection to avoid fixture whitespace normalization. Green candidate exposed rejected Livewire opening promises as uncaught errors; the production rejection handling above addresses those errors. Expectations were not weakened.
3. `npm run build`: **PASS**, final built asset `app-BJUIHcSX.js`; existing optional fontaine and bundle-size warnings remain.
4. `node --test tests/JavaScript/lesson-documents.browser.test.mjs`: **PASS**, 4 tests, 68.69 seconds. Existing catalog/private Open/reuse/archive/revocation/localization, interrupted search/reuse/archive, and original upload/cancellation/learner-popup tests all pass. Disposable fixtures retained at `/tmp/oceanix-pdf-qa-T3EP8B`, `/tmp/oceanix-pdf-qa-A8PBi8`, `/tmp/oceanix-pdf-qa-cGChWp` (new opening recovery), `/tmp/oceanix-pdf-qa-DjJu2x`.
5. `npm run test:editor:unit`: **PASS**, 30 tests.
6. `php artisan test tests/Feature/CourseEditor/UnifiedCourseEditorContractTest.php tests/Feature/Documents`: **PASS**, 126 tests, 1,208 assertions, 59.00 seconds.

No full canonical verification, Herd writes, PR changes, instruction changes or `.env` changes performed by this Worker. Existing concurrent compliance/composer/Portuguese edits were preserved. Root owns the final immutable checkpoint and canonical verification.

## Proposed gate impact

Reopen directed Code Review and Test Analyst for QA-R2-01 and the three-file remediation delta. Reopen directed Design Review because the error/retry callout is now reachable outside the unopened dialog. Reopen directed executable QA for the initial-opening failure/retry/direct-Save path and smallest causal PDF transport regressions, retaining prior complete matrix evidence. Final architecture conformance remains required after QA; this correction changes no architectural decision or invariant. Root consolidates verdicts and records closure; the Worker does not declare QA-R2-01 approved or overall delivery complete.
