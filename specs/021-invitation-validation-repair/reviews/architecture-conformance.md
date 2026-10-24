# Final architecture conformance — invitation validation repair

Verdict: **PASS**. Material deviations: **0**. Non-blocking findings: **0**.

Run `20261020-invitation-validation-repair`; scope `invitation-validation-repair-v1`; checkpoint `invitation-validation-repair-v1-67670919374299c4`; round 1; review mode independent. This is the one full final implementation conformance check. Actual context mode is inherited: the Architect authored the approved document, then compared raw final implementation/tests to the approved decisions. Worker, review and QA conclusion/verdict reports were not consulted.

## Approved inputs and integrity

Read `architecture.md`, `approval.md`, `test-scope-amendment-01.md`, runtime execution contract and `checkpoint.json`, and the referenced original FR-007–014 / approved architecture rules. The runtime amendment records the explicit owner answer “Sim, ajustar esse teste”; the proposal document's historical pending wording does not substitute for that decision. The original owner's repair approval includes QA-R04's constraint-preserving evidence alternative.

The approved architecture file still hashes to `890bf44ce261d0747087f72f295acc071548da062480c6bdff06b3f1ca2889b5`. Runtime architecture approval binds that exact hash and scope. All **18 Account + 9 Compliance** final checkpoint file hashes match their current raw files. Compared every checkpoint path's delta with Account baseline `8cb3a44f330c4675f11acf1ee3a6fbbde51ea6f2` and Compliance baseline `462f6b5604268904393fa8aebee2bb771ca4beaf`. Read unchanged dependencies only to establish the changed invocation/serialization/transaction boundary, including exact lifecycle membership reads and existing locked `consumeNonce:false` callers. No source, test, approved architecture or operational data was modified.

## Section and rule evidence

| Approved contract | Final implementation evidence | Test seam evidence inspected | Result |
| --- | --- | --- | --- |
| §§2/4–6: retain native Actions/projections, thin UI and bounded clients | Existing classes extended; no new layer, endpoint, event, migration or package. Directory queries remain in CompanyUserDirectory; UI only renders prepared metadata. | Existing feature and canonical browser entrypoints retained. | Conforms |
| §7.1; AC-02/04, INV-04/05: proof before provider I/O, current exact active actor membership without Account login or cache activation | Account `app/Services/Integration/ProductActorAuthorization.php:20` reloads principal; lines 33–58 validate exact proof/signature/time/nonce before line 69's membership GET. Lines 63–89 require unique local member, exact provider user/org/status and re-read principal/module/company/member after the read. No membership/access persistence occurs. Holding/platform exceptions retain their prior kind restrictions. Shared ordinary branch applies across existing product kinds. | `tests/Feature/Integration/RegistrationActorAuthorizationTest.php`: cached false/provider-active positive control for register/map/reconcile without User; proof/provider/pending/inactive/foreign/error/revocation controls; principal/company/module changes during read. | Conforms |
| §§5/7.1/7.7: no provider I/O inside locked rechecks; retain nonce and fresh execution semantics | The same authorization class binds successful evidence to request, exact envelope object and signed proof in WeakMap. `consumeNonce:false` requires previously consumed exact proof and nonce; rechecks local constraints while reusing that request's read. Existing RegisterCompanyPerson / FinalizeProductRegistration / ChangeUserProductAccess callers use it only for local transaction rechecks. New requests/envelopes/proofs cannot borrow it. Normal execution calls continue fresh proof/read. | `RegistrationActorAuthorizationTest.php` exact request/envelope/nonce replay controls; `tests/Support/ProductIntegrationFixture.php:70` exact GET fake asserts no added Action transaction around I/O. | Conforms |
| §7.2; AC-02/03, INV-03/05: additive mapping baseline, immutable historic receipts and source identities | Account `MapExistingProductPerson.php:57` captures `{invitation_id,generation}` in new receipt within existing company/member transaction; prior receipt early return unchanged. Compliance `AdoptPeopleIntoAccount.php:67` uses versioned mapping source identity; lines 74–78 reuse receipt/same frozen payload after ambiguous dispatch; lines 83–99 lock company/User/request and validate current binding/link/provider identity, zero typed baseline and untouched tuple. Writes only pointer, mapping and operation evidence. | Account ExistingPersonMappingTest exact baseline receipt and unchanged member/access; Compliance AccountAdoptionTest historical receipt preservation, lost acknowledgement, typed/nonzero rejection and competing observation wins. | Conforms |
| §7.2/8; AC-02/03: initialize before a new reconcile; no frozen reconcile rewrite | `ReconcileWorkosInvitations.php:120` refreshes person after ensure and before constructing the fresh request. `AdoptPeopleIntoAccount.php:61` requires additional baseline discovery only for an untouched linked reconcile; explicit send retains its existing linked path. Existing requests still use stored payload/hash. | AccountAdoptionTest observes mapping first and imported pointer in reconcile; browser and established sync fixtures preserve known baseline rather than bypassing unknown mapping. | Conforms |
| §7.2; AC-02/03, INV-03/05: Account-origin initial mapping/observation without wildcard replacement | Compliance `MapExistingAccountPerson.php:25` permits differing pointer only for null/gen0/seq0/no hash/no verified tuple with exact user mapping; line 38 writes pointer only. `ApplyInvitationObservation.php:23` limits initial same-generation ID allowance to that untouched tuple. Existing lower/equal/newer pair rules and accepted-history maximum remain. Initialized differing pointer continues conflict. | ExistingAccountPersonMappingTest and InvitationObservationTest independently exercise sequence/hash/verified guards with unchanged access, roles, assignments, certificates, links and receipts after rejection. | Conforms |
| §7.3; AC-02/03/05, INV-04: visible pre-action no-email explanation and retained verification age | Account directory `CompanyUserDirectory.php:79` batches company/member-scoped observations ordered by generation/sequence; uses last_successful_verified_at. List/detail render date/relative age, never-verified and separate safe error. List action's aria-describedby includes pre-action explanation. English source and PT-BR translation mapping maintained. | CompanyInvitationSyncTest retained success/error presentation; CompanyInvitationSyncTest browser initial explanation/never verified and post-sync last verified at wide/narrow locale cases. | Conforms |
| §9 and approved test amendment: real boundaries/fakes and existing regression behavior | Exact actor membership helper responds only to matching provider GET and supports explicit status argument; negative tests replace factory to supply independent provider failure evidence. Product delivery fakes narrowed to product URLs instead of swallowing provider reads. Existing side-effect assertions retained while allowing new authorization GET. | All scoped fixture/test deltas inspected; canonical Compliance PeopleAccountAccessTest used. Approved WorkosInvitationSyncTest amendment sets historical verification only when absent, retains timestamp overrides and original lifecycle/polling/recovery/order/access assertions. | Conforms |

## Complete changed-path inspection inventory

Account (18):

- `app/Services/Integration/ProductActorAuthorization.php`
- `app/Actions/CompanyUsers/MapExistingProductPerson.php`
- `app/Services/CompanyUsers/CompanyUserDirectory.php`
- `resources/views/components/company-users/⚡index.blade.php`
- `resources/views/components/company-users/⚡show.blade.php`
- `lang/pt_BR.json`
- `tests/Feature/Integration/RegistrationActorAuthorizationTest.php`
- `tests/Feature/CompanyUsers/ExistingPersonMappingTest.php`
- `tests/Feature/CompanyInvitationSyncTest.php`
- `tests/Browser/CompanyInvitationSyncTest.php`
- `tests/Feature/Integration/SystemPrincipalTest.php`
- `tests/Feature/CompanyUsers/RegistrationTest.php`
- `tests/Feature/CompanyUsers/RegistrationMappingTest.php`
- `tests/Feature/CompanyUsers/AdoptionTest.php`
- `tests/Feature/CompanyUsers/InvitationObservationDeliveryTest.php`
- `tests/Feature/CompanyUsers/OperationRecoveryTest.php`
- `tests/Feature/CompanyUsers/PrReviewRecoveryTest.php`
- `tests/Support/ProductIntegrationFixture.php`

Compliance (9):

- `app/Actions/People/AdoptPeopleIntoAccount.php`
- `app/Actions/People/ReconcileWorkosInvitations.php`
- `app/Actions/ControlPlane/MapExistingAccountPerson.php`
- `app/Actions/ControlPlane/ApplyInvitationObservation.php`
- `tests/Feature/ControlPlane/ExistingAccountPersonMappingTest.php`
- `tests/Feature/ControlPlane/InvitationObservationTest.php`
- `tests/Feature/People/AccountAdoptionTest.php`
- `tests/Browser/PeopleAccountAccessTest.php`
- `tests/Feature/People/WorkosInvitationSyncTest.php`

## Findings, limits and gate ownership

No IMPLEMENTATION_DEVIATION was found against the assigned approved architecture sections or AC/INV boundaries. No alternative design, new requirement or unrelated improvement is proposed. This inspection did not execute tests or browser QA and does not relabel their outcomes; those remain separate gates. The inherited original QA11 blocker is not silently removed: its replacement evidence boundary is explicitly owner-approved in the repair decision. Operational verifier/tool compatibility is separate from source conformance and is neither waived nor approved by this PASS. Delivery completion still requires the actual project gates.
