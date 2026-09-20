# PDF link implementation tasks

Owner approved request, architecture and frozen QA scope on 2026-09-14: “Pode executar”. Architecture hash and approval are recorded in the execution contract. Implement only the PDF scope.

- [x] T-01 Add immutable private document metadata/lesson associations, private disk and upload Action with draft/ownership validation. AC-03–05; QA-04/06/08.
- [x] T-02 Add canonical reference validation, saved lesson membership, copy associations and contextual private delivery through existing training/preview authorities. AC-02/03/05; QA-03/04/05/08.
- [x] T-03 Add one PDF toolbar/modal to shared editor, stable selection insertion and existing operation guards, English/PT-BR strings. AC-01/04/06; QA-01/02/06/07/09.
- [x] T-04 Add focused Pest and browser regression evidence using representative PDFs and authorized/denied controls. Cover QA-01–09, with adjacent media only where shared hooks change; demonstrate defect sensitivity. See implementation-evidence.md; independent QA remains pending in T-05.
- [ ] T-05 Run deterministic verification and independent Code Review/Test Analyst at one checkpoint, then executable PDF-only QA and design review, final architecture conformance and completion gates.

No unrelated fixes, tests or review expansion. One worker edits production/tests. No deployment, commit or PR is requested.
