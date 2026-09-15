# Independent PDF runtime QA — r2

Verdict: PASS_WITH_NON_BLOCKING_FINDINGS. Run `pdf-editor-20260914`, scope `pdf-links-v1`, checkpoint `pdf-links-20260914-r2`. Independent fresh QA completed the entire frozen matrix. This is the orchestrator's persisted summary of the QA agent's report, not a replacement execution.

Full original report: `/tmp/oceanix-pdf-qa-tkLndM/qa.md`. Raw evidence: `evidence.json`, `copy.json`, `mobile-busy.png`, `mobile-inserted.png`, `storage-error.png`, `desktop-editor.png` in that directory.

| Scenario | Runtime result |
| --- | --- |
| QA-01 | All three editors: selected text, actual PDF upload, Save/reload preserve labels and surrounding HTML. |
| QA-02 | Filename/default and custom labels persist; cancellation leaves HTML identical. |
| QA-03 | Learner click opens popup; original page remains; response 200, application/pdf, inline disposition, PDF bytes. |
| QA-04 | Positive authorized 200; unrelated 403; anonymous redirect without PDF; bare identifier 404; revoked learner 403. |
| QA-05 | Company/shared course/shared module/token preview positive responses; unrelated document 404; expired token 410. |
| QA-06 | Disguised text, 11 MB upload and injected private-storage failure show associated errors and preserve HTML; retry succeeds after restoration. |
| QA-07 | 390×844 modal controls fit; keyboard invocation/submission, polite upload status, disabled duplicate submission and editor focus observed. |
| QA-08 | Real draft Action retains source HTML/document UUIDs; published upload refused; original and copy return identical original PDF bytes. |
| QA-09 | Surrounding bold/italic text and ordinary anchor preserved; no unrelated media workflow added. |

Testing used only a disposable isolated SQLite/session/storage fixture on localhost, independent Playwright scripts and actual rendered UI/API. No production files, authored tests or reviewer conclusions were inspected by QA. No browser errors or failed requests were collected in the final fixture. No separate application log was available. Own browser/server stopped; evidence retained. No `.env` or shared database changes.

QA-FU-01 is a low non-blocking fixture finding: revoke needs explicit tenant setup and copy lookup must use stable manifest identity rather than mutable title. Temporary setup scripts enabled complete actual runtime verification. Detected by QA; failure stage tests. It does not indicate a product authorization/copy failure.

The independent design finding PDF-DES-01 requires directed continuation/caret revalidation after correction; the QA matrix above must not be misrepresented as having tested plain-text continuation after asynchronous modal updates.
