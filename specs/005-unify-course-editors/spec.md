# Feature Specification: Unified Course Editors

**Feature Branch**: `codex/discard-module-drafts`
**Created**: 2026-09-10
**Status**: Approved — 2026-09-10
**Input**: Unify company courses, platform shared courses, and standalone platform modules on one reusable editor behavior and presentation while preserving context-specific authority, publication, lineage, history, and concurrency protections. Use explicit Save with an unsaved-changes warning in every context, correct assessment-control and course-detail widths, and add reproducible cross-context automated coverage.

## Clarifications

### Session 2026-09-10

- Q: What is the persistence boundary for authored fields in company and platform editors? → A: Explicit Save with an unsaved-changes warning in both contexts; typing and blur do not persist.
- Q: Does the saved watch threshold gate assessment availability? → A: No; assessment remains available when the lesson opens and the threshold remains tracking/reporting data.
- Q: May direct lessons and reusable modules silently coexist or replace one another? → A: No; they are mutually exclusive, legacy mixed state is preserved for recovery, and affected preview/publication paths fail closed.
- Q: Which structural and media changes belong to the explicit Save boundary? → A: Dedicated structural and media operations persist immediately through named server-authorized actions with dirty/upload safety gates; authored scalar, rich-content, and assessment values use the atomic Save boundary.
- Q: Is Save atomic across the visible draft? → A: Yes. Any validation, authorization, stale-revision, unavailable-reference, or unexpected failure commits none of the staged draft graph and preserves all staged work.
- Q: Where should actual application-server outage recovery run? → A: Approved: deterministic failed responses gate every pull request; actual stop/restart recovery runs in scheduled and manually triggered CI.
- Q: Which browser runner should own the regression suite? → A: Planning proposal pending dependency smoke and contract approval: Pest Browser with project-owned Playwright dependencies; use standalone project-owned Playwright only if the probe cannot satisfy accepted scenarios.
- Q: What rendered width proves assessment controls are useful rather than merely non-overflowing? → A: Approved: at desktop, question and answer text wrappers use at least 70% of their content row; at 320 CSS pixels they stack and use at least 90% of the inner container. Course title/description occupy their own full-width row below the independent action row.

## User Scenarios & Testing

### User Story 1 - Edit and save through one reliable experience (Priority: P1)

An authorized company or platform author can edit course or module details, content, and assessments through the same editor behavior. Changes remain visibly pending until Save, and success means the intended draft graph was committed without overwriting a newer revision.

**Why this priority**: A common, truthful save contract is the central value of the unification and the primary protection against data loss.

**Independent Test**: Populate three distinct records in each applicable context, change scalar and nested assessment fields, reorder then edit a moved record, save, reload, and verify identities, values, ordering, status, and conflict handling.

**Acceptance Scenarios**:

1. **Given** an editable company course, shared course, or standalone shared module, **When** the author types or blurs a changed authored field, **Then** no draft row changes and the editor reports unsaved changes.
2. **Given** valid pending edits, **When** the author selects Save, **Then** one context-authorized save commits the complete applicable authored payload and reload shows the intended values on the same records.
3. **Given** a stale editor revision, **When** Save is attempted, **Then** it is rejected without partial writes, pending local values remain available, and the editor never reports Saved.
4. **Given** no actual edit, **When** a field is focused and blurred or Save is selected, **Then** no new revision is created and the editor remains truthful.

---

### User Story 2 - Preserve structural, media, ownership, and publication safety (Priority: P1)

An authorized author can perform applicable structural and media operations without losing unrelated pending values or bypassing the distinct company/platform ownership, composition, lineage, publication, and history rules.

**Why this priority**: The editors sit on audit and publication boundaries where an incorrect abstraction could erase content, cross tenant boundaries, or rewrite historical evidence.

**Independent Test**: Exercise each applicable structural/media action and failure path with distinct records, dirty-state guards, server authorization, stale payloads, mixed composition, published records, draft lineage, discard, and assignment-history controls.

**Acceptance Scenarios**:

1. **Given** a confirmed structural or media action and a safe authored/upload state, **When** it is dispatched, **Then** it becomes visibly pending and may persist only through its dedicated server-authorized operation; rejection or failure leaves the protected graph unchanged and retryable.
2. **Given** unrelated dirty data or an earlier error, **When** search, refresh, or another dedicated operation succeeds, **Then** unrelated pending values/error provenance remain and the page cannot report the authored graph Saved.
3. **Given** direct, reusable-module, or legacy mixed composition, **When** the author edits or attempts a transition, **Then** no silent conversion or content deletion occurs and mixed state fails closed with recovery guidance.
4. **Given** published content or historical assignments/certificates, **When** an author edits, publishes, discards, or retries, **Then** immutable versions and historical references remain unchanged except through already-approved explicit publication effects.
5. **Given** an unauthorized, revoked, foreign-tenant, or client-forged context, **When** any read or write is attempted, **Then** the server denies it and an authorized positive control succeeds.

---

### User Story 3 - Use the editor and course details at every supported width (Priority: P2)

Authors can read and operate long course, question, and answer content at desktop and 320 CSS pixels without fields collapsing, controls clipping, focus becoming ambiguous, or primary actions causing horizontal overflow.

**Why this priority**: The known regressions make real content difficult to author and inspect even when persistence is correct.

**Independent Test**: Render long distinct titles, descriptions, questions, and answers in every applicable context at desktop and 320 CSS pixels; measure field wrappers and visible usable area, use keyboard focus, and verify the company and shared course detail headers follow the same course-specific width contract.

**Acceptance Scenarios**:

1. **Given** long question and answer text, **When** the editor renders at desktop or 320 CSS pixels, **Then** each labelled field wrapper receives the available row width, remains editable and focused, and the page has no horizontal overflow.
2. **Given** long course title/description text plus hero actions, **When** a company or shared course detail page renders, **Then** actions do not squeeze the text and both course contexts use the same intentionally wide description presentation without widening unrelated pages.

---

### User Story 4 - Run one reproducible regression suite in every context (Priority: P2)

A maintainer can prepare synthetic fixtures and run the effective shared-editor checks locally and in CI without a personal cache, chosen database IDs, pre-existing session, or silent browser-test skip.

**Why this priority**: The shared implementation is only maintainable if the same observable contract is continuously proven across its context adapters.

**Independent Test**: Start from a clean test environment, run the documented command, and verify company course, shared course, and applicable standalone-module cases execute with explicit pass/fail/N/A results and useful failure artifacts.

**Acceptance Scenarios**:

1. **Given** the documented prerequisites, **When** the regression command runs, **Then** it creates isolated synthetic fixtures, executes applicable PHP/client/browser checks for every context, and exits nonzero on failed or missing required browser setup.
2. **Given** a capability that genuinely does not apply to one context, **When** results are reported, **Then** that case is explicitly N/A with a reason rather than silently skipped.

### Edge Cases

- Another author changes the canonical draft after the page loads.
- A moved record is edited while temporary and persisted records coexist.
- A save contains valid early fields but an invalid later question or answer.
- A structural request is confirmed, declined, duplicated, loses its response, or returns after an unrelated request.
- A module referenced by retry becomes unavailable, archived, foreign, or revision-stale.
- A media upload/link completes after its rendered index moves, or another upload remains active.
- The editor opens direct-only, reusable-module-only, empty, or legacy mixed composition.
- Permission is revoked after mount but before any mutation, discard, or publication.
- A published module/course has an existing lineage draft, references, or assignments in multiple states.
- Long unbroken and natural-language content appears at 320 CSS pixels with keyboard focus.

## Requirements

### Functional Requirements

- **FR-001**: Company-course and platform shared-course routes MUST render one shared implementation for common PHP state coordination, authored form behavior, assessment/content presentation, and browser interaction; standalone shared modules MUST render the same applicable module/content/assessment implementation. Thin context entry points and named policy collaborators are permitted; cloned common state or save implementations are not.
- **FR-002**: Each entry point MUST resolve server-owned context and authorized capabilities. A client-provided ownership/shared flag MUST NOT select authority, tenant, lineage, discard, publish, or propagation behavior.
- **FR-003**: Authored scalar, rich-content, and assessment edits MUST be staged locally and persist only through explicit Save. Input, change, and blur alone MUST NOT write draft data.
- **FR-004**: Save MUST validate the complete applicable authored scalar, rich-content, and assessment graph, reauthorize, compare expected server revision(s), and commit atomically. Validation, authorization, stale-revision, unavailable-reference, or unexpected failure MUST leave every persisted row for that Save unchanged and preserve all staged authored work for correction or retry.
- **FR-005**: Successful Save MUST refresh the canonical graph and revision token(s), and report Saved only when its acknowledgement matches the latest local edit generation. Unchanged input/blur and stale responses MUST NOT create a revision or clear newer dirty data.
- **FR-006**: Shared browser state MUST distinguish clean, dirty, saving/pending, saved, validation error, conflict, and request/network error. Errors MUST retain provenance and a retry path until that cause resolves or the graph is deliberately reloaded.
- **FR-007**: Navigation, close, discard, preview, and publication MUST guard unsaved/saving/error state as appropriate, covering browser unload and in-application navigation without duplicate listeners.
- **FR-008**: Dedicated add, remove, reorder, composition, and media-association operations MAY persist immediately outside authored Save, but MUST use named server-authorized Actions, fresh identity/revision checks, confirmation where required, dirty/upload safety gates, and operation-specific pending/failure/retry state. Declining confirmation MUST dispatch nothing; success or failure MUST never clear unrelated authored dirty fields or errors. Question type, prompt/answer text, attempts, correct-answer selection, scalar values, and rich content remain in authored Save.
- **FR-009**: Persisted and temporary lesson/module/question/option identities MUST remain stable across reorder, DOM updates, save, retry, and reload. Ordered-ID validation MUST reject duplicate, missing, stale, cross-parent, or cross-owner payloads before positional writes.
- **FR-010**: Direct content and reusable-module composition MUST remain mutually exclusive. Legacy mixed state MUST preserve both inventories and fail closed for preview/publication until explicit non-destructive recovery.
- **FR-011**: Shared-module draft lineage, exact-copy behavior, revision protection, composition references, discard rules, publication propagation, and partial-failure retry MUST remain context-specific and intact.
- **FR-012**: Company tenant isolation and publication/assignment effects MUST remain context-specific and intact. Published versions, assignment references, certificate history, and compliance evidence MUST never be rewritten by editor unification.
- **FR-013**: Every read and mutation MUST re-check applicable route permission, Policy/Gate, ownership, editable-draft status, and nested-record scope. Revocation after mount MUST take effect; hidden controls alone MUST never authorize an operation.
- **FR-014**: Video transfer and authorized media-association operations MAY execute immediately through existing or focused Actions/provider contracts, MUST preserve stable target identity after reorder, MUST never persist a permanent public URL, and MUST retain retryable operation state on provider failure. Their acknowledgements MUST NOT mark unrelated authored values Saved. Rich content MUST continue through existing sanitizer/renderer contracts.
- **FR-015**: Assessment availability MUST remain independent of watch progress; the saved threshold remains tracking/reporting data.
- **FR-016**: At desktop width, each question and answer text wrapper MUST occupy at least 70% of its content row. At 320 CSS pixels, controls MUST stack and each text wrapper MUST occupy at least 90% of the inner content container, while retaining unique accessible labels, visible focus, and zero document overflow.
- **FR-017**: Company and shared course detail heroes MUST place title/description on their own full-width row below the independent action row and share a course-specific wide-description contract. Unrelated heroes MUST retain their default constraint.
- **FR-018**: New visible source strings MUST be canonical English with Portuguese translations; loading/error/status text MUST be accessible without color alone.
- **FR-019**: Automated coverage MUST reuse one behavioral matrix across all contexts while explicitly recording context-specific N/A cases.
- **FR-020**: The local/CI runner MUST create isolated synthetic fixtures, fake WorkOS/Cloudflare calls, require rather than silently skip the accepted browser suite, publish useful failure artifacts, and avoid personal paths, hard-coded IDs, and prior sessions.
- **FR-021**: Obsolete implementations MUST be removed only after all entry points use the shared implementation and the integrated cross-context suite passes at one recorded checkpoint.
- **FR-022**: Deterministic controlled request failures MUST run in every pull request. A focused actual application-server stop, restart, and recovery scenario MUST run in scheduled and manually triggered CI using the isolated synthetic harness; interception MUST NOT be presented as actual outage proof.

### Key Entities

- **Editor Context**: Server-resolved company-course, shared-course, or standalone-shared-module mode with authorized capabilities and named context-specific collaborators.
- **Editor Draft Snapshot**: Canonical authored values, stable identities, ordering, media references, and expected revision token(s) for an editable draft.
- **Pending Edit Set**: Latest local authored values and edit generation, including error provenance, not yet committed by Save.
- **Course Version / Module Version**: Existing versioned records whose drafts are editable and whose published state and lineage are historical evidence.
- **Structural Operation**: A dedicated immediate add/remove/reorder/composition Action with its own authorization, revision/scope checks, transaction, confirmation, and outcome, separate from authored Save.
- **Media Operation**: A dedicated immediate transfer/status/association Action bound to stable module/lesson/video identity and separate from authored Save.

## Success Criteria

### Measurable Outcomes

- **SC-001**: All three entry points use one common implementation for shared state, authored save behavior, content/assessment presentation, and browser interaction, with every contextual branch named and justified.
- **SC-002**: In every context, 100% of authored changes remain unpersisted before Save and persist on successful Save; every rejected save leaves the pre-save snapshot unchanged.
- **SC-003**: With three distinctly populated nested records, 100% of reorder/edit/save/reload checks preserve identity, intended values, and contiguous order without modifying siblings.
- **SC-004**: Every frozen authorization, composition, immutability, history, lineage, media, and conflict scenario has automated or executable QA evidence at the same final checkpoint.
- **SC-005**: Long question/answer wrappers occupy at least 70% of their content row at desktop and at least 90% of the inner container when stacked at 320 CSS pixels, accept keyboard input with visible focus, and cause zero horizontal page overflow.
- **SC-006**: Company and shared course details use the same wide presentation while unrelated page heroes retain their default constraint.
- **SC-007**: The required regression command runs from synthetic setup without personal paths, fixed IDs, or prior sessions; missing browser prerequisites fail the run and results identify context applicability.
- **SC-008**: Canonical verification and the frozen targeted suite complete at one integrated checkpoint with measured durations and no unresolved in-contract blocker.

## Assumptions

- No schema migration or production data rewrite is required; discovery of one returns for scope amendment.
- Authored scalar, rich-content, and assessment values share the explicit atomic Save boundary. Dedicated structural/media operations persist independently through named Actions only when their dirty/upload safety gates permit.
- A stale whole-draft save is rejected rather than silently merged; local pending values remain available.
- Pull requests use deterministic controlled network failures; scheduled/on-demand CI retains focused actual stop/restart recovery proof.
- Existing WorkOS, Cloudflare, versioning, publication, propagation, audit, and assignment contracts remain in force and are faked in tests.
- Thin server-side context adapters/capabilities are allowed, but the browser cannot choose ownership or authorization behavior.
- Existing scenarios in `specs/004-fix-course-editor/scenarios.md` are regression inputs, not proof and not authority for superseded autosave.
- Pest Browser is the selected browser test interface because it follows project Pest conventions and integrates Laravel fixtures with Playwright-backed browser automation. Standalone project-owned Playwright is the fallback only if the approved compatibility probe fails.
- Rendered-width checks use element and container bounding boxes, not CSS source strings or screenshots alone.

## Out of Scope

- Production data migration, schema cleanup, or model/table renaming.
- Changes to history, evidence, scoring/assessment availability, or publication policy beyond preserving approved effects.
- A client-editable ownership or `is_shared` switch.
- Replacement of WorkOS, Cloudflare, Livewire, Flux, or the authorization architecture.
- The historical Toscanini directed-QA gate inconsistency.
- Commit, push, pull request, deployment, or scheduled automation.
