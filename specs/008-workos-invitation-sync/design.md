# Design contract: WorkOS invitation reconciliation

Scope: workos-invitation-sync-v1. Read-only design-agent authoring completed; owner approval pending. Implements FR-001–012 and SC/QA-001–018 without new scenarios. Source: docs/control-center-design-system.md and current People list/detail/import/css.

## Primitives and composition
Reuse x-page-hero, x-status-message, x-empty-state, Flux labelled controls/callouts/modal, admin-page space-y-7, form-panel, detail-card, admin-control/admin-primary-action and status-pill modifiers. Existing Instrument Sans and --ds-* semantic tokens; no new palette or inverse surface. Keep hero identity/result count/Import. Operational invitation controls go in a labelled panel below the hero.

## Directory and filters
Rename Status to Person status. Retain combined search/department/job-function/person-status filters. Add Invitation status (all, pending, accepted, expired, revoked, not invited, unverified) and Oceanix access (all/no recorded access). Pagination/counts describe the same filtered population; reset page after changes. Company-wide recovery is explicitly labelled in this company, not implied to follow filters.
Table keeps identity/department/function, separate person status/invitation/local access, right-aligned Open training/Overdue training counts. No recorded access is explicit; accepted invitation/WorkOS identity never substitutes for local timestamp. Pending accent, accepted positive, expired warning, revoked negative, not-invited/unverified neutral. Invitation cell shows last verified date. Failed refresh preserves prior evidence and says Latest check failed.

## Actions and confirmation
Synchronize WorkOS requires PeopleSyncWorkos and explains that it checks this company without emails. Send invitations to selected requires PeopleInvite; selection count/Clear selection; change of page/filter clears selection; no invisible cross-page selection. Invite all pending is explicitly company-wide, excluding accepted/current members/revoked/blocked statuses. Cached candidate counts are not guaranteed email counts. Zero candidates disables action with explanation.
Known exclusions show readable reasons. Revoked remains explicitly selectable with new-invitation warning. Unverified requires execution-time verification. Confirmation identifies scope/count, distinguishes resend versus new invitation, says verification may skip recipients and explicitly names revoked reissue when selected. Flux modal Cancel/Queue invitations; no typed-email requirement.
Detail labels: Pending Resend invitation; Expired/Revoked Send new invitation; Not invited Send invitation; Unverified Verify and send invitation. Accepted/current-member/blocked state shows reason instead.

## Progress and outcomes
Stable panel retains data while queued/running. Distinguish synchronization from invitation sending. Queued Waiting to start; running processed/total; completed successful/unchanged/skipped counts and time; partial failure successful/skipped/failed counts; failed readable error. Last successful synchronization is separate from latest attempt; Not synchronized yet when absent. Queue success says Invitations queued, never sent. Authorized failed-sync retry sends no mail; invitation retry returns through explicit confirmation and fresh check.
Bounded readable per-person outcomes: already accepted/current member/suspended/terminated/provider verification failed/invitation unavailable/permission revoked. No raw exceptions, IDs, tokens or payloads. Unknown delivery is not delivered. Counts/outcomes may be projected from existing audit/job evidence; this design does not require a separate send-run subsystem beyond the approved architecture.

## Detail and import
High-priority Invitation and access description grid contains provider state/send/accept/expiry/revocation/verification timestamps, first/last Oceanix access, separate Last WorkOS sign-in with explanation that it does not prove access to this company. Keep organizational/access-profile/employment/training sections. Import opt-in invitations and preview remain; imported people invited until first access; import success distinct from queue outcome; company-wide post-import recovery scope explicit.

## Responsive, accessibility and states
Base one-column filters/actions; sm two columns; lg/xl wider grids. Wrap actions/long names/emails; table scroll stays inside container. Identity/states/counts remain reachable on mobile. Visible labels, native keyboard controls, focus ring, real headers, one h1. Selection/status never color-only. Modal manages focus, Cancel/Escape and focus restoration.
Polite live region for progress; alert for errors; do not repeatedly announce table. Preserve data during targeted loading; initiating/overlapping controls disabled readably. Empty company uses existing primitive and authorized Import; filtered-empty says No people match these filters with Clear filters. Inline selection errors. Unauthorized controls omitted; revoked action/job produces honest denial. No new destructive controls or implicit reactivation.
