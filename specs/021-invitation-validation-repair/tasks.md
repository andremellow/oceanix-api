# Approved bounded implementation tasks

Scope invitation-validation-repair-v1; architecture SHA-256 890bf44ce261d0747087f72f295acc071548da062480c6bdff06b3f1ca2889b5. Only architecture-paths.json production/test files may change.

- [x] T01 QA-R01/R06: causal authorization tests in Account RegistrationActorAuthorizationTest and CompanyInvitationSyncAuthorizationTest; proof-first exact fresh membership across ordinary kinds, preserving exceptions and no writes.
- [x] T02 QA-R02/R04: causal exact mapping tests in both ExistingPersonMapping tests and AccountAdoptionTest; additive typed Account baseline and guarded reciprocal initialization for both origins, preserving identity conflicts.
- [x] T03 QA-R02/R03: immutable versioned mapping recovery and refresh before new reconciliation envelope; AccountOwnedInvitationCutoverTest and InvitationObservationTest cover historical receipt, retry and ordered first observation.
- [x] T04 QA-R05: Account directory/list/detail no-email preaction explanation and absolute/relative retained successful verification time; CompanyInvitationSync feature/browser tests.
- [x] T05 QA-R01–R06: adjust exact membership fakes only in approved existing fixtures/tests, run focused tests and scoped formatting. Record causal red/green evidence.
- [x] T06 QA-R01–R06: hand stable implementation to parent for canonical verification, independent complete review, executable QA, design and conformance. No claim of completion before actual gates.

## Initial implementation evidence

- Account focused approved feature set: 156 passed, 1094 assertions; 3 PostgreSQL-only tests skipped on SQLite.
- Compliance focused approved feature set: 27 passed, 229 assertions; 1 PostgreSQL-only test skipped on SQLite.
- Scoped Pint completed in both repositories. Full canonical verification remains parent-coordinated.
- Causal red evidence: original pending-cache authorization returned403; untouched imported exact-user mapping returned409. Temporarily removing additive receipt metadata caused2 expected failures, then source was restored and focused checks passed.
- Baseline tests cover historical deterministic mapping identity preservation, lost acknowledgement receipt recovery, typed generation denial, competing observation preservation, fresh envelope pointer and initialized ordering conflicts.
- UI feature evidence proves preaction copy and retained absolute/relative successful time beside error. Browser assertions added; execution awaits canonical/browser gate.
- No normal database/provider mutation, .env change, verifier change, commit or staging performed.

### Initial deterministic fixture correction

Account canonical initially exposed one newly required exact actor-membership read in unchanged MemberLifecycleTest. No production defect or new scope: approved ProductIntegrationFixture now gives only the named synthetic source actor an exact GET-only independently active provider response. Deliberate negative provider tests replace that fixture before any tested request; no recordings are reset after an assertion target runs. Only approved fixture and RegistrationActorAuthorizationTest changed. Affected tests:37 passed183 assertions; scoped Pint pass. Parent reruns Account canonical; Compliance implementation/checkpoint unchanged.

### Initial Compliance deterministic correction

Five recovery regressions traced to applying the reconciliation-only baseline prerequisite to already-linked send intents. Existing links now retain their original path for source kinds other than person.invitation.reconcile; reconciliation retains the exact uninitialized tuple/binding/typed-metadata checks. WorkosInvitationRecoveryTest plus approved focused Compliance tests:46 passed352 assertions; one PostgreSQL-only skip, scoped Pint pass. Five remaining WorkosInvitationSyncTest failures use bare synthetic links with neither a known verification baseline nor the binding/mapping receipts required for initialization; no unapproved test path was edited. Parent owns the explicit one-file test-scope amendment decision.

### Owner-approved test-scope-amendment-01

Owner explicitly authorized the existing WorkosInvitationSyncTest fixture correction. The local helper prepares a historical known verification baseline only when absent; all pre-existing assertions, specific retained timestamps, permission denials, operation counts and immutable request checks remain unchanged. No production guard relaxed. WorkosInvitationSyncTest, WorkosInvitationRecoveryTest and approved focused tests:70 passed451 assertions, one PostgreSQL-only skip; scoped Pint pass. Source/tests frozen for parent Compliance canonical rerun. Account canonical passed at its unchanged source delta, per parent actual result.

### Initial browser fixture correction

Actual canonical browser execution exposed the same unknown-baseline precondition in the existing TA-06 polling cohort: the synchronization actor and empty-invitation peer had bare links, while the populated target already had retained verification evidence. In the already-approved PeopleAccountAccessTest path, those two existing known-baseline fixtures now have explicit historical verification timestamps. All3target/count/loading/status/partial/focus/narrow-layout assertions remain unchanged. Targeted real browser execution:4 passed86 assertions, exit0; scoped Pint pass. Source/tests frozen for final parent checkpoint and canonical coordination.

## Consolidated remediation round1 — CR-R01, TA-R01, TA-R02

- CR-R01: exact successful proof/provider evidence belongs to a request-owned WeakMap keyed by the immutable envelope object and principal/full signed-proof hash. Only consumeNonce:true performs fresh provider reads. Existing locked consumeNonce:false calls require the matching successful evidence and still validate current proof/time/consumed nonce, principal, company, module binding, member removal/enable/provider identity; missing/swapped/crossrequest evidence fails closed. No caller edit or persistent/global provider cache. Actual registration/finalization/product-access tests assert provider GET transaction level equals the outer test baseline. Causal red:13 failed when the old unconditional read entered the Action transaction (level2 versus baseline1); corrected Actions pass.
- TA-R01: authenticated mapping and observation receivers each retain positive controls and reject initialized null pointers at generation0 with independent sequence/hash/verification guard cases and a real observed-none case. Incoming observation sequence99 reaches the changed predicate. Nonempty person/link/receipt/access/role/assignment/certificate records are compared byte-for-byte after conflict. Sensitivity: temporarily reducing both predicates to pointer-null made all8cases fail; production was restored immediately.
- TA-R02: exact membership GET callback changes persisted principal state, organization identity or actual module-principal binding while otherwise returning valid active evidence. Each is denied by the real authorizer after exactly1GET; positive control succeeds, cached member activation remains unchanged, no downstream operation/user is created. Existing invalid-proof zero-call cases remain. Sensitivity: replacing corresponding post-read fresh queries with stale models produced3failures while positive passed; source restored immediately.
- Directed proof/evidence controls also cover local locked success without a second GET; false-without-prior, swapped nonce, copied envelope, different request, nonce replay and current principal revocation fail closed.
- Final focused Account:136passed1191assertions. Final focused Compliance:51passed284assertions, one existing opt-in PostgreSQL skip. Scoped Pint passed. One existing receipt-recovery request-count assertion changes3to2 to reflect removal of the redundant locked GET while retaining no-PUT behavior.
- Remediation source/tests frozen; parent owns next immutable checkpoint and canonical verification. No completion or independent finding closure is inferred from Worker evidence.

## Final completion evidence

Both actual final gates exited0 after owner-approved gate-amendment02. Application checkpoint67670919374299c4 remains unchanged; separate tool checkpoint gate-directed-order-db2f936ade34ccda passed7controls/19automatedCLIhistories and12independentCLIprobes. Original6QA scenarios, design and full conformance retained; real directed conformance impact check recorded. Final budgets16/17 specialiststarts and2/2 remediationrounds. See reviews/execution-report.md and final-gates.json.
