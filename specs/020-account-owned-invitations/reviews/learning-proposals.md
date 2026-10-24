# Learning proposals — pending owner decision

These are proposals, not accepted policy changes. No AGENTS.md, skill, adapter or workflow instruction has been edited from these findings. Applying any accepted proposal is a separate change.

| ID | Detected by / failure stage | Proposed reusable rule | Proposed target | Status |
| --- | --- | --- | --- | --- |
| LP-01 | Code Review and Test Analyst / implementation and tests (CR-01, TA-01) | Cross-system authority fixtures should include legitimate actors who have never logged into the coordinating system; test the changed local authorizer rather than replacing it with a signing fake. | Cross-system test planning and fixture guidance | Pending |
| LP-02 | Code Review and Test Analyst / implementation and tests (CR-02, TA-03) | Test concurrency through the external receipt contract as well as the local work Action; distinguish deduplicated work identity from each caller's immutable protocol identity. | Architecture/test boundary checklist | Pending |
| LP-03 | Code Review and Test Analyst / implementation and tests (CR-03/04, TA-04/05) | Ambiguous-write recovery tests should persist real dispatch markers, drive the operator's actual retry path and distinguish failure before acceptance, lost acknowledgement after commit, scheduled retry and exhaustion. | Durable operation verification guidance | Pending |
| LP-04 | Test Analyst / tests (TA-03/07) | Running sequential assertions on PostgreSQL is not contention evidence; require actual competing connections/processes for approved locking/ordering scenarios. | Concurrency test guidance | Pending |
| LP-05 | Canonical verification / orchestration | Before closing a mutation inventory, search the full existing test suite for assumptions on the exact behavior being changed, including indirect authorization and provider ownership fixtures. | Scope/readiness preparation | Pending |
| LP-06 | Code Review / orchestration | Coordination messages to a running reviewer should contain only scheduling/checkpoint facts; never include another reviewer's findings or outcomes. Preserve a contaminated terminal result honestly and use isolated directed certification. | Review handoff discipline | Pending |

The independent gates detected these issues before publication. Their detection does not establish that upstream implementation/test stages were sufficient; the finding ledger retains the stages that should have prevented each issue.
