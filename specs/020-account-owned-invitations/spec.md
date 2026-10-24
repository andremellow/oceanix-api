# Feature Specification: Account-owned invitations

**Created**: 2026-10-20  
**Status**: Draft for product-owner approval; no implementation authorized by this artifact  
**Feature directory**: `specs/020-account-owned-invitations` (continues existing discovery)  
**Input**: One-time WorkOS import must not contact Compliance. Compliance uses WorkOS only for sign-in. All operational person/invitation work goes through Account. Retain HTTP and queues; no event infrastructure. Prepare specification and architecture for approval.

## User Scenarios & Testing

### User Story 1 — Import the existing population independently (P1)
A holding superadmin imports known WorkOS organizations' people and invitations into Account while Compliance remains on its older release or offline. All this legacy population is declared by the owner to already exist in Compliance.

**Independent test**: run preview/apply against a representative provider fixture with no configured product connection. Preview writes nothing; apply creates only Account records and declared product associations. No invitation email or Compliance request occurs. See SC-01–SC-06.

### User Story 2 — Synchronize invitations from either product (P1)
An authorized operator can request invitation status from Account or Compliance. Account alone reads WorkOS and records the result. Existing Compliance people are updated through their exact company/identity mapping without changing operational access or historical training data.

**Independent test**: initiate both origins, including an existing person without an integration link; observe the same latest invitation result, or an explicit recoverable mapping error, with no direct provider call from Compliance. See SC-07–SC-15.

### User Story 3 — Recover without duplicate invitations (P1)
An operator sees queued, running, completed, partial and failed communication, can identify the person affected and retry exhausted work. Status-only reconciliation never sends email, even after an ambiguous response.

**Independent test**: lose a response after a committed operation, restart the worker and retry; no duplicate external effect, bounded automatic attempts, manual recovery visible. See SC-16–SC-19.

### User Story 4 — Connect systems independently of company lists (P1)
A service credential authenticates the product pair; adding a company does not require adding its UUID to token configuration. Each operation still validates the real company relationship and current human authority.

**Independent test**: provision a new company using an authorized system identity without an allowlist, then reject foreign/mismatched identities and unauthorized actors. See SC-20–SC-23.

### Edge cases
All recognized provider invitation states with or without accepted identity; inactive or absent membership; pagination/duplicates; incompatible old product release; repeated import after partial work; unlinked/duplicate/conflicting legacy identities; failed dispatch after commit; out-of-order observations; user permission revoked while queued; deleted/expired provider invitation; old queue payload after deployment; empty company; no existing invitation; valid service with unrelated company payload.

## Requirements

### Confirmed product decisions
- **D-01**: Owner explicitly confirmed initial import is a one-time migration; WorkOS population already exists in Compliance. It must not contact Compliance.
- **D-02**: Owner confirmed Compliance communicates directly with WorkOS only for login. Operational user and invitation work belongs to Account, callable from both UIs.
- **D-03**: Owner selected HTTP with queues, explicitly excluding an event system now.
- **D-04**: Owner rejected company UUID allowlists on system credentials. Company identity and human permission remain separate checks.
- **D-05**: Preserve existing Compliance people, access, permissions and operational history; local IDs may differ and shared WorkOS identity remains stable.

### Functional requirements
- **FR-001**: Initial import accepts one Account company UUID or all locally registered companies; only holding superadmin may run it. Default is a read-only preview; apply is explicit.
- **FR-002**: Import reads provider people/memberships and invitations with complete pagination and local organization validation; it makes zero product requests and zero provider mutations. It works with no product token, old/offline Compliance and no product endpoint.
- **FR-003**: Record the declared Compliance association with migration provenance. Do not invent a Compliance person ID, remote receipt or observation of remote access. Association and verified remote access must be distinguishable in the product projection.
- **FR-004**: Import all recognized states pending/accepted/expired/revoked independently of accepted_user_id. A provider identity alone or invitation alone never grants company access; active exact-company membership is required. Preserve explicit existing denials and product/person history. Unknown states, invalid IDs and contradictory identity/organization evidence produce visible conflict, not guessed associations.
- **FR-005**: Import is restartable/idempotent despite intended one-time use. Preserve already imported data and old durable import evidence. Existing pending/unknown/dispatched legacy operations must not trigger old product calls under the new command. No automatic rollback, deletion or fabricated success for uncertain old remote effects.
- **FR-006**: Account owns invitation send/resend and status reconciliation. Compliance operational code must never fall back to direct WorkOS, including unlinked legacy people, queue retries, import/adoption and user registration. Existing AuthKit sign-in/token refresh remains direct and functioning.
- **FR-007**: Status synchronization is read-only with respect to provider users/invitations and operational access. It updates invitation status/timestamps/history and its verification/error metadata; membership evidence may be shown but must not itself enable/disable people or products. It never discovers/downloads arbitrary new people, deletes people or sends email.
- **FR-008**: Both Account and Compliance can request reconciliation with their existing atomic permissions; grants, prerequisites, direct access, administrator behavior, revocation and tenant isolation are enforced server-side at dispatch and execution. Normal user permissions do not become global because the integration token is system-wide.
- **FR-009**: Exact company binding plus stable WorkOS user ID (or exact invitation ID for invitation-only people) establishes existing-person mappings. Local person IDs may differ. Never join by email alone, create a duplicate Compliance user to make sync work, or silently overwrite conflicting mappings. Unlinked people must have a defined explicit mapping/recovery path before sync, with actionable conflict feedback.
- **FR-010**: Successful Account-origin synchronization must become visible in linked Compliance invitation data through durable HTTP communication. Compliance-origin synchronization also updates Account and eventually the existing Compliance person. Preserve the last verified result on transport failure and expose synchronization age/status. Final contract proposal defines the exact delivery protocol.
- **FR-011**: Use existing durable operations, HTTP, queues and visible communication history. Automatic retries are bounded; exhaustion is actionable with per-person errors and manual retry. Reuse operation identities on ambiguous outcomes; retry must not send duplicate email or repeat an uncertain mutation blindly.
- **FR-012**: Deduplicate concurrent requests; older results cannot overwrite newer invitation generations or more recent verified history. Recheck current invitation identity/company and human authorization before applying/dispatching queued work.
- **FR-013**: Service credentials are independent of company UUID allowlists. Keep authentication, active/expiry/environment/product checks, request integrity, company binding and actor authorization. Preserve operation capability requirements where currently required in Compliance→Account; do not reintroduce per-capability checks on Account→Compliance.
- **FR-014**: Keep all current user-facing flows but make progress and failures honest. Synchronization shows empty/queued/running/partial/completed/needs-attention states, preserves disabled/loading controls and accessible feedback; UI must not label declared association as remotely confirmed access. Reuse existing screens and design system; no new dashboard.
- **FR-015**: Provide versioned deployment/setup/runbook instructions: local-only import can run first; enable centralized operational flows only when both new versions and credentials are ready; no secret values in artifacts/logs. Queued legacy work must not resume direct provider operations after cutover.

### Key entities
Provider identity and membership; Account shared person/company member; invitation observation/history and generation; declared legacy Compliance association; exact product person mapping; durable integration operation/receipt and delivery target; service principal; company binding; Compliance existing person and operational evidence.

## Success Criteria
- **OUT-01**: Initial preview/apply issues zero Compliance calls and no invitation sends for every SC-01–SC-06 fixture.
- **OUT-02**: Every operational invitation provider call in the accepted flows originates in Account; Compliance sign-in still succeeds in SC-23.
- **OUT-03**: Both origins converge on the latest accepted observation without duplicate people or changed access/history in SC-07–SC-19.
- **OUT-04**: Failed/exhausted work is visible and recoverable for each affected person; no unbounded automatic work or false confirmation.
- **OUT-05**: New company operations require no credential UUID edits while invalid actor/company requests remain rejected.

## Proposals for this approval (not previously accepted facts)
- **P-01**: Distinguish imported association from verified product access instead of inventing remote confirmation. Existing explicit enable/disable evidence wins over the legacy baseline. Implementation representation is the Architect's responsibility.
- **P-02**: Resolve unlinked existing people via an explicit authenticated mapping flow before centralized operations; no implicit direct WorkOS fallback. Mapping never creates a Compliance person and only updates integration identifiers.
- **P-03**: Deliver Account-origin invitation observations through a queued, idempotent HTTP snapshot to the existing Compliance person; no broker/pubsub/webhook from WorkOS. Failed projection delivery is a separate visible target from successful WorkOS observation. Architect may select an equivalent HTTP pull protocol only by documenting the approval choice.
- **P-04**: Pending legacy import operations remain historical recovery evidence; the new command does not automatically dispatch them. Operator-visible remediation is required rather than rewriting remote outcomes.

## Assumptions, boundaries and dependencies
Account remains the organization/person control plane; Compliance owns training evidence and local permissions. Only the owner's current legacy WorkOS population is asserted to exist in Compliance; future people still use normal registration. System token setup and company binding are prerequisites for later live operational flows, never for local-only import. No deployment, production mutation, event infrastructure, SDK/package extraction, unrelated UI redesign or historical repair is authorized by preparation of these documents.

Spec, scenarios, architecture and execution contracts require explicit owner approval before implementation. Tests and runtime QA described below are planned, not executed evidence. Unknown production data shape cannot be inferred from local fixtures.
