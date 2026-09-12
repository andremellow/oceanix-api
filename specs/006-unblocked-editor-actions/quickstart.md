# Quickstart: Verify Unblocked Editor Actions

## Primary red/green scenario

In each company-course, shared-course, and standalone-module fixture:

1. Open a clean editable question.
2. Add an answer and confirm the structural addition is persisted.
3. Type a unique value in the new answer without saving.
4. Add another answer and confirm the action is available.
5. Confirm both answers remain visible and the first unique value is still absent from persistence.
6. Save, reload, and confirm the value and both answer identities/order persist exactly.

## Regression matrix

Run focused unit, feature, JavaScript, direct-Action, and browser suites for SCN-01–SCN-07. Include reorder, confirmed/declined removal, media, stale revision, permission revocation, provider failure, unknown response, active upload applicability, focus, and newer in-flight typing.

Finish with formatting/build, `composer verify`, executable QA-01–QA-06, architecture conformance, and the Toscanini gates for run `unblocked-editor-actions-20260911`.
