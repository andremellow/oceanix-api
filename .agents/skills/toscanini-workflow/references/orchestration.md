# Orchestration contracts

Authoring specialists receive the plan and draft contract; product-owner approval follows architecture authoring. Implementation and validation specialists receive the approved execution contract, specification when present, architecture when present, acceptance criteria, relevant repository rules, raw diff/artifact scope, and inspection commands. That is neutral evidence, not inherited conversation. Never include implementation defenses, expected findings, external comments, prior verdicts, or persuasive summaries.

Required outputs:

- Architect: complete boundaries, invariants, risks, testing implications, non-goals, and `APPROVED`, `APPROVED_WITH_CONDITIONS`, or `BLOCKED`.
- Architect after implementation: separate conformance evidence against approved architecture sections or AC/INV IDs; `PASS`, `PASS_WITH_NON_BLOCKING_FINDINGS`, `FAIL`, or `BLOCKED`. This is not architecture-document review.
- Test Analyst: complete acceptance-to-test mapping and `APPROVE`, `REQUEST_CHANGES`, or `BLOCKED`; read-only.
- Code Reviewer: complete material findings over the assigned raw diff and `APPROVE`, `REQUEST_CHANGES`, or `BLOCKED`; read-only.
- QA: executable scenario matrix with setup, action, expected, observed, evidence, cleanup, and `PASS`, `PASS_WITH_NON_BLOCKING_FINDINGS`, `FAIL`, or `BLOCKED`.
- Design Reviewer: complete contract/design findings and `APPROVE`, `REQUEST_CHANGES`, or `BLOCKED`.

Each finding has a stable ID, discovery round and phase, detecting role, `failureStage`, severity, evidence, required outcome, scope (`in-contract` or `follow-up`), causal classification, status, and blocking basis. A blocker must cite an approved acceptance criterion, invariant, or direct regression on a surface frozen before implementation. Everything else is a non-blocking follow-up. Inspect the entire frozen scope and return all material findings in one response. Do not stop after the first blocker.

Run Code Review and Test Analyst at one stable checkpoint before executable QA. Wait for every applicable role at that checkpoint, merge duplicate findings, expose the complete consolidated set to the user, and send one correction batch to the responsible stage. Never begin remediation from the first returned finding while another checkpoint reviewer is still running.

Remediation verification is directed: give a verifier its finding IDs, required outcomes, related criteria/invariants, and remediation delta. It confirms closure and only regression risks causally introduced by that delta. It may not become a new broad review. New unrelated observations are follow-ups. After QA, the Architect performs one conformance check on the approved architecture and raw final implementation/tests. It reports deviations, not design preferences. There is no second mandatory whole-scope Code Review.

Reopen gates by impact. Test-only deltas reopen Test Analyst. UI deltas reopen Test Analyst, Code Review, and QA. Domain deltas reopen Test Analyst, Code Review, and API/UI QA. Security, persistence, transaction, concurrency, or boundary deltas receive directed Architect conformance revalidation when they affect the approved architecture. Retained approvals require a recorded reason.

Use one run ID, numbered remediation rounds, and explicit phases in telemetry. Before completion, require the contract-aware gate. Missing, stale, contaminated, over-budget, or unresolved results never pass.

Before Worker dispatch, complete [execution readiness](execution-readiness.md) for the accepted implementation and validation path, resolving operational conditions and user actions while the user is available. Architecture approval alone is not permission to assume access or installations exist.
