# Architecture

## Context and constraints

Scope: approved clarifications in spec.md; implementation authorized. Critical assurance applies because public draft access crosses authorization boundaries. Runtime contract: .toscanini/runtime/runs/public-draft-preview-20260905/execution-contract.json. User authorized implementation on 2026-09-05.

## Boundaries and contracts

1. Authenticated controllers/Livewire components validate input, authorize, call GenerateCoursePreviewLink, and render a shared link panel.
2. Generation Action owns permission checks, locking, 168-hour timestamps, token creation and active-link reuse. It returns a narrow operator DTO.
3. PublicPreviewResolver owns digest lookup, current eligibility, course scope bypass and exact-version membership. It never relies on the recipient's User, Account, or company session.
4. CoursePreviewProjection owns sanitized content and read-only question/choice data. Ordinary Blade GET navigation avoids Livewire model hydration across the public boundary.
5. PreviewPlaybackService owns temporary video grants without ComplianceEventRecorder, assignment services, AnswerQuestion or progress projectors.
6. Preview middleware owns locale default, no-store/private response headers, noindex/nofollow/noarchive and Referrer-Policy: no-referrer. Token URLs are excluded/redacted in request telemetry and reverse-proxy access logs before release. Do not emit bearer URLs in audit events.

## Data, authorization, and transactions

See data-model.md and contracts/public-preview.md. Company permission: courses.preview-links.generate, prerequisites courses.view and courses.update, Gate and CoursePolicy record method; admin bypass remains Gate::before. Because Gate::before may approve administrators without invoking a Policy, explicit content ownership and version-membership guards MUST run in the shared authority service after the Gate/Policy check, including for administrators and direct Action calls. Passing a CourseVersion to a generic bypass must never authorize a foreign company or platform course. Server-side route and Action checks plus protected link retrieval; revocation removes access even on an already opened component. Shared-course association alone never enables link generation.

Platform: add SharedCoursesView, SharedCoursesUpdate and SharedCoursesGeneratePreviewLink atomic PlatformPermission values and prerequisite metadata for sharing. Apply EnsureUserIsPlatformAdmin and EnsurePlatformHasPermission on dedicated platform generation/retrieval routes, plus a platform course record authorization service checking shared ownership and exact draft. PlatformAccess currently grants all abilities to active platform administrators; retain that existing bypass. Do not invent tenant User sessions or a new platform role-management subsystem. Tests prove platform non-admin denial and active-account/admin revocation; tenant access-profile grant/prerequisite/revocation tests remain mandatory.

Lock order: existing course lifecycle order must be observed (course then version where both are locked). Reauthorize inside transaction; refresh draft and course state. Check newest unexpired generation under the lock, then reuse or append. Concurrent publication may win and reject generation, or generation may win and return a link whose next read is unavailable after publication. No uncommitted content may leak.

Public routes bypass IdentifyCompany and SetLocale only for this namespace, preserve auth/session state, and avoid scoped implicit binding. For local fake-video playback, the preview service must replace the legacy dev.video.play grant URL with a signed, expiring preview-media URL bound to token + item + asset. This local-only route revalidates the link, membership and current asset, excludes tenant middleware, and streams through the existing local media-serving logic; it never redirects to /dev/videos. Extract reuse of file serving without changing authorization on the existing development upload/play routes. Scoped queries elsewhere remain untouched. Link lookup performs bounded explicit ownership checks. Determine active owner eligibility without exposing owner information.

## Invariants

- INV-01: Published versions and assignment/certificate references remain immutable.
- INV-02: No preview operation writes learner progress, answers, assignments, compliance events, or certificates.
- INV-03: Capability authorizes only its exact course version and current composition membership.
- INV-04: Recipient tenant/session does not authorize, restrict, or mutate public preview scope.
- INV-05: Video grants are temporary; public DTOs omit answer keys, PII and credentials.
- INV-06: No operational deletion, environment-file edit, dependency change or unrelated tooling restoration.

## Operational risks and rollback

- Bearer URL recipients can forward it: this is explicitly accepted public access. Use high-entropy tokens, rate limits, no token logs and no referrer leakage.
- Expiry is server-enforced for every application request. Use exact provider expiration capped by link expiry. A preissued external grant can remain valid for at most 60 seconds after a lifecycle change; publication blocks new application reads immediately. The user explicitly accepted this boundary on 2026-09-05; it does not weaken the seven-day expiry ceiling.
- Existing remote/public images remain subject to their existing hosting access rules. No global media-privatization or SSRF proxy is added. Links must not claim to recall previously exposed assets.
- Read consistency: each response reflects committed saved draft state; no snapshot at generation and no silent lineage upgrade. If a concurrent edit removes media during provider authorization, recheck membership and current-video identity before returning the grant.
- Empty/incomplete drafts remain reviewable; unavailable video is an inline state. No completion rule or watch threshold is enforced for editorial navigation.
- Roll back application exposure by disabling preview routes/controls while retaining generation history. Do not drop populated operational records automatically.

## Required tests

AC-01: company/platform authority, tenant permission grant/denial/direct access/prerequisites/admin bypass/revocation; platform role revocation; cross-company and shared association denial.
AC-02: frozen-time 168-hour boundary, same-link read/copy, renewal, old-link denial, separate generations; PostgreSQL concurrency generation/generation and generation/publication.
AC-03: two distinct versions/companies; composed and legacy content; latest saved edits; no latest-lineage substitution; tampered item/video IDs.
AC-04: questions and choices present, answer fields absent from HTML/JSON; no training evidence writes during text, media and navigation requests.
AC-05: publish/archive/discard while page open, subsequent request denied, friendly no-content state; unknown link generic denial.
AC-06: pt-BR despite English browser, explicit English preference, invalid preference fallback; 390px and 1440px, keyboard, clipboard failure.
AC-07: playable/unready/provider-failure/replaced-video, exact expiry during provider latency, poster expiry, no permanent URLs, no training event client.
AC-08: unrelated inactive company session, platform session, malicious content, cache/referrer headers, plaintext token serialization/log redaction, post-hydration permission revocation.
AC-09: planning evidence and independent architecture/design verdicts; later implementation gate evidence remains separate.

## Decision

Approved architecture contract. User accepted the media boundary and authorized implementation. Implementation alignment and executable gates remain required.
