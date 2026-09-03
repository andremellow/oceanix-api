# Orchestration contracts

Give each specialist the accepted spec or requested review target, relevant artifacts, repository paths, and required output. Start every reviewer, Test Expert, and QA with `fork_turns: none`. Their neutral briefing contains raw scope and evidence locations, not previous conversation, external review comments, suspected bugs, expected findings, or author conclusions.

Architect: `APPROVED`, `APPROVED_WITH_CONDITIONS`, or `BLOCKED`.
Architecture reviewer: `APPROVE` or `REQUEST_CHANGES` with evidence.
Test Expert: acceptance-to-test audit plus `APPROVE` or `REQUEST_CHANGES`; read-only.
QA: executable scenario matrix with setup, action, expected, observed, evidence, and cleanup; `PASS`, `PASS_WITH_NON_BLOCKING_FINDINGS`, `FAIL`, or `BLOCKED`. QA does not review or edit code or tests.
Code and design reviewers: `APPROVE` or `REQUEST_CHANGES` with evidence.

Test Expert findings return to the Worker for test repair, then deterministic verification and a fresh independent audit. QA failures return to the Worker and repeat verification, Test Expert, and QA. Do not skip later repetitions because an earlier run passed.

Use one unique telemetry run id per user task. Review terminal events include a structured verdict and `context-mode=fresh`. Before completion, execute the installed completion gate for that run id; require architecture and design gates when those roles participated. Missing, stale, or context-contaminated approvals never satisfy the gate.

Emit observability events when the installed project enables the command center. Events contain timestamps, role and state metadata, artifact paths, and concise summaries. Never emit hidden reasoning, secrets, full prompts, or environment values.
