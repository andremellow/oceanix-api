# Directed architecture impact and retention — GATE-COMPAT-01

Verdict: **PASS**. Causal deviations: **0**; non-blocking findings: **0**.

Run `20261020-invitation-validation-repair`; scope `invitation-validation-repair-v1`; round 2; directed finding `GATE-COMPAT-01`; actual context inherited. Application checkpoint `invitation-validation-repair-v1-67670919374299c4`; verification-tool checkpoint `gate-directed-order-db2f936ade34ccda`. This is a real current directed assessment of the verification dependency after its correction, not a second full application audit or a backdated approval.

## Assigned causal boundary and authority

Read owner-approved `gate-amendment-proposal-02.md`, `gate-amendment-02-approval.md`, runtime amendment and separate tool checkpoint. The approval binds proposal SHA-256 `091e82ce3ffaabc8a0319c395ee702e32bbfc1ff8fe6d899a9fabd471d03da36` and precisely two Account files. Inspected their raw final delta/test contents and only the unchanged helper/gate call context necessary to assess the approved architecture approval, prerequisites and chronology implications. Did not read Worker, directed review or tool QA conclusions/verdict reports. Did not edit application, tool, tests, approved architecture or lifecycle history.

Impact reason: changing the QA-prerequisite checker affects the assurance dependency supporting completion, although application architecture and all application files remain unchanged. It therefore warrants directed gating-impact assessment and explicit retention of earlier application conformance, rather than reopening application preferences or repeating its full check.

## Deterministic identity checks

Both changed tool files match `verification-tool-checkpoint.json`:

| Account path | SHA-256 |
| --- | --- |
| `.toscanini/bin/toscanini-gate.py` | `1f4e62195110d00aa78dc44e0748d4f53e913ed1afccf45df2dd9ddc5cda1464` |
| `.toscanini/tests/test_gate_directed_order.py` | `e12bdcba2952aaa877c60a8190038150c0c635e3c046c7b2523f32eaa085598d` |

Recomputed all 18 Account and 9 Compliance application hashes against `checkpoint.json`: **27/27 unchanged**. Approved architecture remains SHA-256 `890bf44ce261d0747087f72f295acc071548da062480c6bdff06b3f1ca2889b5`. Both runtime contracts retain exact product-owner architecture hash/scope binding. The earlier full report `architecture-conformance.md` is retained, SHA-256 `bd58bfe4a7703a4e258e0a0e9f185c63440ac6ffffb2385b7995a620f31ef7c6`; it is not rewritten by this assessment.

## Directed conformance evidence

| Approved invariant / architecture implication | Raw final evidence | Assessment |
| --- | --- | --- |
| Architecture §11: owner hash/scope approval and final conformance remain mandatory | Gate still invokes unchanged `validate_architecture` for required contract; helper preserves artifact hash, explicit owner decision and scope checks. No architecture-validator delta exists. | No weakening |
| Approval/prerequisite and finite assurance requirements remain operative | Gate's contract, readiness, ledger, checkpoint, budgets, review-context, coverage and final gate validation code remains unchanged. Delta is a focused QA-order helper plus its call. | No weakening |
| QA must follow complete legitimate review evidence, with directed corrections rather than repeated full audits | `review_approved_before_qa` is passed only `history[:first_qa]`. It requires fresh matching-scope completed independent review and matching independent start. Existing same-checkpoint independent approval remains valid. Otherwise directed closure must follow that independent terminal, have matching start/scope/checkpoint, completed state, accepted verdict, finding count and existing linked resolved findings/revalidation reason checks. Later closure cannot authorize earlier QA because it is absent from the prefix. | Preserves chronology and directed-retention model |
| Missing, failed, contaminated or incomplete evidence cannot become approval | Helper rejects missing/nonfresh independent evidence, unmatched directed starts, scope/checkpoint mismatch, unsuccessful closure and absent/unresolved linked findings. Existing whole gate still validates required role final status and frozen checkpoint/coverage. | No causal bypass found |
| Architecture §§9/11: evidence boundaries stay explicit; tools do not replace application QA or rewrite application decisions | New test file uses isolated temporary synthetic contracts/history/ledger and actual gate subprocess; seven named controls cover independent/direct closure positives and late/missing/nonfresh/unresolved/mismatched/failed/incomplete negatives. It neither edits real run history nor exercises/changes application behavior. | Tool-specific test seam conforms |

This assessment inspects those controls; it does not claim to have rerun them or adopt another specialist's verdict. Actual tool execution evidence and final completion commands remain their owners' responsibilities.

## Retention decision

**Retain the original full application architecture-conformance PASS and its entire evidence unchanged.** The only causal delta concerns how a legitimate prior directed review closure satisfies QA-order prerequisites; there is no application/API/data/authorization/UI architecture delta. Architecture §§2–9 and AC/INV mappings in the full report therefore remain valid by identical approved architecture and all 27 application hashes. Section 11's approval and assurance dependencies remain mandatory in the corrected tool.

No IMPLEMENTATION_DEVIATION or unrelated improvement is proposed. This directed PASS closes only the assigned architecture-impact question for `GATE-COMPAT-01`; it does not waive operational conditions, manufacture approval, replace executable QA, or declare delivery complete. Run the actual required completion gates using current evidence.
