# Directed code-review verification — round 1

Verdict: **APPROVE** for CR-R01 closure. No new findings.

Run: `20261020-invitation-validation-repair`; scope: `invitation-validation-repair-v1`; checkpoint: `invitation-validation-repair-v1-67670919374299c4`. Agent/sourceRole: `code-reviewer`; context-mode: inherited; review-mode: directed; round: 1; phase: remediation. Assigned finding: `CR-R01`. Finding count: 0 new; closure count: 1.

This directed verification retains the full independent round-0 evidence in `code-review.md` and verifies only CR-R01 and the smallest causal regression surface. It does not replace an independent fresh review. Compliance production hashes are unchanged; its separate test remediation is outside this assigned closure.

Inspected the remediation in Account `ProductActorAuthorization.php`, `ProductIntegrationFixture.php`, `RegistrationActorAuthorizationTest.php`, and the receipt-count adjustment in `PrReviewRecoveryTest.php`, with the original registration/finalization/product-access transaction callers as necessary context. The four assigned Account files match the current checkpoint hashes.

## CR-R01 closure evidence

The ordinary actor guard now performs provider membership I/O only for the initial nonce-consuming authorization. Successful evidence is stored in request attributes in a WeakMap keyed by the exact envelope object and exact signed proof. The existing locked `consumeNonce: false` calls require that successful prior evidence and the consumed nonce, then reuse membership evidence without HTTP. They revalidate current persisted principal status/expiry/environment/capability, registered module principal, company identity, member enabled/verified/removal state, exact membership and provider identity. Proof signature, identity, timestamps and expiry remain validated; a fresh principal is loaded at entry. Missing prior evidence, a changed proof, a copied envelope, a different request and repeated nonce consumption are denied. Failed provider checks do not publish reusable successful evidence.

The fixture captures transaction depth before an Action and asserts provider membership GETs occur at that depth; Laravel RefreshDatabase's outer test transaction is explicitly accounted for. Actual registration, finalization and product-access/replay paths run with this fake. The locked calls no longer add a provider request, and current-authority revocation controls continue passing.

Executed directly in Account with synthetic HTTP fakes and SQLite `:memory:`:

`DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array QUEUE_CONNECTION=sync ./vendor/bin/pest tests/Feature/Integration/RegistrationActorAuthorizationTest.php tests/Feature/CompanyUsers/RegistrationTest.php tests/Feature/CompanyUsers/RegistrationMappingTest.php tests/Feature/CompanyUsers/PrReviewRecoveryTest.php`

Result: exit 0; **97 passed, 621 assertions**. These include exact-evidence/nonce controls, ordinary registration/finalization, product removal replay and principal/company/capability/actor revocation before locked rearming.

Required outcome achieved: provider membership reads occur outside Action-owned locked transactions while local authority/proof/nonce/exact-identity checks remain. CR-R01 may be closed by the orchestrator after consolidation. No production or test files were edited by this reviewer. Full executable QA and other delivery gates remain separate.
