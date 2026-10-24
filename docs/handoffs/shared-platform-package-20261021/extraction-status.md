# Shared platform extraction status

Version: 5. Date: 2026-10-21.

## Delivered scope

One local Composer package, `oceanix/platform`, at `/Users/andrepiresdemello/Code/Oceanix/support/sdk`, consumed by Compliance through a symlinked path repository (`../support/sdk`, explicit `dev-main`). The owner clarified that reusable functionality includes ready-to-use screens and SDK-owned routes, requiring only integration configuration and menu links in a new consumer.

The SDK owns WorkOS identity/OAuth/session/logout, Accounts transport/actor assertions/inbound control plane/durable operations, tenant and product-access enforcement, people registration/mapping/invitation/reconciliation, profile assignment, permission prerequisites/projection/Gates/common policies/audit, configurable models/schema and CLI support.

It also owns login/company selection, people directory/registration/detail, profile list/creation and permission editor: `src/Livewire`, `resources/views`, `routes/ui.php`. Shared authentication endpoints are registered by `routes/auth.php`. A new consumer does not implement those routes, controllers, components or views. No local dashboard route is required: the default destination is the shared people directory. The integration fixture declares no host screen routes or views.

Compliance retains its model/domain adapters, permission catalog and domain policies, historical inbound API adapters and optional global platform administration. Its only people-screen subclasses add domain filters, organizational data, management responsibilities and training. Domain markup lives under `resources/views/platform-extensions`; generic login/registration/profile SFCs were removed from Compliance. Existing sidebar links remain valid. Configuration preserves existing URLs, guest/authenticated layouts, logo and product-specific copy.

## Installation and presentation boundary

The SDK supplies default routes and a minimal fallback shell. Configure product identity, WorkOS/Accounts credentials, initial administrator policy, queue/storage, and the auth provider/model and schema as described in its README. Add menu links according to the user's capabilities. For an existing shell, choose it through `ui.layout` / `ui.login_layout`; no copied screens are needed. Product-specific extensions are optional.

The screen controls require free `livewire/flux` ^2.14 (declared Composer dependency), not Flux Pro. The consumer's Tailwind build scans the SDK views; Compliance adds one `@source` directive for this purpose. Its existing layout files, JavaScript, CSS tokens/rules and design assets were preserved. No boilerplate, new design system or visual redesign was created. The fallback shell belongs to the package and does not generate application files.

## Verification

- Complete non-browser suite: `php -d memory_limit=512M vendor/bin/pest --exclude-group=browser --compact`: **1,210 passed, 18 skipped, 7,183 assertions**, 161.96 seconds. Skipped cases are not claimed as verified.
- Focused authentication, people, access and package isolation checks passed before the full run; the full run includes eight shared-screen tests for rendering, mutations, revocation, direct denial, foreign records, unknown-company URLs, locked model identity and authenticated-login redirect.
- The independent in-memory Laravel consumer blocks App/consumer-seeder autoloads, boots Livewire and free Flux, and uses HTTP fakes. It renders default login and all five management screens without consumer routes/views/components or a dashboard; executes Livewire profile creation, permission save with prerequisite expansion and assignment; and verifies administrator denial, missing-company rejection and the default signed-in redirect. Existing provisioning/Accounts/invitation/authentication/isolation tests remain included.
- Static checks: no App imports in SDK source/routes/views; symlink resolves to the requested directory; generic consumer SFCs were removed; existing dependency records are unchanged except the new SDK dependency and its declared free Flux requirement; existing layout/JS sources are unchanged and CSS only adds package view scanning. `git diff --check` passed.
- Root and SDK Composer validations passed. Package/source Pint checks passed. Production frontend build passed with the existing large-chunk warning.

- Final browser run (SharedPlatformProfilesTest, ComplianceAccessTest, AccountCompanyOwnershipTest and PeopleAccountAccessTest): **19 passed, 269 assertions**, 40.13 seconds. Includes profile creation/save, invitations, company ownership and product access on SDK-owned routes.
- Final actual local `oceanix:doctor`: all eight checks passed. It checks configuration/table availability without external calls.
- Final actual Herd login at `http://compliance.oceanix.test/login`: HTTP 200 after clearing only generated config/route/view caches. Application cache and operation locks were preserved.

During verification, the unknown-company host fixture was corrected to expect the existing Compliance global guard's 403; the independent SDK consumer separately verifies that an unknown company is rejected before session fallback. The complete final suite passed after that correction.

## Operational limits

No consumer database migrations, environment-file edits, external Account changes, publication or deployment were performed. Existing migration history remains consumer-owned. The installer supports a compatible fresh consumer and refuses an incompatible existing users schema; rollback refuses destructive deletion.

The package remains local/unpublished. Other checkouts/CI must supply it or configure distribution. WorkOS/Accounts automated requests use fakes; no real external user was invited or activated and a real provider round trip is not claimed as verified.

Global platform administration/impersonation remain optional consumer-specific features. Training, organization structures/scopes/import, Tasks, existing navigation composition and design assets remain local. This excludes their domain UI, not the shared login/people/profile UI now delivered.
