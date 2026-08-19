# Oceanix Training Compliance — Claude Guidelines

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
