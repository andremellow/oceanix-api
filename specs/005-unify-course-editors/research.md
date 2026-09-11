# Research: Unified Course Editors

## Shared coordinator with server context collaborators

**Decision**: One Livewire editor coordinator/partials own common state and content/assessment UI; server-resolved collaborators own context actions.

**Rationale**: The SFCs duplicate common mapping, state, and markup while authority/publication genuinely differ. Laravel injection and Livewire scoped SFC scripts support this within existing Actions/Services conventions.

**Alternatives considered**: Copying company over platform; shared HTML over duplicated state; giant owner branches; many nested reactive inputs.

## Atomic authored Save with revision rejection

**Decision**: Stage scalar/content/assessment fields, validate complete payload, compare expected revisions under locks, commit atomically, and retain local values on conflict.

**Rationale**: D-01 supersedes company autosave while platform already protects complete staged graphs.

**Alternatives considered**: Last-write-wins, automatic merge, per-field persistence, or a revision-column migration.

## Atomic structural changes and staged media associations

**Decision**: Stage add/remove/reorder/composition changes and media association changes in the visible graph; commit them only through the atomic Save. Preserve immediate, stable-identity upload transfer/provider operations with truthful pending/failure provenance.

**Rationale**: The owner selected one understandable persistence boundary for the whole visible draft. Upload transfer remains separate because it is an external side effect, but it cannot make the draft Saved or attach media before Save.

**Alternatives considered**: Immediate structural transactions; immediate media attachment; per-module partial Save. All were rejected because they create mixed persistence semantics or ambiguous partial success.

## Pest Browser first; project Playwright fallback

**Decision**: Use Pest Browser with project-owned Playwright after contract approval and a compatibility/Chrome smoke. Keep direct Playwright evidence until parity; if the probe cannot satisfy the accepted scenarios, use project-owned standalone Playwright rather than Dusk.

**Rationale**: Pest 4.7.8 is canonical and official Browser supports Laravel fixtures, viewports, interactions, screenshots, and Playwright browsers. Current Playwright depends on arbitrary paths/manual state and silently skips.

## Node 22 validation environment

**Decision**: Align local/CI browser/build path to Node 22 because locked `concurrently` requires >=22 and optional Multiplex >=22.13; Node 20 emits engine warnings.

## Scoped width fixes

**Decision**: Apply `min-w-0 flex-1` to generated labelled Flux field wrappers and prove wrappers occupy at least 70% of the content row at desktop and 90% of the inner container when stacked at 320 CSS pixels. Opt both course detail pages into existing `descriptionClass="max-w-none"` without changing the global default.

## Deterministic and actual outage gates

**Decision**: Gate every pull request with deterministic controlled request failures. Run one focused real application stop/restart/recovery scenario in scheduled and manually triggered CI.

**Rationale**: Routine failures stay fast and reproducible while actual transport/process recovery remains automated evidence rather than being silently replaced with interception.

## Framework and source assessment

- Installed: Laravel 13.26.1, Livewire 4.4.1, Flux/Flux Pro 2.17.0, Pest 4.7.8, Laravel Boost 2.7.0.
- Consulted installed Boost foundation, Laravel, Livewire, Pest, and test-enforcement guidance: verify versions, reuse conventions/components, validate/authorize server-side, use Pest/focused checks, and obtain dependency approval.
- Consulted official Laravel 13 docs for container injection, Policies, transactions, and Blade components; Livewire 4 docs for SFCs, forms/actions, JS cleanup/morph/navigation; Flux input docs for field anatomy; Pest 4 Browser docs for setup/fixtures/viewports/artifacts.
- No reference project was supplied. Current editors and spec 004 are evidence, not architecture authority.

## Readiness observations

- Focused PHP baseline: 137 passed, 10 PostgreSQL-only skipped, 1,007 assertions, 22.21s.
- Client baseline: 12/12 passed in 63ms; browser scenario produced one skip for missing fixture/module variables.
- Chrome 152 exists; Pest Browser/Playwright are not project dependencies.
- Clean private Composer resolution is unverified due DNS/private Flux/VCS access; installed dependencies work.
- Local/CI Node 20 must move to 22. Local PostgreSQL is absent; CI PostgreSQL 17 is the concurrency environment.
- Toscanini 0.9.0 has a known historical directed-QA lookup inconsistency; do not alter or bypass it.
