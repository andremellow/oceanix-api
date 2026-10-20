# Tasks: Account-owned invitations

Approved scope account-owned-invitations-v1; architecture hash490eb8c361fc7cbdb60f3ae8d0b33843fb25c1c140a243df5e2a9f8294310ecb. Exact path boundary: architecture-paths.json. A = Account; C = Compliance. Only one Worker writes production/tests across both. No parallel production tasks; independent external checks may run concurrently.

## Phase1 — Setup and protection
- [x] T001 Record explicit owner approval in both execution contracts and approval.md; retain proposal hashes.
- [x] T002 Finish actual runtime/dependency/browser/private HTTP/queue/PostgreSQL probes; update readiness in both contracts and pass preflight before Worker.
- [x] T003 Worker record baseline HEAD and pre-existing dirty-file hashes; preserve unrelated source/docs/tooling and .env. Read approved architecture and both design systems.

## Phase2 — Foundation (US4, SC20–23)
- [x] T004 Add regression-sensitive request/receipt/actor authorization cases for no UUIDallowlist, valid binding, expired/revoked/wrongenvironment/wrongproduct/tampered request; A tests/Feature/Integration/SystemPrincipalTest.php, C tests/Feature/ControlPlane/SystemPrincipalTest.php and affected existing provision/authorization tests.
- [x] T005 Remove company_uuids authorization meaning consistently in both ServicePrincipal middleware/Actions/receipt endpoints, retain real companybinding, currenthuman and token product/lifecycle; deprecated column remains. Exact affected paths per inventory.
- [x] T006 Implement A Actions/Integration/AuthorizeAccountOperator.php and invokable controller/request + route; bind fresh proof to operation/hash/company/subject/sourceability. Extend C actorrequest/AccountUserClient for provisioning and mapping proof, preserve existing tenant and platform authority.
- [x] T007 Add additive A database/migrations/2026_10_20_000001_add_account_invitation_ownership.php and C database/migrations/2026_10_20_000001_add_account_invitation_projection.php with modelcasts/immutability/indices per architecture§7.1; no destructive repair.

## Phase3 — US1 Independent initial import (SC01–06)
- [x] T008 Add red/causal tests A tests/Feature/WorkosPeopleImportTest.php + DiagnosticsTest.php for zero product requests in preview/apply, baselineprovenance, state/membership cases, pagination errors and old immutable pending/unknown/confirmed work.
- [x] T009 Refactor A Actions/CompanyUsers/ImportWorkosPeople.php and command into local-only complete-read→localbaseline flow. No product registry/client resolution or remote receipts; retain WorkOS GET/identityvalidation and explicit readonlypreview.
- [x] T010 Store declared association fields on CompanyProduct/UserProductEntitlement separately from confirmedaccess/localproductID; preserve old denial/transitions/evidence and importdurableinputs; quarantine legacydispatch independently.
- [x] T011 Update A CompanyUserDirectory and existing company/users product cards for imported/unverifiedassociation; gates still require actualbinding/confirmation. Translations only sourceEnglish→pt_BR.

## Phase4 — US2 Mapping and Account ownership (SC07–15)
- [x] T012 Add mappingonly positive/negative/response-loss tests A tests/Feature/CompanyUsers/ExistingPersonMappingTest.php; C tests/Feature/ControlPlane/ExistingAccountPersonMappingTest.php with distinct localIDs and imported Accountrows.
- [x] T013 Implement A MapExistingProductPerson.php and C MapExistingAccountPerson.php, strict kind-specific request/receipt/envelope handling and reciprocallink acknowledgement. Exact company+providerID/invitation only; no personcreation/access/roles/history changes.
- [x] T014 Wire automatic first-use mapping as durable prerequisite of existing sync/invite intent in C AdoptPeopleIntoAccount/AccountUserClient and A processor; retain sourceoperation/hash/actor/ability proof and safe conflicts. No extra manualmigration for valid mapping.
- [x] T015 Implement A QueueCompanyInvitationSync.php and ReconcileCompanyInvitation.php; existing SyncCompanyInvitations facade reserves durable readonlywork for knownmembers including noinvitation. Currentauthorization at enqueue/execution, no provider populationdownload.
- [x] T016 Implement C AccountInvitationObservation.php + ApplyInvitationObservation.php; extend existing control-plane kind/request/resource contracts. Only invitation/verification/sequencefields; no workos_active_membership/status/access/training mutation.
- [x] T017 Add immutable deliverytarget reservation, ProductIntegrationClient transport/receipt validation and AccessTransitionProcessor kindhandling. Independent mapping/read/deliveryresults; generation+sequence comparison and acceptedhistorymax.
- [x] T018 Route C ReconcileWorkosInvitations and SendWorkosInvitation entirely through Account for linked/unlinked paths; delete operational directprovider fallback. Keep old jobsclasses as guarded compatibilityadapters. Preserve confirmed-first RegisterPerson.
- [x] T019 Route C Platform GrantPlatformCompanyAccess/InviteCompanyAdministrator through Account existingpersonmembership/send with current platform proof; implement A EnsureProductPersonMembership.php. Preserve existing privilege/role behavior only after confirmation; no grant via mapping/sync.
- [x] T020 Enforce Compliance operational providerboundaries in old WorkosInvitationService/WorkosOrganizationMembershipService while preserving SocialLogin AuthKit. Add tests C AccountOwnedAdministratorTest.php and existing AuthKit/Register tests positivecontrols.
- [x] T021 Extend A/C affected existing UI/projection Services for honest queue/status/noinvitation/conflict/lastverified states; use existing primitives, permissionhidden controls and translations. No dashboard redesign.

## Phase5 — US3 Recovery, concurrency and cutover (SC16–19,22)
- [x] T022 Add actual persistence failure/replay/order tests A InvitationObservationDeliveryTest.php, InvitationConcurrencyTest.php, LegacyInvitationCutoverTest.php; C InvitationObservationTest.php, AccountOwnedInvitationCutoverTest.php and existing WorkosInvitationRecoveryTest.php.
- [x] T023 Implement bounded targetattempts, independent confirmedread retention, immutable deliveryhash/UUID, receipt-before-replay, pertargetmanualretry and audit in existing RetryAccessTransition/processor. No blindresend or job retryengine duplication.
- [ ] T024 Add A RecoverInvitationOperations.php scheduledduerecovery; recover lostenqueue/leaseexpiry safely and verify actualdatabasejobs aftercommit. Keep jobtimeout60/lease120/retry_after>120.
- [x] T025 Implement C QuarantineLegacyInvitationWork.php + guards on oldserializedjobs and A legacyimport disposition/cutoverflag. Newdispatch off until pairedreadiness; unknownoldmutation never replayed or erased.
- [x] T026 Add real disposablePostgreSQL interleaving tests for leases/dedup/receipt/generationordering using private fixture environment; no SQLite-only lockclaims.
- [x] T027 Extend existing person/Communication retry UI and safe failure statuses; browser evidence for queued/running/partial/exhausted, permissionrevocation, empty/noinvitation, narrow/wide and retrywithoutqueuerestart.

## Phase6 — Cross-system verification and delivery
- [x] T028 Build reusable safe paired API/queue/clock fixtures in approved A/C tests/Support/AccountOwnedInvitationFixture.php; private guarded DBs/providerfakes only. Provide exact executable QA entrypoints/data for every QA01–23, not a test-suite substitute.
- [x] T029 Update both docs/production-integration.md with no-UUIDlist token setup, independentimport, actualregistryverification, pairedreadiness, legacyqueue quarantine, mappingconflict/exhaustion and forwardrepair; no secretvalues or productionexecution.
- [x] T030 Run focused tests by causal change; Pint only featurefiles (avoid unrelated dirtyfiles), then canonical toscanini verify / composer verify for both; record genuine baselinefailures separately. New browser tests must participate in repository-required verification using existing approved testfiles/entrypoints.
- [x] T031 Freeze stable perrepo implementationcheckpoint and scope; independent complete CodeReview+TestAnalyst on approvedchangedpaths only. Consolidate all material findings once per round, no earlyremediation while any gate running.
- [ ] T032 Execute every QA01–23 in private realUI/API/queuefixtures; independent DesignReview on changedstates; record runtime failures and persistence/HTTP/provider boundaries. No unexecutedscenario claimedpassed.
- [ ] T033 Architect performs one post-QA conformance to approvedhash/AC/INV. Revalidate correctiondeltas by impact only; budgetscritical17specialiststarts/2remediationrounds acrossrun.
- [ ] T034 Pass final perrepo contract-aware gate and visible canonicalexit; reconcile scenario/table evidence and pending learningproposals. Ready-for-review PRs for both with actualresults and setupinstructions; attach each PR. No deploy/merge/productionmutation.

## Dependencies and delivery strategy
T001→T002→T003. T004–07 foundation precedes mapping/centralization. T008–11 localimport is independently verifiable and can ship first within final coherent PR/release instructions. T012–21 depends on foundation and exact mapping before observation/send. T022–29 recovery/cutover accompanies each flow before marking it complete. T030–34 follow stable implementation. One Worker may reorder tightly related tests/code within approvedscope for causal efficiency; no sourceparallelism, no newpaths/criteria without amendment. Tests are mandatory by policy and reference the approved SCIDs. No newproductchoice is inferred from tasks.

## Worker evidence status

Implementation and focused evidence: reviews/worker-verification.md. T024 retains its actual database-job runtime confirmation requirement for paired QA; T030 awaits the parent-run canonical pair. T031–T034 are independent/final gates and remain unchecked. Checked implementation tasks do not approve executable QA or final delivery.

Author verification waiting point: Account final visible canonical PASS (414 feature/unit, 6 environment-optional PostgreSQL skips, 2670 assertions; 43 browser/658; build/Pint). Compliance build/browser pass, but full canonical awaits explicit approval for two existing test paths in test-scope-amendment-proposal-v2.md. T030 remains unchecked until both full canonical exits pass; T031–T034 remain unchecked. Worker emitted blocked/waiting-owner-approval, not completed. No final checkpoint or publication yet.

Final Worker implementation checkpoint: account-owned-invitations-v1-3734b6699a921fa4; both visible canonical exits passed. Source commits and raw path inventories: reviews/implementation-checkpoint.json. T024 retains actual paired queue runtime confirmation for QA; T031–T034 remain independent/final gate work. Earlier waiting-state notes are historical and resolved by owner-approved amendment02.

Round2 deterministic verification and directed review evidence: reviews/remediation-02-checkpoint.json, code-review-directed-02.md and test-analysis-directed-02.md. Final implementation checkpoint account-owned-invitations-v1-remediation2-9a02b0261bbf387d. All code/test findings have verified resolution; original blocked/contaminated review records remain retained and are not approvals. T032 executable QA/design is in progress; T024 runtime database-job obligation, T033 conformance and T034 final gates/publication remain incomplete. Rejected Account verifier amendment remains rejected.

Runtime checkpoint terminal: QA BLOCKED,22/23 completed (including failures), QA11 incomplete; two implementation findings QA-F01/F02. Design BLOCKED, DSR01–03. Protected five table counts/hashes unchanged. T032/T033/T034 remain incomplete; no architecture conformance or publication approval inferred. Both correction rounds consumed, requiring owner replan before further source work. Final actual gate output retained separately.
