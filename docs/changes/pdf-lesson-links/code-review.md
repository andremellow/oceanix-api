# Independent PDF code review, round 1

Verdict REQUEST_CHANGES, checkpoint pdf-links-20260914-r1, scope pdf-links-v1. Returned by fresh code-reviewer after inspecting every manifest file and relevant execution paths. This file records the reviewer's returned findings; no new review is claimed.

## CR-01 — default selection normalization loses authored content

Blocking, AC-01/AC-04/INV-04. EditorCoordinator truncated selected text to 500 characters, then trimmed/defaulted falsy labels including `0`; content-editor.js treated a changed label as intentional replacement. A formatted selection ending in whitespace loses its separator/formatting, and long selections lose their remainder. Preserve the exact original selection and formatting when accepting its default label. Any label limit must validate without silently replacing or truncating lesson text.

## CR-02 — X dismissal does not invalidate pending insertion

Blocking, AC-04/AC-06 and architecture section 7. The modal handles cancel and its explicit Cancel button, but Flux's built-in X calls dialog.close(), which emits close. pendingPdf therefore survives; delayed completion can insert after dismissal and keyboard reopening can reuse the stale selection/token. Invalidate every user dismissal and restore focus, capture current selection on each opening, and retain successful insertion on its normal modal close.

Focused reviewer Pest execution passed 21 tests/137 assertions. These findings came from changed source execution paths; no independent QA was claimed.
