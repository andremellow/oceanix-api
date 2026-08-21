# Research: Platform Administration and Tenant Identity

## Global identity boundary

- **Decision**: Add a global Account and retain tenant User as the operational Person model.
- **Rationale**: A WorkOS user is global and may belong to multiple organizations, while training evidence, permissions and employment state are company-specific.
- **Alternatives considered**: Rename every User and foreign key to Person (high-risk churn); keep duplicate WorkOS identities per tenant (cannot represent one AuthKit identity cleanly).

## Platform privilege

- **Decision**: Store platform-administrator capability on Account and enforce it with dedicated middleware, never a tenant Role.
- **Rationale**: Tenant admins must be structurally unable to grant global access.
- **Alternatives considered**: A protected tenant role; a separate password guard.

## Cross-company workspace entry

- **Decision**: A tenant workspace requires a linked active person; accounts with several links switch explicitly and the change is audited.
- **Rationale**: This preserves tenant policy semantics and never manufactures workforce records.
- **Alternatives considered**: Auto-creating people; an unrestricted hidden bypass.

## Tenant URLs

- **Decision**: Prefix operational routes with `/c/{company:slug}` and provide URL defaults from tenant context.
- **Rationale**: The active tenant is visible, bookmarkable and testable.
- **Alternatives considered**: Session-only context; bare slug prefix with public-route collisions.

## Existing Users screen

- **Decision**: Remove duplicate Users navigation and manage roles/linkage on person detail.
- **Rationale**: Both current screens query the same table.
- **Alternatives considered**: Rename the duplicate screen to Access Management.

## WorkOS organization boundary

- **Decision**: Add company public UUID and nullable WorkOS organization ID now; external provisioning and invitations follow later.
- **Rationale**: Correct identity boundaries precede external side effects.
- **Alternatives considered**: Block local company creation on WorkOS availability.
