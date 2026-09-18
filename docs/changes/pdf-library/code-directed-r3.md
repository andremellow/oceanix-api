# Directed code verification — r3

Verdict: **approve**. New finding count: **0**. Assigned finding **QA-R2-01** is resolved at the code-review boundary; executable QA remains a separate gate.

Run `pdf-library-20260916`; scope `pdf-library-v1`; checkpoint `pdf-library-20260918-r3`; remediation round 2; context fresh; review mode directed. Revalidation reason: Initial PDF opening recovery delta.

## Inspected boundary

Read repository instructions, approved specification, architecture, design and approval, the execution contract, QA-R2-01 in `qa-r2.md`, `remediation-r3-scope.md` and `checkpoint-r3.json`. Compared the exact retained r2 snapshots against the current three assigned files using `git diff --no-index`. Their SHA-256 values match checkpoint-r3.json. No production/test edits were made. Unrelated compliance, composer and translation additions were excluded. This is directed verification, not another full audit; prior unaffected full-review evidence remains retained.

## Assigned outcome and causal checks

- `resources/js/course-editor.js:642` includes `openPdfModal` in the existing single-PDF-call failure classification. A transport failure (status 0) or server failure (5xx) now records the original call and clears pending operation controls without setting the editor to unknown-outcome, resetting authored values or changing dirty state. `clearLocalOperations(false, false)` retains external operations and releases the pending local control state. This addresses the Save/Insert PDF lock from QA-R2-01 under AC-06 and INV-04.
- `resources/views/components/course-editor/root.blade.php:188` exposes the localized alert and keyboard-operable Try again outside the unopened modal. `retryPdfRequest` invokes the captured method with its original model, selected text and token; opening clears the failure before dispatch. The initial event and retry consume rejected action promises while the existing request failure hook presents recovery. The change does not suppress the request hook itself.
- Inspected the existing `openPdfModal` coordinator boundary to verify that reissuing it sets modal state and reads the library rather than persisting lesson content. Retry retains existing fresh editor access and canonical target checks. Successful opening continues through the unchanged overlay restoration path.
- The single-call restriction and status filter are unchanged. In particular, 403 is not converted to retryable transport success; it retains the permission-loss path. Search, paging, reuse and archive retain their existing failure classification, pending-control cleanup and distinct archive confirmation recovery. No insertion callback, cancellation token handling, attachment, archive or Save persistence code changed.
- The browser-test addition covers all three editors, ordinary-open positive controls, repeated initial-open aborts, loss after server processing, retained HTML and selection, keyboard retry and reuse, caret outside the inserted anchor, Save/reload, continued editing and Save without retry, and absence of uncaught page errors. Existing interrupted list/reuse/archive coverage is retained without weakened assertions.

This review inspected implementation and test changes; it did not execute a new browser QA session or repeat canonical verification. Test execution and independent directed QA results must be reported by their respective gates. No scope expansion or follow-up finding was added.
