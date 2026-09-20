# Implementation Plan: PDF library in the lesson editor modal

**Branch**: `feature/lesson-pdf-links` | **Date**: 2026-09-16 | **Spec**: [spec.md](spec.md)

**Status**: Approved for implementation on 2026-09-16; see approval.md. Run `pdf-library-20260916`, scope `pdf-library-v1`.

## Summary

Extend the existing PDF modal with a searchable paginated owner-specific library and Open, Reuse, Archive actions. Company A, company B and the platform remain separate owners. Preserve immutable document bytes/metadata and old lesson links. Add explicit catalog capabilities without granting a learner access to a library.

The concrete design is to be authored in architecture.md by the Architect. This plan proposes native Laravel Services/Actions and a separate archival record; research.md records evidence and alternatives. No global document management page, cross-owner sharing, physical deletion, restoration, unrelated editor changes or tooling repairs.

## Technical Context

- Language/runtime: PHP 8.3+ project requirement, installed PHP8.4.14 and Node20.20.2; JavaScript, Blade.
- Dependencies: installed Laravel13.26.1 / Livewire4.4.1 / Flux Pro; no new packages expected.
- Storage: PostgreSQL app, isolated SQLite feature tests, existing private lesson_documents filesystem disk. Additive archival table, no destructive data backfill.
- Testing: Pest, existing direct Playwright PDF harness, focused JavaScript checks. Detailed scenario-to-test mapping lives in scenarios.md, all NOT_RUN for this extension.
- Target: authenticated desktop/mobile web; existing company course, platform course and platform module editors.
- Performance/scale: paginated projection of 20 rows, deterministic newest-first ordering; reach all results with search including >60 files. No benchmark/load project.
- Constraints: thin components, explicit policies/capabilities, private bytes, no .env modifications, published-content immutability, current editor staged-save/selection behavior.

## Constitution Check

The installed constitution is an unfilled template, not an adopted policy. Follow AGENTS.md and docs/control-center-design-system.md instead; do not edit either. Pre-design check: proposal preserves domain invariants and native action/service layering. Post-design check and framework assessment belong in architecture.md. No architectural exception is currently proposed. Spec/plan/architecture/contract owner approval remains a prerequisite for Worker dispatch.

## Project Structure

Documentation: spec.md, scenarios.md, checklists/requirements.md, this plan, research.md, data-model.md, contracts/library.md, quickstart.md and architect-owned architecture.md. Tasks are generated only after approval.

Expected source seams (exact class selection finalized by Architect):

- app/Services/Documents/: authorized owner-scoped library projection and file reading.
- app/Actions/Documents/: reuse attachment and archive mutation; preserve existing upload logic.
- app/Models/, app/Policies/, database/migrations/: retained archival state and record authorization.
- app/Enums/Permission.php, PlatformPermission.php and existing catalog sync/prerequisite convention.
- app/Http/Controllers/, routes/web.php: private library opening with route middleware and record policy.
- app/Livewire/CourseEditor/EditorCoordinator.php and three Contexts: thin library state/action delegation.
- resources/views/components/course-editor/root.blade.php and PDF insertion JS only where needed; lang/pt_BR.json.
- tests/Feature/Documents/, focused access prerequisite tests, existing PDF browser suite and safe fixture harness.

## Verification and rollout boundary

Use scenarios.md's eight QA scenarios as the complete proposed runtime matrix. Tests include positive access controls, foreign-owner denials both directions, archive/reuse ordering, pre-archive attachment save, old published/copied links, exact cursor continuation and failure preservation. Any implementation gap is resolved inside that scope or returned for amendment, not added silently.

Migration execution on the user's local/shared database, deployment, pushing and updating PR #25 are not part of this planning phase. Disposable verification data only. Existing earlier PDF-run gate failures remain historical and are not cleared by this new run.

## Execution-readiness probes

On 2026-09-16: `npm run build` passed; existing PDF upload tests passed3/42; existing actual browser suite passed20.4s with synthetic database/files under `/tmp/oceanix-pdf-qa-96uSB2`. These prove installed dependencies and the isolated validation path, not the new library. A disposable PostgreSQL database with two verified independent connections is ready for B-12's native row-lock probe; see quickstart.md. Actual concurrency testing remains NOT_RUN. Owner approval and affected readiness refresh remain pre-dispatch conditions. No extensions.yml exists, so before/after plan hooks are absent.
