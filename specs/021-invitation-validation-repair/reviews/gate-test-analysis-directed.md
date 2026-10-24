# Directed gate amendment test analysis

**APPROVE — GATE-COMPAT-01 closed. Zero new findings.**

Run `20261020-invitation-validation-repair`; scope `invitation-validation-repair-v1`; round 2; phase remediation; review-mode directed; context-mode inherited; sourceRole `test-analyst`. Application checkpoint `invitation-validation-repair-v1-67670919374299c4`; separate tool checkpoint `gate-directed-order-db2f936ade34ccda`.

## Authority and exact scope

Read the amended runtime contract including AC-GATE01 and gate-amendment-02, the explicit owner approval, and the entire proposal. Independently matched proposal SHA-256 `091e82ce3ffaabc8a0319c395ee702e32bbfc1ff8fe6d899a9fabd471d03da36`. Inspected the complete Account-only delta: `.toscanini/bin/toscanini-gate.py` against git HEAD, and the complete new `.toscanini/tests/test_gate_directed_order.py`. Read the existing directed-resolution helper and surrounding gate checks to assess whether tests exercise the actual approval boundary. No Worker conclusions, other-role verdicts, application changes, or excluded policy/config/verifier changes were reviewed or made.

Both verification-tool SHA-256 values match verification-tool-checkpoint.json. All **27 application file hashes** independently match checkpoint.json. Retain the earlier TA-R01/TA-R02 closures and original full-scope test analysis: this isolated CLI validation change cannot alter their application branches, fixtures or assertions. This directed report does not replace that prior evidence or claim application QA was rerun.

## Executed evidence and causal sensitivity

Ran `python3 .toscanini/tests/test_gate_directed_order.py` against the actual amended CLI: **7 tests passed**, containing **19 isolated CLI probes**. Each probe creates a temporary valid approved contract, ledger and events, invokes the actual gate in a subprocess, parses its JSON, and asserts the process exit and `approved` value. Both positive histories require exit 0, `approved=true`, and an empty findings list. Each negative requires exit 1, `approved=false`, and the exact intended QA-order finding, so rejection cannot be credited solely to an unrelated malformed fixture.

Also executed a temporary copy of **git HEAD's original gate** against the same valid directed fixture, using the existing unchanged helpers. It returned exit 1, `approved=false`, with **only** `QA started before approved code-reviewer` and `QA started before approved test-analyst`. The original independent positive returned exit 0, `approved=true`, findings `[]`. Thus the corrected positive is causal for the actual ordering defect, rather than simply improving an invalid fixture. No source or test mutation was used for this comparison.

| Frozen control | Effective assertions and boundary | Result |
| --- | --- | --- |
| GATE-QA01 | Complete fresh independent approvals before QA, empty ledger; actual CLI success and empty findings | PASS |
| GATE-QA02 | Fresh independent request-changes at original checkpoint, real resolved linked ledger findings, matching directed starts and approvals at final scope/checkpoint before QA; actual CLI success and empty findings | PASS; original gate fails only the intended order check |
| GATE-QA03 | Move directed terminal after QA while retaining otherwise valid history; require intended pre-QA approval error | PASS |
| GATE-QA04 | Independently remove all independent evidence, freshness, matching start, or terminal; require intended pre-QA approval error | PASS, four negative probes |
| GATE-QA05 | Independently remove finding link, matching ledger finding, resolved status, or impact reason; exercise actual unchanged directed_findings_resolved helper | PASS, four negative probes |
| GATE-QA06 | Change directed checkpoint or scope; require intended pre-QA approval error | PASS, two negative probes |
| GATE-QA07 | Failed, blocked or incomplete terminal; missing start; start scope mismatch; start checkpoint mismatch | PASS, six negative probes |

The controlled histories differ from an accepted fixture only at the intended condition. The new helper examines history truncated before first independent QA, requires fresh completed independent evidence with a matching earlier start, then accepts the latest completed directed closure only at the exact scope/checkpoint with matching start and resolved linked findings/revalidation reason. The old main completion, contract/readiness, ledger, architecture, budget, independent-review, scope/checkpoint and coverage validation code remains in place. Tests invoke these actual checks and helper rather than mocking their result. No copied second verifier or weakened expected result is involved.

## Finding disposition and limits

GATE-COMPAT-01: **closed** against approved AC-GATE01 and all seven frozen tool controls. No remediation-introduced causal test gap identified in this two-file scope. Zero new findings; no new product decisions or scope additions.

The synthetic tests validate verification-tool behavior only. They do not claim six-scenario application QA coverage, actual-run completion, new architecture approval, or final publication readiness. The orchestrator must execute the actual run's unchanged required completion checks and record retention/order metadata separately; this directed test verdict does not substitute for those gates.

**APPROVE**
