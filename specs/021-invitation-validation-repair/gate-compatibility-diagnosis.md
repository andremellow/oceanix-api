# Existing Account gate compatibility — observed, not waived

At checkpoint `invitation-validation-repair-v1-67670919374299c4`, the unchanged Account gate was executed after both original reviews and their directed closures, while runtime QA/design were ongoing. It exited 1. Missing terminal QA/design/conformance evidence was expected at that intermediate point. Two additional findings were:

- `QA started before approved code-reviewer`
- `QA started before approved test-analyst`

The actual history contains complete independent reviews, their findings, one consolidated correction batch, resolved finding-ledger entries and completed directed approvals at the QA checkpoint before QA started. The gate accepts those directed closures for each role's final approval, but its later QA-order predicate only searches for independent approval at the current checkpoint. This rejects the approved impact-based remediation sequence. Both original review results and actual directed closure records remain intact; no event is relabeled or backdated.

The functional canonical commands pass. That is separate from this completion gate. Final QA/design/conformance still must finish, and the unchanged final gate must run again. Do not call the delivery approved or publish PRs while the actual gate fails.

The previously declined Account verifier edits remain out of scope. No verifier or its tests has been changed. The final result must distinguish any application/QA defect from this tool incompatibility and request a concrete tooling decision only if it remains the completion blocker after all authorized validation is finished.
