# Implementation Plan: Shared Training Library

**Branch**: `002-shared-training-library` | **Date**: 2026-08-26 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/002-shared-training-library/spec.md`

## Summary

Extend the course/version domain with explicit platform-or-company content ownership, first-class reusable modules and immutable module versions. Published course versions snapshot ordered module-version references; companies associate shared courses without copying them. Publishing a shared module creates a durable propagation run whose tenant-aware jobs derive and publish new course versions from each course's latest published version, preserve separate drafts, and idempotently replace not-started assignments while optionally restarting in-progress assignments.

## Technical Context

**Language/Version**: PHP 8.3+, Laravel 13.17

**Primary Dependencies**: Livewire 4.3, Flux Pro 2.15, Laravel queues, Eloquent, Sanctum, existing WorkOS AuthKit and Cloudflare Stream abstractions

**Storage**: PostgreSQL in runtime; SQLite in automated tests

**Testing**: Pest 4.7; global `RefreshDatabase`; `Http::fake()` for WorkOS and Cloudflare calls

**Target Platform**: Stateful Linux web deployment; Laravel Herd locally; server-rendered responsive web control center

**Project Type**: Laravel monolith with Livewire single-file components and queued background work

**Performance Goals**: Shared catalog screens render in under 2 seconds; publication returns an accepted/progress state in under 2 seconds; propagation supports at least 100 associated companies without duplicate versions or assignments

**Constraints**: Published course/module versions and historical assignment references are immutable; compliance events are append-only; operational data remains tenant-scoped; no public video URLs; English source strings with PT-BR localization; no `.env` changes

**Scale/Scope**: Platform and tenant course/module screens, at least 100 companies per shared course, fan-out across potentially thousands of assignments, retryable propagation and complete auditability

## Constitution Check

The repository constitution is still an unratified placeholder, so the enforceable gates come from `AGENTS.md`, `docs/product-spec.md`, and `docs/control-center-design-system.md`.

- PASS — Published `CourseVersion` and `ModuleVersion` records are immutable; propagation creates versions and never rewrites evidence.
- PASS — Assignments and certificates keep frozen references; replacement cancels and recreates obligations with append-only events.
- PASS — `BelongsToCompany` remains strict for operational records; only shareable roots receive an ownership-aware boundary.
- PASS — Platform actions use global platform authorization; company actions receive atomic permissions, prerequisites, middleware and policy checks.
- PASS — Livewire methods remain thin; domain reads use Services and writes use Actions/jobs.
- PASS — Jobs carry explicit actor and tenant context; WorkOS and Cloudflare remain faked in tests.
- PASS — Authenticated UI follows the control-center design system and English-first localization.
- PASS — No operational deletion is introduced; associations deactivate and content archives.

Post-design check: PASS. Module aggregates and durable propagation records are necessary for reuse, immutable snapshots and retryable cross-company fan-out without weakening tenant isolation.

## Project Structure

### Documentation

```text
specs/002-shared-training-library/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── domain-actions.md
│   └── routes-and-ui.md
└── tasks.md
```

### Source Code

```text
app/
├── Actions/
│   ├── Assignments/ReplaceAssignmentsForPublication.php
│   ├── Courses/{AssociateSharedCourse,RemoveSharedCourse,MakeCourseShared}.php
│   └── Modules/PublishModuleVersion.php
├── Enums/{ModuleStatus,ModuleVersionStatus,Permission}.php
├── Jobs/PropagateSharedModuleToCourse.php
├── Models/
│   ├── Concerns/HasContentOwnership.php
│   ├── {Course,CourseVersion,Module,ModuleVersion,CourseVersionModule}.php
│   ├── CompanyCourse.php
│   └── {SharedContentPropagation,SharedContentPropagationItem}.php
├── Policies/{CoursePolicy,ModulePolicy}.php
└── Services/
    ├── Courses/{CompanyCourseLibrary,CoursePromotionImpact}.php
    ├── Modules/{ModulePropagationImpact,ModuleVersionValidator}.php
    └── SharedContent/SharedContentCatalog.php
database/
├── factories/
├── migrations/
└── seeders/
resources/views/components/
├── courses/
├── platform/{shared-courses,shared-modules}/
└── layouts/{app,platform}.blade.php
routes/web.php
tests/
├── Feature/{Access,Courses,Modules,Platform,SharedContent}/
└── Unit/
```

**Structure Decision**: Extend the monolith and its Service/Action boundaries. Keep operational evidence on the strict tenant concern. Add ownership-aware scoping only to shareable roots and derive child visibility through them. Use queued per-course propagation with durable run/item records instead of a separate service or cross-tenant transaction.
