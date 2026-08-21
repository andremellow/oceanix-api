# Data Model: Platform Administration and Tenant Identity

## Account (global)

- Identity fields: name, normalized globally unique email, provider identity, WorkOS user ID and avatar.
- Authorization fields: platform-administrator flag and active/suspended status.
- Relationships: has many tenant Users (product term: People).

## Company

- Existing name, slug and lifecycle status.
- Immutable globally unique public UUID used as an external identifier.
- Nullable unique WorkOS organization ID.

## User (product term: Person)

- Retains existing company-owned workforce fields, roles and evidence relationships.
- Adds nullable Account linkage.
- Email remains unique per company.

## State rules

- An Account links to at most one User per Company; a User links to at most one Account.
- Suspended Account cannot authenticate anywhere.
- Suspended or terminated User cannot enter its company but keeps historical evidence.
- Suspended Company blocks tenant entry, including existing sessions.
- Platform administration never creates a User implicitly.
