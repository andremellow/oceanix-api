# Behavior scenarios and test evidence

Rules are authoritative in spec.md. User confirmation of artifact details: PENDING. Execution evidence: NOT_RUN.

## Concrete examples

| ID | Rule | Preconditions | Action | Outcome / forbidden side effects |
| --- | --- | --- | --- | --- |
| SC-001 | FR-001/002 | Pending/accepted/expired/revoked company invitations | Sync | Four correct states persisted and rendered; no email |
| SC-002 | FR-002/003 | More than one WorkOS response page; repeated email invitations | Sync all | All pages processed; deterministic current state; accepted history not lost |
| SC-003 | FR-003 | No local ID; exact email company invitation; different company lookalike | Sync | Match company/exact email only; no cross-tenant update |
| SC-004 | FR-003 | 404/deleted invitation or malformed/mismatched provider record | Sync | Unknown/unverified state; no false pending/activation |
| SC-005 | FR-002/011 | 429/timeout/5xx on one person, positive control succeeds | Sync and retry | Visible partial failure; prior successful snapshot preserved; retry bounded |
| SC-006 | FR-004/006 | Global WorkOS last_sign_in_at, no tenant access evidence | Sync | External evidence displayed separately; no local timestamp fabricated |
| SC-007 | FR-010 | Granted, denied, admin, prerequisite and revoked profiles | Direct action and hydrated page | Only authorized current-company sync; revoked action denied |
| SC-008 | FR-011 | Login or new invitation occurs during old snapshot read | Apply snapshot | New first access/current invitation preserved; tenant context restored |
| SC-009 | FR-008/009 | Pending invitation and expired invitation, valid local recipients | Selected resend | Pending resend; expired new invitation; current ID/time persisted |
| SC-010 | FR-008/009 | Accepted recipient, active member with old expired invitation, suspended/terminated | Send selected/all | Skipped with reasons; no email; positive eligible recipient sends |
| SC-011 | FR-008/009 | Not invited, pending, expired, revoked, unverified, not locally accessed | Default all / explicitly select revoked | Default excludes accepted/revoked/blocked states; fresh verification before sending; explicit revoked can create |
| SC-012 | FR-008/011 | Repeated clicks, queue retry, remote failure and revoked initiation permission | Queue and execute send | No overlapping duplicate send; truthful failure/audit; authorization reevaluated |
| SC-013 | FR-004/005 | Invited provisioned person, verified identity, allowed account | WorkOS callback | Tenant session succeeds; active; server first/last access; repeat preserves first timestamp |
| SC-014 | FR-005 | Suspended/terminated person or inactive account or invalid/unverified identity | Callback | Denied; no activation/access timestamp; successful control proves path |
| SC-015 | FR-005 | Invited administrator/platform entry and authorized company switching | Enter tenant | Explicit tenant access records activation; blocked users remain denied |
| SC-016 | FR-005/006 | Imported/new admin, legacy active, suspended and terminated people with assignments | Create/migrate | New/legacy no-evidence people invited; blocked status retained; roles and obligations unchanged |
| SC-017 | FR-012 | All four statuses; published course; targeted/untargeted/effective-date people | Manual and requirement assignment | All statuses assignable when targets match; untargeted excluded; exact version frozen |
| SC-018 | FR-007/012 | Empty results, long names, mixed state rows, second page, recurrence and existing assignments | Filter, resize, keyboard, retry materialization | Accurate combined filters/counts; accessible controls; clarified training headings; idempotent recurrence/history |

## Coverage examination
| Dimension | Evidence | Status |
| --- | --- | --- |
| Happy/alternative flows | SC-001,009,013,015,017 | Planned |
| Roles, permissions, isolation | SC-003,007,010,012,014,015 | Planned |
| Data/limits/invalid input | SC-002,003,004,018 | Planned |
| Lifecycle | SC-001,006,008,009,013,016 | Planned |
| Empty/loading/error | SC-005,018 | Planned |
| External failure/recovery | SC-004,005,009,012 | Planned |
| Time/retries/concurrency | SC-005,008,012,013,017,018 | Planned |
| Affected behavior | SC-013–018 | Planned |

## Test plan and execution evidence
Feature/Pest at real Action/HTTP/Livewire/persistence boundaries with Http::fake and representative factories for SC-001–017. SC-018 uses Livewire tests for filters/pagination plus real browser QA for keyboard, mobile, loading and text. Concurrency SC-008 uses deterministic interleaving at the HTTP fake boundary and persistence assertions. SC-012 verifies queue uniqueness/recheck and documents external delivery uncertainty (no exactly-once guarantee across an API success and a lost response).
Every scenario needs a named test/check and command/result during implementation; none has run for the new feature. Reuse existing tests where they prove the behavior; new tests must be defect-sensitive. No automation exceptions are approved.

## Questions and decisions
User confirmed assignment to any person; confirmed proceeding with the proposed first-access and invitation separation. Artifact details in spec FR-006/009 await explicit approval. No additional scenario may become blocking without contract amendment.

## Proposed named automated checks

| Scenario | Proposed file / check name | Execution |
| --- | --- | --- |
| SC-001 | tests/Feature/People/WorkosInvitationSyncTest.php — maps supported invitation states | NOT_RUN |
| SC-002 | tests/Feature/People/WorkosInvitationSyncTest.php — paginates and selects current invitations | NOT_RUN |
| SC-003 | tests/Feature/People/WorkosInvitationSyncTest.php — matches invitation email and company | NOT_RUN |
| SC-004 | tests/Feature/People/WorkosInvitationSyncTest.php — rejects unavailable and contradictory records | NOT_RUN |
| SC-005 | tests/Feature/People/WorkosInvitationSyncTest.php — retains evidence on partial provider failure | NOT_RUN |
| SC-006 | tests/Feature/People/WorkosInvitationSyncTest.php — keeps external signin separate from tenant access | NOT_RUN |
| SC-007 | tests/Feature/People/WorkosInvitationSyncTest.php — enforces sync profile authorization | NOT_RUN |
| SC-008 | tests/Feature/People/WorkosInvitationSyncTest.php — preserves newer access and invitation writes | NOT_RUN |
| SC-009 | tests/Feature/People/WorkosInvitationRecoveryTest.php — resends pending and reissues expired invitations | NOT_RUN |
| SC-010 | tests/Feature/People/WorkosInvitationRecoveryTest.php — skips accepted members and blocked people | NOT_RUN |
| SC-011 | tests/Feature/People/WorkosInvitationRecoveryTest.php — distinguishes default and explicit revoked recovery | NOT_RUN |
| SC-012 | tests/Feature/People/WorkosInvitationRecoveryTest.php — guards concurrent sends and revoked actors | NOT_RUN |
| SC-013 | tests/Feature/Auth/TenantFirstAccessTest.php — records first and last authorized tenant access | NOT_RUN |
| SC-014 | tests/Feature/Auth/TenantFirstAccessTest.php — rejects blocked and invalid callback without activation | NOT_RUN |
| SC-015 | tests/Feature/Auth/TenantFirstAccessTest.php — records authorized platform entry and tenant switching | NOT_RUN |
| SC-016 | tests/Feature/People/InvitationStatusTransitionTest.php — preserves obligation evidence during legacy transition | NOT_RUN |
| SC-017 | tests/Feature/Requirements/AllStatusAssignmentEligibilityTest.php — assigns all statuses with target and version constraints | NOT_RUN |
| SC-018 | tests/Feature/People/PeopleInvitationProjectionTest.php + tests/Browser/PeopleInvitationBrowserTest.php — combines filters and preserves recurrence and accessible UI | NOT_RUN |
