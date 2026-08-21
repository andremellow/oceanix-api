# Implementation Plan: Platform Administration and Tenant Identity

**Branch**: `main` | **Date**: 2026-08-21 | **Spec**: [spec.md](spec.md)

## Summary

Introduce a global `Account` identity linked to one or more existing tenant-owned `User` workforce records, retain `User` as the internal compliance person model to avoid rewriting evidence foreign keys, expose it only as “Person” in product language, move tenant routes under `/c/{company:slug}`, and add a separately authorized `/platform` control plane.

## Technical Context

**Language/Version**: PHP 8.3+, Laravel 13

**Primary Dependencies**: Livewire 4, Flux Pro, Sanctum, existing WorkOS AuthKit HTTP provider

**Storage**: PostgreSQL in runtime; SQLite in automated tests

**Testing**: Pest 4; `Http::fake()` for WorkOS

**Target Platform**: Stateful Linux web deployment; Laravel Herd locally

**Project Type**: Laravel monolith with server-rendered Livewire components

**Performance Goals**: Interactive aggregate dashboard for hundreds of companies and tens of thousands of people

**Constraints**: Preserve immutable content and compliance evidence; scope every tenant query; no `.env` edits; WorkOS-only sign-in

**Scale/Scope**: Platform dashboard/company management, route migration for tenant screens, global identity entity and authorization coverage

## Constitution Check

- PASS — Tenant authorization remains server-side and gains an independent platform guard.
- PASS — Livewire components remain thin; projections and writes use Services/Actions.
- PASS — Existing compliance entities and evidence foreign keys are preserved.
- PASS — English source language and Portuguese locale separation remain intact.
- PASS — WorkOS calls are faked in tests.
- PASS — The control-center design system governs both shells.

Post-design check: PASS. `Account` is necessary for global identity; platform privilege remains outside the tenant permission catalog.

## Project Structure

### Documentation

```text
specs/001-platform-tenancy/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/routes.md
└── tasks.md
```

### Source Code

```text
app/
├── Actions/Platform/
├── Http/Middleware/EnsureUserIsPlatformAdmin.php
├── Models/Account.php
├── Models/Company.php
├── Services/Platform/PlatformOverview.php
└── Services/SocialLogin/
resources/views/components/
├── layouts/app.blade.php
└── platform/
routes/web.php
tests/Feature/
├── Auth/
├── Platform/
└── Tenancy/
```

**Structure Decision**: Extend the monolith. The legacy `User` model remains the tenant-owned person and session principal for compatibility with policies and evidence; `Account` owns global WorkOS identity and platform privilege. Every authenticated User links to an Account after WorkOS login.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| Global Account plus tenant User | One WorkOS identity spans companies while compliance people remain tenant-owned | A platform flag on tenant users duplicates identity and may disagree across companies |
| Tenant workspace entry | Requires an active person linked to the account | Preserves tenant policies and never pollutes workforce metrics |
