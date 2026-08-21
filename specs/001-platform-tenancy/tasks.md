# Tasks: Platform Administration and Tenant Identity

## Phase 1: Setup

- [x] T001 Preserve official branding changes and verify ignore rules in `.gitignore` and `public/images/`
- [x] T002 Update product decisions for Account, Person, platform administration and tenant URLs in `docs/product-spec.md`

## Phase 2: Foundational

- [x] T003 Add Account, Company identity fields and User linkage to `database/migrations/0001_01_01_000000_create_users_table.php`
- [x] T004 Add Account model/factory and Company/User relationships in `app/Models/Account.php`, `app/Models/Company.php`, `app/Models/User.php`, and `database/factories/AccountFactory.php`
- [x] T005 Add platform bootstrap configuration and seed linkage in `config/oceanix.php`, `.env.example`, and `database/seeders/DatabaseSeeder.php`
- [x] T006 Add account-aware authentication and closed provisioning tests in `tests/Feature/Auth/WorkosLoginTest.php`
- [x] T007 Implement global Account resolution and exact pre-existing tenant-person linkage in `app/Actions/Auth/AuthenticateSocialLogin.php`

## Phase 3: User Story 1 — Explicit tenant workspace

- [x] T008 [US1] Add tenant route and URL contract tests in `tests/Feature/Tenancy/TenantRouteTest.php`
- [x] T009 [US1] Move authenticated operational routes under `/c/{company:slug}` and add compatibility redirects in `routes/web.php`
- [x] T010 [US1] Enforce route/session company agreement and route URL defaults in `app/Http/Middleware/IdentifyCompany.php`
- [x] T011 [US1] Show active company and company switch access in `resources/views/components/layouts/app.blade.php`

## Phase 4: User Story 2 — Platform administration

- [x] T012 [US2] Add platform authorization and company-management tests in `tests/Feature/Platform/PlatformAdministrationTest.php`
- [x] T013 [US2] Add platform middleware, company write Actions and overview Service in `app/Http/Middleware/EnsureUserIsPlatformAdmin.php`, `app/Actions/Platform/`, and `app/Services/Platform/PlatformOverview.php`
- [x] T014 [US2] Add `/platform` routes and Livewire dashboard/company screens in `routes/web.php` and `resources/views/components/platform/`
- [x] T015 [US2] Add explicit audited company switching for accounts linked to multiple tenant people in `app/Actions/Tenancy/SwitchCompany.php` and `resources/views/components/layouts/app.blade.php`

## Phase 5: User Stories 3–4 — Identity and access clarity

- [x] T016 [US3] Add multi-company Account linkage and tenant isolation tests in `tests/Feature/Tenancy/AccountLinkageTest.php`
- [x] T017 [US4] Move role assignment into person detail and remove duplicate Users navigation/routes in `resources/views/components/organization/⚡person.blade.php`, `resources/views/components/layouts/app.blade.php`, and `routes/web.php`
- [x] T018 [US4] Add atomic access-profile authorization coverage for person role changes in `tests/Feature/People/PersonAccessTest.php`

## Phase 6: Polish and validation

- [x] T019 Add English/PT-BR strings and platform/tenant UX copy in `lang/pt_BR.json` and `lang/pt_BR/ui.php`
- [x] T020 Run full Pest, Pint and production asset build; update this checklist and `specs/001-platform-tenancy/quickstart.md`

## Dependencies

- Foundational tasks T003–T007 block every user story.
- US1 establishes route tenancy before company switching.
- US2 depends on US1 for safe company navigation.
- US3 and US4 depend on Account linkage but can otherwise be validated independently.

## Parallel opportunities

- T006 can be written alongside T003–T005 before T007.
- T012 can be written while T008–T011 are implemented.
- Translation work can proceed after screen copy stabilizes.

## Implementation strategy

Deliver the identity foundation and explicit tenant routes first, then the platform control plane, then remove the duplicate Users experience. Tests precede each behavior-changing implementation and all external identity calls remain faked.
