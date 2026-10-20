# Independent test effectiveness review

Verdict: **REQUEST_CHANGES**. Two blocking test gaps; no production or test edits made.

Run `20261020-invitation-validation-repair`, round 0, phase review, review-mode independent, context-mode fresh, sourceRole `test-analyst`. Frozen scope `invitation-validation-repair-v1`; checkpoint `invitation-validation-repair-v1-0e82b8595ce44ed2`.

Read the approved runtime contract, repair architecture/approval/amendment/checkpoint/handoff, original specification/clarification/architecture/design constraints, both repository rules and design systems. Inspected the complete raw diff for every checkpoint-listed production/test path against Account `8cb3a44f330c4675f11acf1ee3a6fbbde51ea6f2` and Compliance `462f6b5604268904393fa8aebee2bb771ca4beaf`, together with the relevant surrounding tests and persistence boundaries. All checkpoint file hashes matched after inspection. Only four repair translations were included. Unrelated dirty changes and other review/Worker reports were excluded.

## Execution evidence

- Account targeted Pest: 141 passed, 993 assertions. Files: Integration/{RegistrationActorAuthorizationTest,SystemPrincipalTest}; CompanyUsers/{ExistingPersonMappingTest,RegistrationTest,RegistrationMappingTest,AdoptionTest,InvitationObservationDeliveryTest,OperationRecoveryTest,PrReviewRecoveryTest}; CompanyInvitationSyncTest.
- Compliance targeted Pest: 43 passed, 1 skipped, 260 assertions. Files: ControlPlane/{ExistingAccountPersonMappingTest,InvitationObservationTest}; People/{AccountAdoptionTest,WorkosInvitationSyncTest}.
- The first sandboxed Compliance invocation could not bind Pest's local socket. The identical command rerun with socket permission passed. This was an environment restriction, not an application failure.
- SQLite real Eloquent reads/writes and HTTP boundaries ran with provider fakes. The explicitly opt-in PostgreSQL contention test was skipped. No PostgreSQL runtime or browser execution is claimed here. Independent QA remains responsible for the frozen paired-runtime and browser matrix.

## Acceptance and scenario mapping

| Frozen scenario / basis | Automated evidence inspected | Result |
| --- | --- | --- |
| QA-R01; AC-02/05, INV-05 | RegistrationActorAuthorizationTest cache-false/provider-active ordinary actor exercises register/map/reconcile guard with no Account User and exact unchanged member attributes; RegistrationTest and RegistrationMappingTest exercise normal registration effects with live-membership fake; holding-only control remains bounded | Main changed success branch covered; post-read revocation coverage incomplete, TA-R02 |
| QA-R02; AC-02/03, INV-03/05 | Account ExistingPersonMappingTest receipt baseline serialization and repeated identity; Compliance ExistingAccountPersonMappingTest untouched exact-identity pointer initialization; AccountAdoptionTest linked historic mapping receipt plus refreshed reconcile envelope; InvitationObservationTest first generation-zero observation | Both new initialization branches execute with real persisted people; narrow exclusion guards incomplete, TA-R01 |
| QA-R03; AC-03, INV-05 | AccountAdoptionTest lost mapping acknowledgement polls original receipt and preserves historical operation attributes; concurrent observation wins; InvitationObservationTest equal-pair hash conflict, older pairs/generations, same-generation different invitation, newer generation, atomic receipt rollback; WorkosInvitationSyncTest pending/status polling, terminal failures, transient recovery and interleaved newer state; Account recovery/delivery tests retain immutable targets and bounded retries | Core replay/order/recovery assertions effective; unknown-versus-initialized boundary incomplete, TA-R01 |
| QA-R04; AC-02, INV-03 | Both ExistingPersonMappingTest files reject conflicts, mismatched organization/binding/local identity and occupied links, preserve rows, and successfully map a distinct positive peer; duplicate invitation fixture is reachable without disabling constraints | Reachable API conflict controls effective. Owner-approved PostgreSQL unique-constraint equivalent is executable QA evidence still to be produced, not asserted by these SQLite tests |
| QA-R05; AC-02/03/05, INV-04 | Account CompanyInvitationSyncTest seeds an actual observation whose verified_at differs from last_successful_verified_at and asserts exact old date, relative age, retained safe error and explanation on list/detail; aria association asserted. CompanyInvitationSyncTest browser suite interacts with populated/empty/partial/exhausted/retry controls and checks keyboard/loading/focus/narrow layouts and hidden controls. Compliance PeopleAccountAccessTest retains populated polling/permission controls with explicit known-baseline fixtures | Effective server-rendered regression for new date/error fields; browser tests inspected, not rerun by analyst. Dynamic final QA/design execution remains separate |
| QA-R06; AC-02/04, INV-04/05 | RegistrationActorAuthorizationTest invalid signature sends nothing; pending/inactive/foreign/missing/malformed/unavailable evidence denies; exact GET count; unchanged persisted member and no transitions/Account users. SystemPrincipalTest and OperationRecoveryTest retain company/principal/proof/nonce/source-callback controls; WorkosInvitationSyncTest has authorized positive then revoked-permission zero-request control and no direct WorkOS requests | Main denial branches effective; new post-network principal/company checks unproved, TA-R02 |

AC-01 / INV-01 local-only import and INV-02 AuthKit are retained surfaces outside the repair's changed branches; no claim of independent import/login rerun. INV-02's directly relevant no operational WorkOS fallback is asserted in Compliance's mapping/reconcile tests. INV-03 preservation of real training evidence is exercised by the pre-existing populated adoption test; that test does not execute the newly allowed null-pointer receiver initialization, which is why TA-R01 requests protected-state assertions at that boundary. No new full-workflow requirements are introduced.

## Consolidated findings

### TA-R01 — Untouched-baseline exception lacks initialized-null negative controls

- Severity: high. Classification: test-gap. Blocking: yes.
- sourceRole: `test-analyst`; failureStage: `test-implementation`; discoveryRound: 0; discoveryPhase: review.
- Basis: QA-R02/R03, AC-02/03, INV-03/05; approved repair architecture §7.2 explicitly permits the new exception only for null pointer, generation zero, sequence zero, null hash and null successful verification.
- Evidence: Compliance `app/Actions/ControlPlane/MapExistingAccountPerson.php:24–39` and `ApplyInvitationObservation.php:23–32` add this exception. `ExistingAccountPersonMappingTest.php:93` tests only the entirely untouched positive tuple. `InvitationObservationTest.php:136` initializes that tuple and then rejects a replacement when **all** pointer/sequence/hash/time guards have changed. Neither test exercises an already-observed **null** invitation pointer. The concurrent mapping test at `AccountAdoptionTest.php:109–134` changes pointer, sequence, hash and verification together and consequently cannot isolate the null-pointer safety conditions in either receiver branch.
- Causal gap: replacing the receiver's complete untouched predicate with merely `workos_invitation_id === null` would still satisfy the new positive and nonnull-replacement tests, while allowing a known no-invitation observation to be replaced at generation zero. This is static defect-sensitivity analysis; no source mutation was performed.
- Required outcome: through the existing authenticated receiver API, retain the untouched positive control and add representative initialized-null negative cases (successful verification/observation already exists, incoming different nonnull ID at the same generation). Cover both mapping and observation branches, with incoming sequence high enough to reach the changed observation condition. Assert conflict and unchanged person/link/receipt/protected state; use nonempty existing protected state where asserting preservation. Include independent guard boundary cases when needed so sequence/hash/verification cannot be dropped together unnoticed. Reuse current files/fixtures; no new feature or PostgreSQL requirement.

### TA-R02 — New post-provider principal/company recheck is not regression protected

- Severity: high. Classification: test-gap. Blocking: yes.
- sourceRole: `test-analyst`; failureStage: `test-implementation`; discoveryRound: 0; discoveryPhase: review.
- Basis: QA-R01/R06, AC-04, INV-05; approved repair architecture §7.1 requires principal/company/member removal/enable/binding constraints to be reread after the network read.
- Evidence: Account `ProductActorAuthorization.php:58–68` adds fresh principal, company, module binding and member rechecks. `RegistrationActorAuthorizationTest.php:61–102` interleaves only `confirmed_enabled=false` inside the membership HTTP response; its other cases alter provider data, not local authority. Existing `PrReviewRecoveryTest.php:287` revokes principal/company/capability/actor **after the entire authorizer returns**, so a later outer guard can reject without executing the new post-provider reread for the changed state.
- Causal gap: removing the new fresh-principal/company/module conditions but retaining the member-enabled condition would leave these tests passing, despite accepting authority revoked during the provider request.
- Required outcome: extend the existing real-authorizer HTTP fake seam with representative principal revocation and exact company/binding change during the membership GET, returning otherwise valid active exact provider evidence. Use a valid positive control and assert authorization denial after exactly the expected GET, no downstream operation/access mutation, and no cached activation persistence. Cover the shared guard without copying each case across every operation kind. Keep the original proof-before-provider zero-call assertion. Tests must be sensitive to removal of the corresponding post-read recheck, rather than only a subsequent outer execution check.

## Limits and retained evidence

The new tests are causal for the primary repair: restoring the old cache-only guard would fail the cache-false success test; removing receipt baseline serialization, local refresh, or first-observation exception would fail the corresponding new positive tests. No claim is made that every accepted safety boundary has that sensitivity; the two findings identify the remaining material gaps. Browser-dependent behavior still requires actual browser QA; schema prevention requires the approved isolated PostgreSQL QA boundary. Neither is replaced by empty data or source inspection.

No product questions or scope additions were identified. No unrelated blocking requirements or follow-up work were added.

**REQUEST_CHANGES**
