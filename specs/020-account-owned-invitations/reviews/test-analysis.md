# Independent test effectiveness audit

Verdict: **REQUEST_CHANGES**. Eight blocking test gaps; no production/test edits, delegated work, or product QA performed.

Scope `account-owned-invitations-v1`; checkpoint `account-owned-invitations-v1-3734b6699a921fa4`; round 1, independent, fresh context. Account diff `e9b128a3de1592a5b0a0cbaf3674c110c2ad7b51..90ef69866cfd37729d45db15986df86d305f4bd9`; Compliance diff `c46e45874b717c20d26d2674a079e1881699ea53..20e707ff5b8c2861829808ff88fca7701c516578`. Architecture `490eb8c361fc7cbdb60f3ae8d0b33843fb25c1c140a243df5e2a9f8294310ecb`.

## Inputs and method

Read both repository rules and execution contracts, approved spec/scenario map, architecture/inventory, plan, design constraints, approval, architecture clarifications 01/02 and both test-scope approvals. Examined frozen changed paths and relevant existing tests/helpers as context, including unchanged registration, authorization, communication, queue, migration, and AuthKit controls. Did not read Worker conclusions or other reviews. Source behavior establishes branch reachability, not product authority. Draft labels retained in approved documents are interpreted with their explicit approval artifacts.

Canonical raw logs at `/private/tmp/account-owned-invitation-run-3r98wy0s/{account,compliance}-canonical.log` show broad passing verification; this is execution evidence, not coverage proof. Independently reran targeted feature tests with explicit `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory:`: Account **20 passed / 223 assertions**, Compliance **33 passed / 145 assertions**. Logs: `/private/tmp/owned-invitations-ta-account.log`, `/private/tmp/owned-invitations-ta-compliance.log`. Initial sandboxed executions could not bind Pest's local socket; escalated reruns succeeded. No normal or live QA database was refreshed. No provider call was permitted by these fixtures. No new browser or PostgreSQL execution was performed by this audit.

Account's SC-19 test uses an actual competing PHP process blocked by a company row lock and compares operation identities; its lease test uses another PDO connection during fake provider HTTP. Those are meaningful concurrency boundaries. Running Compliance's existing two observation tests on PostgreSQL still gives sequential replay/rollback evidence, not competing receiver execution.

## Findings

Every finding below has `sourceRole=test-analyst`, `failureStage=test-implementation`, classification `in-contract-test-gap`, discovery round 1 / phase review, and blocking basis an approved AC/INV. All listed changed paths belong to the frozen review surface. Required outcomes can use the lowest-cost effective boundary; they do not add product requirements or QA IDs.

### TA-01 — High: new live authority boundaries are replaced by unconditional proof fakes

**Basis:** AC-02/AC-04, INV-05; SC/QA-09,15,20,21. Architecture §7.5.

**Changed paths / change evidence:** Account adds `app/Actions/Integration/AuthorizeAccountOperator.php`, its controller/request and `routes/api.php`; Compliance adds source-intent validation for `person.map-existing` in `app/Actions/ControlPlane/AuthorizeProductActor.php` and signed proof validation in `app/Services/Account/AccountUserClient.php`. These are the actual new authority paths after removing token company lists.

**Evidence:** There is no Account test referencing `AuthorizeAccountOperator` or POST `/api/product-access/v1/actor-authorizations`. Account `Integration/SystemPrincipalTest.php / SC-20 authorizes a bound current actor…` calls the older `ProductActorAuthorization`, then asserts true. Compliance `AccountIntegrationFixture::fakeAccountActorAuthorization()` signs whatever it receives. Mapping tests use that fake; `ConfirmedRegistrationAuthorizationTest.php` tests registration, not the new mapping-source branch. Platform authority has a real positive/revoked control, but it cannot establish tenant mapping-source authorization or Account holding/operator authority.

**Required outcome:** Execute the real new Account HTTP callback with a persisted provision intent and persisted mapping/observation targets, valid principal/module/company and current human grant positive controls. Reject changed hash/company/actor/source, missing intent and revoked current human authority. Execute Compliance's real mapping-source callback from persisted source plus mapping operations; test exact success, forged source/subject/hash and revocation. At the receiving proof-validation boundary exercise bad/expired/reused proof and preserve records/no subsequent effects. Fake only remote transport, not the local authorizer under test.

### TA-02 — High: invitation-only and conflicting existing-person mapping branches lack representative tests

**Basis:** AC-02, INV-03; SC/QA-09,10,11,14.

**Changed paths / change evidence:** New `MapExistingProductPerson.php`, `QueueCompanyInvitationSync.php::reserveMapping`, `ReconcileCompanyInvitation.php` mapping target handling; Compliance new `MapExistingAccountPerson.php` and rewritten `AdoptPeopleIntoAccount.php` select exact provider identity or invitation-only identity and persist reciprocal links.

**Evidence:** Account `ExistingPersonMappingTest.php / SC-09 maps an existing imported identity…` has one WorkOS-user success/replay and calls the Action directly. Compliance `ExistingAccountPersonMappingTest.php / SC-09 SC-11 maps an existing exact company identity…` has one WorkOS-user success plus changed shared-ID conflict. Neither new mapping test sets WorkOS identity null with an exact invitation ID. The observation test named “invitation-only snapshots” uses `Fixture::receiver()` whose person has `user_synthetic_target`. Account's “SC-10 SC-17 freezes a no-invitation snapshot” also uses a linked provider user and no invitation. Old migration/adoption tests exercise different receiver Actions and do not cover these new selectors. `readyInvitations()` precreates the ProductPersonLink, bypassing Account-origin automatic mapping in normal sync/send tests.

**Required outcome:** Exercise both new mapping directions with null provider user, exact invitation and distinct local IDs, then expired/revoked reconciliation; assert reciprocal links, unchanged people/access and no provider mutations. Exercise Account-origin unlinked mapping acknowledgement before read/send. Add the frozen conflict cases (duplicate identity, wrong company/org, email collision/different stable ID, occupied prior link, missing binding), with a valid peer in the batch proving other people finish. Assert no overwritten mapping, duplicate person or fallback request.

### TA-03 — High: receiver ordering and snapshot-conflict branches are not proved under competing execution

**Basis:** AC-03, INV-05; SC/QA-19. Architecture §§7.3,7.6,9 expressly select PostgreSQL for contention/order evidence.

**Changed paths / change evidence:** Compliance `ApplyInvitationObservation.php` adds lexicographic generation/sequence comparison, equal-pair hash conflict, current invitation identity guard and locked projection; `ApplyProductOperation.php` couples this to receipts.

**Evidence:** `InvitationObservationTest.php` applies generation 1/sequence 2 and then generation 1/sequence 1 sequentially, and separately injects receipt persistence failure. It does not execute equal pair/same snapshot under another operation, equal pair/different snapshot, generation N+1 before N, or competing receiver transactions. `WorkosInvitationSyncTest.php / SC-19 preserves a newer invitation…` changes the row inside an HTTP fake on one connection; it is useful stale-client evidence, not receiver lock contention. Account's real concurrency tests cover reservation and lease loss, not this Compliance receiver.

**Required outcome:** On the guarded disposable Compliance `_test` PostgreSQL database, interleave actual receiver connections/processes on the same representative linked person and verify receipts plus final generation/sequence/hash/invitation. Include N+1-before-N and current invitation replacement; sequential tests may cover same-pair identical/different snapshot outcomes. Assert no stale overwrite, duplicate receipt or protected access/history change. Demonstrate network happens outside the locked transaction where that path calls the authority service.

### TA-04 — High: delivery recovery assertions use no-invitation shortcuts and omit frozen acknowledgement failures

**Basis:** AC-03, INV-04/05; SC/QA-16,17,18.

**Changed paths / change evidence:** Account `ReconcileCompanyInvitation.php` persists provider-read result independently, freezes a delivery payload, retrieves prior receipts after ambiguous dispatch and validates delivery identities/order before confirmation.

**Evidence:** Both delivery recovery tests in `InvitationObservationDeliveryTest.php` create `Fixture::member()` without an invitation; `read()` therefore never calls WorkOS. The retry browser test does likewise. Their `assertNotSent(WorkOS)` and unchanged sequence cannot catch rereading a real invitation during delivery retry. The delivery failure is 503; the recovery GET returns 404 followed by successful PUT. There is no observation-delivery test for provider-read success with a real invitation followed by 401, old-version 404, malformed/mismatched receipt, or receiver commit followed by lost acknowledgement and receipt GET. Compliance tests prove same-intent replay but not altered observation payload under the same key. Access-removal and platform-send recovery tests exercise other senders/receipts.

**Required outcome:** Start with a non-null provider invitation and verified read, fail only projection delivery with frozen failure fixtures, then recover. Assert the real provider GET count stays one, original snapshot/UUID/hash survive, successful observation is retained and only validated delivery completes. Simulate committed receiver/lost response and return its stored receipt on GET; verify no second application. Submit changed observation/mapping payload with the same UUID and assert 409 plus unchanged receipt/projection. No email during any projection retry.

### TA-05 — High: durable recovery and cutover commands have no executable regression coverage

**Basis:** AC-03/AC-05, INV-05; SC/QA-18,22; architecture §§2,7.7,8.5.

**Changed paths / change evidence:** New Account `app/Console/Commands/RecoverInvitationOperations.php` and schedule in `routes/console.php`; new Compliance `QuarantineLegacyInvitationWork.php`. These own missed-enqueue/crashed-worker recovery and deployment quarantine.

**Evidence:** No test invokes `account:recover-invitations` or `account:quarantine-legacy-invitations`. Account helpers manually call the processor twice and advance time; that bypasses dispatcher selection, due/lease/terminal filtering and job payload. Compliance cutover test directly constructs default-version jobs; it proves their quarantine adapters but never command preview/apply or persisted queue recovery. Existing committed-dispatch tests predate the new recovery command and cannot catch its omission/selection errors.

**Required outcome:** Persist committed new-format work without an enqueued job, expired and live claims, future/due retry timestamps and terminal/confirmed rows. Run the real recovery command and execute a recovered job; assert exact selected IDs, current authorization, bounded attempts and no confirmed provider replay. Test cutover-disabled control and reenable. For Compliance quarantine, prove read-only preview, apply against representative old queued/running/sending work, retained evidence, and no external dispatch. Prove new-format scalar job execution separately from legacy adapter quarantine.

### TA-06 — Medium: changed Compliance synchronization/polling UI has no browser interaction coverage

**Basis:** AC-05, FR-014; SC/QA-07,12,15,18,23 and approved design constraints.

**Changed paths / change evidence:** `resources/views/components/organization/⚡people.blade.php` adds Account pending/terminal lifecycle presentation and `wire:poll.3s="refreshOperations"`; `PeopleDirectory.php` now treats pending Account requests as active work. This is the changed path that must visibly converge or expose failure.

**Evidence:** Canonical `PeopleAccountAccessTest.php` exercises registration/access removal and invitation selection/confirmation. Its invitation browser case only sees “Synchronize WorkOS”; it never clicks synchronization, runs its work, observes Account pending→completion/failure, or invokes polling recovery. `PeopleInvitationBrowserTest.php` is only a comment pointing to that file. Feature `PeopleInvitationProjectionTest.php` has direct authorization/rendering checks; `WorkosInvitationSyncTest.php` invokes refreshPending directly. Neither proves browser-driven refresh, disabled controls, safe terminal feedback or focus/state under the new polling flow. Account browser coverage is meaningful but cannot prove Compliance behavior.

**Required outcome:** In the existing browser scope, interact with Compliance synchronization and changed refresh/recovery UI using populated linked and empty/no-invitation fixtures, pending→confirmed and terminal/partial failures, permitted/view-only controls and revocation. Observe rendered per-person outcomes and unchanged last verified evidence at narrow/wide widths, disabled repeated action and accessible feedback. Use real local application behavior with fake external HTTP; product QA remains a separate gate.

### TA-07 — High: new platform provider writer has only successful creation coverage

**Basis:** AC-03/AC-05, INV-03/05; SC/QA-17,19,23; architecture clarification 02.

**Changed paths / change evidence:** Account adds `EnsureProductPersonMembership.php` with distinct identity/membership dispatched markers, lease/claim, per-target persistence, bounded failure state and exact ProductPersonLink reconciliation. Compliance adds `AccountUserClient::platformOperation` reciprocal-link transaction before existing role grant/invite.

**Evidence:** Account's sole `EnsureProductPersonMembership` test is `InvitationObservationDeliveryTest.php / SC-23 confirms a new platform identity…`: immediate successful user+membership creation followed by confirmed replay. Existing `RegistrationTest.php` exercises another Action. Compliance tests fake a complete membership receipt and prove authority revocation and stable envelope recovery, but do not inject malformed membership receipt, conflicting reciprocal mapping or local link-persistence failure after Account confirmation. No test puts the new Account writer in its dispatched-but-unconfirmed identity/membership branch or competing platform intent.

**Required outcome:** At the new writer's real persistence boundary simulate provider response loss after each mutation, replay/recover the original intent and assert one user/membership mutation maximum, stable envelope, honest attention state and no invitation. Cover exact existing identity/membership and conflicting link without access/identity reassignment. At Compliance inject malformed receipt, mapping conflict and link-transaction failure after confirmed remote membership; assert rollback/no admin role or success audit, then recovery from the same stored receipt. Add real competing execution for the frozen platform-intent deduplication case, reusing the approved PostgreSQL harness.

### TA-08 — Medium: local import restart test never fails after partial persistence

**Basis:** AC-01, INV-01/03; SC/QA-05 (explicit partial page/write failure).

**Changed paths / change evidence:** `ImportWorkosPeople.php::prepare()` now writes local person/member, immutable import intent, local outcome and association/entitlement within per-source transactions; outer processing continues after a source failure.

**Evidence:** Repeated import and old pending/unknown/confirmed evidence are well covered. Failed-page tests abort before applying any source. `WorkosPeopleImportDiagnosticsTest.php / SC-05 retains frozen evidence and local failure counters…` fails an email identity check before any local materialization; the diagnostic QueryException test only classifies a constructed exception. Neither exercises rollback after member/import-item insertion, a later source failure with earlier committed rows, nor recovery after that partial write failure.

**Required outcome:** Import at least two concrete source identities; make a later local association/outcome write fail after earlier source commit and after some writes in the failing source. Assert rollback of only the failing source, truthful partial outcome, preserved immutable legacy evidence, then restart to complete exactly once. Snapshot people/members/associations/entitlements/import rows, and forbid every product call/provider mutation throughout.

## Complete scenario-to-test assessment

`A:` means Account; `C:` means Compliance. Paths are repository-relative. This matrix distinguishes proven assertions from gaps; runtime QA IDs share the matching numeric scenario suffix and are not marked executed by this audit.

| SC / QA | Concrete automated boundary, file/name and assertions | Assessment |
| --- | --- | --- |
| 01 | A `WorkosPeopleImportTest.php / SC-01 SC-03 previews and imports locally…`, `paginates nonempty isolated company snapshots…`: no product configuration, populated preview table snapshots, one/all companies, GET-only provider positive control, no remote receipts. Diagnostics tests add missing/offline connection controls. | Effective core |
| 02 | A same import suite / `imports every invitation state using organization membership for access` dataset includes all four states × active/inactive/pending membership with accepted ID, repeat counts/history, no ProductPersonLink/confirmed entitlement. `paginates…` asserts exact multi-page source keys and tenant isolation. | Effective core |
| 03 | A import suite / `derives initial entitlement…`, `reuses exact shared identity…preserves revoked entitlement snapshots`, `does not infer organization access…`: actual membership and explicit denial snapshots. | Effective core |
| 04 | A import suite / unauthorized preview, invalid selector, foreign organization/email collision; diagnostics invalid identity/state fixtures reject with zero materialization and safe output. Holding-admin success in same suites. | Effective core |
| 05 | A import suite / immutable unknown evidence, vanished old source pending/unknown/confirmed, preview after apply, repeated local baseline; exact stored input/hash/receipt and no replay assertions. | Partial: TA-08 |
| 06 | A import suite / `rejects failed later and malformed provider pages…`, repeated cursor; Diagnostics / source 401, malformed payload: zero truncated rows and safe failure. Empty company covered by command preview. | Effective shared failure handling; no distinct 429/timeout import invocation |
| 07 | C `People/WorkosInvitationSyncTest.php / SC-07 SC-13 receives every lifecycle…`: real projection, fake Account, state enum and protected user/role snapshots, no WorkOS. A real read exercised in CompanyInvitationSyncTest. | Partial: TA-01/04/06 |
| 08 | A `CompanyInvitationSyncTest.php / refreshes only known invitations…`, `retains exact accepted expired and revoked history…`: real provider GET, persisted observation/history, exact protected member/profile/entitlement snapshots, repeated verification. Browser `CompanyInvitationSyncTest.php / synchronizes actual invitation controls…` observes loading/results. C receiver test applies payload through real API. | Partial delivery protocol: TA-04 |
| 09 | A `CompanyUsers/ExistingPersonMappingTest.php / SC-09 maps…`: member table unchanged, one exact local link, no entitlement; C `ControlPlane/ExistingAccountPersonMappingTest.php / SC-09 SC-11 maps…`: users/access unchanged and exact replay. C `People/AccountAdoptionTest.php / preserves local evidence…` has nonempty assignment/certificate/event/role snapshots. | Partial: TA-01/02 |
| 10 | New tests bearing SC-10 use provider-user fixtures, not invitation-only mapping. Old `AccountPeopleMigrationTest.php / links exact invitation-only identities…` exercises migration receiver, not new mapping protocol. | Gap: TA-02 |
| 11 | C new mapping test rejects changed shared ID; import/old migration tests protect email and foreign-identity cases at their own boundary. New Account mapping failure selectors and automatic mapping batch lack controls. | Partial: TA-02 |
| 12 | A no-invitation delivery snapshot explicitly state `none`, no provider send; A browser `shows empty completion and hides synchronization…`; C lifecycle `none` and pending queued-sync tests. | Core service/Account UI covered; C browser TA-06 |
| 13 | C lifecycle dataset all states × membership active flag checks status/access/roles; malformed/foreign observation dataset checks complete unchanged user row. C receiver test preserves prior accepted history on null history; A sync retains repeated history and independent identity/access. | Core lifecycle covered; ordering TA-03 |
| 14 | A `CompanyUsers/InvitationTest.php / resends pending or replaces expired and revoked…`: one POST, generation/history. `never resends an uncertain invitation…`: one POST after ambiguity. C `AccountInvitationTest.php` proves GET recovery, preserved first access and already-active no-send. Recovery suite asserts Account ownership. | Unlinked new mapping missing: TA-02 |
| 15 | A `CompanyInvitationSyncAuthorizationTest.php` grants/prerequisites/view-only/direct Livewire denial, nonempty success, revocation after provider read. C sync suite positive then detached-role denial; queued new-format job restores tenant. Existing access tests retain ordinary atomic grants; platform proof has positive/revoked control. | New mapping/live callback gaps TA-01; browser TA-06 |
| 16 | A delivery suite 503 failure→attention→manual recovery preserves frozen snapshot; C transport/malformed receipt keeps complete prior user attributes; receiver receipt failure rolls back projection. | Missing representative read + specified delivery failures TA-04 |
| 17 | C new receiver identical replay; A mapping identical replay; A invitation uncertain-send count, C accepted GET-receipt recovery; C platform lost invitation response preserves original operation/hash despite later generation. | Observation/mapping altered intent/lost-ack coverage TA-04; platform writer TA-07 |
| 18 | A delivery suite asserts 7 attempts, no future automatic timestamp, same target identity and confirmed manual recovery. A browser person retry actually clicks keyboard control at two widths. C `SC-18 reports exhausted Account targets…` checks run failed and unchanged last verified timestamp. | Dispatcher/crash TA-05; representative read TA-04; C UI TA-06 |
| 19 | A `InvitationConcurrencyTest.php`: separate PHP reservation contention and second-PDO lost lease, actual guarded PostgreSQL. C receiver older sequence and in-flight-generation mutation are sequential. | Receiver/platform concurrency TA-03/07 |
| 20 | C `ControlPlane/SystemPrincipalTest.php`: empty and obsolete company lists with successful submit/read plus org mismatch rejection. ProvisionCompany and ProductOperation suites exercise real binding/receipt formation. A SystemPrincipal exercises current bound actor with obsolete list. | New Account callback unproved TA-01 |
| 21 | Existing ProductOperation/ProvisionCompany suites cover human/expired/inactive/environment/issuer/company/key/hash denials; Account OperationRecovery proves forged/expired/mismatched actor proof, nonce and callback rejection; new platform proof tests revoke privilege. | New source/callback proof branches TA-01; receiver snapshot conflict TA-03 |
| 22 | A `LegacyInvitationCutoverTest.php`: false flag rejects with zero operation/request; true flag positive reserves. C cutover test invokes old job adapters and asserts disposition/context/no requests. Import legacy evidence quarantined separately. | Command/dispatcher execution TA-05 |
| 23 | C `Platform/AccountOwnedAdministratorTest.php`: membership confirmed before role, revoked authority, immutable learned-identity envelope, lost invitation ack, pending result honesty. Existing platform cases route fake Account and forbid WorkOS. Existing AccountRegistration tests and browser registration recovery protect confirmed-first behavior; unchanged AuthKit fake-provider suite remains login positive control. A browser sync exercises localized narrow/wide interaction. | Platform failure/concurrency TA-07; C sync browser TA-06 |

## Boundary and verdict limits

HTTP fakes are appropriate external boundaries. The gaps above arise where they bypass a newly changed local authority/receipt path, or where fixtures omit the records/state required to execute a protected branch. An empty/no-invitation shortcut does not prove recovery after an actual provider read. Passing broad test counts and running sequential tests on PostgreSQL do not establish the missing claims.

All eight findings are within the frozen matrix; no follow-up scope additions or product decisions are proposed. These are test-effectiveness findings, not assertions that the implementation necessarily fails those cases. Full product QA and architecture conformance remain independent. **REQUEST_CHANGES**.
