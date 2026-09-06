# Feature Specification: Public Draft Preview

**Feature Branch**: `main` (requested by user)
**Created**: 2026-09-05
**Status**: Approved for tasks and implementation — 2026-09-05
**Input**: Generate an open public draft preview link, valid for seven days and regenerable after expiration, for the operator to copy and email to a client.

## Clarifications

### Session 2026-09-05

- Q: Live draft or frozen snapshot? → A: Show the latest saved draft, including edits after link generation.
- Q: Which drafts support public preview? → A: Course drafts only; standalone module draft links are outside scope.
- Q: Which course authoring contexts support link generation? → A: Company-owned courses and platform shared courses, respecting permissions and content authority in each area.
- Q: How does assessment work in the preview? → A: Display questions and answer choices only; recipients cannot submit answers.
- Q: What happens if the course is published before expiration? → A: The link stops working and displays a friendly message. Portuguese (pt-BR) is the default language for the public preview.

- Q: Can already authorized video continue briefly after publication, and already public images remain accessible? → A: Yes. Block new Oceanix page/content/playback authorization requests immediately; previously issued video grants may continue for at most 60 seconds, never beyond preview expiry. Existing public images retain their hosting rules. User authorized tasks and development with this boundary.

## User Scenarios & Testing

### User Story 1 — Share unpublished content (Priority: P1)

An authorized operator selects **Generate preview link** from a draft, copies it, and sends it to a client.

**Why this priority**: Clients can review content without accounts or publication.
**Independent Test**: Generate the link and open it in a signed-out browser.

**Acceptance Scenarios**:

1. **Given** an eligible draft and authorized operator, **When** generation is requested, **Then** a copyable link and expiration date appear.
2. **Given** a valid link, **When** any recipient opens it without authentication, **Then** the linked draft content is displayed without a login prompt.
3. **Given** missing sharing permission or authority over the content, **When** generation is attempted through the screen or directly, **Then** it is denied.
4. **Given** a company-owned course draft or a platform shared course draft, **When** an operator authorized in its respective authoring area generates a link, **Then** that draft can be reviewed publicly. Company access to shared content alone does not grant platform authoring or sharing authority.

### User Story 2 — Expire and regenerate a link (Priority: P1)

An operator generates another link after the seven-day review period expires.

**Why this priority**: Public access is time-limited but repeat review remains possible.
**Independent Test**: Check access immediately before and at expiration, regenerate, and compare old and new links.

**Acceptance Scenarios**:

1. **Given** an eligible draft link, **When** fewer than 168 hours have elapsed, **Then** the link remains valid.
2. **Given** the expiration instant has arrived, **When** content is requested, **Then** access is denied and an unavailable-preview state appears.
3. **Given** an expired link and an eligible draft, **When** an authorized operator regenerates, **Then** a different link receives a fresh 168-hour lifetime and the old link remains invalid.
4. **Given** a currently valid link, **When** the operator reloads the draft or copies it again, **Then** the existing link is available with its original expiration.
5. **Given** a linked draft published before expiration, **When** the recipient opens the link, **Then** no course content is returned, no login is required, and a friendly preview-ended message explains that the recipient should contact the sender.
6. **Given** a first-time recipient without a saved language preference, **When** any public preview page or unavailable-preview state is opened, **Then** interface text is displayed in Brazilian Portuguese, including when the browser prefers English.

### User Story 3 — Review without creating training evidence (Priority: P2)

A client browses content and available video without becoming a learner.

**Why this priority**: Editorial review must not count as completed training.
**Independent Test**: Browse the preview and verify no learner or compliance records change.

**Acceptance Scenarios**:

1. **Given** a valid preview, **When** content is viewed, **Then** a draft-preview label is visible and administrative controls and employee data are absent.
2. **Given** playable video belonging to the linked draft, **When** playback is requested, **Then** temporary access is granted only for that content.
3. **Given** processing or missing media, **When** a preview is opened, **Then** available content remains reviewable with a clear unavailable-media state.
4. **Given** a draft containing assessment questions, **When** the recipient opens the assessment preview, **Then** questions and choices are visible without answer submission controls, answer keys, correctness feedback, or scoring.

### Edge Cases

- Expiration applies to additional requests from an already open page.
- Unknown, altered, expired, and replaced links disclose no content.
- Changing identifiers cannot access another version, module, lesson, video, or company's content.
- Publishing, archiving, or discarding the linked draft ends preview eligibility; another draft does not inherit its link.
- Simultaneous generation does not create competing active links for the same draft.
- Clipboard failure leaves a selectable link for manual copying.
- Video-provider failure does not expose permanent video addresses.

## Requirements

### Functional Requirements

- **FR-001**: An authorized operator MUST generate and copy a preview link from an eligible draft.
- **FR-002**: Anyone possessing a valid link MUST be able to open it without login, account creation, invitation, or email verification.
- **FR-003**: Links MUST expire exactly 168 hours after generation according to the server clock.
- **FR-004**: After expiration, an authorized operator MUST be able to generate a different link valid for another 168 hours. The previous link MUST remain invalid.
- **FR-005**: Reloading or copying an active link MUST NOT extend its expiration; the draft screen MUST display its expiration.
- **FR-006**: Generation MUST require a separately grantable sharing permission, its prerequisites, and authority over the content. Revoking that permission MUST prevent subsequent generation.
- **FR-007**: Public access MUST expose only the selected version and included content, with no administrative controls, employee data, or unrelated content.
- **FR-008**: Preview activity MUST NOT create or modify assignments, learner progress, assessment submissions, compliance events, or certificates.
- **FR-009**: Each Oceanix page, content, and playback-authorization request MUST validate the link, version eligibility, and content membership. Video access MUST remain temporary, expire no later than the preview link, and last at most 60 seconds. Already issued external video grants may continue to their bounded expiry after publication; existing public images retain their hosting access rules.
- **FR-010**: Publishing, archiving, or discarding the linked draft MUST immediately deny new Oceanix preview reads and playback authorizations, subject to the accepted external-media limits in FR-009. A published version MUST NOT remain accessible through its draft link until expiration. Recipients MUST see a friendly preview-ended message directing them to contact the person who shared the link, without a login prompt or draft content.
- **FR-011**: Generating a link MUST NOT publish or modify authored content.
- **FR-012**: Preview screens MUST identify the draft state and support desktop, mobile, keyboard navigation, and English/Portuguese localization. Brazilian Portuguese (pt-BR) MUST be the default interface language for public preview pages and their unavailable states when the visitor has not explicitly selected a supported language. Browser language alone MUST NOT override this default. Authored course content remains in its authored language.
- **FR-013**: Public preview links MUST be generated only for course drafts, covering both company-owned courses in company administration and shared courses in platform administration. Each area MUST enforce its permissions and content authority. Recipients may browse the content included in that exact course draft; standalone module draft preview links are outside scope.
- **FR-014**: The preview MUST show the latest saved content of the exact linked draft, including edits made after link generation. Unsaved editor changes MUST NOT appear. Editing MUST NOT extend link validity.
- **FR-015**: Previews MUST be excluded from search indexing and public content listings.
- **FR-016**: Assessment preview MUST display questions and answer choices as read-only content. It MUST NOT accept answers, evaluate responses, display correctness feedback or answer keys, or issue a score.

### Key Entities

- **Draft version**: Exact unpublished course version selected for review, with its content owner, included content, and eligibility status.
- **Preview link**: Access capability for one version, with issuing operator, generation time, and expiration time.
- **Recipient**: Any person holding the link, without a required learner identity.

## Success Criteria

### Measurable Outcomes

- **SC-001**: Generation and copying take at most two actions from an eligible draft screen.
- **SC-002**: Every valid-link acceptance scenario opens in a signed-out browser without authentication.
- **SC-003**: All expiration scenarios accept eligible links immediately before 168 hours and deny them at and after the boundary.
- **SC-004**: Every regeneration scenario produces a working new link while the expired link stays denied.
- **SC-005**: Preview journeys produce zero changes to assignments, progress, assessment submissions, compliance events, and certificates.
- **SC-006**: All unauthorized generation and cross-content access scenarios deny access without exposing draft content.
- **SC-007**: Every publication-before-expiration scenario denies content and shows a friendly preview-ended state, in Brazilian Portuguese by default.

## Assumptions

- Operators send emails themselves; automatic email delivery is outside scope.
- One active link per exact version is sufficient. Early rotation and manual revocation are outside initial scope.
- Assessment questions and choices are displayed read-only for editorial review, as confirmed by the user; submissions, scoring, answer keys, and learner gating are outside scope.
- A preview ends when its exact version ceases to be an eligible draft, even before expiration.
- Already downloaded content cannot be recalled. Subsequent Oceanix requests require valid access; already issued external video grants and existing public images follow the accepted FR-009 boundary.
- Existing project rules govern ownership, permissions, localization, temporary media access, and published-version immutability.

- Portuguese is the public preview display default; English remains the canonical source for translatable interface strings under existing repository localization rules.
