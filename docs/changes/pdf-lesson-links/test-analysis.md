# PDF automated-test effectiveness

Verdict: **REQUEST_CHANGES**. Run `pdf-editor-20260914`, round 1, independent review, context fresh, checkpoint `pdf-links-20260914-r1`, frozen scope `pdf-links-v1`. Source role: `test-analyst`.

Reviewed the approved request, architecture, design and execution contract; the complete implementation manifest against base `f6a6645`; PDF Pest/browser tests and their fixtures; and existing shared editor/media test seams. No implementation evidence or other specialist verdict was consulted. No production code or tests were edited. These findings require test implementation, not changes to the approved product scope.

## Execution and effective coverage

`node --test tests/JavaScript/lesson-documents.browser.test.mjs` passed (one real-browser test). It uses a unique disposable SQLite database/private filesystem and real Laravel, Livewire and editor insertion. Selection insertion, subsequent typing outside the link, save/reload in three contexts, surrounding bold/ordinary links, cancellation, invalid-file retry and learner PDF popup receive meaningful evidence. The popup targets a pre-saved fixture PDF; it independently proves the delivery/rendering path rather than the newly uploaded document.

`php vendor/bin/pest tests/Feature/Documents/LessonDocumentTest.php --compact` passed serially: **21 tests, 137 assertions**. The earlier restricted invocation could not obtain the plugin's socket, and an escalated attempt overlapped canonical verification and shared fake-storage resets. Both earlier attempts are excluded as defect evidence; the final isolated execution passed.

The Pest sources exercise real database association/save/copy boundaries and local fake-disk reads/writes. Copy fixtures contain actual PDF records, and the explicit removal of composition mirrors reaches the changed legacy copy loop. The shared-module case reaches `CreateModuleDraft`'s new association copy. MIME/size fakes are supported by Livewire's test metadata; an empty physical oversized fake is not itself a false-positive finding. Browser invalid-content upload separately checks content-detected MIME. No PostgreSQL-specific test requirement is introduced.

| Frozen scenario | Automated assessment |
| --- | --- |
| QA-01 | Actual three-context selection/upload/save/reload covered. |
| QA-02 | Custom insertion and cancellation covered partly; filename fallback and cancellation focus absent (TA-03). |
| QA-03 | Real learner page, PDF headers and popup covered; split rendering absent (TA-02). |
| QA-04 | Same-company non-assignee and cancelled assignment covered; company/anonymous and meaningful membership controls incomplete (TA-01). |
| QA-05 | Direct author/preview byte endpoints positive, expired token covered; real foreign document and preview link rendering absent (TA-01, TA-02). |
| QA-06 | Invalid/oversized validation and early filesystem failure/retry covered; preservation fixtures and later failure boundary incomplete (TA-04). |
| QA-07 | Keyboard opening, narrow-screen cancellation and insertion focus covered partly; upload busy/duplicate and narrow insertion assertions absent (TA-03). |
| QA-08 | Both changed copy hooks have representative association assertions; company published mutation missing (TA-05). |
| QA-09 | Surrounding bold and ordinary links covered. Existing media operation tests exercise the unchanged image/video branches of the shared guard; no unrelated media expansion requested. PDF split-render integration remains TA-02. |

## Consolidated findings

### TA-01 — Document membership and access denial controls do not prove the PDF authorization boundary

Severity: high. Classification: in-contract test gap. Blocking basis: AC-03, INV-01, INV-03; QA-04/QA-05. `sourceRole=test-analyst`; `failureStage=test-implementation`.

Evidence: `tests/Feature/Documents/LessonDocumentTest.php:68` and `:129` use `11111111-1111-4111-8111-111111111111`, which has no document row. The preview negative also has no matching saved anchor, so it stops before the association query. It would still pass if the association query were replaced with a global document lookup. The learner test at `:76` only uses a lesson inside the assignment, same-company users, and an authenticated bare-reference request. No automated request exercises an existing foreign lesson/document, cross-company user, or anonymous non-token byte URL. No author byte-read test revokes previously valid author access. Upload revocation tests do not execute the read paths.

Required outcome: build a second real lesson/document with bytes and its own positive read control; prove a saved anchor alone cannot grant access without the target lesson association, and prove substituting an out-of-assignment/out-of-course lesson is denied. Exercise the frozen cross-company, anonymous and formerly-authorized read revocation cases with real linked documents. Assert denial of bytes, preserving valid controls. Shared-document learner eligibility should use a real shared lesson in an authorized assignment so company filtering cannot falsely deny that supported case. These are PDF-specific authority tests, not an authorization-system audit.

### TA-02 — Changed preview and split-content rendering branches have no PDF-bearing regression fixtures

Severity: high. Classification: in-contract test gap. Blocking basis: AC-02/AC-03; frozen “PDF-bearing draft copies and lesson rendering” surface, QA-03/QA-05/QA-09; architecture §§5, 9. `sourceRole=test-analyst`; `failureStage=test-implementation`.

Evidence: the tests at `LessonDocumentTest.php:129` and `:154` construct document routes directly; they never render the containing company, platform course/module or token-preview page. The standalone mapper test at `:95` cannot prove callers supply a callback. The browser fixture contains no video marker. Consequently the new mapping calls in `CoursePreviewController`, `CoursePreviewProjection`, the three preview views and the learner/platform `array_map` split branches can be removed while these PDF assertions remain green.

Required outcome: render the affected containing pages with saved, associated PDFs and assert the actual anchors carry their appropriate contextual URL and new-tab attributes. Include PDF anchors before and after a real authored video marker for the changed split paths. Follow representative rendered links to the private response. This can be mostly HTTP/component integration testing; it does not require duplicating the full browser workflow across layers.

### TA-03 — Browser assertions leave accepted filename, busy and accessibility behavior unprotected

Severity: medium. Classification: in-contract test gap. Blocking basis: AC-01/AC-04/AC-06; QA-02/QA-07. `sourceRole=test-analyst`; `failureStage=test-implementation`.

Evidence: `tests/JavaScript/lesson-documents.browser.test.mjs:46` waits for the submit button to be enabled, which passes even if it is never disabled. There is no duplicate-submit assertion. At `:63` the narrow viewport only opens/cancels an empty modal; neither bounds nor a loaded filename/actions are asserted. At `:68` cancellation checks HTML but not returned focus. At `:80` the filename is immediately replaced with custom text; fallback insertion is never asserted, and custom text is not checked after reload. No PDF-specific rendered PT-BR check exists.

Required outcome: with a deliberately held real upload request, assert disabled submission and one resulting insertion after attempted duplicate activation. Explicitly collapse the selection, exercise untouched filename fallback and custom label through save/reload, and assert focus after cancellation. Perform the existing narrow/keyboard scenario with a selected file and reachable, in-viewport actions. Add a small rendered locale assertion for the new PDF controls/errors. Preserve the real insertion path; do not substitute an event stub.

### TA-04 — Failure-preservation tests use empty content and miss the new post-transfer failure boundary

Severity: medium. Classification: in-contract test gap. Blocking basis: AC-04 and INV-02; QA-06; architecture §§7–9 upload transaction/failure contract. `sourceRole=test-analyst`; `failureStage=test-implementation`.

Evidence: the editor fixtures initialize `content_markdown` to an empty string. The invalid-file and storage-failure assertions at `LessonDocumentTest.php:59` and `:122` therefore remain green if failure clears user content. The browser invalid-file branch asserts an error but never compares content before/after failure. The mocked `putFileAs` exception at `:120` occurs before `UploadLessonDocument` enters its metadata transaction, so no test reaches the new cleanup/revision-failure catch after successful storage. The size tests reject 10241 KB but never prove that the approved 10240 KB upper boundary is accepted.

Required outcome: seed/stage nonempty formatted text and a valid existing link before invalid, oversized and failed upload checks; compare exact staged content and assert no insertion, then successful retry. Add a deterministic post-transfer failure (for example a stale revision on a directly invoked Action with an otherwise authorized draft), asserting no metadata/association commit, cleanup of only the uncommitted upload and unchanged existing file bytes. Include a valid PDF at the accepted size boundary. Real SQLite and the private fake filesystem are sufficient; no concurrency or PostgreSQL expansion is requested.

### TA-05 — Company published-upload protection is skipped by the only PDF immutability test

Severity: high. Classification: in-contract test gap. Blocking basis: AC-05, INV-02; QA-08. `sourceRole=test-analyst`; `failureStage=test-implementation`.

Evidence: `LessonDocumentTest.php:175` explicitly excludes company-course from its published upload attempt. Its dataset also excludes shared-course. The test titled “rechecks author permissions and editable state” at `:103` only revokes roles/account activity and never publishes anything. The shared-module published test exits at record resolution; it does not exercise the separate company-course resolver/guard. Company copying alone proves association retention, not refusal of a published upload or link edit.

Required outcome: for the three PDF editor contexts, exercise the relevant published target through the real PDF upload operation and the staged save path, with a linked PDF already present. Assert rejection and unchanged published HTML, metadata/associations and existing bytes. Reuse existing published-save tests where they actually execute these PDF-bearing paths; do not add tests for unrelated publication behavior.

There are five blocking findings, no non-blocking scope additions and no unresolved product decisions. No implementation gate is approved by this report. **REQUEST_CHANGES**.
