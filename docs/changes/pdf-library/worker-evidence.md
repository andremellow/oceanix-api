# PDF library implementation evidence

Run `pdf-library-20260916`; frozen scope `pdf-library-v1`; Worker round 1. Architecture SHA remains `cc287892d63b48973ea614074fcdf958da8609768fc082156e2133708413a69c`. This is implementation evidence, not independent review, QA approval or a completion gate.

## Implemented scope

- Owner-first, freshly authorized company/platform catalog with literal filename search, stable 20-row pages, full pagination and whitelisted output. Private authenticated Open is separate from contextual learner/preview delivery.
- Explicit View/Reuse/Archive capabilities, prerequisites and insert-only catalog projection without non-admin grant backfill; company record Policy and existing platform Account authority remain separate.
- Reuse locks the target graph before the document, rechecks identity/owner/draft/revision, checks archival state after acquiring the document lock and retains the pivot without saving HTML or duplicating bytes.
- Archive retains one immutable actor/time row, enforces exactly one actor in both model and database, and retains metadata, files and all attachments. Rollback refuses to discard archival evidence.
- One shared modal across all three editors: upload, custom/selected/default text, library search/paging, native private Open, Reuse, named archive confirmation, child cancellation/focus restoration, error/retry and permission-loss states, English/PT-BR and narrow layout. Existing insertion token and settled caret implementation is reused unchanged.

## Defect sensitivity and tests

Initial capability tests failed three times on missing enum/service, before implementation. Reuse tests then failed in all three contexts on missing `reusePdf` and missing Action (six failures), before those seams were implemented. Projection was implemented with the authorization foundation, so its 65-item test was first executed against that increment; it asserts exact reachable reverse IDs, four pages, literal `%_!` search, no results and page clamping. Archive assertions were added after the shared write foundation; they inspect real persistence/bytes/actor/time, positive saved delivery, forbidden attachment and actual pending Save, rather than mirroring query syntax. Browser assertions use actual Flux/editor lifecycle and intercepted real responses; no mocked authorization or persistence.

Executed evidence:

| Check | Result |
| --- | --- |
| Focused PDF suite after archive and access expansion | 58 passed, 444 assertions, 22.02s |
| Final cancelled/published-target addition, all three contexts | 3 passed, 15 assertions, 2.18s |
| Existing actual upload/selection/save/reload/learner-popup browser regression | Passed, 21.17s, `/tmp/oceanix-pdf-qa-D8sGk8` |
| New library browser matrix including real failures/retry and held stale completion | Passed, 20.75s, `/tmp/oceanix-pdf-qa-gu1Rvu` |
| Final integrated browser run after localization/pending-dismissal changes | Both passed, no skips, total45.28s: library23.42s at `/tmp/oceanix-pdf-qa-BWDYdM`; upload21.57s at `/tmp/oceanix-pdf-qa-I6pX2s` |
| Editor unit suite | 30 passed |
| Build | Passed; existing optional font optimization/chunk-size notices only |
| Pint changed PHP scope, then check | Passed; final unused import removed |
| `git diff --check` | Passed |
| Architecture hash | Matches approved hash |

Full canonical verification is handed to the root orchestrator, serial with any shared test fixtures. Independent Code Review, Test Analyst, executable QA, Design validation, architecture conformance and completion gates remain pending; no skipped scenario is represented as independent evidence.

## Native PostgreSQL B-12

`OCEANIX_PDF_QA_DIR=/tmp/oceanix-pdf-qa-HhaDkJ php tests/Support/Documents/PostgresRace.php` passed both archive-first and reuse-first cases against the exact local disposable database `oceanix_pdf_library_qa_20260916_085729b9`. Two separate PHP/backend processes executed the real Actions. A test-only post-query listener held the first document lock; a third observer confirmed the opposing backend's `wait_event_type=Lock` before release. Archive-first produced no pivot. Reuse-first committed a pivot and later actual `SaveCompanyCourseEditorDraft` persisted the canonical link after archive. Both cases verified unchanged original metadata/bytes and idempotent archival actor/time. Lock/statement/barrier waits were bounded; no SQLite locking claim is made.

Initial sandbox execution could not connect to localhost. The first escalation was rejected because database ownership was not established to automatic approval review. Root supplied provisioning provenance plus a new read-only probe proving exact name, zero public tables and current-role ownership in `postgres-readiness.md`; re-evaluation then allowed the probe. No rejection was bypassed. Only additive migrations/fixtures ran on this verified disposable database; no `.env`, Herd database or user data changed. Fixtures are retained for independent inspection.

## Browser artifacts and limitations

Fixture manifests expose only synthetic IDs and safe URLs. `tests/Support/Documents/README.md` describes login, failure flags and permission controls. New browser coverage includes 65+ files across three contexts, private new-tab Open with unchanged HTML, formatted selection/custom/default reuse with exact settled caret outside every anchor, explicit Save/reload, child Cancel/Escape, retained archive effects, list/reuse/archive failure and retry, revoked catalog metadata clearing, disabled pending reuse plus late cancellation, keyboard opening and 390px PT-BR. Screenshot `library-mobile-pt.png` is retained in the reported browser directory. A first visual pass identified the existing status translation for “Open”; the feature now uses contextual “Open PDF”/“Abrir PDF” and adds missing Archive/pagination translations.

The harness uses injected faults only inside its explicitly selected disposable bootstrap; production has no test route or failure injection. Independent QA still must execute the frozen QA-01–08 and cannot substitute this authored browser suite for its own verdict.

## Canonical checkpoint correction: bounded upload-row assertion

Root's first canonical run stopped in `composer test`: 1 failed, 16 skipped, 910 passed, 5047 assertions, 129.94s. The expanded shared modal increased the editor HTML enough for the existing greedy whole-page upload-row regular expression in `UnifiedCourseEditorContractTest.php` to exhaust PCRE's backtrack limit. The targeted concurrent-upload test reproduced this failure (1 failed/20 assertions). This is a causally exposed test assertion scalability defect on the frozen modal regression surface, detected by canonical verification; failure stage: test implementation. No upload behavior or requirement changed, and independent reviews had not begun.

The correction selects the exact upload article by stable token using native DOM/XPath, requires exactly one row, asserts its `data-upload-state=failed`, and requires its own single `role=alert` containing “Upload failed”. This also prevents unrelated rows or alerts from satisfying the assertion. The corrected targeted test passed (1/32 assertions). A temporary in-memory mutation changing only that row's alert role to status failed on the expected missing-alert assertion (0 versus 1); the mutation was removed. The full affected contract file passed56 tests/656 assertions in23.62s with local-port access; its initial sandbox run was blocked by Pest's socket listener, not behavior. Pint and diff checks passed. Root owns the full canonical rerun. No production edits were needed; approved architecture hash remains unchanged.
