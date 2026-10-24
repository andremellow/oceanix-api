# Implementation Plan: Account-owned company creation

**Branch**: `codex/account-company-ownership` | **Date**: 2026-09-29 | **Spec**: [spec.md](spec.md)
**Status**: Approved by owner on 2026-09-29; see approval-record.md.

## Summary
Remove Compliance's independent company creation and WorkOS organization provisioning from its two platform company components and runtime Actions/client. Keep the old CLI command as an explanatory nonzero exit with no writes. Account creation/import and its existing enable receiver already provide the requested behavior; reuse and verify them without production changes.

## Technical Context
Laravel 13, PHP 8.3+, Livewire 4/Flux Pro, Pest 4; exact installed versions recorded by Architect. Independent PostgreSQL application databases; isolated SQLite tests and synthetic local integration fixtures. No migrations, new packages, environments or external provider mutations. Web/CLI change restricted to 10 files enumerated in execution-contract.json; source deletion is intentional. Existing dependencies are context only, not broad review scope.

## Constitution Check
Compliance constitution remains an unfilled template; actual AGENTS.md and docs/control-center-design-system.md govern. Preserve tenant evidence, English source, thin components, authenticated machine boundary and local user/permission flows. No new grantable feature or permission is introduced: the obsolete operation is removed for every human role. Account constitution ownership rule is preserved. No architectural exceptions proposed. Owner approval is required before Worker; this plan is not that approval.

## Project Structure
- Components: resources/views/components/platform/⚡companies.blade.php and ⚡company.blade.php — remove form/actions/handlers and show minimal Account guidance; keep unrelated details and workflows unchanged.
- Delete app/Actions/Platform/CreateCompany.php, app/Actions/Platform/ProvisionCompanyInWorkos.php, app/Services/Workos/WorkosOrganizationService.php after confirming all runtime callers removed.
- app/Console/Commands/CreateCompany.php — retain signature only to return failure with use-Account guidance.
- Existing tests/Feature/Platform/PlatformAdministrationTest.php and tests/Feature/Tenancy/TenantIsolationTest.php — replace only legacy creation/provision expectations and add direct-call no-side-effect assertions.
- tests/Browser/AccountCompanyOwnershipTest.php — list/detail desktop/mobile, empty/long name state, navigation; register only this suite in composer.json canonical browser command.
- Existing receiver and Account feature tests remain unchanged unless explicit amendment approved.

## Phase 0 — Research
See research.md for observed callers, receiver behavior and alternatives. No framework/layer overhaul. No automatic identity repair. Other findings remain outside review/implementation scope, including the WorkOS synchronized badge.

## Phase 1 — Design and validation
Architect authors architecture.md against exact repository sources and framework guidance. UI follows existing design tokens/components; removing the form changes the list to full-width and adds brief English guidance. No new cross-application link/configuration required. Follow the eight scenario rows in scenarios.md and matching QA IDs in execution contract. Tests are planned at the lowest boundary proving the behavior, not all layers for every case. Existing Account/receiver tests prove compatibility; isolated two-app QA exercises Enable without WorkOS access. No live Herd fixtures.

## Execution readiness
Resolve PHP/Node, local dependency install without .env, SQLite feature/browser execution and disposable integration servers before Worker. Canonical verify includes broader suites by project policy; unrelated baseline failures are recorded only. Readiness evidence belongs to the runtime contract.

## Delivery sequence
Owner approves consolidated artifacts → tasks generated → one Worker implements tests-first removal → deterministic verification → fresh bounded Code Review/Test Analyst/Design Review → complete executable QA matrix → Architect conformance → completion gate. Max two remediation batches, 17 specialist starts. Implementation and verification evidence are recorded in verification.md.

## Complexity Tracking
No new layers, queues, endpoints or tables. No violations proposed. Scope changes require explicit approval before edit.
