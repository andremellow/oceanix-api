# Directed gate-tool executable QA

**PASS** — seven approved controls matched expected actual CLI outcomes. No new findings.

Run20261020-invitation-validation-repair; scope invitation-validation-repair-v1; application checkpoint invitation-validation-repair-v1-67670919374299c4; tool checkpoint gate-directed-order-db2f936ade34ccda. Directed/inherited, round2, assigned GATE-COMPAT-01 only.

Executed actual Account gate CLI copied with three import helpers into a private metadata-only directory. No source/test review, automated-test substitution, UI/DB/provider access or application scenario rerun. Synthetic approvals/artifact stubs are explicit test data outside actual repositories.

| ID | Expected | Actual exits | Result |
| --- | --- | --- | --- |
| GATE-QA01 | Valid fresh independent approvals before QA remain accepted. | 0 | PASS |
| GATE-QA02 | Fresh independent request-changes followed by resolved directed closures before QA is accepted at exact final scope/checkpoint. | 0 | PASS |
| GATE-QA03 | Directed closure completed after QA started is rejected. | 1 | PASS |
| GATE-QA04 | Missing or non-fresh independent review is rejected. | 1, 1 | PASS |
| GATE-QA05 | Missing or unresolved linked findings are rejected. | 1, 1 | PASS |
| GATE-QA06 | Directed scope or checkpoint mismatch is rejected. | 1, 1 | PASS |
| GATE-QA07 | Failed/incomplete closure or missing matching directed start is rejected. | 1, 1, 1 | PASS |

## GATE-QA01

**Setup:** Private metadata-only project with approved contract template, artifact stub, exact synthetic scope/checkpoint, chronological event stream and typed ledger. Directed positive has complete original fresh reviews, resolved findings, matching starts, accepted terminals and reasons; change only the named metadata condition.

**Action:** Execute actual copied Account gate CLI subprocess: python3 .toscanini/bin/toscanini-gate.py --run-id synthetic-gate-cli --require-contract.

**Expected:** Valid fresh independent approvals before QA remain accepted.

**Observed:**

- independent-before-qa: exit0, approved=true. No gate errors.

**Evidence:** `observations/GATE-QA01/independent-before-qa`.

**Cleanup:** No actual lifecycle/approval data changed. Synthetic histories and outputs retained privately outside repositories.

## GATE-QA02

**Setup:** Private metadata-only project with approved contract template, artifact stub, exact synthetic scope/checkpoint, chronological event stream and typed ledger. Directed positive has complete original fresh reviews, resolved findings, matching starts, accepted terminals and reasons; change only the named metadata condition.

**Action:** Execute actual copied Account gate CLI subprocess: python3 .toscanini/bin/toscanini-gate.py --run-id synthetic-gate-cli --require-contract.

**Expected:** Fresh independent request-changes followed by resolved directed closures before QA is accepted at exact final scope/checkpoint.

**Observed:**

- resolved-directed-before-qa: exit0, approved=true. No gate errors.

**Evidence:** `observations/GATE-QA02/resolved-directed-before-qa`.

**Cleanup:** No actual lifecycle/approval data changed. Synthetic histories and outputs retained privately outside repositories.

## GATE-QA03

**Setup:** Private metadata-only project with approved contract template, artifact stub, exact synthetic scope/checkpoint, chronological event stream and typed ledger. Directed positive has complete original fresh reviews, resolved findings, matching starts, accepted terminals and reasons; change only the named metadata condition.

**Action:** Execute actual copied Account gate CLI subprocess: python3 .toscanini/bin/toscanini-gate.py --run-id synthetic-gate-cli --require-contract.

**Expected:** Directed closure completed after QA started is rejected.

**Observed:**

- directed-terminal-after-qa: exit1, approved=false. Errors: QA started before approved code-reviewer.

**Evidence:** `observations/GATE-QA03/directed-terminal-after-qa`.

**Cleanup:** No actual lifecycle/approval data changed. Synthetic histories and outputs retained privately outside repositories.

## GATE-QA04

**Setup:** Private metadata-only project with approved contract template, artifact stub, exact synthetic scope/checkpoint, chronological event stream and typed ledger. Directed positive has complete original fresh reviews, resolved findings, matching starts, accepted terminals and reasons; change only the named metadata condition.

**Action:** Execute actual copied Account gate CLI subprocess: python3 .toscanini/bin/toscanini-gate.py --run-id synthetic-gate-cli --require-contract.

**Expected:** Missing or non-fresh independent review is rejected.

**Observed:**

- missing-independent: exit1, approved=false. Errors: missing gate: code-reviewer; missing started event: code-reviewer; QA started before approved code-reviewer.

- nonfresh-independent: exit1, approved=false. Errors: invalid directed revalidation evidence: code-reviewer; unapproved gate: code-reviewer (request-changes); checkpoint mismatch: code-reviewer; QA started before approved code-reviewer.

**Evidence:** `observations/GATE-QA04/missing-independent`, `observations/GATE-QA04/nonfresh-independent`.

**Cleanup:** No actual lifecycle/approval data changed. Synthetic histories and outputs retained privately outside repositories.

## GATE-QA05

**Setup:** Private metadata-only project with approved contract template, artifact stub, exact synthetic scope/checkpoint, chronological event stream and typed ledger. Directed positive has complete original fresh reviews, resolved findings, matching starts, accepted terminals and reasons; change only the named metadata condition.

**Action:** Execute actual copied Account gate CLI subprocess: python3 .toscanini/bin/toscanini-gate.py --run-id synthetic-gate-cli --require-contract.

**Expected:** Missing or unresolved linked findings are rejected.

**Observed:**

- missing-linked-findings: exit1, approved=false. Errors: invalid directed revalidation evidence: code-reviewer; unapproved gate: code-reviewer (request-changes); checkpoint mismatch: code-reviewer; QA started before approved code-reviewer.

- unresolved-linked-finding: exit1, approved=false. Errors: unresolved blocking finding: SYN-CR01; invalid directed revalidation evidence: code-reviewer; unapproved gate: code-reviewer (request-changes); checkpoint mismatch: code-reviewer; QA started before approved code-reviewer.

**Evidence:** `observations/GATE-QA05/missing-linked-findings`, `observations/GATE-QA05/unresolved-linked-finding`.

**Cleanup:** No actual lifecycle/approval data changed. Synthetic histories and outputs retained privately outside repositories.

## GATE-QA06

**Setup:** Private metadata-only project with approved contract template, artifact stub, exact synthetic scope/checkpoint, chronological event stream and typed ledger. Directed positive has complete original fresh reviews, resolved findings, matching starts, accepted terminals and reasons; change only the named metadata condition.

**Action:** Execute actual copied Account gate CLI subprocess: python3 .toscanini/bin/toscanini-gate.py --run-id synthetic-gate-cli --require-contract.

**Expected:** Directed scope or checkpoint mismatch is rejected.

**Observed:**

- checkpoint-mismatch: exit1, approved=false. Errors: checkpoint mismatch: code-reviewer; QA started before approved code-reviewer.

- scope-mismatch: exit1, approved=false. Errors: scope mismatch: code-reviewer; QA started before approved code-reviewer.

**Evidence:** `observations/GATE-QA06/checkpoint-mismatch`, `observations/GATE-QA06/scope-mismatch`.

**Cleanup:** No actual lifecycle/approval data changed. Synthetic histories and outputs retained privately outside repositories.

## GATE-QA07

**Setup:** Private metadata-only project with approved contract template, artifact stub, exact synthetic scope/checkpoint, chronological event stream and typed ledger. Directed positive has complete original fresh reviews, resolved findings, matching starts, accepted terminals and reasons; change only the named metadata condition.

**Action:** Execute actual copied Account gate CLI subprocess: python3 .toscanini/bin/toscanini-gate.py --run-id synthetic-gate-cli --require-contract.

**Expected:** Failed/incomplete closure or missing matching directed start is rejected.

**Observed:**

- failed-directed-terminal: exit1, approved=false. Errors: incomplete gate: code-reviewer (failed); QA started before approved code-reviewer.

- incomplete-directed: exit1, approved=false. Errors: unapproved gate: code-reviewer (request-changes); checkpoint mismatch: code-reviewer; QA started before approved code-reviewer.

- missing-directed-start: exit1, approved=false. Errors: invalid directed revalidation evidence: code-reviewer; unapproved gate: code-reviewer (request-changes); checkpoint mismatch: code-reviewer; QA started before approved code-reviewer.

**Evidence:** `observations/GATE-QA07/failed-directed-terminal`, `observations/GATE-QA07/incomplete-directed`, `observations/GATE-QA07/missing-directed-start`.

**Cleanup:** No actual lifecycle/approval data changed. Synthetic histories and outputs retained privately outside repositories.

## Application evidence retained

All27 application checkpoint file hashes recomputed and unchanged. Only the separately checkpointed Account gate tool changed. Prior actual paired HTTP/queue/UI application QA evidence is retained; none is relabeled as rerun. QA-R01–QA-R06 retained; none rerun. Executed copy SHA-256 1f4e62195110d00aa78dc44e0748d4f53e913ed1afccf45df2dd9ddc5cda1464 matches current tool.

## Actual run remains blocked

Actual Account completion gate exited1 with approved=false. Literal errors:

- unresolved blocking finding: GATE-COMPAT-01
- architecture conformance must follow completed Worker
- architecture conformance must follow completed QA
- invalid directed revalidation evidence: code-reviewer
- unapproved gate: code-reviewer (request-changes)
- checkpoint mismatch: code-reviewer
- invalid directed revalidation evidence: test-analyst
- unapproved gate: test-analyst (request-changes)
- checkpoint mismatch: test-analyst

Actual Account completion gate exited1/approved=false. Open GATE-COMPAT-01, pending Worker/QA conformance ordering and unresolved directed-review linkage remain literal errors. This result does not assert overall completion or close the ledger; orchestrator must record actual closure and obtain remaining conformance/retention evidence.

## Scope and cleanup

Directed GATE-COMPAT-01 verification and seven approved tool controls only; not a new independent whole-scope QA approval.

No app/tool/test source edits, actual event rewrites or approval changes. Only required actual QA lifecycle telemetry emitted. Private synthetic fixture artifacts retained outside repositories.
