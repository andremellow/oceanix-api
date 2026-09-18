# Proposed data model

Authoritative implementation decisions are finalized by architecture.md; product rules are FR-001–009.

## Existing immutable document

Keep lesson_documents public_id, company_id, is_shared, name, disk, path, mime_type, size_bytes and original timestamps immutable. Existing lesson_document composite-key associations are retained. No ownership transfer or blanket model update exemption.

## LessonDocumentArchive

Proposed lesson_document_archives: id, unique lesson_document_id with restrictive FK, nullable archived_by_user_id and archived_by_account_id with restrictive FKs, archived_at assigned by the server. Exactly one actor reference is required; principal numeric IDs are not interchangeable. Record is append-only/no deletion. No archive reason field is proposed because the accepted operation requires confirmation, not a new reason workflow.

Active means no archive record; archived means one exists. Archive is idempotent under document row lock and unique constraint. Existing files/metadata remain queryable for contextual delivery and draft copying.

## Capabilities

Company catalog additions: lesson-documents.view, lesson-documents.reuse, lesson-documents.archive. Reuse/archive require view; reuse also requires courses.update and the target policy. No role/profile grants are backfilled. Platform abilities preserve existing active administrator resolution, not tenant profiles.

## Invariants and migration

Additive migration creates archive records table and permission catalog rows only. Historical PDFs are active by absence of archive rows. Rollback must refuse loss of retained archival evidence; permission records/grants are not destructively rolled back. No local/shared migration runs during planning. Domain concurrency: target locks before document lock for reuse; document-only lock for archive. Original published content, original metadata and private bytes remain unchanged.
