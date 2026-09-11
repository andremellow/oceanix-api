# Architecture: Reliable Course Authoring

## 1. Context, references and decision scope

This architecture implements `spec.md` FR-001–FR-017 and SC-001–SC-007 within frozen scope `course-editor-reliability-scope-v1`. It is constrained by AC-01–AC-10 and INV-01–INV-05 in `.toscanini/runtime/runs/course-editor-reliability-20260909/execution-contract.json`, the design in `plan.md`, `research.md`, `data-model.md`, `contracts/course-editor-ui.md`, and `quickstart.md`, and product rules in `docs/product-spec.md` §§6 and 14. The only affected repository is `oceanix-api`.

Preserved behavior includes the company routes and access-profile permissions, draft-only edits, published-version immutability, immutable assignment version references, audited opt-in assignment replacement, private video identifiers, immediate assessment availability when a lesson opens, English-first localization, and the existing single-screen Livewire editor. The frozen non-goals are platform shared-course/shared-module editor redesign, automatic conversion or deletion of legacy content, migrations, packages, authentication/catalog/scoring/evidence changes, and unrelated control-center screens. Per the product-owner amendment recorded on 2026-09-10, `docs/product-spec.md` §7, current `AnswerQuestion` behavior, amended FR-005/FR-017, and amended INV-04 are authoritative: `minimum_watch_percentage` is tracking/reporting data and does not gate assessment access. The corresponding `AGENTS.md` invariant has been explicitly corrected to the same rule.

| Convention / decision | Repository or reference-project evidence | Classification | Why needed |
| --- | --- | --- | --- |
| Livewire single-file page components under `resources/views/components/**` | `AGENTS.md`; `resources/views/components/courses/⚡index.blade.php`; `resources/views/components/courses/⚡editor.blade.php` | Existing convention | Retains the established routing and screen composition. |
| Livewire validates and authorizes; focused Actions own writes; focused Services own reusable reads/decisions | `AGENTS.md`; `app/Actions/Courses/CreateCourse.php`; `app/Actions/Courses/UpdateCourseModuleComposition.php`; `app/Services/Courses/CourseVersionValidator.php` | Existing convention, applied consistently to changed paths | Keeps UI orchestration thin without adding generic layers. |
| Composition is derived as empty, direct lessons, reusable modules, or mixed | `research.md`; `data-model.md`; `database/migrations/2026_08_26_000100_create_shared_content_tables.php`; `app/Models/Lesson.php` | New feature-local decision | Pivot existence alone is insufficient because direct lessons are mirrored into `course_version_lessons`. |
| Normalize course codes before both validation and persistence; uniqueness is company-scoped and database-enforced | `app/Actions/Courses/CreateCourse.php`; partial index `courses_company_code_unique` in the shared-content migration | Existing persistence contract, corrected in the UI/application boundary | Covers friendly validation and concurrent races without a migration. |
| Immediate per-mutation persistence remains; a truthful client/server save-state protocol is added | `resources/views/components/courses/⚡editor.blade.php`; `research.md` | Existing persistence style plus new feature-local state protocol | Meets FR-007/FR-008 without adopting the platform bulk-save design. |
| Publication keeps current assignments unless replacement is explicitly selected | `app/Actions/Courses/PublishCourseVersion.php`; `app/Actions/Assignments/ReplaceOpenAssignmentsForCourseVersion.php` | Existing Action capability; corrected presentation default | Protects frozen learner progress under INV-01/INV-05. |
| Watch threshold remains editable tracking/reporting data; assessment is available immediately | `docs/product-spec.md` §7; `app/Actions/Training/AnswerQuestion.php`; amended `AGENTS.md` invariant 9; owner decision 2026-09-10 | Existing behavior and explicit product-owner decision | Corrects editor help without adding or changing employee assessment behavior (AC-03, INV-04). |
| No reference project | None supplied | N/A | Repository code is evidence; no external internal architecture is imported. |

### Framework suitability and reference assessment

| Target repository / framework / installed version | Official and adapter guidance actually consulted | Reference purpose and patterns accepted/rejected | Framework-native option and tradeoffs | Alignment / material conflict / specific owner decision |
| --- | --- | --- | --- | --- |
| `oceanix-api`: PHP 8.4 runtime (project `^8.3`), Laravel 13.26.1, Livewire 4.4.1, Flux/Flux Pro 2.17.0, Pest 4.7.8, Tailwind 4.3.3. Versions are from Composer/npm locks and Laravel Boost `application_info`; the plan's Livewire 4.3 and Flux 2.14/2.15 figures are stale. | Project `AGENTS.md`, enabled Laravel/Spec Kit/terminal UI adapter policy, Laravel Boost 2.7.0 `application_info` and version-scoped `search_docs`; official [Laravel 13 validation](https://laravel.com/docs/13.x/validation), [database transactions](https://laravel.com/docs/13.x/database), and [query locking](https://laravel.com/docs/13.x/queries); official Livewire 4 [forms](https://livewire.laravel.com/docs/4.x/forms), [actions](https://livewire.laravel.com/docs/4.x/actions), [loading](https://livewire.laravel.com/docs/4.x/wire-loading), [navigation](https://livewire.laravel.com/docs/4.x/navigate), and [JavaScript](https://livewire.laravel.com/docs/4.x/javascript); Flux [input](https://fluxui.dev/components/input), [button](https://fluxui.dev/components/button), and [editor](https://fluxui.dev/components/editor); `docs/control-center-design-system.md`. Boost policy is optional, but Boost is installed and its tools were available and consulted. | No reference project. Existing Actions/Services, Policies, Eloquent models, and Livewire SFCs are accepted. The platform editor's bulk-save/revision architecture and a new repository layer are rejected because this repair preserves immediate persistence and needs no alternate data source. | Framework-native validation, `DB::transaction`, `lockForUpdate`, Livewire loading/navigation hooks, Flux labelled/read-only controls, and Eloquent are sufficient. Focused Actions add a few classes but give transaction and authorization seams; a generic repository or SPA store would add indirection without benefit. | **Aligned.** The installed patch versions supersede draft planning metadata but do not change the architecture. No deviation or unresolved framework conflict. |

TDD is an implementation/test strategy, not a layer. `plan.md` explicitly chooses focused failing Pest tests before fixes where feasible; implement that sequence and record defect sensitivity, while using browser QA for interaction behavior that feature tests cannot prove.

## 2. Architectural style

Use a Laravel modular monolith with thin Livewire presentation, focused application Actions for writes, focused stateless Services for composition/read projections, Eloquent models for records/relationships, and existing Policies/Gates for authorization. This is the simplest sufficient Laravel design because the feature has one database, one server-rendered UI, and no new external boundary. It adds no repository, generic domain layer, queue, controller, or client-side framework.

The one new reusable domain decision is `CourseVersionComposition`, because editor display, module mutation, preview, and publication must share an identical interpretation of the unusual legacy persistence layout. Multi-row structural changes use Actions because atomicity, ownership checks, locks, and contiguous positions are material. Scalar editor updates may be routed through one narrowly named `UpdateCourseEditorField` Action rather than duplicated Eloquent writes in the component; it is a feature command, not a general model wrapper.

## 3. Directory and module structure

```text
oceanix-api/
├── app/
│   ├── Actions/Courses/
│   │   ├── CreateCourse.php                         # extend normalization, actor/tenant guard, collision translation
│   │   ├── UpdateCourseModuleComposition.php        # extend composition guard and non-destructive pivot replacement
│   │   ├── PublishCourseVersion.php                 # retain false replacement default; validate after locks
│   │   ├── AddDirectCourseLesson.php                # new: guarded first/direct lesson creation
│   │   ├── RemoveDirectCourseLesson.php             # new: explicit confirmed removal and resequence
│   │   ├── UpdateCourseEditorField.php              # new: guarded scalar course/version/lesson/question/option update
│   │   ├── MutateCourseAssessmentStructure.php      # new: add/remove question or option, select single correct
│   │   └── ReorderDirectCourseContent.php            # new: lesson/question/option ordering transaction
│   ├── Services/Courses/
│   │   ├── CourseVersionComposition.php             # new: mode and canonical inventory projection
│   │   ├── CoursePublicationImpact.php              # new: pending/in-progress open-assignment counts
│   │   ├── CourseVersionValidator.php               # extend: fail mixed, validate canonical inventory
│   │   └── PublicPreviewResolver.php                 # extend: same canonical inventory; fail closed for mixed
│   └── Models/CourseVersion.php                     # relationships stay; only a query-free convenience helper if justified
├── resources/views/components/courses/
│   ├── ⚡index.blade.php                             # normalize/scoped validate/create modal state
│   └── ⚡editor.blade.php                            # orchestration, rendering, save-state/navigation/drag interaction
├── lang/{en,pt_BR}/ui.php                           # English keys and PT-BR values
└── tests/Feature/Courses/                           # focused Pest coverage
```

`MutateCourseAssessmentStructure` is bounded to direct-course draft question/option structure and exposes one `handle(...)` command with a validated operation value; it must not become a generic CRUD dispatcher. If the implementation is clearer as separate `Add/Remove` Actions, that is a naming split within the same boundary, not an architectural deviation.

## 4. Class responsibilities and non-use

| Type | Responsibility / principal class | When not used |
| --- | --- | --- |
| Livewire component | `courses.⚡index` validates and presents creation; `courses.⚡editor` reauthorizes, maintains browser-visible state, invokes Actions/Services, and renders all modes/states. | Must not decide composition, run cross-record updates, catch-and-hide unexpected persistence failures, or contain duplicated read queries. |
| Controller | Existing preview controllers remain unchanged entry points. | No new controller: both changed authenticated screens are routable Livewire components. |
| Form Request | N/A. | Livewire owns request validation; adding an HTTP-only Form Request would duplicate rules. |
| API Resource | N/A. | No JSON API contract is added. Arrays rendered by Livewire remain explicit and exclude answer-key serialization. |
| Action | Existing `CreateCourse`, `UpdateCourseModuleComposition`, `PublishCourseVersion`; new focused direct-content structural/scalar Actions above. All use `handle`. | No Action for pure projections or formatting. No `execute`/`__invoke` variants. `App\Actions\Training\AnswerQuestion` is not modified by this feature; immediate assessment availability remains its existing contract. |
| Service / external client | `CourseVersionComposition`, `CoursePublicationImpact`, `CourseVersionValidator`, `PublicPreviewResolver`; existing `VideoProvider` boundary remains unchanged. | No generic `CourseService`, Helper, Manager, or new video client. |
| Repository | N/A. | Eloquent is the only persistence source; wrapping it method-for-method adds no seam. |
| Query Object | N/A as a distinct layer. | Two focused read Services already name the reusable queries. |
| DTO / Value Object | N/A. Composition returns a documented array/collection shape and mode constants. | No transport boundary or complex immutable input requires one. |
| Model / persistence record | Existing `Course`, `CourseVersion`, `CourseVersionModule`, `Lesson`, `Question`, `QuestionOption`, `Video`, and `UserTrainingAssignment`; names retain legacy physical-table compatibility. | Do not rename persistence models/tables or introduce a second course-version aggregate. |
| Policy / authorization service | Existing `CoursePolicy` abilities `create`, `update`, `updateVersion`, `publish`; route middleware `courses.update`; `TenantContext` plus explicit owner predicates. | No new permission: all requested actions are facets of existing course create/update/publish grants. |
| Job / command | N/A. | Mutations are interactive and bounded; background ordering/publication would complicate truthful outcomes. |
| Event / listener | Existing audit/compliance recording inside publication replacement remains. Browser events are presentation state signals, not domain events. | No new queued/domain event; nothing needs asynchronous fan-out. |

## 5. Invocation, naming and query placement

All Actions expose `handle`; Services expose intention-revealing methods such as `inspect`, `canonicalLessons`, `problems`, and `forVersion`. Controllers are not added, so invokable-controller naming is N/A. Persistence names remain `CourseVersionModule` over `course_version_lessons` and `ModuleVersion`/`Module` aliases over `lessons`; UI terms must not redefine storage lineage.

`CourseVersionComposition::inspect($version)` must classify by provenance:

- `directIds` are `lessons.id` rows whose `course_version_id` equals the version.
- `mirroredDirectRows` are composition pivots whose `lesson_id` is in `directIds`; these compatibility rows do **not** make the draft reusable-module mode.
- `reusableRows` are composition pivots whose `lesson_id` is not in `directIds`.
- direct IDs present and reusable rows absent => `direct_lessons`; no direct IDs and reusable rows present => `modules`; neither => `empty`; both => `mixed`.

The canonical ordered inventory is direct `lessons` for `direct_lessons`, referenced module versions for `modules`, empty for `empty`, and unavailable for `mixed`. Mixed callers receive a specific conflict result; they never select one side. Editor-only recovery data exposes both inventories without changing either. This definition is mandatory to avoid treating `Lesson::created` compatibility pivots as reusable modules.

Eloquent queries may exist in models for relationships/scopes, in `CourseVersionComposition`/`CoursePublicationImpact`/existing read Services for projections, and in Actions for guarded writes and their locks. Livewire `with()` and mutation methods call those Services/Actions; they may retain only framework state access, validation, authorization, and model-to-form mapping. New raw write queries, cross-record Eloquent updates, tenant predicates, composition branching, assignment impact queries, or resequencing are forbidden in Blade/Livewire. Policies may query only what is needed to authorize. Presentation JavaScript may not infer persisted state or send untrusted record IDs without server re-resolution.

## 6. Dependency direction

```text
routes + EnsureUserHasPermission
              |
              v
Livewire SFCs -----> CoursePolicy / Gate
     |                    |
     | calls              | authorizes actor + record
     v                    v
Course Actions ------> CourseVersionComposition <------ Read/preview Services
     |                         |
     | transaction/locks       | Eloquent reads
     v                         v
Eloquent records/DB <---- existing models/relationships ----> VideoProvider (existing only)
```

Permitted: Presentation → Policy, Action, read Service; Action → Policy/Gate, composition Service, model/DB, existing audit/assignment Actions; read Service → model relationships. Prohibited: model → Livewire/Blade; Service/Action → concrete Flux UI; read Service → write Action; browser JavaScript → database/provider; Course Actions → WorkOS or concrete Cloudflare implementation. `CourseVersionComposition` has no presentation dependency and performs no writes.

## 7. Cross-cutting ownership and system contracts

| Concern | Owning layer / class | Contract and constraints, or N/A reason |
| --- | --- | --- |
| Request validation | Livewire plus Action preconditions | Normalize code before `Rule::unique(...)->where(company_id/is_shared)`; validate watch threshold as 1–100 tracking/reporting data and passing score as 1–100; validate known operation and complete ordered IDs; unique positional labels map to exact error keys. Actions re-resolve IDs and reject stale/foreign inputs. |
| Authorization / security boundary | Route middleware, `CoursePolicy`, each state-changing component method and Action | Reauthorize on hydration-sensitive operations so revocation applies; admin bypass remains in Gate setup. No UI visibility is treated as authorization (INV-03). |
| Tenant isolation / data ownership | `TenantContext`, Policy, explicit Action/Service predicates | Company course, version, lessons/questions/options/modules, content images, and assignment-impact rows must all resolve to active `company_id`; global scopes are not the sole guard (INV-03). |
| Mapping | Livewire form mapping and `CourseVersionComposition` | Only UI-safe fields enter arrays. `QuestionOption::is_correct` remains hidden from general serialization and is explicitly loaded only for the authorized editor. |
| Transactions and locks / concurrency | Write Actions | Lock course/version first, then ordered children in stable ID order. Validate composition after locking and before delete/update. Code races rely on the unique index. Publication retains its course/version locks and transaction retries. Reorder locks siblings and compatibility pivots, stages temporary non-colliding positions, then writes `1..N`. |
| Persistence | Eloquent in Actions; database constraints | No migration. Preserve composite/partial code uniqueness, pivot uniqueness, version immutability, and assignment references. |
| External calls and timeouts / API contracts | Existing video Actions and `VideoProvider` | Unchanged. No permanent public URL; provider exceptions remain media-specific and must not be reported as saved. Tests fake provider calls. |
| Error and outcome translation / failure behavior | Actions → `ValidationException`; Livewire/Alpine state protocol | Known duplicate/composition/stale-order failures become inline errors. Database code collision is translated only when the violated constraint/index is `courses_company_code_unique`; unrelated `QueryException` is rethrown. Mixed preview/publication fails closed with a specific conflict. Network failure remains visibly unsaved/retryable. |
| Serialization | Blade/Livewire | Text status and safe view arrays only; no raw IDs as user-facing copy, JSON, provider secrets, or answer keys outside the authorized editor. |
| Configuration | Existing Laravel/Livewire/Flux/video configuration | No new config or `.env` mutation. |
| Logging and telemetry | Existing `AuditLogger`/compliance recorder | Course creation/publication/replacement audit behavior remains. Do not log answers, tokens, secrets, or full editor payloads. Autosave UI status is not audit evidence. |
| Retry behavior | `PublishCourseVersion` transaction retry; user retry for editor/media | Do not automatically retry validation or unique failures. A network-failed scalar edit stays dirty for explicit retry. Repeating an in-flight button is disabled. |
| Migrations and rollback | N/A schema; transaction rollback and code rollback | No migration. Failed structural/create/publish transactions leave all rows unchanged. Deployment rollback is application-code rollback; existing stored records need no data rollback or destructive cleanup. |

Non-negotiable invariants: never mutate a published version (INV-01); never implicitly delete/rewrite either composition inventory or evidence (INV-02); enforce tenant/authorization on every path (INV-03); keep the saved watch threshold as tracking/reporting data without gating assessment availability (INV-04); retain assignment replacement only as explicit opt-in with existing audit behavior (INV-05). No course-editor Action or Service may call `LessonProgress::meetsWatchThreshold`, add a progress check to `AnswerQuestion`, hide assessment content below the threshold, or otherwise introduce employee-side gating.

## 8. Concrete execution flows

### Create a company course

1. `courses.⚡index::create` authorizes `CoursePolicy::create`, canonicalizes `$code = mb_strtoupper(trim($code))`, then validates title/description and a company-owned unique rule.
2. `CreateCourse::handle(..., User $actor)` reauthorizes, verifies actor company equals `TenantContext`, canonicalizes again, and starts one transaction.
3. It performs the company-scoped existence check, inserts `Course`, inserts draft version, and audits.
4. A concurrent database violation of `courses_company_code_unique` rolls back both inserts and becomes `ValidationException(['code' => ...])`; the modal remains open with the normalized value and inline error. Other errors propagate.

### Inspect and mutate composition

1. Editor/readiness/preview call `CourseVersionComposition::inspect` and render/use its same mode.
2. `AddDirectCourseLesson::handle` locks the draft; `modules` rejects before insert, while `empty`/`direct_lessons` insert the direct lesson. The model-created mirror pivot is accepted as compatibility state.
3. `UpdateCourseModuleComposition::handle` locks course, version, all current pivots, and requested module versions. If any direct lesson exists and the request would add any reusable row, it throws an inline composition error before deleting anything.
4. In mixed recovery, removing reusable rows deletes only explicitly selected reusable pivots and preserves all direct lessons and mirror pivots. Direct removal occurs only through confirmed `RemoveDirectCourseLesson`; reaching zero direct lessons yields `modules` or `empty` naturally.
5. `CourseVersionValidator` adds the mixed conflict and returns non-publishable. `PublicPreviewResolver` uses the canonical inventory or returns the same conflict; it never silently prefers pivots.

### Save and navigation state

The editor root owns a small Alpine state machine: `clean | dirty | saving | saved | validation-error | network-error`. Native `input`/`change` marks the relevant mutation dirty immediately. Starting a matching Livewire request records its local revision and sets `saving`. A successful Action sets `saved` only if no newer local revision is dirty; validation adds field errors and sets `validation-error`; the Livewire request failure hook sets `network-error`. Both error states retain the dirty flag and a retry instruction. `savedAt` changes only after committed persistence.

`livewire:navigate` is cancellable. The component-scoped listener calls `preventDefault()` and asks for confirmation only for dirty, saving, validation-error, or network-error state. It unregisters on component teardown/navigation to avoid duplicate persistent document listeners. Confirmed navigation clears the guard and resumes once; clean/saved navigation proceeds silently. Action buttons use targeted `wire:loading`/Flux loading, `disabled`, and text status so duplicate create/save/media/publish requests cannot be initiated (AC-05/AC-09).

### Save the watch threshold without changing assessment access

1. The direct-lesson field visibly labels the 1–100 percentage and explains that it affects tracking/reporting only; it also states that assessment is available as soon as the lesson opens.
2. `UpdateCourseEditorField::handle` authorizes the tenant-owned draft, validates and persists `minimum_watch_percentage`, and participates in the same save-state protocol as other scalar edits.
3. No employee-training Action, progress projection, or assessment view is changed. Existing `AnswerQuestion` behavior remains immediately available below the threshold (AC-03, FR-017, INV-04).

### Reorder direct content

Pointer drag emits the same complete ordered-ID command as keyboard move-up/down; JavaScript never updates persistence itself. `ReorderDirectCourseContent::handle($version, $actor, $level, $parentId, $orderedIds)` reauthorizes, locks the draft and exact sibling set, verifies IDs are unique and exactly match the current tenant-owned siblings, and rejects stale/missing/foreign input before writes. Within one transaction it moves rows to safe temporary positions and writes contiguous final positions. Lesson reordering updates both direct lesson positions and their mirror pivot positions; question/option ordering updates their own rows. Rollback prevents duplicates or partial ordering. The component reloads canonical ordered data after success.

### Publish safely

1. `confirmPublish` reauthorizes, asks `CourseVersionValidator` after fresh composition inspection, and obtains pending/in-progress/open counts from `CoursePublicationImpact`.
2. The modal starts at `keep_existing`; replacement is never inferred from count or previous UI state. It explains immutability and impact.
3. `publish` disables itself, reauthorizes, and calls `PublishCourseVersion::handle(..., replaceOpenAssignments: $mode === 'replace_open')`.
4. The Action locks course/version/current version, recomputes composition/readiness, publishes atomically, and calls the existing audited replacement Action only for explicit opt-in. Default publication changes no existing assignment `course_version_id` (AC-10, INV-01, INV-05).

## 9. Test seams

- Pest Livewire feature tests exercise validation, Policies, component state/output, Actions, and real SQLite transactions; do not mock Eloquent. Factories must create direct/mirrored, module, mixed, and empty provenance explicitly.
- Unit/feature evidence for `CourseVersionComposition` proves mirror pivots classify as direct, external pivots as modules, mixed preserves both inventories, and all consumers agree (AC-01/AC-02; INV-02).
- Race/collision coverage uses two database writes or a forced named unique-constraint `QueryException` seam to prove only course-code collisions become inline validation and no partial course/version survives (AC-04, INV-03). PostgreSQL constraint behavior receives integration confidence from the existing migration/index; SQLite remains canonical automated storage.
- Structural Action tests use stale, duplicate, missing, boundary, and cross-tenant IDs; assert exact unchanged snapshots on rejection and contiguous persisted order on success (AC-07; INV-02/INV-03).
- Publication tests use real `UserTrainingAssignment` rows and existing audit/event recorders; assert default retention, explicit cancellation/supersession, completed-history preservation, and rollback on readiness failure (AC-10; INV-01/INV-05).
- Video provider calls remain faked; test each status label and provider failure without external traffic (AC-06/AC-09).
- Browser QA is required for actual pointer drag, keyboard controls, focus names, live announcements, request/network failure, cancellable navigation, duplicate-action prevention, and 320 CSS pixel overflow. Pest rendering alone cannot prove these behaviors (AC-05–AC-09).
- AC-03/INV-04 are a frozen regression boundary: tests prove the editor persists valid 1–100 tracking/reporting values, rejects invalid values without persistence, renders explicit non-gating help, and preserves the existing `AnswerQuestion` test demonstrating that an assessment can be answered below the threshold. No new employee-assessment implementation or mock gate is added.
- Exhaustive cases remain in the specification scenario map and frozen QA matrix; this section defines seams, not a second matrix.

## 10. Author verdict and classified conditions

Author readiness: `APPROVED`.

| Condition / open decision | Category | AC/INV or architecture section | Owner and next action | Could materially change architecture? |
| --- | --- | --- | --- | --- |
| Implement the named Services/Actions, component state protocol, translations, and Pest/browser coverage. | implementation-task | §§3–9; AC-01–AC-10 | Worker after product-owner approval | No. |
| Refresh execution-contract `architecture.frameworkAlignment` with the assessment in §1 and use installed Livewire 4.4.1 / Flux 2.17.0 rather than stale plan figures. | implementation-task | §1 | Orchestrator before contract validation/approval | No. |
| Confirm the local browser/network-failure harness can exercise the frozen UI scenarios. | spike | §9; AC-05/AC-08/AC-09 | Orchestrator/QA readiness check | No; lack of environment blocks Worker/QA readiness, not architecture approval. |

There are no unresolved architecture or product decisions. The watch-threshold conflict is resolved by the explicit 2026-09-10 product-owner decision and amended artifacts; it is not a condition. No operational credential or licensed dependency is required. The principal risks are provenance misclassification, destructive pivot replacement, unique-position collision, stale reorder payloads, false saved state after a failed request, and accidentally reintroducing assessment gating through misleading help or a progress check; §§5–9 assign explicit controls for each. Out-of-scope improvements remain a shared-editor refactor, optimistic-revision columns for multi-admin editing, schema cleanup/renaming of legacy lesson/module storage, a new sortable package, and any employee assessment implementation or scoring change.

## 11. Product-owner approval

The product owner must explicitly approve this artifact and the execution contract, binding the decision to this file's SHA-256 and frozen scope ID `course-editor-reliability-scope-v1`. This `APPROVED` author verdict is readiness advice only and is not product-owner approval. Once that binding is recorded, task generation and the single Worker may proceed without another architecture-document review.

After implementation, the Architect will write a separate conformance artifact against this approved document, the specification/contract, and the final implementation/tests. It may report implementation deviations and non-blocking follow-ups, but it may not redesign or modify this approved architecture.
