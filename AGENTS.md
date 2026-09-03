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
9. **The assessment is gated server-side.** `AnswerQuestion` refuses an answer whose lesson
   has not met its watch threshold, whatever the page renders.
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

Use Toscanini for feature development, material bug fixes, refactors, and architectural changes. Classify work as small, medium, or large and use independent specialist contexts. Only one worker may edit production code at a time. Architecture reviewers, design reviewers, test experts, and code reviewers are read-only. QA does not review or edit code; it may create temporary data only in a proven local, ephemeral, or explicitly designated test environment.

For changed behavior, run the deterministic verification command, then require an independent Test Expert to audit whether the automated tests can catch the regression, followed by independent QA against the executable product or API with representative data. For changed UI behavior, QA must navigate to the affected screen and interact with the changed control. A passing test command does not replace either gate. Test Expert `REQUEST_CHANGES` returns to the Worker for test repair and repeats verification plus a fresh test audit. QA `FAIL` returns to the Worker and repeats verification, test audit, and QA. Treat unavailable executable validation or an unverified data environment as `BLOCKED`, not passed. Do not claim completion while any gate is unapproved.

Spawn Test Expert, QA, Architecture Reviewer, Design Reviewer, and Code Reviewer with no inherited conversation history (`fork_turns: none`). Their briefing may contain only the accepted requirements/specification, repository rules, relevant artifact paths, base/head or diff scope, and commands needed to inspect evidence. Do not include prior agent conclusions, suspected defects, expected findings, implementation justifications, external review comments, or requests to confirm a particular fix. A reviewer must perform a complete review of its scope from raw evidence. If context contamination is discovered, discard that result and start a new reviewer with a neutral briefing.

Toscanini is the default development policy for this repository, including tasks started by commands from other tools. Optional adapters extend the workflow but never replace its orchestration and review guarantees.

Canonical verification: Not configured; see .toscanini/gaps.md
Design system reference: Not configured; resolve with the project owner when UI work begins
Enabled adapters: laravel, spec-kit, terminal-ui
Enabled specialist agents: architect, architecture-reviewer, code-reviewer, design-agent, design-reviewer, qa, test-expert
Installed extensions: none

When `.toscanini/bin/toscanini-event.py` exists, create a unique `run-id` for each user task and record orchestration lifecycle events automatically. Pass the same run id to every specialist. The orchestrator records its own start, material progress, handoffs, blocked state, and completion. Every spawned specialist records start and terminal state. Terminal review events include a structured verdict. Use:

`python3 .toscanini/bin/toscanini-event.py --run-id <current-run-id> --agent <stable-id> --role <role> --event <started|progress|handoff|completed|blocked|failed> --state <active|waiting|completed|blocked|failed> --summary "<brief public-safe status>" [--verdict <approve|request-changes|pass|pass-with-non-blocking-findings|fail|blocked>] [--context-mode <fresh|inherited>] [--artifact <repository-relative-path>]`

Before declaring a behavioral task complete, run `python3 .toscanini/bin/toscanini-gate.py --run-id <current-run-id>`, adding `--require-architecture` and/or `--require-design` when those reviews apply. Exit code zero is required. A missing event, missing verdict, non-fresh review context, rejection, failure, or blocked gate must prevent completion and trigger the required specialist or repair loop. Never describe reviews as complete from memory; the completion gate is the source of truth.

Never include prompts, hidden reasoning, secrets, environment values, raw tool output, or source-code contents in telemetry. Telemetry failure must not block development work.

Treat repeated human review feedback as a candidate repository rule update. Preserve existing project instructions and load reusable procedures from repository skills on demand.
<!-- toscanini:end -->
