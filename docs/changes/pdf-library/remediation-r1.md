# PDF library consolidated remediation — round 1

Run `pdf-library-20260916`, frozen scope `pdf-library-v1`, approved architecture SHA-256 `cc287892d63b48973ea614074fcdf958da8609768fc082156e2133708413a69c`. Worker implementation evidence only; findings remain subject to directed specialist verification. No contract, architecture, Action/service, migration, global stylesheet, deployment or PR changes.

## Exact remediation scope

No full pre-edit source archive was captured by this Worker. The original r1 manifest/hashes remain unchanged; do not treat the current git diff (which also contains the initial feature) as an exact remediation-only diff. The following final source locations bound this correction batch.

| Finding | Changes and directed evidence |
| --- | --- |
| CR-01 / DES-02 | `app/Livewire/CourseEditor/EditorCoordinator.php:1049` clears unauthorized confirmations on denied list; `requestArchivePdf` returns a rendered denial rather than aborting; `pdfFailure` clears confirmation and refreshes only the permitted catalog/actions. `tests/Feature/Documents/LessonDocumentLibraryAccessTest.php:30` exercises View and Archive-only revocation before request and after confirmation, unchanged HTML/label/token and zero archive records. Browser owner-library test repeats these actual UI transitions. |
| DES-01 / TA-03 | `resources/js/course-editor.js:21` and request hook near line637 retain the failed single PDF action for explicit manual retry; no editor-wide unknown-outcome lock for these idempotent PDF actions. Mixed/non-PDF requests retain existing behavior. `resources/views/components/course-editor/root.blade.php:866` shows accessible transport error/stale-results notice and retry. Browser transport test aborts search and reuse requests and aborts archive response after real server commit, proves recovery, preserved selection/label/search/HTML and one archive record. Existing server-fault coverage remains; failed archive confirmation is retried to success. |
| DES-03 / TA-04 | Modal near line914 announces archive progress, restores Cancel focus after terminal failure, and supports keyboard retry/Cancel/Escape. Parent focus falls back to Link text when denial removes search. Browser tests assert initial/return/success/failure focus, held page and archive busy controls, genuine empty owner versus no-results, keyboard Search/Open/pagination/Reuse/Archive and narrow PT-BR confirmation reachability. |
| DES-04 | `operationActionLabel` maps new PDF operations through translation; `lang/pt_BR.json:893` supplies retry, operation, denial, progress and transport translations. Browser PT-BR denied retry, failed reuse and archive controls are exercised. |
| DES-05 | PDF row Reuse uses existing `admin-primary-action`; only PDF Archive row/confirm apply semantic danger colors locally, overriding the existing neutral admin button rule without changing global CSS. Browser checks actual computed primary/danger colors. |
| TA-01 | `tests/Feature/Documents/LessonDocumentLibraryAccessTest.php:86`: actual successful Reuse/Archive controls followed by isolated Reuse, Archive, View and destination CoursesUpdate removal. Runtime prerequisite denials use deliberately incomplete grants; unrelated allowed write still succeeds, forbidden association/archive and HTML mutation do not. |
| TA-02 | `tests/Feature/Documents/LessonDocumentLibraryTest.php:35`: successful reuse starts with a saved source association, preserves source HTML/association/metadata/bytes, explicitly saves destination and exercises authorized/denied contextual delivery in all three contexts. Archive test adds published-source retention/copy validation/pending Save and a reused destination's authorized learner bytes/unrelated denial before and after archive. `QaFixture.php` last two company/platform files now have existing source uses. |
| TA-05 | Owner-library browser test handles text/element selection nodes correctly, requires collapsed editor-focused caret at exact prefix, types suffix outside every anchor, saves/reloads and verifies exact paragraph/label/ordinary link for selected/custom/default insertions in all three contexts. |

Complete files changed by this Worker:

- `app/Livewire/CourseEditor/EditorCoordinator.php`
- `resources/js/course-editor.js`
- `resources/views/components/course-editor/root.blade.php`
- `lang/pt_BR.json`
- `tests/Feature/Documents/LessonDocumentLibraryAccessTest.php`
- `tests/Feature/Documents/LessonDocumentLibraryTest.php`
- `tests/Feature/Documents/LessonDocumentArchiveTest.php`
- `tests/JavaScript/lesson-documents.browser.test.mjs`
- `tests/Support/Documents/QaFixture.php`
- `docs/changes/pdf-library/runtime-setup.md`
- this evidence document

## Failure sensitivity and checks

The four new archive permission-loss checks ran against the prior implementation first: two failed on HTTP403 and two on confirmation remaining open. After correction all four pass. Other added tests assert real boundary outcomes rather than markup strings: separately revoked permissions reach actual Actions, existing source associations are retained, real HTTP transport is aborted, a committed archive response is lost and retried, focus/computed styles are read from Chromium, and exact insertion text is persisted and reloaded.

Initial focused `php artisan test tests/Feature/Documents`: 68 passed /536 assertions. After final learner/prerequisite additions and stale-error clearing, targeted causal filter `renders archive revocation|independent write grants|serves a reused saved`: 9 passed /63 assertions. Single-file archive invocation was unusable because existing helpers are defined in other PDF test files; rerun via directory plus filter succeeded. Sandbox port restriction was resolved by authorized test execution outside the sandbox, using only disposable/in-memory test data.

`node --test tests/JavaScript/course-editor.test.mjs`: 30 passed at final source. Targeted Pint passed. Production build passed, retaining existing optional-font and bundle-size warnings. Final owner-library and interrupted-transport browser suites: 2 passed, upload suite intentionally filtered; evidence `/tmp/oceanix-pdf-qa-vPIuyp` and `/tmp/oceanix-pdf-qa-gElma0`. Existing full upload/browser regression also passed in the preceding complete browser invocation (`/tmp/oceanix-pdf-qa-pVjwWG`); that invocation's owner-library suite exposed a fixture fault described below. No upload production path changed afterward.

Earlier intermediate browser attempts caught test synchronization/fixture issues: Escape fired before dialog focus settled; the new permission restore command collided with the existing storage-fault restore command; and an intentionally injected reuse INSERT failure targeted an already-associated file (correctly producing no INSERT). The final commands are `revoke-archive-permission` / `restore-archive-permission`, documented in neutral runtime setup, and the fault test uses an unattached row. No assertions were relaxed. A final request guard restricts PDF-specific recovery to one PDF call with network/server failure, preserving unrelated/mixed requests and normal permission/session failures; the causal transport suite is re-executed after this final guard.

No PostgreSQL lock/probe rerun was necessary: Actions, services, transaction ordering, schema and concurrency behavior were unchanged. Root owns final serial canonical verification, checkpoint, ledger decisions, directed reviewers, independent executable QA and architecture conformance. Worker tests are not independent QA approval.

Final guard causal transport result: 1 passed (other two browser tests filtered), `/tmp/oceanix-pdf-qa-99PRqu`. No unresolved Worker implementation/test failure remains. Process inventory after completion found no PDF browser test or harness server processes; all tool sessions completed. Disposable evidence directories are retained. Finding statuses have not been changed by this Worker.
