# Feature Specification: WorkOS invitation reconciliation

**Created**: 2026-10-01
**Base**: origin/main, 2370b33
**Status**: Draft for product-owner approval
**Input**: Synchronize invitation status from WorkOS, filter unaccepted invitations and resend; people become active only after their first Oceanix access; any person may receive course assignments.

## User Scenarios & Testing

### User Story 1 - Reliable invitation inventory (P1)
An authorized operator synchronizes the current company's people with WorkOS and sees the current invitation state, synchronization outcome and access evidence separately.
**Independent test**: synchronize seeded people with pending, accepted, expired, revoked, missing and contradictory provider data; inspect persistence and table labels. See scenarios.md SC-001–SC-008.

### User Story 2 - Recover outstanding invitations (P1)
An authorized operator filters unaccepted invitations and sends/reissues invitations to selected eligible people or all eligible pending people, without emailing accepted recipients or suspended/terminated people.
**Independent test**: verify recipients, API operation and audit evidence for each state, partial errors and retries. SC-009–SC-012.

### User Story 3 - First access and assignment independence (P1)
New people are invited until their first successful tenant access. Login eligibility and assignment eligibility are separate. All people may receive manual and requirement-derived assignments regardless of user status.
**Independent test**: successful and rejected callbacks, tenant/platform entry, all four status values in assignment paths and unchanged historical assignments. SC-013–SC-018.

### Edge Cases
An accepted invitation does not prove Oceanix access. WorkOS user login can belong to another organization. Missing/deleted provider invitations are unknown, not pending. Never resolve an invitation to a different local person merely because WorkOS allows corporate-domain acceptance. Provider failures preserve previously verified information and display errors. Concurrent login/sync must not downgrade newly activated people. A changed invitation ID invalidates an in-flight old snapshot. Sync failures must not make stale data look freshly verified. Repeated clicks and queued retries must not silently duplicate sends.

## Requirements

### Functional Requirements
- **FR-001**: Invitation state is independent of local person status and local access evidence. Supported provider states: pending, accepted, expired and revoked; local display states also include not invited and unverified/unavailable.
- **FR-002**: A manual synchronize action processes the current company only, including pagination, and shows queued/running/completed/partial-failure/failed outcomes and last successful sync time. Synchronization never sends emails.
- **FR-003**: Match by stored invitation ID with email and organization validation; where no valid stored ID exists, discover company invitations by exact normalized email. Select the current invitation deterministically, retaining accepted history and distinguishing stale invitations from current membership. Unknown or mismatched data never authorizes access or triggers a resend automatically.
- **FR-004**: Show distinct person status, invitation state and access evidence. Record local first/last access from the server at successful tenant access. WorkOS last_sign_in_at is separate external evidence and does not invent a local login date.
- **FR-005**: New people, including imported people and tenant administrator grants, start invited until successful tenant access. A valid callback may activate invited people; suspended/terminated people and inactive accounts remain blocked. Platform-created access and tenant switching must use the explicit access boundary instead of training eligibility.
- **FR-006**: Legacy active people have no reliable first-access timestamp. Proposed transition: reclassify them as invited until their next successful Oceanix tenant access; never fabricate historical login dates. Preserve suspension/termination, roles, assignments and evidence. This migration behavior is explicitly included in owner approval; existing WorkOS identity links are not proof of tenant access.
- **FR-007**: Provide invitation-state and no-local-access filters, combine them with existing search/department/function/person-status filters, and keep pagination/counts consistent. Rename ambiguous Open/Overdue headings to Open training/Overdue training with PT-BR translations.
- **FR-008**: Send or resend only after an explicit operator action, with existing PeopleInvite authorization. Eligible recipients: invited/active people with an unaccepted current invitation or not yet invited, subject to a fresh provider check at execution. Accepted recipients and suspended/terminated people are skipped with a visible reason. Provider-unverified recipients require a successful check before sending.
- **FR-009**: Pending invitations use resend; expired invitations use creation/reissue because the documented resend endpoint requires pending state. A revoked invitation is shown and may be explicitly selected for a new invitation, but is excluded from the default all-pending action. Accepted/current members are not automatically emailed because an old invitation expired.
- **FR-010**: Add atomic PeopleSyncWorkos permission with PeopleView prerequisite; enforce Gate, record scope/Policy, route middleware where a new route exists, hydration/action checks and queued initiation authorization. Cover grants, denial, prerequisites, admin bypass, revocation and tenant isolation.
- **FR-011**: Synchronization and send jobs carry explicit company and actor IDs, restore tenant context after execution, avoid overlapping jobs, guard snapshot application against newer invitation/login writes and audit outcomes without secrets or invitation tokens.
- **FR-012**: Every person status may receive manual and automatic course assignments. Preserve target matching, effective dates, active/published course constraints, requirement lifecycle, recurrence windows, idempotency and frozen versions. Existing assignments are not cancelled or recreated by status reconciliation. Access remains denied for suspended/terminated people.

### Key Entities
- Person: company-specific status and local access evidence.
- Invitation snapshot: provider identity, state, acceptance/expiry/revocation and verification time.
- Sync run: company/initiator, lifecycle and outcome counts.
- Assignment: immutable-version obligation, independent of first access/person status.

## Success Criteria
- **SUCCESS-001**: Every supported invitation state has an accurate, distinct visible label and filter in the frozen scenarios.
- **SUCCESS-002**: The selected/default recovery actions send only to their intended recipients in all frozen scenarios.
- **SUCCESS-003**: Invited people can complete authorized first access; suspended/terminated people cannot.
- **SUCCESS-004**: All four person statuses can receive both manual and automatic assignments with no changes to existing obligation history.

## Decisions and assumptions
Confirmed by user: synchronize and recover invitations; invited before first access; active after first access; any person may receive a course assignment; preserve suspended/terminated access restrictions.
Proposals awaiting artifact approval: legacy reclassification (FR-006), explicit-only revoked recovery (FR-009), current-company batch synchronization and distinct local versus WorkOS access evidence.
No production synchronization, deployment or bulk email is executed as part of local development. The shipped UI enables these operator actions.
No material question beyond approval of the explicit transition/recovery rules above is known. Existing code is evidence, not authority for the new assignment rule.
