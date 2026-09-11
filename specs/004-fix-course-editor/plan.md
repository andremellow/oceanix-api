# Implementation Plan: Reliable Course Authoring

**Branch**: `004-fix-course-editor` | **Date**: 2026-09-09 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/004-fix-course-editor/spec.md`

## Summary

Repair the company course creation and editor journey by enforcing one composition mode per draft, exposing every employee-impacting lesson rule, scoping normalized course codes by company, making publication conservative by default, and adding trustworthy save/media feedback, complete ordering, loading protection, responsive behavior, and accessible answer labels. Preserve existing records and published-version immutability; no schema or external dependency change is planned.

## Technical Context

**Language/Version**: PHP 8.4.1 runtime with PHP ^8.3 project contract; JavaScript ES modules on Node 20.20.2

**Primary Dependencies**: Laravel 13.26.1, Livewire 4.3, Flux/Flux Pro 2.14/2.15, Tailwind CSS 4, Tiptap 2.11.7

**Storage**: PostgreSQL in deployed environments; SQLite in tests

**Testing**: Pest 4.7 feature tests, Node test runner for preview JavaScript, browser QA, `composer verify`

**Target Platform**: Authenticated responsive web control center

**Project Type**: Laravel monolith with Livewire single-file components

**Performance Goals**: Existing editor mutations remain interactive without adding additional remote round trips; module search retains its current debounce behavior

**Constraints**: Published versions immutable; tenant isolation; components remain thin; no `.env` changes; no new package; English-first source localization; direct and module composition data must never be silently deleted

**Scale/Scope**: Company course creation modal and one course editor screen, related domain validation/action seams, localization, and focused regression coverage

## Constitution Check

The constitution template contains no ratified project-specific principles. The binding `AGENTS.md`, product specification, and control-center design system therefore supply the gates:

- PASS: published `CourseVersion` records remain immutable.
- PASS: tenant-scoped course identity and authorization stay server-enforced.
- PASS: the Livewire component delegates composition decisions and normalized creation to focused application/domain classes.
- PASS: user-facing source text remains English-first with Portuguese mappings.
- PASS: Pest feature coverage and executable UI QA are planned.
- PASS: no environment file changes or real external calls are required.

Post-design check: PASS. The selected design adds no schema, repository abstraction, external integration, or alternate authorization path.

## Project Structure

### Documentation (this feature)

```text
specs/004-fix-course-editor/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── architecture.md
├── contracts/
│   └── course-editor-ui.md
└── tasks.md
```

### Source Code (repository root)

```text
app/
├── Actions/Courses/
│   ├── CreateCourse.php
│   └── UpdateCourseModuleComposition.php
├── Services/Courses/
│   └── CourseVersionValidator.php
└── Models/
    ├── Course.php
    ├── CourseVersion.php
    ├── Lesson.php
    ├── Question.php
    └── QuestionOption.php

resources/views/components/
├── courses/⚡index.blade.php
└── courses/⚡editor.blade.php

lang/
├── en/ui.php
└── pt_BR/ui.php

tests/Feature/Courses/
├── CourseEditorTest.php
├── CourseAuthoringTest.php
└── HybridCourseCompositionTest.php
```

**Structure Decision**: Extend the existing course Action/Service seams and the existing single-file Livewire screens. Do not introduce controllers, repositories, jobs, migrations, or a new front-end package.

## Implementation Phases

1. Add focused failing tests for normalized company-scoped codes, composition exclusivity and mixed-draft publication blocking.
2. Implement normalized creation validation at both UI and persistence boundaries and render course code read-only after creation.
3. Centralize composition-mode inspection and enforce it in module composition, publication validation, and editor presentation without deleting either data set.
4. Restore watch-threshold editing with accurate tracking/reporting copy and preserve immediate assessment availability; expose lesson video status.
5. Add persistent ordering for questions and alternatives plus pointer/keyboard-operable controls for all three direct-content levels.
6. Add trustworthy dirty/loading/error feedback, guarded navigation, duplicate-action prevention, and accessible answer labels.
7. Make publication preserve existing assignments by default and display explicit impact before opt-in replacement.
8. Run focused tests, canonical verification, independent review/test analysis/design review, executable browser QA, and architecture conformance.

## Complexity Tracking

No constitution violations or exceptional complexity are planned.
