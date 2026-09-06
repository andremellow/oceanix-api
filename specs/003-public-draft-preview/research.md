# Research: Public Draft Preview

## Repository evidence and decisions

### Exact-version graph

Decision: Resolve the course from the token's course_version_id. Use ordered course_version_lessons and their exact lesson_id when compositions exist; fall back to CourseVersion::lessons only for a legacy version without compositions. Never select a lineage's newest version or merge both representations. Shared included lessons are eligible through composition membership, not the visitor's company.
Rationale: CourseVersion::allLessonIds and company course detail already distinguish composed and legacy representations; ModuleVersion is a compatibility class backed by lessons.
Alternatives: snapshot cloning contradicts live-draft clarification; current-published-version lookup can expose the wrong version.

### Recoverable bearer links

Decision: 32 cryptographically random bytes encoded as a URL-safe token; store SHA-256 digest for unique lookup plus Laravel-encrypted token in a TEXT column for authorized copying. Hide both from model serialization. A separate row for each generation retains expiry history.
Rationale: Hash-only storage cannot re-display the existing link. Encrypted-only storage cannot index lookup. Tokens must never enter audit payloads, telemetry or exception context.
Alternative: temporary signed routes are supported, but explicit link lifecycle and recovery remain necessary.

### Generation concurrency

Decision: In a transaction, use existing lifecycle lock order (course then version), recheck authoritative permission and draft state, return existing unexpired generation, otherwise append one. Generation is synchronous. Do not delete expired generations. Do not mutate draft content.
Rationale: Serialize generation against itself and lifecycle transitions; SQLite alone cannot prove PostgreSQL locking.

### Public tenancy and locale

Decision: Exclude only public preview routes from IdentifyCompany and global SetLocale using route middleware exclusions, then apply a preview-specific locale middleware. Do not set TenantContext from the link or session. Resolve ownership explicitly after digest lookup, using only the targeted model scope bypass required for public access.
Rationale: IdentifyCompany can reject a visitor with an unrelated inactive company and writes session defaults; Course's company-library global scope can also filter content based on an unrelated session. Public access must behave identically for signed-out and signed-in visitors.
Alternative: switching the visitor into the link owner's company risks session contamination and unrelated access.

### Playback and authored media

Decision: Do not invoke training PlaybackAuthorizationService or the training player's event/answer endpoints. PreviewPlaybackService validates membership and playable current video, then calls VideoProvider. Add optional exact expiration to the existing provider method and preserve its defaults for existing callers; cap preview grants at min(now + 60 seconds, preview expiry). Verify returned expiry and recheck eligibility after the external call before exposing a grant. Public playback retries and renewals always re-resolve the capability.
Rationale: Training authorization records compliance evidence, and the current provider API accepts only integer minutes. Rounding up would overrun exact expiry.
Limits: A provider grant already delivered before publication may continue until its short expiry; buffered/downloaded bytes cannot be recalled. Existing content images may already be public storage or remote URLs, and this feature does not privatize the existing media estate. These limits must be shown in the architecture and reviewed against the accepted application-access boundary; strict revocation of all external bytes would require a separate media proxy design and explicit contract amendment.

### Public content safety

Decision: Use LessonContentRenderer::renderContent with its sanitizer, not ModuleVersion passed to render(Lesson). Reuse sanitized HTML, project only question prompts/choices, strip correctness/feedback fields. Do not serialize Eloquent models into the public page or client state. Suppress unsafe/non-content relative links in the public projection; never fetch arbitrary authored URLs server-side.
Rationale: Existing lesson renderer uses typed Lesson and placeholder video, so it is not a ready-made public player. Existing sanitizer strips active HTML but permits relative links; public navigation must not reveal admin paths.

### Framework documentation checked

Boost search-docs succeeded for installed Laravel 13, using encrypted casts, pessimistic locking, and temporary signed URLs. Official references:

- [Encrypted casts](https://github.com/laravel/docs/blob/13.x/eloquent-mutators.md): use TEXT; encryption cannot be queried.
- [Query locking](https://github.com/laravel/docs/blob/13.x/queries.md): transactions enclose pessimistic locks.
- [Signed URLs](https://github.com/laravel/docs/blob/13.x/urls.md): supported alternative, not chosen as the generation record.

Installed package versions were verified with composer show --direct. Boost application-info confirmed PHP 8.4, Laravel 13.26.1 and PostgreSQL. No external credentials or data were modified.

### Local provider request boundary

Decision: preview playback adapts the fake provider grant to a dedicated signed local preview-media route, reusing extracted file streaming after capability checks. Do not send public clients to dev.video.play, whose tenant middleware still applies. Actual media GET/Range is included in inactive-company-session QA.
