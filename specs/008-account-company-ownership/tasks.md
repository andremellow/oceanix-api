# Tasks: Account-owned company creation

Approved scope: account-company-ownership-v1. Architecture SHA-256 in approval-record.md. Only one Worker edits production/tests.

## Setup and foundation
- [x] T001 Record owner approval in approval-record.md and runtime execution-contract.json.
- [x] T002 Complete isolated two-app readiness in runtime execution-contract.json; validate contract before Worker dispatch.

## US1 — One place to create companies
Independent acceptance: SC-01–04; removed paths reject without company/user/grant/provider side effects.
- [x] T003 [US1] Replace legacy creation/provision tests and add direct stale/unauthorized no-side-effect cases in tests/Feature/Platform/PlatformAdministrationTest.php; replace CLI expectation in tests/Feature/Tenancy/TenantIsolationTest.php. Capture intended red evidence before production edits.
- [x] T004 [US1] Remove obsolete form/methods from resources/views/components/platform/⚡companies.blade.php and provision method/control from ⚡company.blade.php, applying design.md only within changed regions.
- [x] T005 [US1] Remove app/Actions/Platform/CreateCompany.php, app/Actions/Platform/ProvisionCompanyInWorkos.php and app/Services/Workos/WorkosOrganizationService.php; keep app/Console/Commands/CreateCompany.php as side-effect-free failure guidance.
- [x] T006 [US1] Add tests/Browser/AccountCompanyOwnershipTest.php for list/detail desktop/mobile/empty/long-name/navigation, and register in composer.json browser command. Prove SC-01–04 green.

## US2 — Enable the product
Independent acceptance: SC-05–08; existing provisioning compatibility, no new production edit.
- [x] T007 [US2] Run existing Account tests/Feature/Companies and tests/Feature/Products/EnableComplianceTest.php plus Compliance tests/Feature/ControlPlane/ProvisionCompanyTest.php; record assertions in scenarios.md. Preserve all expected behaviors; no changes outside contract.
- [x] T008 [US2] Execute complete QA-05–08 via synthetic local two-app environment, including new and existing company, replay/conflict and unauthorized request; record immutable evidence in runtime run directory.

## Verification and delivery
- [x] T009 Run canonical composer verify, required formatting/build checks; record unrelated baseline failures without fixing them. Freeze implementation checkpoint in execution-contract.json.
- [x] T010 [P] Obtain fresh scoped Code Review and Test Analyst at the same checkpoint; record whole-scope verdicts in runtime directory.
- [x] T011 [P] Obtain bounded design review for the two changed UI regions against design.md; record runtime evidence.
- [x] T012 Execute full QA-01–08 at final checkpoint; consolidate any in-contract findings before a correction batch, maximum two batches.
- [x] T013 Obtain architect conformance against approved architecture.md after QA, then run Toscanini completion and visible verify gates; report evidence and remaining baseline limitations in verification.md.

## Dependencies and parallel opportunities
T001→T002→T003→T004/T005→T006→T007→T009→T010/T011→T008/T012→T013. T004/T005 are sequential within the single Worker. Code Review/Test Analyst/Design Review may run concurrently only at the same stable checkpoint. No parallel production editing. User stories are independently provable but delivered together; US1 is the changed behavior and US2 its preserved integration.

## Strategy
Test-first removal with representative fixtures and positive controls. Read unrelated code only as necessary context. No migrations or live mutations. New requirements or file-boundary amendments return to owner; incidental other findings are non-blocking and not investigated/fixed.
