# Route Contract

## Public and authentication

- `GET /login` — company selector.
- `GET /login/{company:slug}` — company-specific WorkOS entry.
- WorkOS redirect and callback preserve the selected company in session.

## Tenant workspace

Every operational route is under `/c/{company:slug}` and requires the matching active tenant plus a linked active person.

- dashboard and personal training
- courses, requirements, assignments and certificates
- people, departments and job functions
- settings, audit log and access profiles

The former tenant `/admin/users` route is removed.

## Platform control plane

- `GET /platform` — aggregate dashboard.
- `GET /platform/companies` — company directory and creation.
- `GET /platform/companies/{company}` — company detail and lifecycle controls.
- `POST /switch-company/{targetCompany:slug}` — switch to another active person linked to the same account and audit the context change.

Every platform route requires global platform-administrator capability.

## Compatibility

Old unscoped authenticated URLs redirect to the company-scoped equivalent when context exists; they never render tenant data directly.
