# Planning research: PDF library

Run `pdf-library-20260916`; no production changes. Source-backed repository research completed by a read-only explorer. Official framework alignment is separately authored in architecture.md.

## Native authorization integration

Decision: company `lesson-documents.view`, `.reuse`, `.archive` permissions, with view prerequisites for reuse/archive and edit permission plus destination policy for reuse. Use existing enum-projected Gates and a document Policy, with mandatory explicit owner filters before any admin bypass. Register rows through an additive migration, no automatic role grants. Existing PermissionSeeder handles new installs; never run RoleSeeder as a deployment migration.

Evidence: app/Enums/Permission.php, app/Providers/AppServiceProvider.php, app/Models/User.php, database/migrations/2026_09_06_011954_project_course_preview_permission_catalog.php, database/seeders/RoleSeeder.php. Alternative rejected: route/menu-only visibility, which does not protect direct calls or revocation.

Platform authority uses `Account`, not tenant `User`; its PlatformPermission enum is atomic, but all active platform admins currently receive all abilities through PlatformAccess. Keep that native behavior and add explicit named PDF abilities. A new independently grantable platform-profile subsystem would be an out-of-scope auth redesign. FR-003 now states that distinction explicitly; concrete rollout remains part of owner architecture approval.

## Ownership and private Open

Decision: company query requires fresh actor company and is_shared=false; platform requires company_id null and is_shared=true. Neither library uses lesson association as ownership. LessonDocument has no tenant global scope, and Gate::before may bypass a record policy: explicit owner checks remain mandatory. “is_shared” is internal legacy naming for platform-owned content, not cross-library sharing.

Add a library-specific authorized read path because current LessonDocumentAccess requires both saved HTML membership and attachment. Reusing that requirement would prevent opening an unattached library item; removing it would weaken learner delivery. Keep contextual delivery unchanged. Routes use existing company/platform middleware and identities; never persist a public file URL.

## Retained archive and reuse transaction

Decision: retain immutable LessonDocument. Add one archival row per PDF, with unique document FK, server time and exactly one actor kind (User or Account). No byte/pivot deletion. Alternative rejected: allowing all document updates or soft-deleting the metadata, which would affect existing reads/relationships.

Reuse follows UploadLessonDocument's established target lock order and revision validation, then locks the document, checks owner/capability and absence of archival state, and syncWithoutDetaching's the destination association. Archive locks only the document before writing the archival row. Both serialize on the same row without an inverse target lock order.

Existing LessonDocumentLinks::validate checks retained attachment membership, so an attachment committed before archival can still be explicitly saved. Existing copy and delivery behavior remains unchanged. Archive-first refuses a new attachment. No file duplication or new target HTML write inside the reuse Action.

## Modal and insertion boundary

Decision: list/search/archive do not refresh the lesson editor snapshot. Reuse returns existing id/name/reference shape and delegates to the existing token/stable-record insertion event. Preserve its overlay/modal-close selection restoration and all dismissal guards. Search/pagination preserve captured selection and authored label. No broad editor refactor.

## Remaining conditions

No unknown framework or external provider dependency requires a new integration. Proposed artifact approval, additive migration implementation, representative fixtures and runtime QA are required later. Baseline probes do not prove new behavior. Prior PDF-run gate bookkeeping defects are not repaired or waived by this feature.
