# Research: Reliable Course Authoring

## Composition mode

**Decision**: Treat reusable modules and direct legacy lessons as mutually exclusive active composition modes. Reject attempts to create a new mixed draft. Block publication of pre-existing mixed drafts while preserving both data sets for manual resolution.

**Rationale**: Every existing learner/preview projection already chooses module composition when any module exists and otherwise falls back to direct lessons. Enforcing that boundary prevents silent omission without inventing an interleaving order or destructive migration.

**Alternatives considered**: Concatenate modules and lessons with modules first; automatically convert direct lessons into modules; automatically delete or detach one content set. These either introduce ambiguous ordering, new lineage semantics, or irreversible behavior outside this repair.

## Course-code validation

**Decision**: Normalize with trim and uppercase before validation, scope uniqueness to `company_id`, retain the database composite constraint, and translate persistence collisions into inline validation.

**Rationale**: This matches the existing composite identity and Laravel's supported conditional unique-rule pattern while covering normalization and race boundaries.

**Sources**: Laravel 13 validation documentation for `Rule::unique()->where(...)` and safe model-based `ignore`: https://laravel.com/docs/13.x/validation

## Autosave and navigation

**Decision**: Keep immediate mutation-based persistence, add explicit dirty/saving/saved/error states, and cancel Livewire navigation while known work is unsaved. Do not replace the company editor with the platform editor's bulk-save architecture.

**Rationale**: This preserves current behavior and limits risk while using Livewire-native dirty state and cancellable navigation hooks.

**Sources**: Livewire 4 forms dirty indicators: https://livewire.laravel.com/docs/4.x/forms; Livewire 4 cancellable navigation event: https://livewire.laravel.com/docs/4.x/navigate

## Accessible fields

**Decision**: Give each answer a visible positional label and each correct-answer selector a unique accessible name. Preserve the labelled Flux editor and existing field primitives.

**Rationale**: Flux controls support shorthand labels and composed fields; positional names disambiguate repeated controls.

**Sources**: Flux input labels: https://fluxui.dev/components/input; Flux editor accessibility: https://fluxui.dev/components/editor

## Watch threshold semantics

**Decision**: Expose and persist the 1–100 watch threshold as tracking/reporting data. Assessment remains available immediately when the lesson opens and is not gated by this value.

**Rationale**: This follows `docs/product-spec.md` §7, current `AnswerQuestion` behavior, and the product-owner clarification recorded on 2026-09-10 without expanding the task into employee assessment behavior.

## Ordering interaction

**Decision**: Persist move operations for lessons, questions, and alternatives and expose both pointer drag handles and keyboard-operable move controls. Use existing dependencies only.

**Rationale**: The product specification explicitly requests drag-and-drop, while accessible move buttons provide deterministic keyboard operation and a low-risk fallback.

**Alternatives considered**: Add a sortable package; support buttons only. A package is unnecessary for the required data operations, and buttons alone do not satisfy the product specification.

## Publication safety

**Decision**: Default to keeping open assignments on their frozen version. Require an explicit selection before replacement and show impact counts, separating in-progress work where possible.

**Rationale**: This protects learner progress and keeps replacement available as an intentional administrative action.
