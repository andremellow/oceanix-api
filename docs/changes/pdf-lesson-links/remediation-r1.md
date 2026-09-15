# Consolidated PDF correction batch

Run pdf-editor-20260914, scope pdf-links-v1, input checkpoint pdf-links-20260914-r1.

All checkpoint reviews returned. Code review: CR-01/02 (request changes). Test analysis: TA-01–05 (request changes). Design: DR-01 duplicates CR-02; full UI source inspected, desktop modal inspected, remaining browser checks blocked by a hung file chooser tool. No production edits occurred during reviews.

Implement CR-01 and CR-02/DR-01, plus the tests required by TA-01–05. See code-review.md and test-analysis.md for exact evidence and required outcomes, and the runtime finding ledger. Preserve the frozen acceptance scope. No other fixes.

DR-01 evidence: pendingPdf survives native modal close; keyboard reopening can reuse stale selection. Required result: every user dismissal invalidates the pending insertion and each new opening captures current selection. Preserve successful close-after-insert. This is the same correction as CR-02.

Verification: Worker runs focused Pest serially and real PDF browser suite/build/Pint. Parent runs canonical verification after the batch. Reopen code/test reviews only for assigned finding IDs and causal delta. Repeat the incomplete design review once with a usable browser upload mechanism. QA then independently executes QA-01–09, followed by architecture conformance. No unrelated whole-scope review expansion.
