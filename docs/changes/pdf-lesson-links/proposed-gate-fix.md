# Proposed Toscanini bookkeeping correction (not applied)

The product's PDF scope is unchanged. This is a separate operational correction requiring explicit owner authorization.

Observed command: `python3 .toscanini/bin/toscanini-gate.py --run-id pdf-editor-20260914 --require-contract --require-architecture --require-design`.

Two gate errors contradict the repository's permitted remediation workflow:

1. Its `independent_starts` list counts Worker implementation/remediation events as whole-scope reviews; the allowed second Worker pass therefore reports `repeated whole-scope review: worker (2/1)`.
2. Its QA-order check accepts only independent approval events at the final checkpoint. It ignores valid directed approvals after the initial independent review requested changes, despite the same gate validating and accepting those directed closures earlier.

Proposed minimal change to `.toscanini/bin/toscanini-gate.py`:

- Exclude `worker` from the list of whole-scope reviewer starts. Keep Worker starts counted in specialist and remediation budgets.
- In the QA-order check, accept a completed approval at the QA checkpoint when it is independent OR a directed approval whose finding IDs are resolved through the existing `directed_findings_resolved` validator. Existing full gate validation still requires original independent fresh review and validates directed sequence/scope/checkpoint. Do not remove any QA, architecture, review or budget requirement.

Verification would exercise positive valid-remediation history and negative missing-original-review, unresolved-findings and review-after-QA histories; rerun the gate. No event history would be rewritten or fabricated. This proposal is NOT applied, and no completion gate is claimed passed.
