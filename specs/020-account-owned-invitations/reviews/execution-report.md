# Toscanini execution report — 20261020-account-owned-invitations

- Result: **BLOCKED**
- Goal: Local-only initial import and Account-owned operational invitations across Account/Compliance using HTTP queues without company-token allowlists.
- Assurance: critical
- Elapsed telemetry time: 235m 15s
- Specialist starts: 16
- Remediation rounds observed: 2

## Efficiency

- Score: **56/100**
- This is a directional execution score; its deductions are listed below.
- Elapsed/baseline ratio: 1.3x (-1)
- Repeated specialist starts: 8 (-16)
- Additional remediation batches: 1 (-8)
- Late findings: 3 (-9)
- Out-of-contract findings: 0 (-0)
- Execution ended blocked (-10)

## Agent usage

- architect: 3
- code-reviewer: 4
- design-reviewer: 1
- qa: 1
- test-analyst: 3
- worker: 4

## Findings

- Total: 14
- Resolved: 11
- Open or deferred: 3
- Non-blocking follow-ups: 0

- `CR-01` [in-contract] Product-origin delivery incorrectly requires an Account login User — detected by code-reviewer; attributed to implementation — resolved
- `CR-03` [in-contract] Manual retry routes a requeued invitation into recovery that cannot complete — detected by code-reviewer; attributed to implementation — resolved
- `CR-04` [in-contract] A transient invitation failure becomes permanent needs-attention in Compliance — detected by code-reviewer; attributed to implementation — resolved
- `TA-01` [in-contract] High: new live authority boundaries are replaced by unconditional proof fakes — detected by test-analyst; attributed to tests — resolved
- `TA-02` [in-contract] High: invitation-only and conflicting existing-person mapping branches lack representative tests — detected by test-analyst; attributed to tests — resolved
- `TA-03` [in-contract] High: receiver ordering and snapshot-conflict branches are not proved under competing execution — detected by test-analyst; attributed to tests — resolved
- `TA-04` [in-contract] High: delivery recovery assertions use no-invitation shortcuts and omit frozen acknowledgement failures — detected by test-analyst; attributed to tests — resolved
- `TA-05` [in-contract] High: durable recovery and cutover commands have no executable regression coverage — detected by test-analyst; attributed to tests — resolved
- `TA-06` [in-contract] Medium: changed Compliance synchronization/polling UI has no browser interaction coverage — detected by test-analyst; attributed to tests — resolved
- `TA-07` [in-contract] High: new platform provider writer has only successful creation coverage — detected by test-analyst; attributed to tests — resolved
- `CR-07` [in-contract] Recovering historical send overwrites newer same-generation observation — detected by code-reviewer; attributed to implementation — resolved
- `QA-F01` [in-contract] Authorized product-only administrator cannot synchronize — detected by qa; attributed to implementation — open
- `QA-F02` [in-contract] Exact imported-person mapping cannot bootstrap invitation reconciliation — detected by qa; attributed to implementation — open
- `QA-G01` [in-contract] Frozen QA-11 conflict matrix remains incomplete under safe database setup. — detected by qa; attributed to orchestration — open

## Efficiency signals

- Roles started more than once: code-reviewer (4), test-analyst (3), worker (4)
- Findings discovered after round 1: 3
- Out-of-contract observations retained as follow-ups: 0

## Proposed learnings — user approval required

- `CR-01` → **Cross-system scenario and fixture planning**: Cross-system lifecycle fixtures should exercise a newly invited actor through provider acceptance and first product use, without manufacturing an Account login or an already-active local authority snapshot. — decision: `pending`, applied: `false`

Toscanini never applies a learning automatically. Accepting a proposal requires an explicit user decision and a separate change.

## Gate result

- unresolved blocking finding: QA-F01
- unresolved blocking finding: QA-F02
- unresolved blocking finding: QA-G01
- architecture conformance requires exactly one full check after implementation
- missing architecture-conformance evidence
- incomplete gate: qa (blocked)
- incomplete QA coverage: QA-11
- incomplete gate: design-reviewer (blocked)

## Orchestrator disposition

Source changes remain frozen at remediation2. Canonical automated verification passed in both repositories before independent runtime QA. Final actual completion gate exited1, so delivery/publication is not approved. Architecture conformance was not dispatched because executable QA/design remain blocked. Both allowed correction rounds were consumed; further source corrections require owner replan. The Account verifier amendment remains explicitly rejected, and no verifier/config/.env change was made.

Pending reusable proposals (including review handoff discipline) are recorded in learning-proposals.md; none was applied to policy. The independent QA report records observed403 without an Account login and409 after exact mapping; it does not establish an exhaustive internal root cause. Further diagnosis must preserve the approved read-only invitation/access invariants.
