# Directed test-effectiveness verification — r3

Verdict: **APPROVE**. New finding count: **0**. Assigned finding QA-R2-01 is adequately covered by the remediation tests; runtime closure remains the directed QA gate's responsibility.

Run `pdf-library-20260916`; scope `pdf-library-v1`; checkpoint `pdf-library-20260918-r3`; role test-analyst; phase remediation; round 2; review-mode directed; context-mode fresh. Revalidation reason: Initial PDF opening recovery delta.

## Scope and evidence

Read the repository instructions, approved specification, architecture, design and approval, assigned QA-R2-01 report, remediation-r3-scope.md and checkpoint-r3.json. Used the Toscanini verification skill's separation between test-effectiveness analysis and executable QA. Compared the exact r2 snapshots against the three assigned files only. Current SHA-256 values of all three files match checkpoint-r3.json. No production or test file was edited, no new scenario was added, and no test execution or runtime QA is claimed here.

The change admits a single `openPdfModal` call into the existing PDF transport recovery path, exposes a retry outside the unopened modal, and consumes rejected opening/retry action promises. The applicable basis is AC-06 / INV-04, FR-008, B-14 / QA-07 and architecture §8.

## Assigned outcome checks

The new browser test at tests/JavaScript/lesson-documents.browser.test.mjs:365 uses the real editor, browser selection, Livewire request path and disposable fixture. It loops over company-course, shared-course and shared-module entry points. It does not stub the editor state or the successful response.

- Positive control opens and cancels the actual modal after typing unsaved content, then compares exact HTML.
- Failure interception parses the outgoing component calls and asserts the intercepted method list is exactly `["openPdfModal"]`. This ties the failure to the reported initial-opening path. The shared-module variant fetches the actual server response before aborting delivery, covering an already-handled opening as well as an unsent request.
- After interruption, assertions require unchanged authored HTML, enabled Insert PDF and Save, and the visible preservation error. A second failed retry must be observed as a second intercepted opening, with Save enabled and HTML unchanged.
- Removing interception and activating Try again by keyboard must open the modal. The selected label must remain `Before`; actual Reuse must wrap the selected strong text in the expected document anchor. Subsequent typing is checked outside anchors.
- Actual Save followed by a page reload must reproduce the exact recovered HTML. A separate failed opening is followed by more editing and Save without retrying the modal, then another reload and exact HTML comparison. This checks the requested independent ability to continue editing and persist the draft.
- Captured browser page errors must be empty, exercising both newly consumed rejection paths rather than merely checking an error flag.

Defect sensitivity follows directly from the retained r2 failure branch: without `openPdfModal` in the PDF allowlist, the failed operation enters the general failure state, so the enabled toolbar/Save and visible retry assertions fail. Removing the exterior retry UI prevents the recovery interaction. A broken selection replay, dropped authored values or ineffective Save fails the downstream real DOM/persistence assertions. This is source-based sensitivity analysis, not a claimed mutation-test execution.

## Causal regression coverage and retained evidence

The unchanged browser test at tests/JavaScript/lesson-documents.browser.test.mjs:260 continues to exercise the shared retry handler: interrupted search retains rows/query/label/HTML and retries by keyboard; held pagination exposes busy/disabled navigation; an archive response lost after real server handling retries to exactly one archival row; interrupted reuse retains text/selection and retries into a correctly formatted anchor with following text outside it. Its empty page-error assertion remains relevant to the added rejection handling. These checks cover the existing transport behavior causally touched by the shared retry change.

Retain the prior full test-analysis evidence and r2 directed approval for all unaffected scope: the r3 correction changes only opening classification, error/retry presentation and their regression test. There are no domain, authorization, persistence, route or migration changes in this correction, and those surfaces do not need another full audit. Unrelated concurrent translations, compliance and composer changes were excluded.

All assigned verification outcomes were assessed. No material test-effectiveness gap was found within QA-R2-01 or the causal delta. This approval neither substitutes for deterministic execution nor closes executable QA by itself.
