# Implementation Plan: WorkOS invitation reconciliation
**Date**: 2026-10-01 | **Spec**: spec.md | **Base**: main 2370b33

## Summary
Separate provider invitation projection from tenant-local access status; batch manual reconciliation, safe explicit invitation recovery and assignment eligibility independent of status.

## Technical Context
PHP 8.4 runtime; locked Laravel 13, Livewire 4 and Flux Pro; PostgreSQL production, SQLite tests. Pest and Http::fake. Current framework-native Action/Service/Eloquent/Job patterns retained. No new package proposed. Operator batch processing uses existing queue; representative QA uses isolated SQLite and stub WorkOS, never production credentials.

## Constitution Check
Constitution is an unfilled template; AGENTS.md and docs/product-spec.md are actual rules. User's explicit all-person assignment decision supersedes active-only eligibility. Preserve English-first UI/PT-BR translations, tenant boundaries, append-only events, immutable versions, existing access profiles and .env. Architecture and execution-contract approval are required before implementation.

## Project Structure
Extend auth callback/tenant entry actions and new focused RecordTenantAccess action; People projection Service; WorkOS invitation read/send Service; company/actor-scoped reconciliation Action/Job and sync run model; user invitation/access fields and migration; People list/detail/filter controls; Permission/Policy/catalog; assignment eligibility; Pest and browser checks. Architect assigns precise names and responsibilities.

## Research and Design
See research.md, data-model.md, contracts/operations.md and architecture.md. No framework API assumptions from WorkOS internal architecture. No webhook scheduler, platform-user management redesign, production mutations or generic auth refactor.

## Validation
Frozen SC-001–018 map to QA-001–018 and AC/INV in execution-contract.json. New behavior tests before fixes where feasible; targeted tests plus composer verify, Pint and Toscanini gates. Independent Code Review and Test Analyst share one stable checkpoint; real UI/API QA; one final Architect conformance check. Critical assurance budgets: 17 specialist starts, two consolidated remediation rounds.

## Execution Readiness
Dependencies installed/probed in the worktree before Worker dispatch. No .env copied or changed; QA configuration uses process environment. Pending readiness is recorded honestly in the contract. Architecture authoring may proceed while setup probes run; Worker may not.
