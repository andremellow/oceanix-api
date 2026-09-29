# Architecture: Account-owned company creation

## 1. Context, references and decision scope

Run: `20260929-account-company-ownership`. Scope: `account-company-ownership-v1`. Author readiness is separate from product-owner approval, which remains pending.

Authoritative feature inputs: [spec.md](spec.md), [scenarios.md](scenarios.md), [plan.md](plan.md), [research.md](research.md), and the run execution contract. This architecture implements FR-001–006 / AC-01–05 and preserves INV-01–04. Scenario and QA definitions remain in their existing artifacts; this document does not expand them.

The only production target is Compliance at baseline `cc621c77fb0901dd15b72873e3a8d346205e7631`. Account at `3e4978016dd8ab6e260ce3fad90aa58ddbb9358c` is a read-only integration reference. The contract's ten-file change boundary is exhaustive. In particular, the existing receiver and Account implementation are compatibility evidence, not additional edit scope.

| Convention / decision | Repository evidence | Classification | Why needed |
| --- | --- | --- | --- |
| Thin Livewire components, domain Actions and Services | Both AGENTS.md files; Compliance platform components; Account company components | Existing convention | Remove behavior within established boundaries |
| Account owns company identity creation/import | Account `Actions/Companies/CreateCompany`, `ImportCompanies`, `Services/Workos/WorkosOrganizationClient` | Existing behavior and confirmed product decision | One supported human ownership point |
| Synchronous enable uses authenticated Compliance API | Account `ChangeComplianceAccess`, `ComplianceProvisioningClient`; Compliance `ProvisionCompanyController`, `EnsureCompany` | Existing integration contract | Preserve creation/adoption, confirmation and retries |
| Delete obsolete Compliance Actions/client and Livewire methods | Runtime caller search limited to app/resources/routes; research.md | New feature-local decision | Hidden controls alone leave callable methods |
| Keep CLI signature as a failing guidance stub | `Console/Commands/CreateCompany` | New feature-local decision | Existing callers get an explicit failure without side effects |

No user management, permission redesign, disable/re-enable changes, operational suspension changes, WorkOS badge corrections, identity repair/backfill, schema changes, framework upgrade or unrelated cleanup is included. Existing human-access controls and identity displays stay intact.

### Framework suitability and reference assessment

Versions were checked in each repository's `composer.lock`. Installed-version evidence is Account's `vendor/composer/installed.json` and the existing Compliance-access-toggle installation: both Laravel **13.26.1**, Livewire **4.4.1**. The new Compliance checkout's dependency readiness is independently recorded in the contract; sibling version evidence does not imply this checkout is ready.

Consulted guidance: each repository's AGENTS.md; Compliance `docs/control-center-design-system.md`; installed Toscanini architecture skill/template; `/Users/andrepiresdemello/Code/toscanini/templates/adapters/laravel.json` (framework required, Boost/Pint recommended), together with the repository manifest's optional Boost policy. Neither inspected installation/lock includes Laravel Boost, and no Boost documentation tool is exposed in this session. Official documentation is the fallback, not a claim that Boost ran.

| Repository / installed framework | Official guidance consulted | Reference assessment | Simplest native option / tradeoffs | Alignment |
| --- | --- | --- | --- | --- |
| Compliance / Laravel 13.26.1, Livewire 4.4.1 | [Laravel 13 structure](https://laravel.com/docs/13.x/structure), [Artisan exit codes](https://laravel.com/docs/13.x/artisan#exit-codes), [Livewire 4 actions security](https://livewire.laravel.com/docs/4.x/actions#security-concerns); adapter and project guidance above | Account supplies identity/API compatibility, not new internal layering. Accept its existing HTTP contract; do not copy its product-operation machinery into Compliance UI. | Remove component methods and unused classes; retain ordinary Artisan `handle(): int` failure. This avoids a new authorization switch or proxy feature. | Aligned; no material unresolved framework conflict |
| Account / Laravel 13.26.1, Livewire 4.4.1 (read-only) | Same Laravel structure/Livewire actions documentation and adapter; Account AGENTS.md | Account is evidence of the existing caller, not an architecture migration target. No other reference implementation is adopted. | Preserve its Actions/Eloquent/HTTP client design and existing authorization. No new layer or cross-database access. | Aligned; no production change or deviation |

Laravel permits application-specific class organization; it does not mandate these Action/Service names. Livewire's public-method exposure supports removing the obsolete methods rather than only their buttons. Artisan supports explicit nonzero return codes. These are narrow framework-fit conclusions, not a repository-wide audit.

Test-first removal is the plan's development strategy, separate from layering: demonstrate the proposed absence/no-side-effect expectations fail on the baseline where feasible, then remove the paths. Existing integration tests are reused rather than rewritten solely to follow a pattern.

## 2. Architectural style

Keep the current Laravel application architecture: Livewire presentation, application Actions for writes, Services for projections and external HTTP boundaries, Eloquent persistence, and an invokable controller/Form Request/Resource at the machine API. This feature primarily subtracts obsolete behavior. No repository abstraction, new orchestrator, queue, event bus, proxy endpoint or database-sharing mechanism is needed.

## 3. Directory and module structure

Compliance production edits:

```text
resources/views/components/platform/
  ⚡companies.blade.php               remove create/provision handlers and form/actions
  ⚡company.blade.php                 remove provision handler/action
app/Console/Commands/CreateCompany.php  retain signature; error and FAILURE only
app/Actions/Platform/
  CreateCompany.php                   delete
  ProvisionCompanyInWorkos.php         delete
app/Services/Workos/
  WorkosOrganizationService.php        delete
```

Test/build edits remain `tests/Feature/Platform/PlatformAdministrationTest.php`, `tests/Feature/Tenancy/TenantIsolationTest.php`, new `tests/Browser/AccountCompanyOwnershipTest.php`, and only the new suite registration in `composer.json`.

Preserved Compliance boundary: `Http/Middleware/EnsureProvisioningPrincipal`, `Http/Requests/ControlPlane/ProvisionCompanyRequest`, `Http/Controllers/ControlPlane/ProvisionCompanyController`, `Actions/ControlPlane/EnsureCompany`, `Http/Resources/ControlPlane/ProvisionedCompanyResource`, and existing models/seeders.

Read-only Account structure:

```text
app/Actions/Companies/     CreateCompany, ImportCompanies
app/Actions/Products/      EnableCompliance, ChangeComplianceAccess
app/Services/Workos/       WorkosOrganizationClient
app/Services/Products/     ComplianceProvisioningClient
app/Models/                Company, CompanyCreationIntent, CompanyProduct, ProductOperation
app/Console/Commands/      ImportWorkosCompanies
```

## 4. Class responsibilities and non-use

| Type | Responsible class/location | Deliberate non-use |
| --- | --- | --- |
| Livewire component | Existing company list/detail render inspection/navigation; retain unrelated handlers | No company-creation or organization-provision methods, properties, dependency imports or residual error panels |
| Controller | Existing invokable `ProvisionCompanyController` translates receiver outcomes | No new browser or API controller |
| Form Request | Existing `ProvisionCompanyRequest` validates route UUID, idempotency header and payload | No new creation request in Compliance |
| API Resource | Existing `ProvisionedCompanyResource` returns allowlisted receipt fields | No new serializer |
| Action | Existing `EnsureCompany` owns receiver transaction; Account Actions retain their responsibilities | Delete the two legacy Compliance Actions; no replacement Action |
| Service / external client | `PlatformOverview` retains list projection; Account clients own creation/provider and product HTTP calls | Delete only `WorkosOrganizationService`; other WorkOS clients are unaffected |
| Repository | Eloquent is the existing persistence interface | No wrapper around Eloquent |
| Query Object | `PlatformOverview` remains the focused existing read service | No new query abstraction |
| DTO / Value Object | Existing validated arrays and receipt representation | No new transport type needed |
| Model / persistence | Existing companies, bindings, receipts and product-operation records | No schema, identity or model changes |
| Policy / authorization | Existing PlatformAccess, Account Gates/CompanyPolicy, receiver principal middleware | No new grantable ability; removed operation unavailable to all human roles |
| Job / command | Legacy `CreateCompany::handle(): int` returns failure and Account guidance | No dependency injection, persistence query, Action call or provider request in stub; no new job |
| Event / listener | Existing audit paths remain on accepted machine writes | No event/listener introduced; rejected obsolete paths create no domain audit record |

## 5. Invocation, naming and query placement

Retain Action `handle(...)`, the invokable receiver controller and descriptive domain class names. The CLI keeps `oceanix:create-company {name} {--slug=}` for discoverability, changes its description to reflect unsupported local creation, and unconditionally reports that companies must be created/imported and Compliance enabled in Account. It returns `Command::FAILURE` without resolving the deleted Action or validating/querying a company.

Read queries stay in existing projections; receiver Actions may query Eloquent within their existing transactions. Do not add queries to either changed component, Blade markup or command. Existing detail-component queries for unrelated workflows are preserved, not extracted as an unsolicited refactor. Account queries remain untouched. Remove unused imports only when caused by this removal.

## 6. Dependency direction

```text
Account company UI/CLI -> Account company Actions -> WorkOS client -> WorkOS
Account product UI -> EnableCompliance / ChangeComplianceAccess
                   -> ComplianceProvisioningClient -> authenticated HTTP
Compliance middleware -> Form Request -> controller -> EnsureCompany -> Eloquent
                                                         -> receipt / resource
Compliance company UI -> PlatformAccess / existing read projections -> Eloquent
Compliance obsolete CLI -> error text + FAILURE
```

Neither changed Compliance component may depend on a company-creation or organization-writing Action/client. The command has no application-write dependency. Account never imports Compliance PHP classes or accesses its database. Compliance's receiver does not call WorkOS to create/update organizations. Existing user/invitation integrations are separate and unchanged (INV-03).

## 7. Cross-cutting ownership and system contracts

| Concern | Owner | Preserved contract / constraint |
| --- | --- | --- |
| Validation | Existing Form Request and Account Actions | Provision route UUID and `Idempotency-Key` UUID are validated with existing name/slug/provider-ID limits; no new payload fields |
| Authorization | PlatformAccess; Account Gate/Policy; `EnsureProvisioningPrincipal` | Existing platform inspection authorization retained; receiver requires active correct-product/environment service principal, unexpired token and exact `companies:provision` ability; human roles do not bypass it |
| Tenant/data ownership | Account identity; Compliance `EnsureCompany` | Bind by confirmed WorkOS organization identity, never slug/name similarity. Preserve existing public ID and tenant records; no automatic human grants (INV-01–03) |
| Mapping | Existing receiver and client | Route company UUID, header operation UUID; payload WorkOS ID/name/slug/actor/correlation unchanged |
| Transactions/concurrency | Existing receiver and Account Actions | Principal lock and unique constraints protect receiver receipt/binding creation; local writes, seeders, binding, audit and receipt remain transactional. Account claims/saved operation payload and short local transactions remain unchanged |
| Persistence | Eloquent and separate databases | New tenant gets baseline permission/role definitions; adoption preserves operational records; neither creates a human assignment |
| External HTTP | Account `ComplianceProvisioningClient` | Existing PUT `/api/control-plane/v1/companies/{account_company_uuid}`, bearer auth, JSON and idempotency header; connect timeout 3s, total timeout 15s; no new call or live QA provider access |
| Failure/outcome | Existing controller/client | Receiver 201 created / 200 existing; conflict 409, denied/validation/auth responses retained, unexpected errors safe 503. Account confirms only matching acknowledgement; timeout is not rollback and remains retryable using saved operation |
| Serialization | Existing Resource | Company UUID, Compliance public ID, WorkOS ID, outcome, operation ID only |
| Configuration | Existing service configuration | No `.env`, new URL configuration, credentials or cross-app navigation dependency |
| Logging/telemetry | Existing audit and exception paths | Keep accepted provisioning audit metadata; obsolete command/methods have no domain write side effects. Toscanini artifacts contain no secrets |
| Retry | Existing operation/receipt ownership | Same principal+operation and payload replays saved response; changed payload conflicts. No automatic repair or new retry mechanism |
| Migrations/rollback | No migration | Rollback is code-only and would restore the old unwanted paths; no data deletion, identity rewrite or compensating provider call |

UI composition uses the existing page hero and detail-card primitives. Replace the creation-oriented list description with brief Account creation/activation guidance; remove the form column so the list occupies the available width. Keep details, back navigation and eligible company entry accessible at narrow widths; long names wrap within the remaining layout. Provide an informational empty state using the existing primitive. On detail, remove only the organization action/error state and use brief Account guidance where needed. Preserve identity/status displays, including the explicitly excluded synchronization badge, and all unrelated controls. English source strings use the existing translation helper; no new translation group or language-file scope is introduced.

## 8. Concrete execution flows

1. **Inspection (SC-01–02):** platform route/access checks → list `with(PlatformOverview, PlatformAccess)` or existing detail mount → current read projection → list/detail, Account guidance and retained entry/navigation. No independent creation or organization mutation remains.
2. **Obsolete browser call (SC-03):** stale `create` or `provisionWorkos` request → Livewire dispatch finds no exposed handler → request rejected before application side effects. Test the framework's actual rejection; do not add a compatibility method that resolves removed dependencies.
3. **Obsolete CLI (SC-04):** Artisan parses the retained signature → no-argument `handle()` → explanatory error + failure status. No company/user/profile/binding/audit/provider change.
4. **First enable (SC-05):** existing Account create/import → company with stable WorkOS identity → authorized enable → Account saves/claims provision operation → synchronous client → receiver authentication/request → `EnsureCompany` creates tenant or adopts identity → atomic binding/audit/receipt → client verifies acknowledgement → Account confirms product. Preserve existing baseline role seeding without human access grants.
5. **Adoption/retry/conflict/authentication (SC-06–08):** same existing receiver paths; matching WorkOS identity retains public ID/data; identical operation replays receipt; conflicting identities/payloads reject; invalid machine authentication stops before receiver mutation. Existing Account access-toggle branches remain unchanged and outside expanded testing.

## 9. Test seams and risks

Use the scenario map's automated boundaries and complete frozen QA-01–08 matrix. For removal checks use real Livewire/Artisan dispatch plus representative database snapshots/counts and `Http::fake()`/no-request assertions. Positive controls prove inspection authorization and machine-token fixtures work. Browser checks exercise actual list/detail navigation, desktop/mobile, empty and long-name presentation. Reuse existing Account create/import/product and Compliance receiver tests for the prescribed compatibility scenarios. Isolated two-app QA must use separate synthetic databases and fake provider responses; no shared database or live Herd fixtures (INV-04).

Risks are bounded: stale clients must fail safely rather than invoke a hidden handler; deletion must leave no runtime references; the list reflow can harm narrow/long-name presentation; integration verification can be blocked by disposable-environment readiness. SQLite tests are not new proof of PostgreSQL concurrency semantics; this change leaves receiver locking/constraints untouched. Receipt/idempotency and no-human-grant behavior still require the existing scoped regression evidence. Missing integration evidence blocks completion, not a silent reduction in the QA matrix.

## 10. Author verdict and classified conditions

Author readiness: **APPROVED_WITH_CONDITIONS**. The proposed architecture is implementation-ready; no unresolved architecture alternative materially affects it.

| Condition / decision | Category | Basis | Owner / next action | Could materially change architecture? |
| --- | --- | --- | --- | --- |
| Confirm consolidated specification, architecture hash and frozen contract | product-decision | FR-001–006; section 11 | Product owner approves exact artifact/scope before Worker | Only if requested scope changes |
| Complete runtime/dependency and disposable two-app/browser readiness | operational-dependency | INV-04; contract readiness | Orchestrator records probes and resolves required setup before Worker | No; unavailable access is not architectural approval |
| Remove obsolete paths and implement scoped tests | implementation-task | AC-01–02, AC-05 | Worker after approval; no additional production files without amendment | No |
| Execute preserved integration scenarios and final conformance | implementation-task | AC-03–04; SC-05–08 | QA and Architect at stable final checkpoint | No; unexpected required behavior returns for amendment |

No spike or further architecture-document reviewer is required. Conditions are not a claim of test execution or delivery approval.

## 11. Product-owner approval

**PENDING.** Record the explicit owner decision in `execution-contract.json`, bound to this artifact's SHA-256 and `account-company-ownership-v1`. Author readiness does not authorize Worker dispatch. The architect changes no owner-approval, scope, QA or readiness fields.

After implementation and executable QA, write separate architecture-conformance evidence against the approved hash and final checkpoint, citing these sections and AC/INV IDs. Do not redesign, broaden review scope or treat unrelated existing behavior as a blocking deviation.
