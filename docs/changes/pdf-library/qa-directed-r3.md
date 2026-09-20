# Directed PDF recovery verification

**PASS — QA-R2-01 is resolved by the observed runtime behavior.** No new blocking or non-blocking findings.

Run `pdf-library-20260916`; scope `pdf-library-v1`; checkpoint `pdf-library-20260918-r3`; remediation round 2; review-mode **directed**; context-mode **inherited**. Assigned finding: QA-R2-01, detector QA, original failureStage implementation, basis AC-06 / INV-04 / B-14 / QA-07. Revalidation reason: Interrupted initial PDF opening recovery.

This is a directed replay and the smallest requested causal recovery checks, **not a new independent whole-scope approval**. Read the owner approval, remediation scope/checkpoint and runtime setup. Did not inspect other directed reviewers’ reports, review product source, run the authored browser suite, or edit product/tests/repository fixtures.

## Safe setup

Created a new `/tmp/oceanix-pdf-qa-NENHmf` using mktemp. The previously inspected standalone harness selects testing, SQLite, sessions/uploads/private files in that exact directory, and prevents external HTTP. Initialized new synthetic fixtures, started loopback PHP at `127.0.0.1:8879`, and launched a fresh isolated Chromium context. Used generated manifest identities/routes. No .env, Herd or application database was changed. No PostgreSQL or unrelated scenario replay was needed for this UI-only delta.

## Executed QA-07 directed cases

| Setup/action | Expected | Observed / evidence |
| --- | --- | --- |
| Positive control: type ` DIRECTED unsaved` in company lesson; settle; normal Insert PDF, then Cancel. | Save enabled; populated modal opens without changing authored HTML. | Save enabled; one populated dialog; exact HTML equality before/after opening. |
| Select adjacent bold/italic ` leading trailing ` text; abort only the actual Livewire payload containing `openPdfModal`. | Failure preserves HTML/selection, usable Save and accessible retry; no destructive reload required. | Recorded method list exactly `["openPdfModal"]`. Exact HTML unchanged, Save enabled. Visible “The PDF request was interrupted… Your text and selection are preserved.” and enabled “Try again.” |
| Keep the transport interruption active and activate Try again with keyboard Enter. | Repeated opening failure remains recoverable. | Two intercepted `openPdfModal` requests; exact HTML preserved; Save and retry enabled after the second failure. Screenshot `repeated-opening-failure.png`, visually inspected. |
| Remove interception; keyboard-activate Try again, then Reuse the existing PDF; type suffix after settling; Save/reload. | Original selection preserved; successful retry opens library; insertion keeps formatting/caret; authored values persist. | Populated dialog opened; Link text was exactly ` leading trailing `. Reuse retained `<strong> leading </strong><em>trailing </em>` inside the PDF anchor, with plain ` OUTSIDE` after it and before the existing separator. Original unsaved marker and resulting content survived Save/reload. |
| Allow initial `openPdfModal` to execute via request forwarding, observe server response, then abort delivery to browser. | Lost response also retains editable state and recovery. | Forwarded server response was 200. Exact prior HTML remained; Save stayed enabled. Added ` EDIT AFTER FAILURE` after the earlier ` LOST RESPONSE` marker and successfully saved while recovery was pending. SQLite read-only inspection and browser reload both confirmed the saved combined marker. No destructive reload used. |
| After that successful Save, use normal Insert PDF. Separately repeat the lost-server-response case and directly activate Try again without saving first. | Saving may finish recovery; normal opening remains usable; immediate lost-response retry also succeeds. | Save cleared the recovery notice; normal Insert PDF opened successfully. Separate forwarded-200/dropped-response attempt recovered directly via keyboard Try again and displayed 20 library links. |
| In the recovered modal, interrupt search for `reusable`; activate Try again. | Existing search recovery preserved. | Exact HTML unchanged during failure; enabled retry; recovery rendered the one matching PDF. |
| Interrupt Reuse, then activate the original Reuse control after transport returns. | No false insertion or lost text; retry succeeds. | Exact HTML unchanged on failure with interruption message. Retry inserted and closed the modal. |
| Open Archive confirmation; interrupt Archive PDF; retry confirmation. | Existing archive recovery preserves text and remains idempotent. | Exact HTML unchanged during failure and after retry. Message instructed “Try Archive PDF again to confirm the result.” Retry removed the item from active reuse. Read-only SQLite query found exactly one archive row. |

All assigned checks and requested causal checks completed. There was no unexecuted required runtime case.

## Evidence and execution limits

- Isolated evidence: `/tmp/oceanix-pdf-qa-NENHmf/manifest.json`, `database.sqlite`, private `documents/`.
- Screenshots: `/tmp/oceanix-pdf-qa-NENHmf/repeated-opening-failure.png` and `/tmp/oceanix-pdf-qa-NENHmf/recovered-modal.png`.
- Browser page-error collection was empty. Seven failed requests correspond exactly to intentional aborts: two pre-dispatch opening attempts, two dropped responses after server execution, search, reuse and archive. Relevant local server output showed normal request completion and asset 200 responses, with no runtime exception in this server’s output. The shared application log contains concurrent test-fault entries that cannot be attributed to this isolated run and were not treated as this run’s failures.
- One driver expectation was corrected: after a successful Save, the Try again notice no longer existed. Verified the normal Insert PDF control instead, and separately exercised immediate lost-response retry. The locator timeout was not a product failure.
- The initial recovery screenshot still displays the existing “Insert PDF in progress” status alongside the explicit interrupted-request alert. It did not disable Save or retry. No additional blocking requirement or review scope was inferred from this wording.

## Coverage retained versus newly executed

New execution covers **QA-07’s assigned interrupted-opening recovery and the requested causal modal recovery checks only**. It is not a replay of every QA-07 dimension, and no claim is made that QA-01–08 ran again at r3.

The complete independent matrix in [qa-r2.md](qa-r2.md) remains retained: QA-01, QA-02, QA-03, QA-04, QA-05, QA-06 and QA-08 passed; QA-07’s sole blocker was QA-R2-01. This directed result closes that finding. Other previously executed QA-07 aspects remain retained because this correction concerns interruption recovery, not ownership, domain persistence, layout/localization or upload behavior. The final architecture/completion gates belong to the orchestrator.

## Cleanup and verdict

Removed browser interception routes, closed the isolated browser and driver, and stopped this run’s PHP server. Retained only this run’s disposable fixture data/evidence for audit. No production data or unrelated test fixture was cleaned up or modified.

Final directed verdict: **PASS**. Assigned finding QA-R2-01: **resolved**. New finding count: **0**. Terminal telemetry reports review-mode directed, context-mode inherited, exact r3 checkpoint, assigned finding and newly executed QA-07 coverage; the earlier full-matrix terminal evidence remains retained separately.
