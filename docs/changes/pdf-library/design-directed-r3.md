# Directed design verification — APPROVE

Run `pdf-library-20260916`; scope `pdf-library-v1`; checkpoint `pdf-library-20260918-r3`; round 2, remediation; context inherited; review mode directed. Assigned finding: QA-R2-01. Revalidation reason: New initial PDF opening failure callout.

QA-R2-01 is resolved for the assigned design outcomes. No blocking findings; one non-blocking presentation follow-up below.

Used the approved spec/design/architecture already read in this review context, assigned QA finding, runtime setup, r3 scope and checkpoint. Compared exact r2 snapshots with current shared root Blade and course-editor JavaScript. Current SHA-256 values match r3: JS `a2df3c3b1c6fe9e3fcd1724c3635ecf472544fbf32560e665ed223a1414dff7c`; Blade `04c9b3946919cf7b6fac227507fe7ba140dbb54a07d7066fbf9371551c6156cb`. No other latest reviewer verdict consulted. No product/test edits or test-suite substitution.

Created a new disposable fixture at `/tmp/oceanix-pdf-qa-CKfmZn`, verified testing/SQLite/file/session isolation in the supplied bootstrap, then used loopback server 18947 and a fresh Chromium profile. No Herd data, application database, or `.env` changes. Observation driver: `design.mjs` in that directory.

| Required outcome | Actual rendered observation |
| --- | --- |
| Visible initial-opening error | Typed unsaved marker, intercepted the actual Livewire payload and aborted exactly `["openPdfModal"]`. Page displayed the explicit interruption message in a `role=alert` container with Try again. Original editor HTML remained exact; no modal falsely appeared. |
| Localized narrow alert | PT-BR displayed “A solicitação do PDF foi interrompida…” and “Tentar novamente”. At 390×844, document width equaled viewport width (390); retry rectangle x29/y402/153×40 was fully reachable and its focus ring visible. |
| Keyboard retry | Focused the native retry button and pressed Enter in both locales. Populated PDF modal opened, page alert disappeared, and exact edited HTML remained. |
| Editable/savable unsaved content | After abort, Save and Insert PDF remained enabled. Typed an additional marker while alert was present. After retry and Cancel, actual Save/reload retained exact HTML in both EN and PT-BR. No discard/reload recovery was needed. |
| Browser behavior | No uncaught page errors in final complete driver execution. Actual transport failure was deliberately injected, not inferred from a selector timeout. |

Visually inspected `/tmp/oceanix-pdf-qa-CKfmZn/en-alert.png` and `pt_BR-alert.png`; recovered modal evidence is `en-recovered.png` and `pt_BR-recovered.png` in the same directory. Initial driver used a platform-dependent end-of-document key and introduced doubled whitespace inside a formatted paragraph; Save normalized that whitespace. Corrected the observation setup to an explicit collapsed end range and unambiguous markers, then completed both locale runs with exact HTML assertions. No application defect inferred from that driver mismatch.

## Non-blocking follow-up DES-R3-01

Severity low; sourceRole design-reviewer; failureStage implementation; discovery round 2 / remediation; classification follow-up; blocking basis none. The amber operational guidance still reads “Insert PDF in progress…” / “Inserir PDF em andamento…” above the explicit interrupted-request alert after transport failure. Both screenshots show this contradictory status. Consider clearing or updating that guidance when initial opening fails. This does not prevent editing, saving, reading the failure, or keyboard recovery and does not reopen QA-R2-01 or require expanding the approved correction.

Retain prior design evidence in `design-directed-r2.md` and the unaffected full-review coverage in `design-review-clean.md`: the r3 delta is limited to classifying initial opening as recoverable, consuming rejected promises, and exposing the existing translated recovery copy at page level. Reuse/archive/modal styling and the rest of the frozen UI matrix were not redesigned or re-audited. Independent runtime QA remains its separate gate.

Browser closed and own temporary PHP server stopped. Disposable data/screenshots retained for audit. Verdict APPROVE; assigned QA-R2-01 closed; zero blocking findings, one non-blocking follow-up.
