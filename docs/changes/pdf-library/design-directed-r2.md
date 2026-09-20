# Directed design remediation verification — APPROVE

Run `pdf-library-20260916`; frozen scope `pdf-library-v1`; checkpoint `pdf-library-20260916-r2`; remediation round 1. Fresh context, directed verification of DES-01–DES-05 only. Revalidation reason: Assigned PDF modal state/localization/styling corrections. Zero remaining findings in this assigned scope.

Read the approved spec, architecture, design and approval, project design system/rules, neutral contract/scenarios, checkpoint and changed-file hash manifest. All nine manifest after-hashes match current files. No full r1 textual snapshot exists; this report makes no claim to a remediation-only textual diff. No production or test files edited and no authored test suite substituted for UI execution.

Executed Chromium against a newly initialized isolated SQLite/files fixture at `/tmp/oceanix-pdf-qa-kbqU5B`, using the supplied standalone harness on loopback port 18943. The harness explicitly selects testing configuration and disposable storage/session paths; no `.env`, Herd or application database mutations. Browser scripts are disposable observation drivers, not application test changes.

| Assigned finding | Observed rendered result | Evidence |
| --- | --- | --- |
| DES-01, AC-06/B-14 | Aborted an actual Livewire search request. Alert explicitly says results may be out of date; Try again works. Custom label, search and original editor HTML remain unchanged. Retry returns the one matching result. Separate captured bold selection survives abort/retry and inserts linked bold `Before` on Reuse. | `transport.png`; `review.mjs`; `selection.mjs` |
| DES-02, AC-05/06, INV-01 | Using separately granted libraryUser, revoke the library profile before requesting archive, and separately after confirmation opens. Both requests return controlled denied modal state, zero PDF rows, no child confirmation and preserved `Permission preserved` label. No generic 403 overlay. | `revocation-before.png`; `revocation-after.png` |
| DES-03, AC-06 | Hold archive POST in browser transport: rendered live status is `Archiving PDF…` and confirm is disabled. Release into disposable server archive fault: named context and retry remain, focus returns to Cancel. Keyboard Escape closes child only, retaining parent and authored label. | `pending.png`; `failure.png`; `review.mjs` |
| DES-04, AC-06 | At 390px with PT-BR session, denied state exposes `Tentar novamente`; retry restores rows. Actual failed reuse operation heading reads `Falha em Reutilizar PDF para Company PDF lesson.` and error/guidance are localized. Confirmation says `Arquivar PDF`. | `pt-denial.png`; `pt-operation.png`; `pt-confirm.png`; `localization.mjs` |
| DES-05, AC-06 | Computed Reuse background is rgb(27,35,40), text white; Archive row text rgb(198,66,66) on white; confirmation background rgb(198,66,66), text white. Screenshots visibly establish primary/danger distinction on desktop and narrow PT-BR. | `styles.png`; `failure.png`; `pt-confirm.png` |

Evidence files above are under `/tmp/oceanix-pdf-qa-kbqU5B/`. Screenshots inspected visually. Browser sessions closed; temporary PHP server stopped after observation. Fixture and evidence retained.

Preserve unaffected r1 design evidence from `design-review-clean.md`: three editor composition, normal selected/custom/caret behavior, search/pagination/normal cancellation, normal nested dismissal and upload validation were outside the assigned corrections. This directed pass rechecks the causal error/focus/style/localization surfaces; it neither repeats nor replaces the original full design review or the required independent executable QA matrix. No new scenario, architecture decision or regression surface was added.

Observation-driver correction: a later repeated reuse selected a source already attached during selection verification, so the insert fault could not fire; switched to the unused second source. An exact-text operation locator omitted the surrounding localized heading; corrected to the rendered operation container. Neither driver mismatch was reported as an application defect; the final actual failed-operation heading was observed and captured.
