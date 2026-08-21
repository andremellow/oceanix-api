# Feature Specification: Platform Administration and Tenant Identity

**Feature Branch**: `main`

**Created**: 2026-08-21

**Status**: Approved

**Input**: Separate global authenticated accounts from company people, add a platform-administration area, make company context explicit in URLs and headers, and preserve company-specific access and training data.

## User Scenarios & Testing

### User Story 1 - Work inside an explicit company (Priority: P1)

An authenticated person enters a company-scoped workspace whose URL and header always identify the active company. All operational data and permissions remain isolated to that company.

**Why this priority**: Explicit tenant context is the safety boundary for every operational action.

**Independent Test**: A user linked to one company can navigate its workspace, sees its name in the URL and shell, and receives a denial for another company.

**Acceptance Scenarios**:

1. **Given** an account linked to Oceanix Demo, **When** it opens that company, **Then** the URL contains the company slug and the header identifies Oceanix Demo.
2. **Given** an account without membership in another company, **When** it requests that company's URL, **Then** access is denied without exposing company data.
3. **Given** one identity linked to people in two companies, **When** it switches company, **Then** each workspace uses the correct person, permissions and records.

---

### User Story 2 - Administer the platform globally (Priority: P1)

A platform administrator uses a distinct platform area to see company-level metrics and create and manage companies without receiving a tenant-grantable global role.

**Why this priority**: Company creation and cross-company oversight cannot safely belong to any tenant administrator.

**Independent Test**: A platform administrator can open the platform dashboard and company directory; a company administrator cannot.

**Acceptance Scenarios**:

1. **Given** a platform administrator, **When** they visit the platform area, **Then** they see aggregate company counts and company management actions.
2. **Given** a company administrator, **When** they request a platform route directly, **Then** access is denied.
3. **Given** an account linked to people in multiple companies, **When** it switches company, **Then** the context change is explicit and audited.

---

### User Story 3 - Distinguish identity from workforce records (Priority: P1)

The system represents a WorkOS-authenticated identity once and links it to a separate person record in each company. People may exist before they can sign in.

**Why this priority**: Invitations, multiple-company access and compliance evidence require company-specific people without duplicating global identity.

**Independent Test**: One account can link to two different company people with distinct IDs, roles and training histories.

**Acceptance Scenarios**:

1. **Given** an imported person without an account, **When** directories are viewed, **Then** the person remains operationally available but is shown as not linked for sign-in.
2. **Given** a verified WorkOS callback matching a pre-existing person, **When** authentication completes, **Then** a global account is linked to that company person rather than creating a new person.
3. **Given** no matching person in the selected company, **When** authentication completes, **Then** access is refused and no person is created.

---

### User Story 4 - Manage company access without a duplicate user directory (Priority: P2)

A company administrator manages identity linkage and access profiles from the person's detail while the People directory remains the canonical company workforce list.

**Why this priority**: Operators should not have two directories containing the same records with unclear ownership.

**Independent Test**: The company admin can assign access profiles from a person record and no separate ambiguous Users directory remains.

**Acceptance Scenarios**:

1. **Given** a company administrator viewing a person, **When** they change access profiles, **Then** only that company's grants change and the action is audited.
2. **Given** a training operator without access-management authority, **When** they view the person, **Then** access controls are unavailable and direct writes are denied.

### Edge Cases

- A global account is suspended while its company person records retain compliance history.
- A person is terminated in one company but remains active in another.
- A company slug changes without making old tenant URLs silently resolve to a different company.
- A platform administrator has no person record in a company.
- A callback carries a WorkOS identity already linked to a different person in the same company.
- A company is suspended while authenticated sessions still exist.

## Requirements

### Functional Requirements

- **FR-001**: The system MUST maintain one global authenticated account per verified WorkOS identity.
- **FR-002**: The system MUST maintain separate company-owned person records for workforce, organization and compliance data.
- **FR-003**: A person MUST be linkable to at most one global account, while one account MAY link to people in multiple companies.
- **FR-004**: Authentication MUST link only to a pre-existing person with an exact normalized email in the selected company; it MUST NOT create people during login.
- **FR-005**: Every tenant operational URL MUST carry an explicit company slug and every authenticated tenant screen MUST visibly identify the company.
- **FR-006**: Tenant access MUST require an active linked person with appropriate company permissions.
- **FR-007**: Platform administration MUST use a separate `/platform` area protected by a global capability that tenant administrators cannot grant.
- **FR-008**: Platform administrators MUST be able to list, create, view, activate and suspend companies.
- **FR-009**: The platform dashboard MUST show aggregate company, active-person, course and open-assignment counts without mixing tenant record lists.
- **FR-010**: Switching between linked tenant people MUST be explicit and audited.
- **FR-011**: Company access profiles MUST remain company-owned and assign to people, not global accounts.
- **FR-012**: The People directory MUST be the canonical company directory; identity linkage and access-profile administration MUST appear on the person detail.
- **FR-013**: Existing compliance evidence and assignments MUST continue to reference the company person and MUST not be deleted during identity separation.
- **FR-014**: Company creation MUST establish the baseline company roles and settings without requiring sample data.
- **FR-015**: The first platform administrator MUST be bootstrap-able without granting any company administrator the ability to create another platform administrator.
- **FR-016**: Every platform mutation and company access-profile mutation MUST be audited.
- **FR-017**: Existing official Oceanix branding changes MUST remain intact throughout the refactor.

### Key Entities

- **Account**: Global WorkOS-authenticated identity, including verified email, provider identity, lifecycle status and platform-administrator capability.
- **Person**: Company-owned workforce and compliance subject, including email, employment status, organizational links and optional account linkage.
- **Company**: Tenant, identified by stable public ID and slug, with lifecycle status and external identity-organization linkage.
- **Access Profile**: Company-owned set of operational permissions assigned to people.
- **Platform Audit Event**: Evidence of global company administration or a company context switch.

## Success Criteria

### Measurable Outcomes

- **SC-001**: 100% of authenticated tenant pages display the active company and use a company-scoped URL.
- **SC-002**: Automated authorization checks demonstrate zero tenant data exposure across two companies for all protected route categories.
- **SC-003**: One identity can switch between two linked companies in no more than two user actions while retaining separate permissions and histories.
- **SC-004**: Company administrators receive denial on 100% of platform routes and cannot grant platform access.
- **SC-005**: A platform administrator can create a company and reach its empty workspace in under two minutes.
- **SC-006**: Login for an unimported email creates zero person records and returns a clear access-denied message.
- **SC-007**: Existing application tests remain green and new identity/platform scenarios have automated coverage for grant, denial, direct access and revocation.

## Assumptions

- WorkOS remains the sole authentication provider and local passwords remain unused.
- Platform administrators use the same login flow as everyone else; there is no separate credential store.
- A platform administrator only enters a tenant workspace when its account has an active person in that company.
- Existing development data is disposable, so the baseline migrations may be refactored rather than requiring a live zero-downtime conversion.
- WorkOS Organization creation and invitation delivery are follow-on integration capabilities; this feature stores the identifiers and establishes the identity boundaries they require.
