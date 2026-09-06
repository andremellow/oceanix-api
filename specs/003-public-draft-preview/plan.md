# Implementation Plan: Public Draft Preview

**Branch**: `main` | **Date**: 2026-09-05 | **Spec**: [spec.md](spec.md)
**Feature ID**: `003-public-draft-preview` (Spec Kit resolution identifier, not the current Git branch)
**Run**: `public-draft-preview-20260905`
**Status**: Approved implementation contract.

## Summary

Add a seven-day bearer link to the selected editable course draft in company and platform administration. A separate public reader loads the latest saved exact-version content, permits video playback, and displays questions and choices without answers or evidence recording. Persist link generations separately from course content; reuse an active link and append a fresh generation after expiry. Default the public interface to pt-BR. Publication, discard, or archive denies new application access and displays a friendly state.

## Technical Context

**Language/Version**: PHP 8.4; Laravel 13.26.1 verified through installed Composer packages and Boost.
**Primary Dependencies**: Livewire 4.4.1; Flux/Flux Pro 2.17.0; Tailwind 4; existing VideoProvider and CloudflareStreamProvider/FakeVideoProvider. No new dependency.
**Storage**: PostgreSQL; existing lessons table also stores module versions. SQLite is the ordinary test database; concurrency needs isolated PostgreSQL.
**Testing**: Pest 4.7.8 and browser QA after implementation. External APIs mocked in automated tests.
**Target Platform**: Existing web application, desktop and mobile browsers.
**Project Type**: Laravel monolith; thin authenticated Livewire controls, public Blade/controller reader and small playback client.
**Performance Goals**: One indexed token lookup, eager-loaded exact-version content; no provider calls for text-only views and no polling of course content. User outcome: generate/copy in two actions. No unsupported throughput SLA introduced.
**Constraints**: 168-hour expiry on server clock, authenticated generation, public possession-based reading, no compliance side effects, no dependency or environment-file edits.
**Scale/Scope**: Company courses and platform shared courses, manual editable drafts only; composed and legacy lesson representations. No standalone module links, email automation, snapshots, early rotation, manual revoke UI, or learning simulation.

## Constitution Check

Before research: the constitution file contains only placeholders, so no fabricated constitution principles apply. The supplied repository rules are binding: immutable publication, tenant isolation, atomic permissions, thin components, English source strings, temporary videos, and append-only evidence. No `.ai/rules` directory exists on this main checkout. No extension hooks or custom preset stack is configured; setup-plan resolved the installed template.

After design: see INV-01–INV-06 and AC-01–AC-09 in the runtime execution contract and architecture. The design preserves all domain invariants. Public pt-BR display does not change the canonical English source convention. Platform authorization follows its existing account boundary instead of treating a platform account as a tenant User. Existing platform admin bypass remains explicit; no new platform profile subsystem.

Tooling gate (latest inspection): the repository tooling changed during this planning session and now includes toscanini_contract.py, templates, round/phase telemetry, and the contract-aware gate. The initial missing-validator result is obsolete. Current contract validation passes after approvals; no tooling restoration is required. Correct decisionRisk schema value is material. Boost is available despite the older gaps.md note. User approval of the plan and implementation is recorded. A composer verify script will compose the required existing checks so the visible gate can invoke them. No gate is waived.

## Project Structure

### Documentation (this feature)

- `spec.md`: clarified requirements.
- `plan.md`, `research.md`, `data-model.md`, `quickstart.md`.
- `architecture.md`, `ux.md`, `contracts/public-preview.md`.
- `review.md`: complete independent planning findings and disposition.
- `tasks.md`: subsequent speckit-tasks output; not created by this planning command.
- `.toscanini/runtime/runs/public-draft-preview-20260905/execution-contract.json`: runtime scope and acceptance contract.

### Source Code (planned boundaries)

- `app/Actions/Courses/GenerateCoursePreviewLink.php`, `app/Services/Courses/*Preview*.php`, `app/Models/CoursePreviewLink.php` and additive migration/factory.
- `app/Enums/Permission.php`, `app/Enums/PlatformPermission.php`, course policy and narrowly scoped platform sharing authority.
- `bootstrap/app.php`: preview-only exception response/header handling and token redaction.
- `routes/web.php`, public preview controller/middleware and Blade views; dedicated public locale selection route.
- Existing company/platform course show and editor components: shared link panel for the exact selected/saved draft.
- `app/Contracts/VideoProvider.php`, its two implementations, preview-only playback client: exact expiry support while preserving existing callers.
- `lang/pt_BR.json`: English keys and Portuguese values; no translation group named after a visible label.
- `tests/Feature/Courses`, `tests/Feature/Platform`, `tests/Feature/Video`, isolated PostgreSQL concurrency tests.

**Structure Decision**: Reuse existing Actions/Services and Blade patterns. No snapshot pipeline, assignment proxy, or separate application.

## Delivery Sequence

1. Approve plan and execution contract; verify the current Toscanini preflight. Generate dependency-ordered tasks.
2. Implement additive link persistence, authority checks, serialized generation, and focused tests.
3. Implement public token resolver, exact-version projection, eligibility/locale/error handling and route tests.
4. Implement isolated preview video flow and read-only assessment, with provider and cross-content tests.
5. Add shared authenticated controls and public UI; test grant/denial/revocation and localization.
6. Deterministic verification, independent Code Review and Test Analyst at one checkpoint, executable UI/API QA, final architecture/design alignment and whole-scope review. Run contract and visible delivery gates.

## Complexity Tracking

No new architectural subsystem. One persisted link-generation table is necessary to recover the same active link, retain expired generations, and bind public access to exact content. A stateless signed URL alone does not represent generation history or active-link reuse.
