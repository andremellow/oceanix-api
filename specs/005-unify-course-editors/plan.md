# Implementation Plan: Unified Course Editors

**Branch**: `codex/discard-module-drafts` | **Date**: 2026-09-10 | **Spec**: [spec.md](spec.md)

## Summary

Replace three independent Livewire editors with one shared coordinator and reusable content/assessment Blade/browser-state implementation. Server-resolved collaborators keep company versus platform authorization, lineage, discard, publication, and media-transfer behavior explicit. Authored values use one explicit atomic Save with expected revisions; guarded structural and media operations remain dedicated immediate actions. Add the approved width fixes and a project-owned Pest Browser matrix, retaining current Playwright evidence until parity.

## Technical Context

**Language/Version**: PHP 8.4.14 locally (project supports 8.3+); Node 22 target (local 20.20.2 must align)

**Primary Dependencies**: Laravel 13.26.1, Livewire 4.4.1 SFCs, Flux/Flux Pro 2.17.0, Tailwind 4, Alpine, existing VideoProvider/editor extensions

**Storage**: PostgreSQL 17 production/CI; SQLite in-memory routine tests; no migration planned

**Testing**: Pest 4.7.8, Node client tests, owner-selected Pest Browser 4.x with project-owned Playwright after contract approval and compatibility smoke; direct Playwright retained until parity

**Target Platform**: Authenticated responsive web; local Chrome 152; desktop and 320 CSS-pixel acceptance widths

**Project Type**: Laravel web application with Livewire full-page components

**Performance Goals**: No interaction regression; no arbitrary browser waits; measure targeted/canonical duration rather than promise one

**Constraints**: Explicit Save; atomic stale-save rejection; separate production/test authors; no data rewrite; immutable evidence; server authority; fake external APIs; no `.env` edit, commit, PR, or deploy

**Scale/Scope**: Three routes, three current SFCs totaling 2,731 lines, shared Actions/Services/Blade/browser state, course-detail width, and UCE-01–UCE-20

## Constitution Check

The checked-in constitution is an unfilled template, so binding gates come from `AGENTS.md`, `docs/product-spec.md`, and `docs/control-center-design-system.md`.

- PASS: immutability, history, evidence, server authority, video boundary, immediate assessment, and localization are frozen.
- PASS: Livewire stays thin; writes use focused Actions and reusable projections/validation use Services.
- PASS: no migration, rename, generic repository/manager, or unrelated policy change.
- PASS: Pest tests are mandatory and independently owned; expectations are not weakened.
- PASS: installed versions plus official and Laravel Boost guidance are recorded in `research.md` and architecture.
- WORKER BLOCKERS: Node 22, private dependency resolution, Pest Browser/Chrome smoke, synthetic fixtures, and PostgreSQL execution remain readiness conditions.

## Project Structure

Documentation stays in `specs/005-unify-course-editors/`. Production work stays in existing `app/Actions/Courses`, `app/Actions/Modules`, `app/Services/Courses`, and `resources/views/components` namespaces. A feature-local `resources/views/components/course-editor` area may hold the shared root/partials. Thin existing route SFCs remain entry points. Test work stays in `tests/Feature/Courses`, `tests/Feature/Platform`, `tests/Browser`, and `tests/JavaScript`.

**Structure Decision**: Keep existing Actions/Services/SFC conventions. Shared state and markup live once; thin route components resolve a server context. Named collaborators express differences without a giant owner branch or new top-level architecture layer.

## Delivery Strategy

1. Freeze spec, scenarios, architecture, contract, selectors, and QA matrix.
2. After approval, generate tasks and finish readiness, including Node 22, Pest Browser installation/compatibility smoke, and CI browser/outage probes.
3. Test author owns `tests/**`, manifests/locks, CI; production author owns `app/**`, `resources/**`, routes/translations.
4. Characterize/fail the shared contract, introduce common abstractions incrementally, move entry points one by one, remove old code only after integrated green.
5. Run deterministic verification, fresh Code Review/Test Analyst together, QA, architecture conformance, directed revalidation, and final gates.

## Complexity Tracking

| Added boundary | Why Needed | Simpler Alternative Rejected Because |
| --- | --- | --- |
| Server editor context/capabilities | Shared UI/save, distinct authority/lineage/publication | Client flags or giant branches weaken security and hide domain differences |
| Whole-draft company revision snapshot | Explicit Save must preserve concurrency protection | Client acknowledgements provide no persisted conflict protection |
| Pest Browser dependency | Layout, morph identity, focus/navigation/failure require a real browser | Source assertions and skipped ad-hoc Playwright do not prove behavior |
| Scheduled/on-demand outage job | Actual server stop/restart recovery must remain automated without slowing deterministic pull-request feedback | Interception alone is not actual outage proof; running process orchestration on every pull request adds avoidable instability |

## Post-Design Constitution Re-check

PASS subject to the Worker blockers above. Phase 1 adds no schema, external API, authorization grant, publication-policy, or history change.
