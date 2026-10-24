# Shared Oceanix platform package scope

Version: 5. Date: 2026-10-21. Status: owner-confirmed feature scope; required core implemented and under final validation. This confirms the feature inventory, not a package architecture or completed behavior.

## Intended outcome

A new Laravel product can install the package, configure WorkOS and Account, provision a company, authenticate a provisioned person, register and invite people through Account, and administer product access and local access profiles before adding product features.

The package owns maintained shared behavior. The consuming application owns its domain, permission catalog and optional presentation extensions. The SDK supplies the shared screens and registers their routes; a new consumer only needs configuration and menu links. No boilerplate is created in this stage.

## Required modules

| ID | Module | Included capabilities |
|---|---|---|
| PKG-01 | WorkOS authentication and sessions | AuthKit redirect/callback; session-bound signed OAuth state; identity resolution and linking; verified identity and active-status checks; company-context login; session regeneration; logout; failures; access recording; configurable route destinations. |
| PKG-02 | Outbound Account integration | Configurable product identity; authenticated HTTP transport; authenticated human actor assertions; person registration and local mapping acknowledgment; operation submission/status lookup; strict business receipt validation; timeouts and uncertain outcomes; stable idempotency; durable recovery history. |
| PKG-03 | Inbound Account operations | Company provisioning and identity binding; company-product enable/block; supported person/access operations; operation status and receipts; actor authorization; service-principal product/environment/expiry/status validation; duplicate, conflict and sequence handling. Preserve existing protocol compatibility. |
| PKG-04 | Company context and isolation | Company resolution, request-scoped context, session/URL integration, authorized company switching, tenant-scoped queries and writes, no-context isolation, explicit context restoration/cleanup for background work. Company resolution and access authorization remain separate. |
| PKG-05 | Product access enforcement | Independent checks for active company, enabled company-product access, company membership, active person and person-product access; enforcement on existing sessions, direct requests and Livewire actions. Local administration must not automatically override product blocks. |
| PKG-06 | People management | Shared application services for list/search/detail, Account-backed registration, identity mapping/status, invitation/access state, supported Account access operations, local profile assignment/removal and server-side authorization. Extension points for host fields, filters and detail projections; no whole-model extraction of Compliance User. |
| PKG-07 | Invitations | Account-backed single/bulk invitation and resend, queue execution, per-person progress/failures, status reconciliation, explicit recovery/retry, generation/sequence conflict protection, accepted-history preservation. Invitation acceptance does not independently grant product access. |
| PKG-08 | Access profiles and permission engine | Profiles, grants, user-profile assignment, effective permissions, prerequisites, protected/archived profile handling, delegated assignment restrictions, revocation, permission middleware and Laravel authorization integration. Consumer-supplied extensible catalogs. |
| PKG-09 | Gates and Policies integration | Register permission Gates from catalogs; shared Policies/authorization for shared people/profile actions; configurable administrative behavior. Product Gates, resource Policies and bypass exceptions remain with the product. No unconditional universal admin bypass. |
| PKG-10 | Audit and runtime support | Actor/company/product/operation audit context; durable mappings/operations/receipts; queues and recovery commands; package configuration/migrations; package-message translations; reusable isolation/protocol/authorization tests; nonsecret installation diagnostics. |

## Optional modules, not mandatory core

- Separate platform-administrator login/session.
- Global company administration and authorized company entry.
- User impersonation with authorization and audit.
- Existing-install adoption/migration tools. Compliance requires a safe migration path; new products must not require legacy adoption.

These modules need an explicit inclusion decision before implementation; optional does not mean silently included or discarded.

## Presentation boundary

People and profile-management capabilities belong to the scope. Extract services, Actions, contracts, data projections and functional screens while preserving the current visual treatment; this does not adopt the Compliance design system as the future shared standard. Existing Compliance screens remain operational as the initial consumer. Following the owner's clarification, shared login, people and access-profile screens, their components and routes are included in the SDK. Product-specific sections remain optional extensions. Existing themes/layouts are preserved; a minimal package fallback shell supports a new consumer. Design tokens, a new design system, navigation composition and boilerplate remain deferred. Do not redesign or restyle current screens as part of this extraction.

## Consumer responsibilities

Each system defines its product key, domain models and relationships, feature permission catalog, domain Gates/Policies, administrative exceptions, extra people fields and domain projections. Integrate shared behavior through configurable bindings/adapters rather than imports from Compliance's App namespace. The local identity model named Account is distinct from the central Account service.

## Exclusions

No boilerplate/starter project, a new design system, redesign of existing layouts/navigation, visual redesign, courses, lessons, assessments, certificates, requirements, assignments, compliance events, playback, Cloudflare, training reports/notifications, Compliance spreadsheet import, or Tasks integration. Employee ID, departments, job functions and management hierarchy remain product extensions initially. No central Account changes, package publishing or deployment are implied by this documentation scope.

## Resolved implementation boundaries and future work

The local topology, dependency constraints, host contracts, compatible persistence and installation/upgrade procedures are implemented in the SDK README. The existing User is integrated by contract and composition. Product IDs, Account-supported operations and legacy protocol/operation identities remain configurable without changing Account. External repository/distribution/publication and optional platform modules remain separate future work.

## Single-package decision

The owner approved a single Composer package with internal modules. The initial local package is `oceanix/platform` at `../support/sdk`; WorkOS authentication primitives are the first extraction increment. Account, tenancy, people and authorization remain in the same package as they are extracted. No SDK split, boilerplate or design-system/layout changes. External repository and publication remain separate decisions.

## Implementation result

PKG-01 through PKG-10 are implemented in the single SDK. See extraction-status.md and the SDK README for adapters, installation and validation. Optional global platform administration/impersonation are not mandatory-core targets; boilerplate and visual redesign remain excluded; functional reusable screens and routes are included. The existing User uses contract/composition to retain domain relationships.
