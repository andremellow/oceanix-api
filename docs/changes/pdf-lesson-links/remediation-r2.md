# Final directed PDF correction batch

Scope `pdf-links-v1`; source checkpoint `pdf-links-20260914-r2`; remediation round 2.

All assigned review and QA work at r2 is terminal. Independent runtime QA completed QA-01–QA-09 with no product blockers; original report and raw evidence: `/tmp/oceanix-pdf-qa-tkLndM/qa.md`.

## PDF-DES-01

After successful upload/modal closure and asynchronous updates settle, retain the caret immediately after the inserted PDF link, outside every anchor. Existing selection and surrounding content must be preserved in all three editors. Cover both selected text and no-selection custom-label insertion. The current browser assertion only checks PDF label text and misses typing in an unrelated last anchor; regression evidence must assert exact continuation location and absence of any enclosing anchor.

This is a direct regression on the frozen editor-selection surface after round 1 moved successful modal closure after insertion. No product requirement or QA matrix expansion.

## QA-FU-01 (non-blocking fixture reliability)

Within the existing disposable PDF harness only: establish synthetic tenant context before revoke; resolve the shared module using stable manifest identity instead of its mutable title. No broader test infrastructure changes.

## Revalidation

Single Worker owns production/tests. Demonstrate defect sensitivity, run focused PDF checks and browser regression, then freeze r3. Directed Code Review, Test Analyst, Design Review and executable QA inspect only this delta and its causal effects. Retain unaffected earlier approvals and QA evidence explicitly. Final architecture conformance follows QA. No unrelated workflows are added. Toscanini gate changes remain a separate unapproved proposal and must not be applied by the Worker.
