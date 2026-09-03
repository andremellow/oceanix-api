---
name: toscanini-workflow
description: Orchestrate feature development, material bug fixes, refactors, and architecture changes with independent planning, implementation, QA, and review. Also handles explicit architecture-only, QA-only, and review-only requests.
---

# Toscanini Workflow

Preserve the user's requirements and repository rules. Inspect before planning. Classify the change and state why any role is omitted.

- Small: worker; deterministic verification; test-expert audit for behavioral changes; executable QA when a user or API path changed; then code review.
- Medium: architect; design agent for UI; plan review when risky; worker; verification; test-expert audit; executable QA; final architecture and code review.
- Large: specification clarification and analysis; architecture and UX artifacts; independent reviews; user approval for material decisions; worker; verification; test-expert audit; executable QA; final reviews; resolve and repeat.

Never let an author review their own work. Spawn architecture review, test expert, QA, and code review in independent contexts and give them accepted artifacts plus repository evidence, not the author's persuasive summary. Only the active worker may edit production code or tests. The test expert is read-only and audits automated-test effectiveness. QA does not review or edit code; it exercises actual product behavior and may create temporary data only after proving the environment is local, ephemeral, or explicitly designated for testing. Neither role substitutes for the other.

All review and QA roles must start with no inherited conversation history (`fork_turns: none`). Give them a neutral evidence packet: accepted requirements, repository instructions, relevant artifact paths, diff scope, and inspection commands. Never prime them with suspected bugs, expected findings, prior agent conclusions, implementation defenses, or external review comments. A request to confirm a known fix is not an independent review. Discard and rerun any contaminated review in a fresh context.

Use stable acceptance identifiers such as `AC-01`. Verification must map each criterion to implementation evidence, effective automated evidence, executable QA evidence when applicable, and result. Require evidence that tests execute the changed branch with representative state; empty fixtures cannot validate behavior inside collection or record paths. A green test command alone is not sufficient. Do not claim completion while blocking findings, missing regression coverage, or an unvalidated changed user path remains.

Enforce the feedback loop. Test Expert `REQUEST_CHANGES` returns to the Worker for test repair, followed by deterministic verification and a fresh independent test audit. QA `FAIL` returns to the Worker for repair, followed by deterministic verification, a fresh test audit, and fresh executable QA. QA returns `BLOCKED` when it cannot safely prepare representative data or exercise the changed path. Continue until all required gates approve or a genuine user/external blocker remains.

If the repository contains `.toscanini/bin/toscanini-event.py`, create a unique run id per user task, emit privacy-safe lifecycle telemetry for the orchestrator, and pass the run id to every specialist. Require every specialist to emit `started` and a terminal `completed`, `blocked`, or `failed` event; review roles must include their structured verdict. Emit `handoff` before delegation and `progress` only for material milestones.

Before declaring behavioral work complete, run `.toscanini/bin/toscanini-gate.py` for the current run id and require exit code zero. Add architecture and design requirements when those reviews apply. Review terminal events must declare `context-mode=fresh`; inherited or unspecified context is rejected. The completion gate, not the orchestrator's recollection, is the source of truth. Missing telemetry is a blocking incomplete gate; telemetry write failures should be retried or reported, never silently treated as approval.

For installation, update, doctor, or rollback work, read [installation](references/installation.md). For role inputs and outputs, read [orchestration](references/orchestration.md).
