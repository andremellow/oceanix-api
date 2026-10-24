# Existing UI constraints — approval proposal

Reference: `docs/control-center-design-system.md` in each repository. This is not a new visual design or an approval of rendered implementation. Only affected existing company-user, person invitation and Communication controls participate. Future implementation requires real browser evidence for their accepted states.

- Reuse existing detail cards, status pills, buttons, table patterns, tokens, role-aware navigation and responsive composition. No new dashboard, tab hierarchy or global redesign.
- Distinguish declared Compliance association (imported baseline) from product access and from verified communication outcome. Show a neutral, human-readable imported/unverified indication; never mark a product enabled merely because migration asserted it exists.
- Show sync lifecycle queued/running/completed/partial/needs-attention with per-person details. Successful WorkOS observation and unsuccessful delivery to Compliance are distinct visible outcomes. Preserve last verified invitation information with age/error feedback, not a blank replacement.
- Sync action must communicate that it checks invitation status without sending email. Send/resend is a separate explicit authorized action. Account-origin and Compliance-origin operations use the same behavior and backend authority.
- Empty state distinguishes no people, no invitation, no product association and unavailable integration; network errors are never represented as empty success. Unlinked identity conflicts give an actionable linking error, not a suggestion to create duplicate users.
- Disable repeated conflicting actions while queued/running; keep progress perceivable with status announcements and focus retention. Keyboard access, labels, contrast, narrow layouts and permission-hidden controls follow the existing system. Server authorization remains independent of visibility.
- Retry controls appear for eligible failed/exhausted targets in person and Communication views; show safe reason, current state and scheduled attempt where applicable. Retry requires no queue restart and does not resend invitation for read-only sync/projection failures.
- No raw JSON, tokens, service principals, database IDs, internal capability keys or transport implementation details in end-user screens. Diagnostic logs may retain bounded safe identifiers per existing policy.
- English source strings with Portuguese translations; no localized source keys.

Planned browser scenario evidence: SC-07/08/12/15/18/23. Loading, empty, error, partial, permission and retry states are mandatory in those paths; no UI checks are claimed executed in this preparation turn.
