# Account-owned invitations — discovery (not an approved execution contract)

## Confirmed user requirements

- Initial WorkOS import is a one-time Account operation. Existing WorkOS people are already in Compliance; do not call Compliance during that import or require its new version, token, endpoint or receipt.
- Import people and existing invitations without sending email or changing existing Compliance identities, roles, access or operational evidence. Record Compliance association as imported baseline, not a fabricated remote receipt or local-person-ID mapping.
- Recurring synchronization reconciles invitation status only. It never downloads arbitrary new users, deletes people or changes access.
- Every invitation synchronization initiated in Compliance must call Account; Account owns WorkOS invitation communication. The same reconciliation must be available in Account.
- System credentials must not require per-company UUID allowlists. Keep service lifecycle/environment, actual company identity/binding and initiating actor authorization.
- Previous accepted rule: all recognized invitation states (pending, accepted, expired, revoked) accepted independently of accepted_user_id; membership required before access.

## Observed implementation (evidence, not product requirements)

Account ImportWorkosPeople calls CompliancePeopleMigrationClient during preview and apply and only finalizes links after remote receipt. This is the incorrect initial-import dependency. WorkosPeopleImportItem stores durable remote operation inputs; an upgrade must distinguish already confirmed, unknown/dispatched and new local-import work without replaying remote mutations or inventing receipts.

Compliance ReconcileWorkosInvitations::refresh routes linked people to Account but falls back to direct WorkOS for unlinked people. SendWorkosInvitation has corresponding linked versus direct branches. Existing adoption machinery creates PersonAccountLink/ProductPersonLink through Account, but must reconcile records imported into Account by exact WorkOS identity and organization rather than duplicate them or overwrite access.

Account has two different synchronization paths: SyncCompanyInvitations for Account UI and SendCompanyInvitation person.invitation.reconcile for product-origin operations. They have different candidate selection and authorization/precondition behavior. UI synchronization only considers known invitation IDs; compatibility of people without current IDs must be specified. SyncCompanyInvitations still couples accepted_user_id to accepted state, despite the separate import fix.

Both products retain service-principal company UUID restrictions. Changing to service-wide authentication was agreed conceptually but has not been implemented; this must be an explicit prerequisite/scope item rather than assuming credentials already work.

## Architecture decision pending

User mentioned event communication. Decide whether to retain existing HTTP/jobs/durable operation receipts now or introduce a broker/event delivery model now. No event technology, ordering, delivery or operational assumptions are approved.

Suggested low-complexity path for approval: retain HTTP plus existing bounded jobs/receipts/history; local-only import independent of integration setup; establish exact identity mappings on explicit later adoption/reconciliation; use Account as single invitation owner from either UI; failures visible/retryable without duplicate email. Account-origin changes must be observable in Compliance through a defined pull/response path (or events only if separately chosen).

## Required decisions before execution

- Spec Kit flow or explicit waiver (repository policy requires one for cross-system/security work).
- Approve concrete architecture and frozen contract after authoring, including migration/recovery for existing import records and unlinked Compliance people.
- Finalize propagation semantics for Account-origin synchronization to the Compliance display; no direct fallback to WorkOS.

No production/test/environment/database changes or provider calls made for this task.
