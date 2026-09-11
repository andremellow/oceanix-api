# Behavior scenarios and test evidence

Store this as `scenarios.md` beside the active `spec.md`. Rules and product decisions live in spec.md; reference them here. Replace illustrative placeholders before approval. Scenario counts describe known scope, not exhaustive correctness.

## Coverage examination

| Dimension | Relevant rules | Scenario IDs / open questions | Status or N/A reason |
| --- | --- | --- | --- |
| Happy paths and alternative flows | | | UNREVIEWED |
| Roles, permissions and isolation | | | UNREVIEWED |
| Data classes, boundaries and invalid input | | | UNREVIEWED |
| Lifecycle and state transitions | | | UNREVIEWED |
| Empty, loading and error states | | | UNREVIEWED |
| External failures, partial effects and recovery | | | UNREVIEWED |
| Time, retries, ordering and concurrency | | | UNREVIEWED |
| Affected existing behavior and integrations | | | UNREVIEWED |

## Concrete examples

| Scenario ID | Spec rule reference | Preconditions / representative data | Action | Observable expected outcome | Forbidden side effects |
| --- | --- | --- | --- | --- | --- |
| SC-001 | FR-001 | | | | |

## Questions and decisions

| Question ID | Affected rules/scenarios | Material decision needed | Answer / spec reference | Status and consequence of deferral |
| --- | --- | --- | --- | --- |

Record user decisions in spec.md as well. Re-evaluate affected scenarios after each answer. A proposed scope addition is not an accepted rule.

## Test plan and execution evidence

One check may prove several scenarios; several checks may be needed for one scenario. Link them explicitly.

| Scenario IDs | Test level and real boundary | Fixtures / controls | Assertions | Task ID | Test file / name | Defect-sensitivity evidence | Execution command / result |
| --- | --- | --- | --- | --- | --- | --- | --- |
| SC-001 | | | | | | | NOT_RUN |

## Automation exceptions

| Scenario IDs | Why automation is infeasible | Alternative evidence | User decision and residual manual work |
| --- | --- | --- | --- |

## Readiness

- Known material questions resolved, or explicit deferrals and their consequences recorded.
- User confirmation of consolidated behavior: PENDING.
- Accepted scenarios have test plans or explicit exceptions.
- Implementation evidence: NOT_RUN. Populate from actual execution; planned tests are not passing tests.
