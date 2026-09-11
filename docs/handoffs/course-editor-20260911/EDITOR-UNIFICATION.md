# Handoff B — one shared editor implementation

Read README.md and SHARED-CONTRACT.md first. You are the production implementation/integration agent. The independent test-authoring agent owns tests and test-runner configuration. You are not alone: preserve all existing dirty edits, do not overwrite tests, and do not modify requirements because a test fails.

## Goal and non-goals

One PHP/Blade/browser-state editor for company courses and platform shared courses, with the same reusable content/assessment editor for standalone platform modules. Ownership/permissions/publication policies vary explicitly; the editor is not duplicated. Incorporate all established fixes, including assessment control width and course title/description display width.

Do not delete the platform implementation first or copy company code over it. Do not migrate production data, collapse authorization into a client is_shared flag, alter assignment history, or remove concurrency/version protections to simplify extraction. No unrelated tool-policy fix is bundled.

## Current map

- resources/views/components/courses/⚡editor.blade.php: company editor. Autosave per authored field via UpdateCourseEditorField; structural mutations via dedicated Actions; client revision/error/reorder state. This is where the LAST lifecycle fixes were implemented and browser-tested.
- resources/views/components/platform/shared-courses/⚡editor.blade.php: separate PHP and markup, staged draft edits and explicit save. Uses PrepareSharedCourseEditor, SaveSharedCourseEditorDraft, PublishSharedCourseDraft and SharedModuleDraftWriter.
- resources/views/components/platform/shared-modules/⚡editor.blade.php: separate standalone editor, explicit save via SaveSharedModuleEditorDraft, same SharedModuleDraftWriter backend.
- Shared pieces already include Flux content editor/browser extensions, LessonContentRenderer/Sanitizer, video actions/library, question/option models. Module extends Lesson; ModuleVersion extends Module with published version protections. Similar storage does not mean identical authority or publication effects.
- SharedModuleDraftWriter validates entire staged assessments with revision checks. Company UpdateCourseEditorField writes incremental fields. D-01 is now explicit Save for both contexts; consolidate draft save semantics without silently copying either implementation as product authority for remaining detailed decisions.

## Verified Git history and visible regressions

- 21f7c7c (2026-09-03), refine assessment and learner layouts: fixed platform answer wrapper width using field:class="min-w-0 flex-1". Company prompt/answer markup still puts flex sizing on the inner Flux control rather than its labeled field wrapper. Reproduce the narrow fields; fix the shared component, not three independent copies.
- a43d680 (2026-08-28), preserve course hero content width: page-hero moved title/description below the action row so buttons cannot squeeze them. Already a common component change; preserve it.
- 3ef8f9b (2026-09-03), expand shared course description: page-hero gained descriptionClass default max-w-2xl; platform shared-course show passes description-class="max-w-none". Company courses/⚡show.blade.php still omits it. This is DETAIL DISPLAY, not an assessment input. Apply an agreed common course-header presentation, without globally widening unrelated pages accidentally.

## Recommended extraction boundary (proposal, not approved architecture)

Use one editor component/state coordinator with common Blade components for content and assessment. Avoid separate reactive components for every input unless justified; preserve stable record identities and deliberate state ownership. Route/layout wrappers may identify course, owner and authorized capabilities, then render the same editor. Common draft validation/persistence belongs in Services/Actions, not duplicated view classes. Context-specific server authorization, shared publication/propagation and draft lineage operations remain explicit collaborators behind the shared flow.

No two giant editor branches selected by owner, no duplicated Alpine x-data, and no shared HTML layered over two independent implementations of common save/reorder logic. Thin context wrappers are acceptable; genuine different domain actions are not accidental duplication.

## Work order

1. Apply owner-decided D-01 (explicit Save in both contexts; do not ask again) and prepare required spec/scenario map/architecture/execution contract using installed Toscanini/Spec Kit. Owner intent to unify is not approval of an unreviewed architecture. Critical assurance applies due persistence/concurrency/authorization boundaries. Establish actual dependency/test/browser readiness before unattended implementation; report genuinely remaining owner decisions together.
2. Inventory current behavior and protections on BOTH sides with raw code and existing tests; preserve user fixes that are not yet committed. Share only behavioral interface/semantic selectors with test author, not desired review verdicts.
3. Extract common state/form behavior and common Actions incrementally; move both entry points to it. Preserve working routes/layout/navigation and server-side ownership checks. If a larger schema change appears needed, return that actual scope decision rather than silently performing migration.
4. Correct CE-02 widths in the shared controls/course-header use. Long question/answer/title/description fixtures at desktop and320 must be visibly usable, not merely produce no overflow.
5. Preserve CE-03–08: stable identity, correct persistence, truthful errors, direct/module composition safety, conflict handling, media state, immutable publication and history. D-01 may change test steps but must not weaken no-data-loss guarantees.
6. Integrate test workstream at the same checkpoint; run its independent suite in company/platform contexts. Do not edit expectations to get green. Fix actual deviations and consolidate findings.
7. Remove superseded implementations only after routes use common code and coverage passes. Keep remaining contextual differences small, named and justified. Provide a concrete map proving where UI, state, validation and writes now live.
8. Run canonical and targeted browser/client verification, independent reviews and executable QA per approved contract. Follow directed revalidation after a correction batch; no repeated full-scope loop. Record the known installed gate inconsistency honestly; do not bypass or modify old approvals.

## Deliverables

Shared production editor and thin context entry points; preserved domain guarantees; width fixes; deleted obsolete duplicated code only after replacement; architecture/conformance evidence; exact integrated code/test checkpoint and results for all contexts; remaining limitations and measured runtime. No claim that code is unified merely because both screenshots look alike. No new PR, commit, deployment or scheduled task without user authorization.
