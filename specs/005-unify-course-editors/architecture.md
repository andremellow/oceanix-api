# Architecture: Unified Course Editors

## 1. Context, references and decision scope

This artifact covers the single Laravel repository `oceanix-api` and proposed scope `unified-course-editor-scope-v1`. It implements the boundaries needed by `spec.md`, `plan.md`, `data-model.md`, `contracts/editor-ui.md`, AC-01–AC-10, INV-01–INV-08, and UCE-01–UCE-20. It preserves the three route identities, company/platform authentication differences, existing publication and discard actions, shared-module lineage and propagation, published-version and assignment/certificate history, the `VideoProvider` boundary, English-first localization, and the control-center component system.

There is no reference project. `docs/handoffs/course-editor-20260911/SHARED-CONTRACT.md`, `specs/004-fix-course-editor/**`, the current implementations, and their tests are behavior/regression evidence only. They are not authority to retain autosave, view-owned Eloquent writes, or duplicated editor architecture.

| Convention / decision | Repository or reference-project evidence | Classification | Why needed |
| --- | --- | --- | --- |
| Full-page Livewire 4 SFC route entries under `resources/views/components/**` | `AGENTS.md`; `routes/web.php`; the three current `⚡editor.blade.php` files | Existing convention | Retains route/layout/navigation compatibility and framework-native hydration. |
| Thin Livewire presentation delegating reads to Services and writes to Actions | `AGENTS.md`; `app/Services/Courses/**`; `app/Actions/Courses/**`; `app/Actions/Modules/**` | Existing convention, enforced more consistently | Prevents the shared view from becoming the persistence/domain boundary. |
| One abstract editor coordinator, one shared Blade surface, and one browser state module | FR-001; current 2,731-line editors contain duplicated state/mapping/markup | New feature-local decision | Shares actual behavior and state rather than only visual fragments. No repository-wide Livewire conversion is proposed. |
| Three hard-coded server context adapters | Current `CoursePolicy`, `ModulePolicy`, `PlatformAccess`, platform account provenance, tenant routes | New feature-local decision | Makes legitimate authority/lifecycle differences explicit without a client `is_shared` switch or a giant conditional component. |
| Focused Eloquent Actions with `handle()` and read Services | Existing action/service naming and `AGENTS.md` | Existing convention | Gives atomic write and test boundaries without generic repositories. |
| Canonical snapshot revision hashes, no revision-column migration | `SharedModuleDraftWriter::revision()`, `SaveSharedCourseEditorDraft::revision()`, plan/no-migration constraint | Existing pattern extended feature-locally | Adds company whole-authored-graph conflict protection while staying within approved schema scope. |
| `field:class="min-w-0 flex-1"` and course-only `descriptionClass="max-w-none"` | Current platform assessment markup, `x-page-hero`, platform shared-course show, design-system Field/PageHero contracts | Existing UI mechanisms applied consistently | Fixes AC-07/AC-08 without globally widening unrelated heroes. |

Non-goals remain production migrations/data rewrites, model/table renaming, authorization or publication-policy redesign, employee assessment/scoring changes, provider replacement, and generic application layering. `Module`/`ModuleVersion` continuing to use the `lessons` persistence record is compatibility, not a naming pattern to spread.

### Framework suitability and reference assessment

| Target repository / framework / installed version | Official and adapter guidance actually consulted | Reference purpose and patterns accepted/rejected | Framework-native option and tradeoffs | Alignment / material conflict / specific owner decision |
| --- | --- | --- | --- | --- |
| `oceanix-api`: Laravel 13.26.1 on PHP 8.4.14 (supports 8.3+); Livewire 4.4.1; Flux/Flux Pro 2.17.0; Pest 4.7.8; Laravel Boost 2.7.0; Tailwind 4; PostgreSQL production/CI and SQLite routine tests | `AGENTS.md`, `docs/product-spec.md` §§3, 6, 7, 12, 14, 20–22, `docs/control-center-design-system.md` §§5–11; installed Boost `.ai/foundation.blade.php`, `.ai/laravel/core.blade.php`, `.ai/livewire/core.blade.php`, `.ai/pest/core.blade.php`, `.ai/enforce-tests.blade.php`, and Flux Pro skill; official Laravel 13 Service Container, Database Transactions, Query Builder locking, Authorization, and Blade docs; official Livewire 4 Components, `wire:model`, Actions, Lifecycle Hooks, and JavaScript docs; official Flux Input docs; official Pest 4 Browser docs | Current editors/tests establish route, lineage, media, publication, failure, and UI regression contracts. Accepted: SFC routes, Alpine/Livewire composition, Action/Service/Policy/Eloquent transactions, stable `wire:key`, Flux field targeting, `VideoProvider`, and Pest. Rejected: company per-field autosave, Eloquent/provider writes in SFCs, positional identity, three browser state machines, silent browser skips, and copying either current editor wholesale. No external reference architecture exists. | A shared abstract coordinator used by three thin SFCs, feature-local context adapters, Blade components, an Alpine module, and focused Actions/Services is the smallest native fit. One giant SFC would reduce files but retain unsafe owner branches; nested Livewire inputs would add hydration boundaries and identity complexity without an independent state owner. A trait could share code, but an abstract coordinator states the single owner and contract more clearly. | **Aligned.** Product-owner Option A on 2026-09-10 resolves D-04 as dedicated immediate structural/media Actions with dirty/upload guards and D-05 as atomic whole-authored-graph stale rejection. These are explicit product decisions, not framework mandates. No unresolved framework conflict remains. |

The installed Laravel Boost MCP/search-docs tool was not exposed in this agent session. The installed rendered Boost guidance was inspected directly and version-appropriate official documentation was used as the fallback; this artifact does not claim the Boost tool ran. Boost supports existing directories/components, server validation/authorization, explicit dependency approval, and focused Pest verification. Livewire 4 explicitly supports SFC/multi-file organization, trait lifecycle reuse, and component-scoped JavaScript cleanup; the abstract coordinator below keeps the established SFC route files while moving their shared PHP behavior to one class.

TDD is a delivery/test strategy, not a layer. The approved workflow should add/adjust independent Pest and browser checks before or alongside the production extraction where feasible, preserving defect-sensitivity evidence; no additional architecture reviewer or framework abstraction is introduced for TDD.

## 2. Architectural style

Use a **Laravel modular monolith with thin Livewire SFC entry points, one feature-local presentation coordinator, context adapters, focused application Actions, and Eloquent-backed read Services**.

The coordinator owns the one hydrated editor state machine and maps immutable application snapshots to the one shared Blade/browser presentation. Each SFC fixes its context adapter in server code. Context adapters authorize and delegate; they do not persist or implement domain policy. Read Services build canonical snapshots. Focused Actions own transactions, locks, validation, and writes. Existing Policies, platform authorization, models, database constraints, publication/discard Actions, and external-provider contracts remain the security/domain/infrastructure boundaries.

This is simpler than repositories, command buses, nested reactive components, or separate application layers. The only new abstraction with three implementations is justified by three entry points that share behavior but have genuinely different actors, ownership, composition, and lifecycle actions. No generic `EditorService`, `Manager`, `Helper`, or `Utils` class is permitted.

## 3. Directory and module structure

```text
app/
├── Actions/
│   ├── Courses/
│   │   ├── SaveCompanyCourseEditorDraft.php          # new whole-authored-graph Action
│   │   ├── SaveSharedCourseEditorDraft.php           # extend existing, preserve shared lineage
│   │   ├── ReorderSharedCourseModules.php             # new exact shared-course reorder Action
│   │   └── existing add/remove/reorder/composition/publish/discard Actions
│   ├── Modules/
│   │   ├── SaveSharedModuleEditorDraft.php           # extend existing payload/result contract
│   │   ├── MutateSharedModuleAssessmentStructure.php # new immediate add/remove Action
│   │   ├── ReorderSharedModuleAssessment.php         # new exact-sibling reorder Action
│   │   └── existing publish/discard/propagation Actions
│   └── Videos/
│       ├── RequestVideoUpload.php                     # existing immediate transfer slot
│       ├── LinkExistingVideo.php                      # existing immediate association
│       ├── SyncVideoAsset.php                         # existing immediate status reconcile
│       └── DetachEditorVideo.php                      # new non-destructive association removal
├── Livewire/CourseEditor/
│   ├── EditorCoordinator.php                         # new shared hydrated PHP coordinator
│   └── Contexts/
│       ├── EditorContext.php                         # narrow coordinator-facing contract
│       ├── CompanyCourseEditorContext.php
│       ├── SharedCourseEditorContext.php
│       └── SharedModuleEditorContext.php
├── Services/CourseEditor/
│   ├── EditorSnapshot.php                            # readonly UI-safe DTO
│   ├── EditorSaveCommand.php                         # readonly normalized command DTO
│   ├── EditorSaveResult.php                          # canonical snapshot/revision outcome
│   ├── EditorCapabilities.php                        # readonly server-derived capability DTO
│   ├── EditorSnapshotBuilder.php                     # common graph mapping/read projection
│   └── EditorRevision.php                            # pure canonical hash calculation
├── Services/Courses/                                 # existing composition/validation/rendering
├── Services/Modules/                                 # existing lineage/writer/validation
└── Policies/                                         # existing CoursePolicy and ModulePolicy

resources/
├── js/
│   └── course-editor.js                              # one Alpine state factory/listener cleanup
└── views/components/
    ├── course-editor/
    │   ├── root.blade.php                            # common page/editor composition
    │   ├── details.blade.php
    │   ├── content-record.blade.php
    │   ├── assessment.blade.php
    │   ├── media.blade.php
    │   └── save-bar.blade.php
    ├── courses/⚡editor.blade.php                     # thin company adapter selector
    ├── platform/shared-courses/⚡editor.blade.php     # thin shared-course adapter selector
    ├── platform/shared-modules/⚡editor.blade.php     # thin shared-module adapter selector
    ├── courses/⚡show.blade.php                       # opt into existing wide description variant
    └── platform/shared-courses/⚡show.blade.php       # retain same course-wide variant
```

The DTO classes are justified by the same graph crossing three context adapters and a hydrator; they prevent untyped arrays from becoming an implicit authority contract. They are not persistence entities. The shared Blade files are non-reactive components rendered by the parent coordinator; do not introduce one Livewire child per lesson/question/option.

The three existing routes and middleware names remain unchanged. Existing editor code is transitioned in this order: introduce snapshot/revision DTOs and coordinator; render the common surface behind the company entry while retaining its route; move standalone module and shared-course entries to the same coordinator; run the cross-context matrix at one checkpoint; only then delete superseded PHP/Blade/Alpine bodies from the three entry files. Remove `UpdateCourseEditorField` after explicit Save has replaced it and no caller remains. Retain/adapt the focused structure, media-association, upload-transfer, and status Actions selected by D-04; remove only truly duplicated or uncalled Actions after all entries use the shared coordinator. No route points at a half-migrated implementation.

## 4. Class responsibilities and non-use

| Type | Responsibility / principal class | When not used |
| --- | --- | --- |
| Livewire component | `EditorCoordinator` owns public form graph, canonical/local generations, save and operation state, stable expansion/upload keys, common methods, snapshot refresh, and error provenance. Each existing SFC extends it, declares its layout/root model, and returns exactly one hard-coded context adapter. | SFCs must not query/write Eloquent, call `DB`, implement validation rules, infer ownership, or duplicate common methods/markup/JavaScript. Nested per-input Livewire components are not used. |
| Controller | Existing preview/media controllers remain unchanged. | No controller is added for authenticated editor routes because `Route::livewire()` is the established native entry. No invokable controller decision applies. |
| Form Request | N/A. | Livewire actions are not HTTP-controller requests; rules belong in save/operation Actions so direct invocation is protected. |
| API Resource | N/A. | No public JSON API is introduced. `EditorSnapshot` is an authorized internal projection, not an API resource. |
| Action | All write/side-effect classes use `handle()`. New `SaveCompanyCourseEditorDraft`; extended `SaveSharedCourseEditorDraft` and `SaveSharedModuleEditorDraft`; existing named structure, media-association, upload-transfer/status, publication, and discard Actions remain or are adapted. Save owns only authored scalar/rich-content/assessment persistence. | No Action for rendering, mapping, capability display, or pure hashing. Never mix `execute`/`__invoke` naming. |
| Service / external client | `EditorSnapshotBuilder` is the shared read/mapping projection; `EditorRevision` is pure hashing; existing `CourseVersionComposition`, `LessonContentSanitizer/Renderer`, `ModuleLineageLock`, validators, `VideoLibrary`, and `VideoProvider` retain their precise responsibilities. | No vague `CourseEditorService`; no direct Cloudflare client from editor code; no Service performs writes except existing narrowly approved domain writer internals invoked inside an Action transaction. |
| Repository | N/A. | Eloquent relationships, scopes, focused read Services, and transactional Actions already provide the necessary boundaries; method-for-method repositories add no value. |
| Query Object | N/A as a separate class category. | Snapshot/composition/catalog queries are cohesive projections in named Services. A Query Object may be added only if profiling later proves an independently reusable complex query, not pre-emptively. |
| DTO / Value Object | `EditorSnapshot`, `EditorSaveCommand`, `EditorSaveResult`, `EditorCapabilities`; stable persisted record keys use strings `course:{id}`, `version:{id}`, `lesson:{id}`, `module-version:{id}`, `question:{id}`, and `option:{id}`. A `tmp:{uuid}` key may exist only as an in-flight presentation placeholder and must be replaced by the immediate Action result before another mutation or authored Save. | DTOs contain no Eloquent models, closures, services, authorization decisions, or provider URLs. `EditorSaveCommand` rejects temporary structural keys. Do not add a DTO per scalar field. |
| Model / persistence record | Existing `Course`, `CourseVersion`, `CourseVersionModule`, `Lesson`, `Module`, `ModuleVersion`, `Question`, `QuestionOption`, `Video`, and `ContentImage`. | No new model/table. Do not rename legacy records or treat `ModuleVersion` inheritance as permission to conflate company lessons and shared lineage. |
| Policy / authorization service | `CoursePolicy::updateVersion/publish`, `ModulePolicy::updateVersion/publish/use`, route permission middleware, and `PlatformAccess`. Context adapters invoke these and Actions repeat authorization after re-query/lock. | Capabilities hide/disable controls only; they never authorize. No client flag, DTO field, or route-model binding alone grants access. |
| Job / command | Existing module propagation jobs/commands and scheduled video reconciliation remain downstream of existing publication/provider Actions. The actual-outage CI harness is test infrastructure. | Editor Save and immediate structural/media requests are synchronous for truthful acknowledgement; upload transfer/status keeps its existing lifecycle. No new application job/command. |
| Event / listener | Browser events are semantic UI signals scoped to the root; existing audit/publication/propagation events remain. | No new domain event/listener for local dirty state or synchronous saves. No event is used to hide a partial write. |

`EditorContext` exposes only intention-revealing methods: `open(int $rootId): EditorSnapshot`, `capabilities(int $rootId): EditorCapabilities`, `save(int $rootId, EditorSaveCommand $command): EditorSaveResult`, `performStructure(...)`, `performMedia(...)`, `publish(...)`, and `discard(...)`. An implementation may reject an inapplicable operation. It delegates each write to the named Action and each read to `EditorSnapshotBuilder`; it contains no `DB::transaction`, raw persistence, or generic owner switch.

## 5. Invocation, naming and query placement

All Actions expose `handle`. Read Services use `forCompanyCourse`, `forSharedCourse`, `forSharedModule`, `calculate`, `inspect`, `problems`, or similarly specific verbs. Controllers are not added. Persistence names remain unchanged.

The entry-point rule is fixed in server code, for example:

```php
// courses/⚡editor.blade.php
protected function editorContext(): EditorContext
{
    return app(CompanyCourseEditorContext::class);
}
```

The platform files return `SharedCourseEditorContext` or `SharedModuleEditorContext`; no public/hydrated property chooses that class. A locked root ID is still untrusted: every read and write re-queries it and verifies actor, ownership, draft status, root relationship, and nested scope (FR-002/FR-013, INV-03).

Eloquent queries are permitted in models for relationships/scopes, in `EditorSnapshotBuilder` and existing focused read Services for projections, in Policies/`PlatformAccess` only as needed for authorization, and in Actions for guarded writes, locks, uniqueness, and post-commit refresh. They are forbidden in `EditorCoordinator`, all SFC/Blade partials, JavaScript, DTOs, and generic context adapters. Context adapters may resolve the current authenticated actor and call Services/Actions; they may not issue model queries. Raw `DB` calls are limited to existing database-aware Actions/migrations and are forbidden in presentation.

The snapshot builder loads each graph with explicit owner/root constraints and deterministic `position,id` ordering. It explicitly includes answer correctness only for an authorized editor projection; it excludes secrets, provider upload/playback URLs, audit payloads, and unrelated historical evidence. The revision calculator hashes canonical normalized persisted values and ordered stable IDs, not array indices, browser generations, localized labels, or `updated_at` alone.

## 6. Dependency direction

```text
routes + auth/permission middleware
                |
                v
thin context-fixed SFC --> EditorCoordinator --> shared Blade + course-editor.js
                                  |
                                  v
                     concrete EditorContext adapter
                         /        |         \
                        v         v          v
                 Policy/access  read Service  focused Action
                                     |            |
                                     v            v
                              Eloquent models <-- DB transaction/locks
                                                     |
                                                     v
                                      existing audit / VideoProvider / jobs
```

Permitted: presentation coordinator → context contract/DTOs; concrete context → Policy/access, read Service, or named Action; Action → Policy/Gate/access, validator/sanitizer/lineage/composition Service, model/DB, existing audit or external boundary; read Service → model relationships and pure DTO/revision mapping.

Prohibited: browser/Blade/coordinator → model query, DB, concrete Cloudflare, publication/propagation internals; model → Livewire/Blade/context; read Service → Action/write; one context adapter → another context adapter; company context → platform actor paths; platform contexts → tenant impersonation; Action → Flux/Alpine. `EditorContext` must not become a service locator or branch on client ownership data.

## 7. Cross-cutting ownership and system contracts

| Concern | Owning layer / class | Contract and constraints, or N/A reason |
| --- | --- | --- |
| Request validation | Authored Save Actions and dedicated structure/media Actions; coordinator only maps framework validation errors | Save validates the complete authored scalar/rich-content/assessment-field payload for the current persisted graph and stable identity grammar; trims/canonicalizes strings; checks bounds and correct-answer cardinality, and rejects temporary structural keys. Dedicated Actions validate exact unique ordered sibling sets, confirmations, and media targets. Every path rejects unknown, missing, duplicate, stale, cross-parent, or cross-owner IDs before writes. Rich content is sanitized only when marked changed. |
| Authorization / security boundary | Existing route middleware, `CoursePolicy`, `ModulePolicy`, `PlatformAccess`, context adapter, and every Action | Entry mount/read, every hydrated mutation, Save, preview, publication, discard, transfer/link, and retry reauthorize. Company actor is current `User`; platform actor is active authorized `Account`. UI capability is presentation only. Revocation after mount denies with zero writes (INV-03). |
| Tenant isolation / data ownership | Policies/access plus explicit Action/read-Service predicates | Company graph uses the authenticated `company_id`; shared graph requires `company_id = null`, `is_shared = true`, active platform account, correct manual draft/lineage. Global scopes are defense-in-depth, not the only predicate. |
| Mapping | `EditorSnapshotBuilder` and DTO constructors | Map canonical graph to UI-safe scalar arrays in deterministic order. Persisted/temp keys survive reorder and morph. Never bind upload token or record identity to rendered index. |
| Transactions and locks / concurrency | Authored Save, dedicated structure/media Actions, and existing lifecycle Actions | Authored Save locks in stable order: root `Course`/`Module`, editable `CourseVersion`/`ModuleVersion`, composition rows and lineage versions needed for revision integrity, then authored lessons/modules, questions, and options. Each immediate structure/media Action locks only its root, exact target/siblings, and affected composition/media rows in the same global order. Every Action recomputes its expected canonical revision after locks and before writes. On deadlock, use Laravel transaction retry only for safe database deadlocks; never retry a stale/validation/authorization decision. PostgreSQL is required evidence for competing-writer paths. Remote upload transfer is not held inside a database transaction. |
| Persistence | Focused Actions through Eloquent/database constraints | Draft rows only. Preserve ownership checks, course/composition positional uniqueness, current-video uniqueness, published model guards, and assignment/certificate restrictive references. Explicit confirmed removal may delete only editable draft children; it is never silent and never touches published/history/evidence. |
| External calls and timeouts / API contracts | Existing upload-transfer/status and media-association Actions, `VideoLibrary`, `VideoProvider`, storage | Provider transfer/status and media association operations execute through dedicated immediate Actions. Existing provider timeout/error translation remains. Store stable private provider asset IDs, never permanent public URLs. Tests fake WorkOS/Cloudflare. Never hold the authored Save transaction open during remote transfer; persist/reconcile the authorized upload or association through focused Actions using stable token/asset/record identity. |
| Error and outcome translation / failure behavior | Actions → typed authorization/validation/conflict/provider exceptions; coordinator → editor state | Validation maps to field/graph errors; stale revision maps to `conflict`; provider/network maps to its named operation. Unexpected exceptions are reported and shown as retryable request errors without leaking details. Only the matching successful generation/cause clears status; unrelated success never clears dirty data or another error. Authorization/lifecycle denial is not softened into a retryable validation message. |
| Serialization | `EditorSnapshot` and shared Blade semantic hooks | Only authorized editor fields and stable opaque keys. Status is text/ARIA announced, not color-only. No secrets, raw provider URLs, sensitive answer data outside editor, or raw IDs as user-facing content. |
| Configuration | Existing Laravel/Livewire/Flux/filesystem/video config; build config for one JS module | No `.env` change and no new product toggle. Node 22/Pest Browser dependency and CI configuration require the separately recorded readiness/owner approval. |
| Logging and telemetry | Existing `AuditLogger`, publication/propagation records, Laravel reporting | Preserve lifecycle audit effects. Do not log full drafts, answer keys, upload URLs/tokens, secrets, or authorization payloads. Dirty/Saved is UI state, not compliance evidence. |
| Retry behavior | User-initiated coordinator retry; existing idempotent propagation/provider reconciliation | Save retry reuses retained local graph with its original expected revision until conflict resolution/reload. Duplicate in-flight UI actions are disabled. A lost acknowledgement is not assumed successful; refresh/reconciliation must compare canonical revision before offering retry. No automatic merge/last-write-wins. |
| Migrations and rollback | N/A schema; transaction rollback; staged deployment transition | No migration/data rewrite. Failed transaction restores the complete pre-operation database snapshot. Application rollback restores prior code/routes; records remain compatible. Old editor bodies are removed only after all entries use common code and the integrated checkpoint passes (AC-10). Discovery of schema need returns for scope amendment. |

### Resolved D-04 — dedicated immediate structure/media boundary

Product-owner Option A selects dedicated immediate operations. This includes lesson/module/question/option add, remove, and reorder; course composition changes; and video attachment, replacement, removal, transfer, and status. Company operations retain/adapt `AddDirectCourseLesson`, `RemoveDirectCourseLesson`, `ReorderDirectCourseContent`, `UpdateCourseModuleComposition`, and the add/remove branches of `MutateCourseAssessmentStructure`; its `select_single_correct` branch is retired because correctness is an authored field committed by Save. Shared-course composition retains/adapts `CreateAndAttachSharedModule` and `RemoveSharedCourseModule` and adds `ReorderSharedCourseModules`. Shared-module assessment structure uses new `MutateSharedModuleAssessmentStructure` and `ReorderSharedModuleAssessment`. Media uses `RequestVideoUpload`, `LinkExistingVideo`, `SyncVideoAsset`, and new `DetachEditorVideo`; detaching changes only the editable draft association and never deletes the provider asset or historical media.

Each Action reauthorizes, validates exact identity/revision, takes stable locks, and owns one transaction. The coordinator blocks structural/media operations that require canonical replacement while authored fields are dirty/saving or uploads make refresh unsafe. Read-only search and upload transfer may continue during dirty state only when they merge results by stable key and provably do not reload, persist, or clear authored state/errors. The coordinator exposes operation-specific pending/failure/retry, refreshes the complete canonical snapshot and revision after a committed structural/media success, and never labels that outcome as the authored Save. This keeps provider work and bounded structure changes out of the authored Save transaction; the accepted tradeoff is that an author must Save or deliberately discard authored edits before any immediate operation whose canonical refresh could overwrite them.

Rejected alternative: stage structure and media associations in `EditorSaveCommand`. That would provide a single persistence boundary but materially enlarge authored payload identity validation, Save locking/deletion logic, and the conflict surface while eliminating focused Actions. Option A rejects that architecture.

### Resolved D-05 — atomic whole-authored-graph stale rejection

Product-owner Option A selects one atomic Save for all currently visible authored scalar, rich-content, correct-answer, and assessment fields on the persisted structure applicable to the context. Each Save begins one transaction, reauthorizes, locks the canonical authored rows in the order above, recomputes the canonical revision set, rejects any mismatch, prepares every authored write/sanitization, then writes all or none. Immediate structure/media state is excluded from `EditorSaveCommand` but participates in the expected root revision where needed so concurrent structural/media changes reject an unsafe authored Save. A no-op payload returns the current snapshot/revisions without touching timestamps or creating a revision. Success returns a fresh canonical snapshot plus revisions; the coordinator marks Saved only if the acknowledged local generation is still latest. Failure and conflict never reload over local staged values (INV-05).

Rejected alternative: per-subgraph Save/revision. Although transactions would be shorter, it permits partial authored-draft persistence and requires multiple dirty/error/acknowledgement owners, conflicting with FR-004/INV-05 and the selected one-Save experience.

## 8. Concrete execution flows

### Open any editor

1. The unchanged named route authenticates and applies its company or platform permission middleware.
2. Its thin SFC fixes one concrete context class and passes the route-bound root ID to `EditorCoordinator::mountEditor`.
3. The context re-resolves current actor/root and authorizes; `EditorSnapshotBuilder` loads the exact editable graph, classifies direct/module/mixed composition with `CourseVersionComposition`, and creates `EditorSnapshot`, `EditorCapabilities`, and canonical revisions.
4. Mixed composition exposes both inventories and recovery guidance but disables preview/publication and any operation that would silently convert/delete content (INV-06). The shared Blade root renders the same semantic hooks in all contexts, with inapplicable capabilities explicitly absent/N/A.

### Edit and explicitly Save the authored graph

1. `course-editor.js` receives native input/change on `[data-editor-field]`, increments `localGeneration`, and moves `clean/saved → dirty`; blur performs no persistence request.
2. Save/Ctrl-or-Cmd-S calls one coordinator `save()` with the complete applicable authored scalar/rich-content/assessment graph, `localGeneration`, and server revision set. The coordinator sets `saving` and delegates to its fixed context.
3. The context calls the applicable save Action. The Action follows §7 lock order, reauthorizes, checks editable ownership/status and exact identities, compares revisions, validates/sanitizes every authored subgraph, and writes inside one transaction.
4. Failure rolls back, maps to validation/conflict/request provenance, and retains local graph/generation. Success returns a fresh snapshot. The coordinator replaces canonical state and announces Saved only when the acknowledged generation equals the current generation; otherwise it stays dirty. No-op Save performs no write (AC-02/AC-03, INV-05).

### Confirmed immediate structural change and media association

1. A stable record key identifies the target. Declining confirmation dispatches nothing. Accepting calls `beginOperation(name,key)` and shows pending before Livewire dispatch.
2. The coordinator refuses the request while authored state is dirty/saving, another conflicting operation is active, or upload state makes canonical refresh unsafe. It delegates to the fixed context, which reauthorizes and invokes the named structure/media Action.
3. The Action re-resolves and locks the exact root/parent/siblings, validates expected revision and ownership, and commits only its bounded effect. Reorder uses temporary non-colliding positions then `1..N`; composition refuses direct/module mixing; confirmed removal touches only eligible editable draft children.
4. Success refreshes the complete canonical snapshot/revision and clears only that operation error; it does not announce authored Saved. Rejection/failure leaves the prior database snapshot and unrelated errors unchanged. Upload completion and association changes resolve by token + private asset + stable record key, never current index (AC-04/AC-05, INV-02/INV-06/INV-07).

### Preview, publish, discard, and lineage

1. Coordinator guards dirty/saving/error/upload state before lifecycle navigation; browser unload and `livewire:navigate` listeners are installed once by `course-editor.js` and removed on teardown.
2. The context omits/rejects unsupported actions and delegates supported ones to existing `CourseVersionValidator`, preview authority, `PublishCourseVersion`/`PublishSharedCourseDraft`/`PublishModuleVersion`, and discard Actions.
3. Each action reauthorizes and re-locks. Company publication retains explicit assignment behavior; shared-course/module publication retains manual-draft selection, lineage locks, idempotent per-company propagation and explicit restart choice; discard retains reason/revision and never deletes historical graph.
4. Published versions and frozen assignment/certificate/evidence references are never edited. Assessment availability remains independent of the saved tracking threshold (INV-01/INV-04/INV-08).

### Responsive/shared presentation

`assessment.blade.php` applies `min-w-0 flex-1` to each generated labelled Flux field wrapper and uses stable question/option keys and unique accessible labels. `course-editor.js` restores focus by stable record key after morph/reorder. At 1440 CSS pixels each question/answer wrapper is at least 70% of its content row; at 320 it stacks and is at least 90% of the inner container. Company and shared course show pages both pass `descriptionClass="max-w-none"` to the existing `x-page-hero`; the component default remains unchanged. At 320 CSS pixels actions reflow without hiding Save/status or causing page overflow (AC-07/AC-08).

## 9. Test seams

- Context contract dataset tests run the same snapshot/save/status/semantic-selector assertions against company course, shared course, and standalone module, with explicit capability N/A reasons (AC-01/AC-09). Context adapters are real; Eloquent is not mocked.
- Action/Pest feature tests use representative distinct records and real SQLite transactions for mapping, exact identity, validation, sanitization, no-op, rollback, authorization/revocation, ownership, composition, media metadata, publication, discard, lineage, propagation, and history. Existing tests named in `CourseEditorTest`, `SharedModuleAssessmentEditorTest`, `SharedCourseDraftIntegrityTest`, `CourseEditorActionAuthorizationTest`, `HybridCourseCompositionTest`, `SaveModuleAssessmentTest`, `DiscardModuleDraftTest`, `SharedContentConcurrencyTest`, `SharedModulePropagationTest`, and `PublicationAssignmentMigrationTest` are regression inputs to retain/adapt, not automatic proof of the new contract.
- PostgreSQL 17 integration tests exercise competing Save, publish/discard, composition, archive, and propagation locks because SQLite cannot prove production lock behavior (AC-02/AC-06; INV-01/INV-04/INV-05).
- Pure tests for `EditorRevision` prove deterministic order, scalar/nested/media sensitivity, persisted identity stability, rejection of temporary structural keys, and no localized/timestamp-only noise. A stale positive control changes canonical data through another authorized actor before Save.
- `VideoProvider`, WorkOS, filesystem/provider failures are faked at their existing contracts; no test calls real WorkOS/Cloudflare or persists a public URL. Database and provider boundaries remain integration-tested separately; remote upload transfer is not wrapped in a fake all-encompassing Save transaction.
- Client tests exercise the one Alpine state factory: input versus blur, monotonic generations, stale acknowledgement, per-cause errors, duplicate listener cleanup, confirmation decline/accept, navigation/unload, keyboard Save, and stable upload/focus keys. They do not assert private Alpine variable or exact `wire:model` spellings.
- A project-owned real-browser suite is required for rendered Flux wrapper dimensions, 1440/320 overflow, keyboard focus after morph/reorder, labels/errors/live status, dirty navigation, controlled request failure/recovery, and all three routes. Pest Browser is the planned first choice only after dependency/Chrome smoke; current direct Playwright evidence remains until parity and is the fallback. Missing browser prerequisites fail rather than skip.
- Pull requests run deterministic controlled failures; scheduled/manual CI performs the actual application server stop/restart recovery. The isolated fixture harness owns records, session/browser context, ports, database, and artifacts. Exhaustive cases remain UCE-01–UCE-20 and QA-01–QA-10; this section does not expand them.

## 10. Author verdict and classified conditions

Author readiness: `APPROVED_WITH_CONDITIONS`.

| Condition / open decision | Category | AC/INV or architecture section | Owner and next action | Could materially change architecture? |
| --- | --- | --- | --- | --- |
| Reconcile specification, scenarios, and execution-contract wording to Option A where they still describe staged structural/media association persistence. | implementation-task | FR-004–FR-008, FR-014; AC-02/AC-03/AC-05; INV-05/INV-07; §7 | Orchestrator updates its owned artifacts without changing the selected architecture. | No; D-04/D-05 are resolved owner decisions. |
| Install the owner-selected Pest Browser dependency and complete the Chrome/request-control/artifact compatibility smoke; retain project-owned Playwright fallback until parity. | spike | AC-07–AC-10; §9 | Test owner/orchestrator completes the contract-approved smoke and records the result. | No; both runners exercise the same architecture/test seam. It blocks Worker readiness, not architecture approval. |
| Align local/CI Node to 22/22.13+, prove private Flux/VCS install access, and execute PostgreSQL 17 concurrency path. | operational-dependency | AC-09/AC-10; §9 | Orchestrator/test owner completes nonsecret readiness probes after approval. | No; blocks Worker dispatch/verification, not architecture approval once product decisions resolve. |
| Implement named coordinator/context/DTO/Action/Blade/JS structure and transition entries before deleting obsolete bodies. | implementation-task | §§3–8; AC-01/AC-10 | Production Worker after product-owner architecture/contract approval and readiness. | No. |
| Verify actual stop/restart recovery in scheduled/manual CI. | spike / implementation-task | FR-022; AC-09 | Test owner implements and QA observes at final checkpoint. | No. |

The owner resolved D-04 and D-05 through Option A: dedicated immediate structural/media Actions with dirty/upload guards, plus atomic whole-authored-graph stale rejection. There are no unresolved architecture decisions. Remaining conditions are operational readiness, implementation tasks, or verification spikes and do not alter the architecture. The Playwright-backed runner fallback exercises the same stable semantic hooks and does not reopen architecture.

Principal implementation risks after decision are client-selected context, partial/stale graph writes, lock-order deadlocks, destructive composition conversion, upload callbacks following indices, false Saved state, leaking answer/provider data, and prematurely removing the old entries. §§4–9 assign controls and test seams. Missing classes/routes/tests are implementation tasks, not architecture blockers; no migration is planned.

## 11. Product-owner approval

The product owner resolved D-04/D-05 through Option A on 2026-09-10 but must still explicitly approve this complete architecture artifact and the reconciled execution contract. Approval binds the final file SHA-256 to frozen scope ID `unified-course-editor-scope-v1` in `execution-contract.json`; the author verdict and option selection alone are not approval of the complete artifact. After that binding and execution-readiness checks, task generation and the Worker proceed directly without a second architecture-document reviewer.

After implementation, the Architect writes a separate conformance artifact against this approved document, specification, contract, and final implementation/tests. It cites architecture sections and AC/INV IDs, reports implementation deviations and non-blocking follow-ups, and does not redesign or modify this approved artifact.
