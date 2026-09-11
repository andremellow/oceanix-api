---
name: toscanini-workflow
description: Orchestrate feature development, material bug fixes, refactors, and architecture changes through an approved execution contract, independent complete review rounds, executable QA, causal findings, and impact-based revalidation.
---

# Toscanini Workflow

Preserve the user's request and repository rules. Inspect before planning. Choose the active assurance and classify the change. Read [execution contracts](references/execution-contract.md) and [orchestration](references/orchestration.md).

Before implementation, create and approve the run's execution contract. Freeze its goal, scope, non-goals, acceptance criteria, architecture invariants, direct regression surfaces, complete QA scenario matrix, artifacts, and budgets. Specialists cannot expand that validation scope without explicit contract amendment. If Spec Kit is installed and specification is required, require approved specification, clarification, and plan artifacts or ask the user to explicitly waive the gate with a recorded reason. Do not silently skip it.

Use this order for behavioral work:

1. Specification and clarification when applicable.
2. Planning.
3. Architecture authoring when applicable.
4. Product-owner approval of the architecture and execution contract.
5. Task generation.
6. One Worker implements production code and tests.
7. Deterministic verification.
8. Code Review and Test Analyst review at the same stable checkpoint.
9. Executable QA.
10. If an architecture artifact exists, the Architect checks final implementation conformance once.
11. Completion gate.

Context isolation removes bias, not accepted artifacts. Fresh reviewers receive the contract, specification, architecture, acceptance criteria, repository rules, raw scope, and inspection commands; they do not receive author conclusions or previous verdicts. Directed remediation verification receives finding IDs and is not presented as an independent review.

Require reviewers to inspect their entire assigned scope and report all material findings before returning. No reviewer may add a new blocking criterion outside the approved contract. Classify valuable adjacent improvements as follow-ups. A genuine spec or architecture gap amends and reapproves the contract before work continues.

Use the finding ledger and route `SPEC_GAP` to clarification, `ARCHITECTURE_GAP` to Architecture, `IMPLEMENTATION_DEVIATION` to the Worker, `TEST_GAP` to test implementation, `QA_GAP` to the QA plan, `NEW_REQUIREMENT` to the user, and `ENVIRONMENT_FAILURE` to environment repair or a blocker. Record the detecting role separately from the `failureStage` that should have prevented the problem.

After remediation, run canonical verification once, inspect the delta, and reopen only affected gates. Every reopened role performs directed verification of assigned findings and the smallest direct regression checks caused by the remediation. It must not restart a full audit or add unrelated blockers. The Architect performs one final architecture-conformance check after QA, and only directed revalidation when a later correction affects architecture. No second whole-scope Code Review is required.

Apply `fast` for one remediation round and 7 specialist starts, `standard` for two rounds and 14 specialist starts, and `critical` for two rounds and 17 specialist starts. Critical means deeper evidence, not unlimited repetition. Any exhausted limit triggers stop-and-replan, never approval. Automatically escalate high-risk security, money, destructive, migration, concurrency, sensitive-data, and data-loss work to critical.

Finish with an execution report that makes efficiency deductions and failure attribution visible. Specialists may propose reusable learnings for project policies, adapters, agents, or workflow stages, but Toscanini never applies them automatically. Keep each proposal pending until the user explicitly accepts, rejects, or defers it; application is a separate change.

Record telemetry and require `toscanini_contract.py` before implementation. For behavioral completion, require `toscanini-gate.py --require-contract` plus architecture/design flags when applicable. For installation operations, read [installation](references/installation.md).

### Prepare execution before the user leaves

During planning, inspect the dependency manifests/lockfiles, setup instructions, architecture conditions and planned tasks. Identify prerequisites for the entire accepted path, including build, tests and executable QA—not only the first coding task. Before handing off to the Worker, resolve required runtime/SDK/tools, package installation and private registries, credentials/accounts/licences, local or remote services/devices, test data and QA access, and outstanding user permissions or decisions. Use the same environment, account and tool access the Worker will have. Run small, safe concrete probes: an actual dependency resolution/install when needed and authorized, an authenticated access check without printing secrets, service connectivity, and the available baseline verification path. Package presence or an environment variable name alone does not prove usable access. Existing unrelated test failures should be recorded; they do not automatically block readiness if the accepted verification path can run.

Perform already-authorized setup immediately. Batch remaining user actions into one concrete handoff while the user is available, explaining what is needed and how to configure it securely. Never ask for credentials in Markdown, telemetry or chat; direct the user to the application's secret store or local configuration and report only redacted availability evidence. Do not request approval again for setup already authorized. Resolve requests before promising unattended execution. An approved architecture may still have operational conditions; those are execution prerequisites, not architecture defects.

Record `readiness` in the execution contract with the frozen scope, the Worker environment and checks in all six categories: runtimes, dependencies, access, services, verification, permissions. Include one named check per actual prerequisite, with `verified` and concrete nonsecret evidence, or `not-required` with a reason. Required missing credentials, uninstalled packages or pending permissions stay `pending`/`blocked`; planned installation is not verified installation. Read [execution readiness](references/execution-readiness.md) for the handoff and schema. Run the contract preflight before Worker dispatch; Worker-start telemetry also rejects unresolved readiness. An architecture-only approval does not require execution readiness.

Refresh affected checks if the plan, dependencies, scope or execution environment changes, or a probe fails. Do not rerun all probes per agent or create a new reviewer. If a new prerequisite emerges later, report it promptly and continue authorized independent work where useful, without claiming the whole feature can finish unattended. Readiness evidence reduces foreseeable interruptions; it is not a guarantee against future outages or expired access.
