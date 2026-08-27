# Domain Action Contract

## AssociateSharedCourse

**Actor**: tenant person with `shared-courses.add`.

**Input**: active company and published, non-archived platform-owned Course.

**Outcome**: create or reactivate the unique CompanyCourse pair and audit once. Repeated/concurrent requests return the same active association.

**Failures**: wrong tenant, private/archived/unpublished course or missing permission.

## RemoveSharedCourse

**Actor**: tenant person with `shared-courses.remove`.

**Input**: active CompanyCourse and reason.

**Outcome**: set removal metadata without deletion and audit once.

**Failures**: active requirement, open assignment or current obligation depends on the course. Already removed is idempotent success.

## MakeCourseShared

**Actor**: authenticated platform administrator.

**Preview**: source company, Course, owned Modules that transfer and every other company Course using them.

**Commit**: lock and revalidate; atomically transfer Course and every owned Module in its compositions; create/reactivate source CompanyCourse; preserve all IDs; write aggregate and per-entity audit context.

**Failures**: stale preview, already shared/archived record, invalid ownership graph or content owned by another company. No partial transfer.

## PublishModuleVersion

**Actor**: authenticated platform administrator.

**Input**: editable platform-owned ModuleVersion and `restart_in_progress` boolean.

**Preview**: counts affected current CourseVersions and their not-started/in-progress assignments without cross-tenant person details.

**Commit**: validate and freeze ModuleVersion; create SharedContentPropagation and unique items; dispatch after commit; audit publication.

**Return**: immutable module version and propagation identifier/status.

## PropagateSharedModuleToCourse

**Actor context**: explicit platform Account plus nullable target Company; never request/auth globals.

1. Claim item idempotently and set TenantContext for company content.
2. Lock Course and re-read current published CourseVersion.
3. Verify current composition still needs the target ModuleVersion.
4. Derive a CourseVersion, changing only applicable module references; leave drafts untouched.
5. Validate and publish with propagation provenance.
6. Replace eligible assignments.
7. Mark success/update counters; on exception, persist sanitized failure and allow retry.

Concurrent publications serialize on Course. Existing result makes retry a successful no-op.

## ReplaceAssignmentsForPublication

**Input**: old/new CourseVersion, publication provenance, explicit actor and `restart_in_progress`.

**Eligibility**:

- Always replace assignments with no CourseAttempt and no start evidence, including overdue-but-unstarted.
- Replace in-progress/failed only when restart is true.
- Never replace completed, waived or cancelled.

**Outcome**: lock/re-read each assignment; cancel predecessor; create one idempotent pending successor preserving recurrence occurrence, origin, schedule and metadata; copy no progress; emit one cancel and one create event; audit; obsolete stale notifications and schedule replacements after commit.

If learner start wins the lock, treat it as started. If replacement wins, later start rejects the cancelled predecessor and resolves to the successor.

## ArchiveSharedContent

**Actor**: authenticated platform administrator.

**Outcome**: archive Course or Module and block new associations, compositions and assignments. Existing assignments and all evidence remain available. Archiving Module does not rewrite published CourseVersions.

