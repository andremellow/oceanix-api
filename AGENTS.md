# Oceanix Training Compliance — Agent Guidelines

## Stack

Laravel 13, PHP 8.3+, Livewire 4 + Flux Pro, Sanctum, Pest 4, PostgreSQL (SQLite in tests).
WorkOS AuthKit is the sole sign-in method — see `app/Services/SocialLogin/`.

`docs/product-spec.md` is the product source of truth (PT-BR, as provided by the user — it stays
in Portuguese). Everything else is English-first.

## Test conventions

- Framework: **Pest** (never PHPUnit class syntax).
- Tests live under `tests/Feature/` or `tests/Unit/`; no `namespace` declarations.
- `RefreshDatabase` is applied globally in `tests/Pest.php`.
- Helpers: `adminUser()`, `employeeUser()`, `userWithPermissions([...])`, `grantPermissions()`,
  `seedAccessCatalog()`.
- `Http::fake()` any WorkOS or Cloudflare call — never hit a real API from a test.

## Pull requests

- Open pull requests as **ready for review** by default; create a draft only when asked.
- Run `composer test`, `./vendor/bin/pint` and `npm run build` before opening a PR.

## Environment files — do not touch `.env`

Never rename, move, copy over, delete or edit `.env` unless explicitly asked to change a specific
variable.

## Control Center UI

Before creating or changing authenticated screens, read
[`docs/control-center-design-system.md`](docs/control-center-design-system.md). It is the source of
truth for tokens, page composition, role-aware navigation, data presentation, responsive behavior,
accessibility and UI test expectations.

Livewire 4 single-file components live in `resources/views/components/**` with a `⚡` filename
prefix. Layouts resolve through the `layouts::` namespace registered in `AppServiceProvider`.

## Livewire components stay thin — no business logic in the view class

A component method validates, calls a Service or Action, and returns data for rendering.

- **Service** (`app/Services/<Domain>/...`): stateless domain logic and read projections — e.g.
  `ComplianceOverview`, `RequirementEligibilityService`, `PlaybackAuthorizationService`.
- **Action** (`app/Actions/<Domain>/...`): a single-purpose class for one write/side effect — e.g.
  `CreateManualAssignment`.

Do not duplicate query logic across components; extract it into a Service.

## Domain invariants — do not break these

1. **A published `CourseVersion` is immutable.** Any content change requires a new draft version.
   Assignments and certificates freeze `course_version_id`.
2. **A requirement is a rule; an assignment is the obligation.** Changing someone's department
   never rewrites an already materialized assignment.
3. **`compliance_events` is append-only.** Write through `ComplianceEventRecorder` only; the model
   blocks updates and deletes. Ingestion is idempotent on the client-generated `uuid`.
4. **`occurred_at` is the device's claim, `received_at` is the server's clock.** Never take
   `received_at` from a client payload.
5. **Only client-reportable event types are accepted from a client** — see
   `ComplianceEventType::isClientReportable()`.
6. **Video access is minted per request** through the `VideoProvider` contract. No permanent
   public URL is ever persisted.
7. **Compliance is derived from materialized assignments**, never from the user's current
   department or job function.
8. **Watch progress is credited only for playback that could have happened in real time.**
   A reported position that jumps further than wall-clock time allows is stored as evidence
   and ignored for progress — see `LessonProgressProjector`.
9. **Assessment availability is independent of watch progress.** As defined by
   `docs/product-spec.md` §7, the assessment is available when the lesson opens; the saved watch
   threshold remains tracking and reporting data while its projection is reviewed.
10. **Nothing operational is deleted.** Waiving, cancelling and revoking mark and record a
    reason; anonymization destroys identity but preserves the evidence. Deleting a user would
    cascade the assignments and erase the proof an obligation was ever met.
11. **A translation group must never share a name with a visible label.** `lang/xx/certificates.php`
    makes `__('Certificates')` return the whole file as an array — and only on a
    case-insensitive filesystem, so it breaks in one environment and not the other.

## Localization — English-first source

English is the canonical source language for every user-facing string. Portuguese belongs only in
`lang/pt_BR/**` and `lang/pt_BR.json`, mapping English source text to the localized value — never
Portuguese as a key.

## Authorization architecture — mandatory for new features

Every new authenticated operational feature participates in the access-profile system: one atomic
permission per grantable action in `App\Enums\Permission`, prerequisites declared, Gates for global
abilities, Policies for record abilities, `EnsureUserHasPermission` on the route, and Pest coverage
for grant, denial, direct access, prerequisites, admin bypass and revocation. Section 8 of the
design system document has the full contract.

<!-- toscanini:start -->
## Toscanini

Toscanini is the default delivery policy for feature development, material bug fixes, refactors, and architecture changes in this repository, including work started through another tool. Optional adapters extend this policy but never replace its contracts or gates.

### Establish the execution contract

Before implementation, create `.toscanini/runtime/runs/<run-id>/execution-contract.json` from `.toscanini/templates/execution-contract.json`. Freeze the goal, in-scope and out-of-scope boundaries, stable `AC-*` acceptance criteria, `INV-*` invariants, direct regression surfaces, and the complete executable QA scenario matrix. Required specification and architecture artifacts, assurance, and budgets belong in the same contract. The contract and validation scope must be approved before the Worker starts. No specialist may grow the QA matrix or regression surface during implementation or review without returning to the user for contract amendment.

If the Spec Kit adapter is enabled, require an approved specification, clarification, and plan for large work and for medium work with unresolved product, domain, data, security, or cross-system decisions. For narrow mechanical work, record why specification is not required. When required Spec Kit artifacts are absent, ask whether the user wants to run the Spec Kit flow or explicitly waive it with a recorded reason. Never silently waive it. Validate the contract with:

`python3 .toscanini/bin/toscanini_contract.py --run-id <run-id>`

An architecture artifact must state boundaries, non-negotiable invariants, risks, test implications, and out-of-scope improvements. The Architect authors the implementation-ready artifact using `.toscanini/templates/architecture.md`; the product owner approves it and the execution contract directly, binding the decision to the artifact SHA-256 and scope ID. No second agent reviews the architecture document. Missing implementation tasks or operational dependencies are conditions, not architecture blockers. After approval, agents validate against the contract; they may not silently expand it. A newly discovered contract gap returns to specification or architecture for explicit amendment and reapproval.

Before architecture approval, require a per-repository frameworkAlignment assessment: actual framework/version, consulted framework and adapter guidance (including installed Laravel Boost guidance when applicable), intended use of references and material conflicts. References are not architectural authority. Unresolved conflicts require a specific product-owner decision; do not silently inherit a reference API’s internal layering into Laravel, Flutter or another target stack. Keep TDD strategy separate from architecture. Use the Architect’s framework suitability procedure; no additional reviewer is required.

### Execution readiness before Worker dispatch

During planning and task preparation, identify and resolve dependencies for implementation, build, automated tests and executable QA. Consult the installed Toscanini workflow's execution-readiness procedure. Check required runtime/tools, package installation, private registry credentials/licences, services/devices, test environments/data and pending permissions in the actual Worker environment. Complete already-authorized setup and collect remaining user actions before promising unattended execution. Record nonsecret probe evidence in the contract's `readiness` assessment; never store or request credential values in artifacts or chat. Required missing access or pending installation blocks Worker dispatch, even when architecture is approved. Architecture-only approval remains possible. Refresh affected checks after plan/environment changes; no additional reviewer is needed.

### Share contracts, not persuasive history

Only one Worker may edit production code or tests. Reviewers and QA start with no inherited conversation history (`fork_turns: none`), but context isolation must remove bias, not the contract. For architecture authoring, give the Architect the plan and draft contract; author readiness precedes product-owner approval. Give implementation and validation specialists the approved request/specification, acceptance criteria, architecture, execution contract, relevant repository rules, raw diff or artifact scope, and inspection commands. Do not include the Worker's conclusions, implementation defenses, suspected defects, expected findings, external review comments, or another reviewer's verdict.

Remediation verification is deliberately directed and is not an independent review. It receives the finding IDs and required outcomes it must recheck. The post-implementation Architect receives approved specification, architecture, contract and raw final implementation/tests. This is conformance to approved decisions, not a new design review.

### Execute complete review rounds

The default behavioral workflow is:

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

Every reviewer must inspect its entire assigned frozen scope and return every material finding in one verdict, not stop after the first blocker. Record findings in `.toscanini/runtime/runs/<run-id>/finding-ledger.json` from the installed template. Each finding requires a stable ID, discovery round and phase, severity, evidence, required outcome, scope, classification, and blocking basis. Only three bases may block: an approved acceptance criterion, an approved invariant, or a direct regression caused by the current diff on a regression surface frozen before implementation. Pre-existing defects, general hardening, adjacent workflows, infrastructure improvements, and newly imagined edge cases are `follow-up` and non-blocking.

Toscanini waits for the whole review checkpoint, consolidates all in-contract findings into one correction batch, and routes each finding to the stage that failed. Do not dispatch remediation while any assigned specialist is still running, and never create one correction loop per finding. Specification gaps return to clarification; architecture gaps return to Architecture; implementation deviations return to the Worker; test gaps return to test implementation; new requirements return to the user. Contract changes require reapproval before implementation continues. Record who detected each finding separately from the `failureStage` that should have prevented it; a QA discovery can prove that QA worked while still exposing an upstream implementation, architecture, specification, or test failure.

The Test Analyst is read-only and audits whether automated tests prove the accepted behavior, execute changed branches with representative state, and protect relevant persistence and failure boundaries. QA does not review code or substitute automated tests: it exercises the real UI or API with safe representative data and observes rendered behavior, persistence, browser console, network failures, and application errors. UI QA must navigate to the affected screen and interact with changed controls. QA returns `BLOCKED` when it cannot prove the environment is safe or cannot execute the changed path.

### Revalidate by impact

After a consolidated correction batch, run deterministic verification once and inspect the remediation delta. Reopen only affected gates. A reopened specialist performs directed verification of the assigned finding IDs and the smallest causal regression checks for that delta; it must not restart an open-ended audit or add unrelated blocking scenarios. A new remediation-round blocker is valid only when the remediation itself introduced it on a frozen regression surface. Otherwise it is a non-blocking follow-up. Retained approvals include a recorded reason. Do not repeat Code Review after QA by default. The Architect performs one architecture-conformance check when an architecture artifact exists; directed revalidation is permitted only for architecture-affecting corrections.

Conformance reports deviations from approved architecture sections or AC/INV IDs as implementation findings. Alternative architectures and unrelated improvements are non-blocking follow-ups. Critical assurance increases depth and evidence, not the number of repeated whole-scope reviews.

After implementation stabilizes, record one immutable implementation checkpoint ID in the contract. Every independent gate reports that checkpoint and the frozen scope ID. After executable QA at the stable final checkpoint, perform the architecture-conformance check when applicable. Directed results may close assigned findings; architecture revalidation must retain its earlier full-check evidence and record finding IDs and an impact reason. Partial QA cannot replace full QA: the independent QA terminal event must list every completed frozen `QA-*` scenario. Never convert a budget limit, unavailable gate, unresolved blocking finding, or missing evidence into approval.

### Assurance and convergence

Canonical verification: composer verify
Design system reference: Not configured; resolve with the project owner when UI work begins
Enabled adapters: laravel, spec-kit, terminal-ui
Enabled specialist agents: architect, code-reviewer, design-agent, design-reviewer, qa, specification-reviewer, test-analyst
Installed extensions: none
Default assurance: standard
Laravel Boost policy: optional

Use the project assurance unless the current request explicitly overrides it. `fast` allows one consolidated remediation round and at most 7 specialist runs. `standard` allows two consolidated remediation rounds and at most 14 specialist runs. `critical` allows two consolidated remediation rounds and at most 17 specialist runs. Automatically escalate to `critical` for authentication, authorization, billing, destructive operations, irreversible migrations, concurrency, sensitive data, and credible data-loss risk, and tell the user. When a budget is exhausted, stop and replan with the user.

### Telemetry and completion

Create one run ID per user task. Use `.toscanini/bin/toscanini-event.py` for privacy-safe lifecycle events and pass `--round` and `--phase` when applicable. Every specialist emits `started` and a terminal `completed`, `blocked`, or `failed` event; independent Code Review/Test Analyst/QA events declare a verdict and `context-mode=fresh`. Architect authoring uses phase `architecture` with verdict `approved` or `approved-with-conditions`; final conformance uses phase `architecture-conformance` with `pass` or `pass-with-non-blocking-findings`, an evidence artifact, architecture hash and final checkpoint. Emit only public-safe summaries and artifact paths, never prompts, hidden reasoning, secrets, environment values, raw tool output, or source contents.

Before declaring behavioral work complete, run:

`python3 .toscanini/bin/toscanini-gate.py --run-id <run-id> --require-contract [--require-architecture] [--require-design]`

Exit code zero is required. The gate rejects missing contracts, unresolved in-contract blockers, exceeded budgets, repeated whole-scope reviews, stale or contaminated reviews, and missing approvals. Telemetry write failure must be retried or reported; it must never be treated as approval.

The execution report includes a directional efficiency score with visible deductions, detected-by versus failure-stage attribution, and proposed learnings. A learning is a reusable rule for a project policy, adapter, agent, or workflow stage—not a copy of an implementation-specific fix. Never modify instructions from a finding automatically. Record a proposal as `pending`; only an explicit user decision may accept it, and applying it is a separate change. Rejected and deferred proposals remain visible for auditability.

Preserve existing project instructions and load installed Toscanini skills when their procedures apply.

Before declaring delivery complete, run `toscanini verify --run-id <run-id>`. This is the visible project exit gate: it validates required Spec Kit approvals, reports Laravel Boost status and policy, then runs the repository's canonical verification command. Do not bypass a failing preflight.
### Document reading handoff

When the user requests a readable report of generated documents, use `.toscanini/templates/document-report.json` to list the actual Markdown files, titles and order for that feature. Paths are relative to the target project. Run `toscanini report --manifest <manifest> --target <project>`; use `--serve` for a local browser URL and retain the process while the user reads. Report the actual file or URL returned. Increment the version for changed documents and retain the report ID and stable document IDs. Do not claim a local URL is remotely published. Reading is not approval and test plans are not execution evidence.

### Spec Kit behavior and test discovery

Apply this project policy whenever using Spec Kit through Toscanini, including specify, clarify, checklist, plan, tasks, analyze, and implement. It overrides upstream question quotas, short-answer length limits, and the default that test tasks are optional. Retain Spec Kit's existing commands, artifacts, and project constraints.

Start with the user's requirement and inspect relevant existing behavior, tests, integrations, and project decisions. Distinguish confirmed rules, observed implementation, proposed behavior, and unresolved decisions; existing code is evidence, not automatic product authority. Ask the user to resolve material conflicts. Do not silently turn assumptions into accepted requirements.

During specify and clarify, maintain one scenario map in the active feature directory using `.specify/templates/toscanini-scenarios.md`. Keep spec.md authoritative for rules and decisions; reference rule IDs instead of duplicating specifications. For each applicable rule, derive concrete positive, negative, and boundary examples with preconditions, an action, observable outcomes, and relevant prohibited side effects. Inspect roles and permissions, data partitions and limits, lifecycle transitions, empty/loading/error states, recovery, external failures, and existing behavior affected by the change. Consider concurrency, retries, ordering, and time boundaries when the feature depends on them. Examine meaningful combinations rather than a Cartesian product. Mark irrelevant dimensions N/A with a reason instead of inventing requirements.

Ask focused questions while material ambiguities remain. There is no fixed question limit and no short-answer word limit. Integrate each answer into the spec and scenario map immediately, remove contradicted assumptions, and reconsider affected rules for newly exposed questions. Do not repeat answered questions or ask the user for facts available in the repository. Distinguish a missing decision within scope from a proposed scope expansion. Respect a user request to stop; retain unanswered questions and report the result as incomplete unless the user explicitly defers them with their consequences recorded. Human clarification turns are not specialist starts or remediation rounds.

During checklist and analyze, require evidence: every applicable coverage dimension points to concrete scenario IDs or unresolved questions. A checked box, a requirement linked to an implementation task, or a count of questions does not establish scenario or test coverage. Finish clarification when no known material ambiguity remains within the agreed scope and the user confirms the consolidated behavior. Report known deferrals without claiming exhaustive coverage of all possible behavior.

Plan tests alongside scenarios. Every accepted scenario must map to a proposed automated check and its appropriate boundary, fixture/preconditions, expected assertions, and later the test file/name and execution result. Reuse existing tests when they prove the behavior. Prefer the lowest-cost level that exercises the required boundary; browser-dependent behavior requires browser evidence. Do not duplicate every scenario across all layers. Gherkin and a BDD library are not required. If automation is infeasible, record the reason, alternative evidence, and explicit user acceptance of residual manual work; never label the scenario automatically verified.

Include test tasks by default in tasks.md and reference scenario IDs. While implementing, demonstrate that new tests fail for the intended missing behavior or defect before the fix when feasible; record other evidence of defect sensitivity when not feasible. Use representative data, positive controls for denial tests, and observable outcomes rather than mirroring the implementation. Never weaken an approved expectation just to make a test pass. Mark skipped or unexecuted checks honestly and reconcile scenario coverage with actual test results before delivery.

Perform this discovery in the existing Spec Kit flow. Do not start Three Amigos bots or add a mandatory specialist review checkpoint. If a Test Analyst is requested for discovery, give it the draft spec and scenario map, ask for one consolidated set of concrete gaps and test proposals, and return product decisions to the user. Such a discovery review is not an approval of implemented tests. Freeze the execution contract only after clarification and test planning; later material discoveries reopen the affected decisions explicitly, without hiding defects behind the original scope.

<!-- toscanini:end -->
