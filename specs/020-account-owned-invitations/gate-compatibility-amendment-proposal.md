# Proposed narrow Account verifier compatibility amendment

Status: PROPOSED, not authorized. Application requirements/architecture and QA-01–23 remain unchanged.

## Reproduced execution blocker

The Account checkout's installed `.toscanini/bin/toscanini-gate.py` accepts directed finding closure in its final gate checks, but its separate QA-order check accepts only a new **independent** approval at the final checkpoint. The approved workflow explicitly requires directed verification after one complete review and prohibits repeating whole-scope reviews by default. After the current findings are fixed and independently verified as directed, the old QA-order check would still claim no approved Code Review/Test Analyst existed.

A read-only probe executed the actual installed QA-order AST branch against explicitly synthetic history: independent REQUEST_CHANGES at an old checkpoint, subsequent directed APPROVE at the new checkpoint, then QA start. It returned both `QA started before approved code-reviewer` and `QA started before approved test-analyst`. No real review event, approval, ledger, product source or verifier file was altered. Private evidence: `/private/tmp/account-owned-invitation-run-3r98wy0s/account-gate-order-reproducer.json` and its probe script.

## Exactly two requested Account paths

1. `.toscanini/bin/toscanini-gate.py` — preserve every existing gate; replace only the stale QA-order predicate with the same valid-directed-closure requirements already used by this verifier's final gate. Factor that bounded decision into a testable helper.
2. `.toscanini/tests/test_gate_directed_order.py` — focused Python regression tests for that helper. This is tooling, not a Laravel/Pest product test.

No application source, `.env`, database migration, dependency, product scenario, architecture or secret is added or changed. The rejected Account configuration addition remains rejected. Do not copy the Compliance verifier wholesale or include its unrelated local changes.

## Required behavior and concrete checks

- **TG-01:** An existing fresh independent APPROVE at the current scope/checkpoint clears the prerequisite.
- **TG-02:** A fresh complete independent REQUEST_CHANGES with real findings, followed by completed directed APPROVE at the current scope/checkpoint and resolved referenced ledger findings, clears the prerequisite.
- **TG-03:** Missing original full independent review or directed start never clears it.
- **TG-04:** Unresolved/missing finding IDs or missing impact reason never clears it.
- **TG-05:** Wrong scope or checkpoint never clears it.
- **TG-06:** Failed/blocked/nonapproved directed result never clears it.
- **TG-07:** Nonfresh original independent context never clears it.

The helper inspects only history before QA start. It preserves the original review verdict and timestamp, verifies a matching directed start, and uses the existing `directed_findings_resolved()` ledger check. Do not relabel a directed check as a new independent review, edit original verdicts, synthesize an approval, suppress a failed gate or weaken any application validation.

## Validation and scope treatment

The sole Worker may implement this bounded compatibility correction if the owner approves. Specification is not required for this mechanical tooling correction because it restores the already mandated/approved review sequence; product specification, architecture and all independent implementation gates remain required. Add these exact tooling paths as a supplemental approved boundary and the seven deterministic tool scenarios above as supplemental verifier checks. Run their Python tests including the pre-fix failing positive control, then require directed Code Review/Test Analyst verification of this small delta alongside their assigned findings. The final **actual Account verifier command must still exit zero**; no substitute script or waiver is proposed.
