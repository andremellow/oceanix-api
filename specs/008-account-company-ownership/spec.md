# Feature Specification: Account-owned company creation

**Feature Branch**: `codex/account-company-ownership`
**Created**: 2026-09-29
**Status**: Approved by owner on 2026-09-29; see approval-record.md
**Input**: Companies are created/imported in Account. Enabling Compliance creates or links the company in Compliance. Remove independent Compliance company creation and WorkOS organization provisioning. Strictly exclude other findings.

## User Scenarios & Testing

### User Story 1 — One place to create companies (P1)
An authorized operator creates or imports the company in Account. Compliance does not offer or accept its old independent creation/provisioning operations.
**Independent test**: inspect both Compliance company screens; call the removed actions directly and invoke the legacy command; verify no company, user, grant or provider mutation.
**Acceptance scenarios**: SC-01, SC-02, SC-03, SC-04.

### User Story 2 — Enable the product (P1)
The Account operator enables Compliance. A new company is created in Compliance or an existing company with the same WorkOS identity is linked while its records stay intact.
**Independent test**: use the existing authenticated provisioning boundary with a new identity, an existing identity and a retry.
**Acceptance scenarios**: SC-05, SC-06, SC-07, SC-08.

### Edge cases
An old browser can submit a previously available Livewire method. A local operator can invoke the old command. A company may already exist with operational records. Same slug with a different identity is not proof of sameness. An enable request can time out and be retried. These cases retain explicit server-side denial or existing idempotent/conflict behavior; no automatic repair or bulk migration.

## Requirements

### Functional requirements
- FR-001: Account is the sole supported human entry point for company creation/import and WorkOS organization provisioning; preserve its existing behavior.
- FR-002: Remove independent creation and WorkOS organization provisioning/synchronization controls and callable Livewire handlers from Compliance company list and detail screens. The obsolete local company-creation command must no longer create a company; return a clear instruction to use Account. Delete unused creation/provisioning implementation rather than retaining callable alternate entry points.
- FR-003: Retain the existing authenticated Account-to-Compliance enable flow: provision a new tenant or bind an existing tenant by matching WorkOS organization identity, with no automatic user/role assignments to humans.
- FR-004: Preserve existing conflict, retry, authorization and confirmation rules in that enable flow; never deduplicate by name or slug alone.
- FR-005: Keep company listing, inspection and entry available under existing access controls. Explain briefly that creation/activation belongs in Account using existing UI patterns, without new configuration or navigation dependencies.
- FR-006: Limit work and findings to these changed entry points and their direct integration/regression surfaces. Do not inspect or fix unrelated findings.

### Non-goals
User creation/invitations/permissions; company operational suspend/reactivate; disable/re-enable behavior; WorkOS synchronization badge accuracy; identity reconciliation/backfills; local data repair; changing names/slugs across systems; migrations; environment/Herd changes; new products; refactors or governance/tool changes; automatic WorkOS calls from tests. Existing badge text is intentionally left unchanged in this feature.

### Key entities
Company in Account, WorkOS organization, Compliance Company, AccountCompanyBinding and provisioning receipt retain their present identities and meaning. No schema or identity change.

## Success Criteria
- AC-01: Neither Compliance company screen exposes independent create/provision/synchronize actions; remaining inspection and entry remain usable on desktop/mobile.
- AC-02: Stale/direct requests and the legacy command cannot create companies or mutate WorkOS organizations, even for a platform administrator.
- AC-03: Account creation/import and enabling Compliance continue to produce one correctly bound Compliance company; an existing matching company retains identifiers and operational data.
- AC-04: Retry, conflict and unauthorized provisioning behavior remain unchanged, with no duplicate company or human access grants.
- AC-05: Only the frozen files/behaviors change; no fixes for unrelated findings.

## Invariants
- INV-01: Preserve existing company/user/evidence records and identifiers. No data migration or live-data mutation.
- INV-02: Keep independent databases and the existing authenticated synchronous provisioning contract; no WorkOS organization writes originate in Compliance after removing the obsolete flow.
- INV-03: User management, product disabling and local operational restrictions remain unchanged.
- INV-04: No provider mutation or live Herd data in tests/QA; use isolated fixtures and fake provider responses.

## Assumptions and decisions
The user confirmed the ownership boundary and explicitly excluded other findings. Removing the CLI bypass is a direct consequence of FR-001, not a new workflow. Existing legacy tenants link only by confirmed matching WorkOS identity; no heuristic reconciliation. No new Account implementation is expected because the required flow is present. If evidence shows an in-scope missing behavior requiring new production files, amend the contract before editing them.

Consolidated specification/architecture/test matrix approved by the owner on 2026-09-29; see approval-record.md. No unresolved product alternatives within the approved scope.
