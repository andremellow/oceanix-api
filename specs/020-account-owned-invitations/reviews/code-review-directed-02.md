# Directed Code Review — remediation round 2

Verdict: **APPROVE** for the seven assigned correction outcomes. No remaining material finding was identified in this directed scope. This is directed closure verification, not a replacement independent whole-scope approval.

- Run: `20261020-account-owned-invitations`
- Scope: `account-owned-invitations-v1`
- Checkpoint: `account-owned-invitations-v1-remediation2-9a02b0261bbf387d`
- Account commit: `8cb3a44f330c4675f11acf1ee3a6fbbde51ea6f2`
- Compliance commit: `462f6b5604268904393fa8aebee2bb771ca4beaf`
- Role/sourceRole: `code-reviewer`; phase: `remediation`; discovery/verification round: `2`; review-mode: `directed`.
- Context-mode telemetry: `inherited`, as required by the code-reviewer role instruction for directed remediation. The task assignment requested fresh; no Worker narratives, prior review reports, external comments or verdicts were read. Approved authority, the assigned required outcomes, prescribed checkpoint manifests and raw code/tests were used.
- Revalidation reason: assigned correction outcome verification.
- Local ledger IDs: Account CR-01, CR-02, CR-05, CR-06; Compliance CR-01, CR-03, CR-04, CR-07.
- New/open finding count: **0**. No ledger, production source, tests, environment files or verifier tooling edited.

## Approved authority and scope

Read both execution contracts' AC-01–05, INV-01–05 and frozen regression boundaries; approved spec, scenarios, architecture SHA-256 `490eb8c361fc7cbdb60f3ae8d0b33843fb25c1c140a243df5e2a9f8294310ecb`, and its two clarifications. Checked repository rules in both applications. Examined the raw round-1 and round-2 correction hunks for the assigned Actions, envelope/resource, affected tests and relevant runbook changes. Read unchanged dependencies only to trace the same authorization, retry, receipt and persistence paths.

Both HEADs match the declared commits. No working-tree changes were present in the reviewed production/test surfaces. Unrelated existing repository changes were left alone.

## Assigned outcomes

| ID | Outcome | Evidence and causal verification | Approved basis retained |
| --- | --- | --- | --- |
| CR-01 | CLOSED | Account `AuthorizeAccountOperator.php:44–50` binds the callback hash to its persisted target, then binds source company, actor, member and product before delegating to origin-specific current authority in `ReconcileCompanyInvitation::authorize`. Product-origin tenant/platform actors use fresh signed product proof and do not require an Account login User; Account-origin work still uses its Policy. Compliance `ApplyProductOperation::handle` obtains signed Account authorization before new mapping/observation receipt application. `AuthorizeProductActor` also binds mapping to persisted source hash, actor, company, subject, user and kind. Both SystemPrincipal suites passed, including legitimate actors without Account login, current authority revocation and tampered persisted source controls. | AC-04; INV-05; FR-008/012/013 |
| CR-02 | CLOSED | Account `QueueCompanyInvitationSync::reserve` locks company/member, checks caller UUID/principal/hash, and preserves each product caller as its own durable operation with an immutable `account.reconciliation` reference. Only the canonical operation reserves provider-read work. `reconcileReference` validates the exact canonical company/member, preserves each caller's identity while copying completion evidence, and receipt lookup stays principal-scoped. The CR-02 HTTP test covers two UUIDs, distinct resource sequences, altered-hash conflict, foreign issuer denial, one provider read, durable polling and manual recovery. | AC-03; INV-05; FR-011/012 |
| CR-03 | CLOSED | Compliance `SendWorkosInvitation.php:123–175` loads the immutable operation derived from the original attempt. Recovery accepts manually requeued Queued attempts as well as Sending attempts. Receipt 404 permits submission only of the persisted envelope after current invite Policy and original generation/invitation checks; all other unconfirmed outcomes remain unconfirmed. `AccountUserClient` validates receipt identity and submits the same Idempotency-Key. Account `SendCompanyInvitation` retains operation/hash/principal identity and dispatched-effect markers to prevent blind provider replay. Executed original Sending, manually Queued committed-receipt and Queued 404 same-intent cases; repeated execution adds no request. | AC-03; INV-02/05; FR-011/012 |
| CR-04 | CLOSED | Account `OperationResource.php:17–23` exposes explicit needs_attention only for invitation/reconcile/membership kinds, leaving legacy access outcome translation intact. `ProductOperationEnvelope::validateReceipt` accepts this terminal outcome. Compliance `ReconcileWorkosInvitations::refreshPending` treats only explicit needs_attention/rejected/conflict as terminal; transient unknown remains pollable with the last verified projection preserved. Matching exhausted runs get per-person errors and terminal counters; later confirmed observations use the existing projection transaction. Executed the unknown→confirmed original-operation test and terminal failure cases in WorkosInvitationSyncTest. | AC-03; INV-03/05; FR-010/011/014 |
| CR-05 | CLOSED | Account `EnsureProductPersonMembership.php:64–69` checks current registry readiness before claiming work, authorization HTTP or provider effects. Disabled operations retain their attributes. The real AccessTransitionProcessor dispatch path is exercised by LegacyInvitationCutoverTest: disable, execute with no HTTP/state change, reenable, finish the same reserved operation and replay without additional provider creation. | AC-05; INV-05; FR-015; architecture clarification 01 |
| CR-06 | CLOSED | Account `ReconcileCompanyInvitation.php:133–149` locks company, canonical operation and reference target; reloads and consumes retry_started_at atomically; resets only nonconfirmed canonical targets and only when the canonical operation is terminal. An already consumed reference cannot reset a renewed exhausted budget. The expanded CR-02 test exercises canonical-first and reference-first schedules through seven failed attempts, then repeats both worker paths and asserts unchanged delivery count and terminal states. Another authorized manual action recovers, retaining the confirmed read and its snapshot/hash. | AC-03; INV-05; FR-011; SC-18/19 |
| CR-07 | CLOSED | Compliance `SendWorkosInvitation.php:161–169` recognizes an already applied receipt by exact generation and invitation identity. It finalizes the historical intent/receipt without writing User projection fields in that case. AccountInvitationTest executes newer accepted, expired and revoked states at sequence 2 with an existing hash and accepted history, asserting all User attributes remain exactly equal after recovery; the historical attempt still succeeds and receipt recovery uses GET only. First-access preservation and repeated-execution no-call controls also pass. | AC-02/03; INV-03/05; FR-012; architecture §7.3 |

All seven are implementation-deviation closures routed to implementation (`failureStage: implementation`), detected/revalidated by `sourceRole: code-reviewer`. No specification/architecture gap or new desirable behavior is used as a blocking basis. Their original ledger discovery metadata/severity remains unchanged; this report does not rewrite it.

## Executed checks

Explicit `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, empty DB_URL, array cache/session, sync queue and nonexistent private config-cache paths were used. All exercised external integrations were faked. No PostgreSQL QA database, production database, live provider or normal application environment was used.

| Repository | Pest files | Result |
| --- | --- | --- |
| Account | Integration/SystemPrincipalTest; CompanyUsers/InvitationObservationDeliveryTest; CompanyUsers/ExistingPersonMappingTest | 28 passed; 341 assertions; exit 0 |
| Account | CompanyUsers/LegacyInvitationCutoverTest | 3 passed; 23 assertions; exit 0 |
| Compliance | People/AccountInvitationTest; ControlPlane/SystemPrincipalTest; People/WorkosInvitationSyncTest | 39 passed; 221 assertions; exit 0 |

Total: **70 passed, 585 assertions**. The first Compliance launch could not initialize Pest's local socket under sandbox restrictions; rerunning the identical targeted checks with runtime escalation passed. No source change was made to address that environmental restriction.

No executable browser QA or whole-suite/canonical rerun was claimed by this directed code gate. These checks validate only the assigned closures and their smallest causal regression paths. Required independent QA and final workflow completion remain separate gates.

**APPROVE**
