# Interface Contracts: Public Draft Preview

All paths below are planned, not existing endpoints. Named-route generation is required.

## Authenticated generation and retrieval

| Method/path | Authority | Result |
|---|---|---|
| POST /c/{company}/courses/{course}/versions/{version}/preview-link | auth + company context + courses.preview-links.generate + record policy | 201 new generation, 200 same active generation |
| GET same path | Same authority | 200 operator DTO with active/expired/absent state |
| POST /platform/shared-courses/{course}/versions/{version}/preview-link | platform admin + shared-courses.preview-links.generate + platform record authority | Same semantics |
| GET same platform path | Same authority | Same read semantics |

Both company and platform Livewire panels call the same authorized Action/service as their dedicated routes. Apply middleware and server authorization to direct requests and component actions. Reauthorize on hydration before exposing the credential. CSRF protects writes. Repeated generation while active returns existing link. Forbidden authority → 403; inaccessible/mismatched content → 404; non-draft/archived version → 409 friendly conflict. Failed generation never changes published content or existing expiry. Only authorized operator DTO contains url, expires_at and state; never hash or encrypted token.

## Public access

| Method/path | Name | Result |
|---|---|---|
| GET /preview/courses/{token} | course-preview.show | 200 reader/overview, 410 ended known link, 404 unknown |
| GET /preview/courses/{token}/items/{kind}/{item} | course-preview.item | 200 selected content; kind is composition or lesson; membership checked |
| POST /preview/courses/{token}/items/{kind}/{item}/playback | course-preview.playback | 200 temporary grant; same capability/eligibility/membership checks |
| GET /preview/courses/{token}/items/{kind}/{item}/media/{asset} (local only) | course-preview.local-media | Signed URL plus current token/item/asset eligibility; streams local bytes without redirect |
| POST /preview/courses/{token}/locale/{locale} | course-preview.locale | CSRF + allowlist en/pt_BR, save explicit preference, redirect to same preview |

Public means no account or auth middleware. Keep web CSRF for POST calls; visits establish the session needed for playback. Exclude IdentifyCompany and global SetLocale for these routes; add preview locale middleware. No implicit model binding before token validation. GET includes ordinary session/CSRF plumbing but never changes tenant or training state. Locale endpoint validates token syntax and uses named redirects; no untrusted redirect destination.

Known ended token pages use friendly generic text; no course title, personal data or public published-course fallback. Malformed/unknown credentials return 404 with the unavailable view. Item tampering returns 404. Processing/missing video → 409 media-unavailable JSON; provider failure → 503 retryable generic JSON; expired/ended → 410. JSON errors contain only a stable error code and localized message. Video response fields: playback_url, expires_at, poster_url if available. No download URL, persistent provider identifiers or training event endpoint.

All preview HTML/JSON/error responses: Cache-Control private, no-store; X-Robots-Tag noindex,nofollow,noarchive; Referrer-Policy no-referrer. Ensure middleware covers error responses too. Start with separate public read (60/min/IP) and playback (30/min/IP) limiters, plus authenticated generation (10/min/actor). Rate-limit responses are localized and contain no token. These are operational defaults, not a new product SLA. Audit/telemetry and access-log configuration must redact the token path segment; no token-bearing route parameters in exception context.

Playback grants expire at the earlier of 60 seconds from issue and the preview expiry, including posters. Extend provider API with an optional absolute expiration while preserving existing call behavior. Verify actual returned expiry; fail closed if the provider cannot honor it. A previously issued external grant is not revocable by merely changing database state; this bounded behavior was accepted by the user; see architecture risks.

## Version selection and assessment

Token → exact CourseVersion → composed items if any, otherwise legacy lessons. All child reads use this set. Editing updates later responses, never the generation expiry. Do not chase newest shared-module lineage or currentPublishedVersion. Questions project prompt, order and choice text only. No answer submission endpoint exists for preview. Preview client never calls my-training playback/events or assessment Actions.

For FakeVideoProvider, replace the returned legacy /dev/videos URL with the local preview-media route above. Its signature expiry is the same bounded absolute grant expiry. It excludes IdentifyCompany and SetLocale, validates membership and the current playable asset, and reuses extracted local file-serving logic after authorization. Test actual GET/Range playback with an unrelated inactive-company session; grant-response tests alone are insufficient. Keep the original dev routes unchanged for existing consumers.
