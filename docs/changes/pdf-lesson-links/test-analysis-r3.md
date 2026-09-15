# Directed PDF caret test verification

Verdict: **APPROVE** for the assigned r3 test-effectiveness delta. Run `pdf-editor-20260914`, round 2; checkpoint `pdf-links-20260914-r3`; scope `pdf-links-v1`; review mode directed; context inherited; source role test-analyst.

Revalidation reason: Caret continuation regression and isolated fixture correction; unchanged prior coverage retained.

Read `remediation-r2.md` for PDF-DES-01 and the non-blocking QA-FU-01, and inspected the complete four-file raw delta `/tmp/pdf-r2-before-v4ujR5/remediation-r2.diff` with the relevant current insertion, overlay, browser and fixture code. No Worker conclusion report or other reviewer verdict was consulted. This is not a whole-scope audit. No production code or tests were edited or executed by this reviewer.

## PDF-DES-01: test gap closed

The three-context browser loop now records the actual paragraph and expected selection prefix before inserting the PDF. After the real upload/insertion and modal closure, it allows asynchronous updates to settle, then asserts all four observations together: selection is collapsed, the prefix ends exactly after the intended PDF label, no ancestor anchor encloses the caret, and the contenteditable is focused. Typing continuation text must produce the exact expected paragraph while leaving the PDF label unchanged.

The separate no-selection branch deliberately places the caret after the initial bold text, uploads a PDF with a unique custom label, and asserts the exact resulting prefix, collapsed/focused selection and no enclosing anchor. It then types plain text, compares the complete paragraph, checks that no link includes the continuation, and saves/reloads the exact paragraph. Both branches run in company-course, shared-course and standalone shared-module editors through the actual insertion code.

These assertions are sensitive to the reported defect: focus inside an unrelated final link cannot satisfy either the expected prefix or the absence-of-anchor assertion, even when the PDF label itself is unchanged. There is no replacement editor or manufactured insertion event. The bounded settle interval is part of real browser observation; it is not claimed as a proof of arbitrary timing combinations.

## Smallest causal regression surface

The delta waits for native close and the shared overlay-restored signal before restoring the recorded insertion position, with stored marks cleared. The revised success-path tests exercise that synchronization through actual Livewire responses. The existing Cancel/Escape/X/outside-click tests remain, requiring unchanged HTML and returned focus, along with keyboard reopening. The held real upload response followed by X dismissal still requires no insertion after late completion. Thus cancellation and successful continuation remain separately observable after introducing `insertedPdf` state.

The shared request hook retains its overlay invocation and adds the completion event afterward. Its non-PDF processing is otherwise unchanged; no unrelated editor test expansion is requested.

## QA-FU-01: fixture correction inspected

The four-file delta initializes the existing synthetic tenant from the manifest author before assignment revocation. Copy preparation now resolves the shared module by the manifest's persisted numeric identity rather than mutable title; creation writes that identity. Both changes remain inside the existing disposable fixture. Source inspection supports the requested correction. Execution of those helper modes is left to the assigned directed QA; this report does not claim runtime fixture evidence or convert this non-blocking item into a new test requirement.

## Retained evidence and execution limits

TA-01, TA-02, TA-04 and TA-05 remain closed because their authorization, rendering, persistence and published-operation production/test surfaces are unchanged by this delta. TA-03 remains closed: its filename, busy/duplicate, narrow viewport, locale and custom-label coverage remains, while the affected caret/cancellation assertions are strengthened here. Retain `test-analysis.md` and `test-analysis-directed.md` as the original full-review and directed-closure evidence; this report does not replace the independent gate.

The parent confirmed r2 canonical verification passed, including the expanded PDF browser suite. That is prior evidence only. At this review, the parent is executing r3 canonical verification; no concurrent Pest/browser/formatter process was started here. R3 execution success and executable QA remain separate gates and are not asserted by this source-based test-effectiveness verdict.

Assigned blocking test outcomes unresolved: **0**. New findings: **0**. No unresolved product decisions or scope additions. **APPROVE**.
