# Proposed final gate compatibility amendment

Owner decision: pending. Application changes and their validation are complete; publication remains blocked. No verifier edits have been made.

## Actual remaining blocker

Both actual `toscanini verify` canonical commands exited0. Code Review, Test Analyst, six-scenario executable QA, independent design and architecture conformance completed at application checkpoint `invitation-validation-repair-v1-67670919374299c4`. The Compliance completion gate exited0. The unchanged Account completion gate exited1 with only:

- `QA started before approved code-reviewer`
- `QA started before approved test-analyst`

The actual ordered event history contains fresh independent complete reviews, their findings, one consolidated correction, resolved findings and directed approvals at the QA checkpoint **before** QA started. Account's gate accepts directed closure when selecting each role's final approval, but its QA-order check only recognizes independent approvals at that checkpoint. Repeating a whole review or relabeling/backdating events would violate the approved workflow and is not proposed.

## Exact additional mutation scope

Account only:

1. `.toscanini/bin/toscanini-gate.py`
2. `.toscanini/tests/test_gate_directed_order.py` (new focused test file)

No application, environment, configuration, policy/AGENTS or other verifier file is added. The previous decision to exclude these paths remains effective until this amendment is explicitly approved. Existing application checkpoint and its27 file hashes remain immutable; verification-tool hashes and their approved validation evidence will be recorded separately. This does not assert that tool changes ran in the earlier application checks.

## Required behavior

For QA-order validation, recognize a valid directed closure completed before QA, at the exact QA checkpoint and scope, following a real fresh independent review. Reuse the gate's existing directed-evidence requirements: matching started event, accepted terminal verdict, linked resolved findings and an explicit revalidation reason. Preserve every independent-review, scope/checkpoint, architecture, budget, coverage, finding-resolution and event-order requirement. No success from missing, contaminated, late, incomplete or failed evidence.

## Frozen tool validation matrix

- GATE-QA01: Existing valid independent approval before QA remains accepted.
- GATE-QA02: Real independent request-changes plus valid resolved directed closure before QA is accepted at the exact checkpoint.
- GATE-QA03: Directed closure after QA remains rejected.
- GATE-QA04: Missing or non-fresh independent review remains rejected.
- GATE-QA05: Missing or unresolved linked findings remain rejected.
- GATE-QA06: Checkpoint/scope mismatch remains rejected.
- GATE-QA07: Failed/incomplete closure or missing matching directed start remains rejected.

Use focused Python tests with causal red/green evidence, directed code/test review of this two-file delta, and real CLI gate probes against isolated synthetic histories plus the actual completed run. These are verification-tool checks, not reruns or replacements of the six application QA scenarios. Retain completed application/code/design/conformance evidence by unchanged application hashes with explicit impact reasons. Do not remove existing negative controls, change actual lifecycle records or copy the other repository's verifier wholesale. Record all additional starts and the second consolidated correction against the existing finite budget; stop if the budget or a new decision gap prevents completion.

After approved implementation and affected verification, run the actual completion gates again. Publish the ready PRs only when all required actual gates pass. Until then the delivery stays blocked, despite passing application validation.
