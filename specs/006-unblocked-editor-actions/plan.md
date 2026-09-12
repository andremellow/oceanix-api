# Implementation Plan: Unblocked Editor Actions

**Branch**: `codex/discard-module-drafts` | **Date**: 2026-09-11 | **Spec**: [spec.md](spec.md)

## Summary

Remove dirty-only blocking from draft-editing actions without introducing autosave. Immediate authorized structural/media Actions retain their bounded writes. The shared coordinator captures staged authored state by stable identity before each operation, refreshes canonical state/revisions afterward, and deterministically reapplies still-applicable staged values. A small browser overlay protects keystrokes newer than the request. Test-first evidence begins with the owner's exact add–type–add scenario.

## Technical Context

**Language/Version**: PHP 8.4.14 (project supports 8.3+), JavaScript ES modules
**Primary Dependencies**: Laravel 13.26.1, Livewire 4.4.1, Flux Pro 2.17.0
**Storage**: PostgreSQL production; SQLite routine tests
**Testing**: Pest 4.7.8, Pest Browser, Node test runner
**Target Platform**: Authenticated responsive web application served locally by Laravel Herd and in CI
**Project Type**: Laravel modular monolith with server-rendered Livewire UI
**Performance Goals**: No extra network round trip beyond the selected immediate Action; deterministic linear rebase over one editor graph
**Constraints**: No hidden authored Save, no schema migration, stable identity only, exact revision checks, private provider boundaries, no loss of newer in-flight edits
**Scale/Scope**: One shared editor coordinator and three context entry points; nested records/questions/options and applicable composition/media operations

## Constitution Check

- PASS: Product source and English-first localization remain unchanged.
- PASS: Livewire remains thin; pure rebase logic lives in a Service and writes remain in named Actions.
- PASS: Published versions, materialized obligations, history, event evidence, and provider privacy remain unchanged.
- PASS: Authorization is rechecked by existing contexts/Actions; client identity grants no authority.
- PASS: TDD is explicitly required for the reported scenario and tests cover all contexts.
- PASS: No `.env`, migration, new package, route, permission, or external service change.

Post-design check: PASS. The architecture keeps all constitution boundaries and introduces no exception requiring justification.

## Project Structure

### Documentation

```text
specs/006-unblocked-editor-actions/
├── plan.md
├── research.md
├── data-model.md
├── contracts/editor-interaction.md
├── quickstart.md
└── tasks.md
```

### Source Code

```text
app/Livewire/CourseEditor/EditorCoordinator.php
app/Services/CourseEditor/
resources/js/course-editor.js
resources/views/components/course-editor/root.blade.php
tests/Unit/CourseEditor/
tests/Feature/CourseEditor/
tests/JavaScript/course-editor.test.mjs
tests/Browser/UnifiedCourseEditorBrowserTest.php
```

**Structure Decision**: Extend the existing shared editor boundary with feature-local readonly state carriers and a pure rebaser. Keep all persistence in existing context-specific Actions. Do not restore duplicate editors or add repositories/controllers.

## Complexity Tracking

No constitution violation. The two preservation seams are necessary because Livewire can receive staged values at request start while a browser user may type a newer value before the morph completes.
