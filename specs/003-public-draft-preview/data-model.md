# Data Model: Public Draft Preview

## CoursePreviewLink (new course_preview_links table)

| Field | Meaning / constraint |
|---|---|
| id | Internal primary key; never a bearer credential |
| course_version_id | Required indexed FK to the exact existing course version; restrict deletion |
| token_hash | Unique 64-character SHA-256 digest of a 32-byte random token |
| token_encrypted | TEXT encrypted by Laravel; hidden alongside hash from serialization |
| generated_at | UTC server timestamp, fixed at generation |
| expires_at | UTC generated_at + 168 hours, immutable for this generation |
| generated_by_user_id | Nullable tenant User FK, null on identity deletion; exactly one issuer type at creation |
| generated_by_account_id | Nullable platform Account FK, null on identity deletion; exactly one issuer type at creation |
| created_at / updated_at | Standard bookkeeping; lifecycle records are not deleted or rewritten on renewal |

The course relation derives company/shared ownership; do not duplicate mutable ownership on this row. Enforce exactly one non-null issuer in the Action at creation; do not impose a permanent XOR constraint that prevents legitimate later anonymization. Issuer IDs are never included in public responses. Model mutation protections prevent changing generation, version, token, and expiry after creation; identity anonymization may clear issuer references according to existing policy.

Indexes: unique token_hash; composite (course_version_id, expires_at, id). Serialize under parent locks so overlapping active generations cannot be created by authorized application writes. All generation paths must call one Action; no direct mass assignment endpoint.

## Lifecycle

- Absent → generated: authorized operator + manual draft on active course (and active owner company if company-owned).
- Active → active: read/copy returns the same credential; never updates expiry.
- Expired → new generation: insert new row with new token; keep old row expired.
- Any → unavailable: exact version is no longer draft, course is archived/retired, owning company is inactive, or content no longer exists. Eligibility is derived on each request; no scheduled expiry job.
- Unknown/altered token → not found, without ownership information.

No changes to CourseVersion content fields, lessons, compositions, questions, answers, assignments or certificates. No publication-hook token update is required because reads inspect current lifecycle state.

## Public projection (not a stored entity)

Course title/description/version label, ordered content items (composition id for composed content; lesson id for legacy content), sanitized body, playable-media flag, question prompts and choice text, expiration metadata for client controls. Do not expose company/account IDs, author/publisher details, provider_asset_id, storage credentials, correct-choice flags, explanations revealing answers, or internal model attributes.

All requested item IDs resolve from the already validated version's content set. A composition always points to the exact lesson row; latest-lineage lookups are forbidden. Legacy fallback is only when the composition collection is empty.

## Migration and rollback

Additive table and permission catalog changes only. No backfill, content rewrite, index rebuild of operational tables, or automatic grants to non-admin profiles. Existing administrator bypass applies. Disabling new routes safely turns off the feature while retaining link history. Do not execute migration down in production after links exist; destructive table removal requires separate authorization. Keep application encryption keys available for the life of existing links; decryption failure returns an operator-visible recovery error without silent early rotation.
