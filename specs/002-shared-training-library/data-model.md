# Data Model: Shared Training Library

## Ownership invariant

```text
platform-owned/shared: company_id IS NULL     AND is_shared = true
company-owned:         company_id IS NOT NULL AND is_shared = false
```

The database enforces exactly these two valid combinations so `company_id` and `is_shared` cannot drift. Operational records still require `company_id`. Codes are unique per company for company-owned content and globally unique among shared content; application validation mirrors runtime constraints in SQLite tests.

## Course

- `company_id`: nullable; null for platform-owned shared content, populated for company-owned content
- `is_shared`: boolean; true only when `company_id` is null
- existing `code`, `title`, `description`, `status`, `current_published_version_id`

Relations: CourseVersion editions; CompanyCourse associations when platform-owned; historical TrainingRequirement and UserTrainingAssignment references.

States: `draft → active → archived`. Operational deletion is unavailable.

## CourseVersion

- existing course, version, status and content-snapshot fields
- `publication_kind`: `manual` or `shared_propagation`
- `source_course_version_id`: nullable provenance
- `propagation_item_id`: nullable unique idempotency link
- tenant-user and platform-account publisher references

Rules: `(course_id, version_number)` unique; immutable after publication except retirement; automatic versions derive from locked current published content, never a separate draft.

## Module

- `company_id`: nullable; null for platform-owned shared modules
- `is_shared`: boolean; true only when `company_id` is null
- `code`, `title`, `description`
- `status`: `draft`, `active`, `archived`
- `current_published_version_id`: nullable
- timestamps

Module is the permanent reusable identity. Platform-owned modules may participate in platform or company courses; company modules remain private to their owner.

## ModuleVersion

- `module_id`, `version_number`
- `status`: `draft`, `published`, `retired`
- title/description snapshot
- publication timestamp and tenant-user/platform-account publisher references

Rules: `(module_id, version_number)` unique; published rows and child content are immutable.

## Lesson and child content

Lesson moves beneath ModuleVersion with `module_version_id`, title, description, type, position, watch threshold and passing score. Video, Question and QuestionOption remain beneath Lesson. Backfill preserves all existing IDs so attempts, progress and evidence remain valid. Ownership derives through Module.

## CourseVersionModule

- `course_version_id`, `module_version_id`
- `position`, `is_required`

Constraints: unique `(course_version_id, position)` and `(course_version_id, module_version_id)`; referenced ModuleVersion must be published before CourseVersion publication; company course compositions may use modules owned by that company or by platform, never another company.

## CompanyCourse

- `company_id`, `course_id`
- `associated_at`, `associated_by_user_id`
- `removed_at`, `removed_by_user_id`, `removal_reason`

Constraints: permanent unique `(company_id, course_id)` pair; Course must be platform-owned; active when `removed_at` is null; add reactivates idempotently; removal never deletes and is refused while active requirements or obligations depend on the course.

## SharedContentPropagation

- UUID, `module_version_id`, `initiated_by_account_id`
- `restart_in_progress` boolean, default false
- `status`: `pending`, `processing`, `completed`, `completed_with_failures`
- affected/not-started/in-progress and processed/succeeded/failed counts
- timestamps for start/completion

Impact counts are a confirmation snapshot; workers revalidate current state.

## SharedContentPropagationItem

- `propagation_id`, `course_id`, nullable `company_id`
- `status`: `pending`, `processing`, `succeeded`, `failed`
- source/result CourseVersion references
- `attempt_count`, nullable sanitized `last_error`, timestamps

Constraints: unique `(propagation_id, course_id)` and unique non-null result version. Retry with an existing result succeeds without mutation.

## UserTrainingAssignment replacement additions

- `replacement_generation` integer, default 0
- nullable publication/propagation provenance
- existing `supersedes_assignment_id`, unique when non-null

Replacement preserves series/cycle, origin, schedule and unrelated metadata. Uniqueness includes replacement generation. The predecessor becomes cancelled and the successor pending; attempts/progress do not transfer; cancel/create events are append-only and idempotent.

## State transitions

```text
ModuleVersion draft → published → retired
                         └─ Propagation pending → processing
                                                   ├─ completed
                                                   └─ completed_with_failures → retry
PropagationItem pending → processing → succeeded
                              └──────→ failed → processing
CompanyCourse absent/removed → active → removed
Assignment not-started ──publication──> cancelled → replacement pending
Assignment in-progress ──default──────> unchanged
Assignment in-progress ──restart──────> cancelled → replacement pending
Assignment completed/waived/cancelled ─────────────> unchanged
```

## Migration sequence

1. Add `is_shared` to Course, make `company_id` nullable, backfill every existing row with `is_shared = false`, then add the paired-field check and owner-scoped unique indexes.
2. Create Module, ModuleVersion and CourseVersionModule.
3. Add nullable ModuleVersion link to Lesson and publisher/provenance fields to versions.
4. Backfill one module/version per existing lesson, preserving Lesson IDs and ordered composition.
5. Switch authoring, playback, validation and reports to the composition graph.
6. Remove legacy CourseVersion placement fields from Lesson only after compatibility coverage passes.
7. Create CompanyCourse, propagation records and assignment replacement provenance/uniqueness.
8. Replace destructive content cascades where evidence depends on content; expose archive only.
