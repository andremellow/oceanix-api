# Research: Shared Training Library

## Shareable content ownership

- **Decision**: Represent ownership with nullable `company_id` plus `is_shared`, protected by a database check: shared content has `company_id = null` and `is_shared = true`; company content has `company_id` populated and `is_shared = false`. Add `HasContentOwnership` to Course and Module; do not weaken `BelongsToCompany` on operational records.
- **Rationale**: This is the agreed domain representation, makes shared status explicit in queries and UI, permits platform creation without tenant context, and retains strict isolation for assignments and evidence. The database invariant prevents the two fields from drifting.
- **Alternatives considered**: Nullable `company_id` alone (does not preserve the agreed explicit flag); a separate ownership discriminator (unnecessary); changing `BelongsToCompany` globally (unsafe); polymorphic ownership (unneeded for two owner kinds).

## Reusable module boundary

- **Decision**: Add Module and immutable ModuleVersion aggregates. ModuleVersion owns one or more Lesson units. CourseVersion composes ordered `module_version_id` snapshots through CourseVersionModule.
- **Rationale**: Published courses cannot change silently, and a reusable module may eventually contain multiple lessons.
- **Alternatives considered**: Make each Lesson a module (conflates identity and edition); store only `module_id` (silently mutates published courses); copy content (breaks central maintenance).

## Existing-content migration

- **Decision**: Use additive staged migrations. Backfill one company-owned Module and matching ModuleVersion per existing Lesson while preserving lesson/question/video IDs, then create composition snapshots. Switch reads before retiring legacy placement columns.
- **Rationale**: Existing attempts, progress and evidence reference Lesson IDs; preserving them avoids historical rewrites.
- **Alternatives considered**: Rebuild content in one migration (evidence risk); permanent dual ownership/placement fields (drift).

## Shared course availability

- **Decision**: Add one durable CompanyCourse per company/shared-course pair. Removal and re-addition toggle lifecycle fields on the same row; content is never copied and the row is never deleted.
- **Rationale**: Ownership remains separate from availability, adds are concurrency-safe, and PostgreSQL/SQLite behavior stays aligned.
- **Alternatives considered**: Partial unique active-row indexes; hard-delete pivots; automatic availability to every tenant.

## Authorization boundary

- **Decision**: Keep platform management and promotion behind `EnsureUserIsPlatformAdmin` plus `PlatformAccess`. Add tenant permissions for `shared-courses.view/add/remove` and `shared-modules.view/use`; policies enforce ownership even for tenant-admin Gate bypass.
- **Rationale**: Platform capability is account-global and must not enter the tenant-grantable catalog; company actions are independently grantable.
- **Alternatives considered**: Platform Permission cases (tenant admins inherit the Gate bypass); reuse only `courses.*` (not atomic).

## Shared module propagation

- **Decision**: Publishing ModuleVersion creates a durable run and one idempotent item per affected published Course, dispatched after commit. Each job sets tenant context, locks one Course, derives from its latest published version, changes only target module references, validates and publishes. Existing drafts remain separate.
- **Rationale**: Cross-company fan-out may affect thousands of assignments. Short per-course transactions support progress and retry without global locks.
- **Alternatives considered**: One synchronous transaction; company opt-in drafts; updating published versions in place.

## Visibility and failure state

- **Decision**: Propagation uses `pending`, `processing`, `completed`, and `completed_with_failures`. ModuleVersion becomes published immediately; courses advance independently. The platform exposes counters/errors and retry, while selectors only expose fully published course versions.
- **Rationale**: Queued cross-tenant work cannot be globally atomic; durable partial progress is observable and recoverable.
- **Alternatives considered**: Hide the module until every course succeeds; synchronous rollback; claim atomicity across jobs.

## Concurrent publications

- **Decision**: Serialize per Course with a row lock. A job re-reads current published composition, applies still-relevant targets, then allocates the next version. Provenance keys make retries no-op; stale jobs never retire newer content blindly.
- **Rationale**: Human publications and multiple module updates can race without losing changes or colliding on version number.
- **Alternatives considered**: Unlocked `max()+1`; global lock; last-write-wins.

## Assignment migration

- **Decision**: Not-started means no CourseAttempt and no start evidence, regardless of pending/overdue status. Always migrate these. Migrate in-progress/failed only when restart was selected. Never migrate completed, waived or cancelled.
- **Rationale**: Status alone is not evidence of start and the behavior matches clarification.
- **Alternatives considered**: Replace every open status; use only pending status.

## Replacement identity and race safety

- **Decision**: Preserve requirement series/cycle, add replacement generation/publication provenance, uniquely bind a replacement to its predecessor, and emit idempotent cancel/create events. Assignment start and migration both lock and re-read the row.
- **Rationale**: The current replacement action increments recurrence cycle, processes every open status and depends on request authentication. Shared locking prevents start-versus-migration inconsistency.
- **Alternatives considered**: Reuse the current action unchanged; rewrite `course_version_id`; optimistic checks without locks.

## Promotion

- **Decision**: Atomically transfer Course and every company-owned Module in its compositions to platform ownership, including modules used by other company courses. Reactivate the source CompanyCourse and preserve all version/content/evidence IDs.
- **Rationale**: This is the clarified behavior; other courses retain snapshots while future module editions become platform-managed.
- **Alternatives considered**: Block reused modules; clone; promote only Course with inaccessible dependencies.

## Archive

- **Decision**: Archive blocks new associations, compositions and assignments. Existing assignments continue and history remains resolvable. CompanyCourse removal is blocked by active obligations and otherwise deactivates only that pair.
- **Rationale**: Stops new use without deleting evidence.
- **Alternatives considered**: Permit new assignments for associated companies; cancel existing assignments.
