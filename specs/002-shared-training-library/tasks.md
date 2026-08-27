# Tasks: Shared Training Library

**Input**: Design documents from `/specs/002-shared-training-library/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: Required by the feature's authorization, isolation, immutability, propagation, concurrency and evidence-preservation acceptance criteria. Write each story's tests first and confirm they fail before implementation.

**Organization**: Tasks are grouped by user story so each journey remains independently demonstrable after the shared foundation is complete.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel because it targets different files and has no incomplete dependency.
- **[Story]**: Maps work to the numbered user story in spec.md.

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Prepare conventions and reusable test support without changing feature behavior.

- [X] T001 Verify repository ignore rules cover Laravel, PHP, Node, Vite, IDE, log and environment artifacts in `.gitignore`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Establish ownership, reusable module composition, migration compatibility and authorization primitives required by every user story.

**⚠️ CRITICAL**: No user story work begins until this phase passes.

- [X] T002 Write failing ownership, paired-field constraint, legacy-backfill and immutable-composition tests in `tests/Feature/SharedContent/SharedContentSchemaTest.php` and `tests/Unit/ModuleVersionImmutabilityTest.php`
- [X] T003 [P] Add module, version, composition and lifecycle enums in `app/Enums/ModuleStatus.php`, `app/Enums/ModuleVersionStatus.php`, `app/Enums/SharedContentPropagationStatus.php`, and `app/Enums/SharedContentPropagationItemStatus.php`
- [X] T004 Add ownership, module/version/composition schema and staged legacy backfill preserving lesson IDs in `database/migrations/2026_08_26_000100_create_shared_content_tables.php`
- [X] T005 Add durable company-course association, propagation run/item and assignment replacement provenance schema in `database/migrations/2026_08_26_000200_create_shared_content_operations.php`
- [X] T006 Implement the `company_id` plus `is_shared` invariant and named shared/company visibility scopes in `app/Models/Concerns/HasContentOwnership.php`
- [X] T007 [P] Create reusable content models and immutable relationships in `app/Models/Module.php`, `app/Models/ModuleVersion.php`, and `app/Models/CourseVersionModule.php`
- [X] T008 [P] Create availability and propagation models in `app/Models/CompanyCourse.php`, `app/Models/SharedContentPropagation.php`, and `app/Models/SharedContentPropagationItem.php`
- [X] T009 Refactor content relationships and scopes for nullable `company_id`, `is_shared`, module composition and publisher provenance in `app/Models/Course.php`, `app/Models/CourseVersion.php`, and `app/Models/Lesson.php`
- [X] T010 Refactor lesson/video/question child ownership resolution without weakening operational tenant scopes in `app/Models/Video.php`, `app/Models/Question.php`, and `app/Models/QuestionOption.php`
- [X] T011 [P] Add shared-content factory states and test builders in `database/factories/CourseFactory.php`, `database/factories/CourseVersionFactory.php`, `database/factories/ModuleFactory.php`, and `database/factories/ModuleVersionFactory.php`
- [X] T012 [P] Add shared-content test helpers for platform accounts, company associations and version graphs in `tests/Pest.php`
- [X] T013 Make the migrations/models pass cross-database ownership, backfill and immutability tests in `database/migrations/2026_08_26_000100_create_shared_content_tables.php`, `app/Models/Concerns/HasContentOwnership.php`, and `app/Providers/AppServiceProvider.php`
- [X] T014 Add atomic shared course/module permissions, labels, groups and prerequisites in `app/Enums/Permission.php`
- [X] T015 Add ownership-aware record authorization that rejects tenant writes to shared records even under admin Gate bypass in `app/Policies/CoursePolicy.php`, `app/Policies/ModulePolicy.php`, and `app/Providers/AppServiceProvider.php`
- [X] T016 Refactor course validation, draft cloning, playback and progress reads to traverse immutable module composition in `app/Services/Courses/CourseVersionValidator.php`, `app/Actions/Courses/CreateDraftFromVersion.php`, `app/Models/UserTrainingAssignment.php`, and `app/Services/Training/TrainingCompletionService.php`

**Checkpoint**: Existing courses are migrated into module composition, operational data remains isolated, and all prior course/training tests pass.

---

## Phase 3: User Story 1 - Administer Shared Content on Platform (Priority: P1) 🎯 MVP

**Goal**: A platform administrator can create, edit, publish and inspect shared courses/modules exclusively under `/platform`, including assignment migration choices and propagation status.

**Independent Test**: Create and publish shared content as a platform administrator, verify tenant read-only visibility, deny tenant mutation/direct access, and prove version and assignment history remain immutable.

### Tests for User Story 1

- [X] T017 [P] [US1] Write failing platform route, middleware and navigation tests for shared directories/details/editors in `tests/Feature/Platform/SharedContentAdministrationTest.php`
- [X] T018 [P] [US1] Write failing module/course authoring and immutable-publication tests in `tests/Feature/Modules/SharedModuleAuthoringTest.php` and `tests/Feature/Courses/SharedCourseAuthoringTest.php`
- [X] T019 [P] [US1] Write failing assignment classification, replacement, recurrence, evidence and notification tests in `tests/Feature/SharedContent/PublicationAssignmentMigrationTest.php`
- [X] T020 [P] [US1] Write failing platform Livewire UI tests for impact counts, unchecked restart default, errors and loading states in `tests/Feature/Platform/SharedContentUiTest.php`

### Implementation for User Story 1

- [X] T021 [P] [US1] Implement platform shared-course/module read projections and impact summaries in `app/Services/SharedContent/SharedContentCatalog.php` and `app/Services/Modules/ModulePropagationImpact.php`
- [X] T022 [P] [US1] Implement shared module-version validation in `app/Services/Modules/ModuleVersionValidator.php`
- [X] T023 [US1] Implement idempotent assignment replacement with explicit actor, start-evidence classification, preserved recurrence/schedule and append-only events in `app/Actions/Assignments/ReplaceAssignmentsForPublication.php`
- [X] T024 [US1] Add row-lock/status recheck compatibility for start-versus-replacement races in `app/Actions/Training/StartAssignment.php`
- [X] T025 [US1] Extend manual course publication to use the new migration policy and explicit platform/tenant publisher context in `app/Actions/Courses/PublishCourseVersion.php`
- [X] T026 [US1] Implement shared course/module create, draft and publish Actions in `app/Actions/Courses/CreateCourse.php`, `app/Actions/Modules/CreateModule.php`, `app/Actions/Modules/CreateModuleDraft.php`, and `app/Actions/Modules/PublishModuleVersion.php`
- [X] T027 [P] [US1] Build platform shared-course directory/detail/editor components in `resources/views/components/platform/shared-courses/⚡index.blade.php`, `resources/views/components/platform/shared-courses/⚡show.blade.php`, and `resources/views/components/platform/shared-courses/⚡editor.blade.php`
- [X] T028 [P] [US1] Build platform shared-module directory/detail/editor and propagation status components in `resources/views/components/platform/shared-modules/⚡index.blade.php`, `resources/views/components/platform/shared-modules/⚡show.blade.php`, and `resources/views/components/platform/shared-modules/⚡editor.blade.php`
- [X] T029 [US1] Register platform routes and responsive platform navigation links in `routes/web.php` and `resources/views/components/layouts/platform.blade.php`
- [X] T030 [US1] Add English source strings and PT-BR translations for shared administration/publication in `lang/pt_BR.json`

**Checkpoint**: Platform administrators can manage shared content; tenant users can never mutate it.

---

## Phase 4: User Story 2 - Add Shared Course to Company (Priority: P1)

**Goal**: An authorized company administrator can browse, associate and safely remove shared courses without copying content.

**Independent Test**: Associate one shared Course with a company, verify one durable link and shared labels, then remove it only when no active dependency blocks removal.

### Tests for User Story 2

- [X] T031 [P] [US2] Write failing permission matrix tests for grant, denial, direct access, prerequisites, admin bypass and revocation in `tests/Feature/Access/SharedContentPermissionTest.php`
- [X] T032 [P] [US2] Write failing catalog isolation, duplicate-association concurrency and removal-blocker tests in `tests/Feature/SharedContent/CompanyCourseAssociationTest.php`
- [X] T033 [P] [US2] Write failing tenant catalog/detail UI tests for shared labels, empty states and action visibility in `tests/Feature/Courses/SharedCourseCatalogTest.php`

### Implementation for User Story 2

- [X] T034 [US2] Implement idempotent association/reactivation and audited dependency-aware removal in `app/Actions/Courses/AssociateSharedCourse.php` and `app/Actions/Courses/RemoveSharedCourse.php`
- [X] T035 [US2] Implement company-library and eligible-catalog projections without cross-tenant leakage in `app/Services/Courses/CompanyCourseLibrary.php` and `app/Services/SharedContent/SharedContentCatalog.php`
- [X] T036 [US2] Build tenant shared-course catalog/detail Livewire components in `resources/views/components/courses/⚡shared-index.blade.php` and `resources/views/components/courses/⚡shared-show.blade.php`
- [X] T037 [US2] Integrate Company Courses/Browse Shared Courses presentation and shared read-only states in `resources/views/components/courses/⚡index.blade.php` and `resources/views/components/courses/⚡show.blade.php`
- [X] T038 [US2] Register tenant catalog routes with exact permission middleware in `routes/web.php`

**Checkpoint**: Shared course association is usable, idempotent, independently authorized and isolated per company.

---

## Phase 5: User Story 3 - Reuse Shared Modules in Company Courses (Priority: P1)

**Goal**: Company courses can combine private and shared modules, and shared-module publication safely propagates immutable course versions and eligible assignment replacements.

**Independent Test**: Publish a hybrid course, publish a new shared ModuleVersion across several companies, and verify automatic course versions, untouched drafts, correct assignment outcomes and retry safety.

### Tests for User Story 3

- [X] T039 [P] [US3] Write failing hybrid composition authorization, ordering, validation and cross-tenant injection tests in `tests/Feature/Courses/HybridCourseCompositionTest.php`
- [X] T040 [P] [US3] Write failing propagation fan-out, draft preservation, retry and partial-failure tests in `tests/Feature/SharedContent/SharedModulePropagationTest.php`
- [X] T041 [P] [US3] Write failing concurrent publication/version allocation and start-versus-migration tests in `tests/Feature/SharedContent/SharedContentConcurrencyTest.php`
- [X] T042 [P] [US3] Write failing module-picker permission, search, ownership-label and accessibility tests in `tests/Feature/Courses/SharedModulePickerTest.php`

### Implementation for User Story 3

- [X] T043 [US3] Implement shared/company module eligibility and composition mutation Services/Actions in `app/Services/Modules/EligibleModuleCatalog.php` and `app/Actions/Courses/UpdateCourseModuleComposition.php`
- [X] T044 [US3] Refactor course editor persistence and publication validation for ordered ModuleVersion snapshots in `resources/views/components/courses/⚡editor.blade.php` and `app/Services/Courses/CourseVersionValidator.php`
- [X] T045 [US3] Implement durable propagation-run creation, per-course item dispatch and aggregate status transitions in `app/Actions/Modules/PublishModuleVersion.php` and `app/Actions/Modules/DispatchModulePropagation.php`
- [X] T046 [US3] Implement tenant-aware, row-locked, idempotent per-course propagation and provenance in `app/Jobs/PropagateSharedModuleToCourse.php` and `app/Actions/Courses/CreatePropagatedCourseVersion.php`
- [X] T047 [US3] Make compliance audit/event recording and notification scheduling accept explicit queued actor/provenance context in `app/Services/Audit/AuditLogger.php`, `app/Services/Compliance/ComplianceEventRecorder.php`, and `app/Services/Notifications/NotificationSchedulingService.php`
- [X] T048 [US3] Build the searchable Company Modules/Shared Modules picker with loading, empty and validation states in `resources/views/components/courses/⚡editor.blade.php`

**Checkpoint**: Hybrid composition and automatic cross-company propagation work without mutating drafts or historical versions.

---

## Phase 6: User Story 4 - Promote Company Course to Shared (Priority: P2)

**Goal**: A platform administrator can preview and atomically transfer a company Course and all composed company Modules to platform ownership while preserving every historical identifier.

**Independent Test**: Promote a course containing a module reused by another course, verify the full impact preview, source-company association, changed `company_id/is_shared`, retained references and tenant edit denial.

### Tests for User Story 4

- [X] T049 [P] [US4] Write failing promotion preview, stale-data, atomicity and reused-module tests in `tests/Feature/Platform/CoursePromotionTest.php`
- [X] T050 [P] [US4] Write failing historical assignment/certificate/evidence preservation tests in `tests/Feature/SharedContent/CoursePromotionEvidenceTest.php`
- [X] T051 [P] [US4] Write failing promotion confirmation and affected-course UI tests in `tests/Feature/Platform/CoursePromotionUiTest.php`

### Implementation for User Story 4

- [X] T052 [US4] Implement locked promotion impact projection and stale-preview token in `app/Services/Courses/CoursePromotionImpact.php`
- [X] T053 [US4] Implement atomic Course/Module ownership transfer, source association and audit trail in `app/Actions/Courses/MakeCourseShared.php`
- [X] T054 [US4] Add promotion preview/confirmation to the platform company-course detail flow in `resources/views/components/platform/⚡company.blade.php` and `routes/web.php`

**Checkpoint**: Promotion is all-or-nothing, explicit about reused modules, and preserves every historical reference.

---

## Phase 7: User Story 5 - Control Availability and Lifecycle (Priority: P2)

**Goal**: Platform administrators can archive shared content to stop new adoption/use while existing obligations and evidence remain available.

**Independent Test**: Archive associated content, verify it disappears from eligible selectors and blocks new assignments while current learners and historical evidence continue normally.

### Tests for User Story 5

- [X] T055 [P] [US5] Write failing archive eligibility, new-assignment blocking and existing-assignment continuation tests in `tests/Feature/SharedContent/SharedContentArchiveTest.php`
- [X] T056 [P] [US5] Write failing platform archive confirmation, status and catalog-removal UI tests in `tests/Feature/Platform/SharedContentLifecycleUiTest.php`

### Implementation for User Story 5

- [X] T057 [US5] Implement audited shared Course/Module archive rules in `app/Actions/SharedContent/ArchiveSharedContent.php`
- [X] T058 [US5] Exclude archived content from associations, module composition and assignment materialization in `app/Services/SharedContent/SharedContentCatalog.php`, `app/Services/Requirements/AssignmentMaterializationService.php`, and `app/Actions/Assignments/CreateManualAssignment.php`
- [X] T059 [US5] Add archive confirmation/status presentation to platform shared content components in `resources/views/components/platform/shared-courses/⚡show.blade.php` and `resources/views/components/platform/shared-modules/⚡show.blade.php`

**Checkpoint**: Archive stops all new operational use without cancelling or deleting existing evidence.

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: Complete migration safety, documentation, accessibility, performance and full regression validation.

- [X] T060 [P] Update the Portuguese product source of truth with shared Course/Module ownership, availability, versioning and lifecycle rules in `docs/product-spec.md`
- [X] T061 [P] Document reusable status/navigation/accessibility patterns introduced by shared content in `docs/control-center-design-system.md`
- [X] T062 Add deterministic demo shared content and cross-company associations without external calls in `database/seeders/DatabaseSeeder.php`
- [X] T063 Add query-count and 100-company propagation coverage in `tests/Feature/SharedContent/SharedContentScaleTest.php`
- [X] T064 Run the end-to-end scenarios and record any deviations in `specs/002-shared-training-library/quickstart.md`
- [X] T065 Run `./vendor/bin/pint`, `composer test`, and `npm run build` from repository root `.` and resolve all failures in feature files
- [X] T066 Revalidate every functional requirement and measurable outcome against implementation and update completion notes in `specs/002-shared-training-library/checklists/requirements.md`

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 Setup**: starts immediately.
- **Phase 2 Foundation**: depends on Setup and blocks every story.
- **US1**: starts after Foundation and provides platform authoring/publication primitives.
- **US2**: starts after Foundation; only its read-only catalog depends on shared content produced by US1 for demonstration.
- **US3**: depends on Foundation and US1 publication/replacement primitives.
- **US4**: depends on Foundation plus CompanyCourse from US2 and module composition from US3.
- **US5**: depends on US1 lifecycle roots and integrates with US2/US3 selectors and assignment creation.
- **Polish**: follows all selected stories.

### User Story Dependency Graph

```text
Setup → Foundation ─┬─→ US1 ─┬─→ US3 ─→ US4
                    │        └────────→ US5
                    └─→ US2 ─────────→ US4
                              └──────→ US5
All selected stories → Polish
```

### Within Each Story

- Write the story's Pest tests and verify failure first.
- Implement models/projections before Actions and jobs.
- Implement domain Actions before Livewire components/routes.
- Re-run focused tests at each checkpoint.
- Files shared by tasks are edited sequentially even when neighboring tasks are parallelizable.

## Parallel Opportunities

### User Story 1

```text
T017 platform authorization tests | T018 authoring tests | T019 assignment migration tests | T020 UI tests
T021 catalog projections | T022 module validator
T027 shared-course UI | T028 shared-module UI
```

### User Story 2

```text
T031 permission tests | T032 association tests | T033 catalog UI tests
```

### User Story 3

```text
T039 composition tests | T040 propagation tests | T041 concurrency tests | T042 picker tests
```

### User Story 4

```text
T049 promotion tests | T050 evidence tests | T051 UI tests
```

### User Story 5

```text
T055 archive domain tests | T056 lifecycle UI tests
```

## Implementation Strategy

### MVP First

1. Complete Setup and Foundation.
2. Complete US1 platform administration.
3. Validate platform-only ownership, publication, read-only tenant visibility and assignment migration.
4. Stop for an MVP demonstration before enabling company association/composition.

### Incremental Delivery

1. Foundation → safe ownership and reusable immutable modules.
2. US1 → centrally managed shared library.
3. US2 → company course association.
4. US3 → hybrid composition and automatic propagation.
5. US4 → promotion of existing company content.
6. US5 → archive and retirement safety.
7. Polish → scale, accessibility, docs and full quality gates.

## Notes

- `[P]` means different files and no dependency on an incomplete sibling task.
- Every story task includes an exact repository-relative file path.
- Never edit `.env`, rewrite published records, delete operational history or bypass tenant scopes from Livewire.
- Queued work must carry explicit platform actor and company context.
- Mark tasks `[X]` only after their focused validation passes.
