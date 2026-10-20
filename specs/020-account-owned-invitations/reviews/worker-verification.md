# Worker implementation evidence — Account-owned invitations

Scope: `account-owned-invitations-v1`. Approved architecture SHA-256: `490eb8c361fc7cbdb60f3ae8d0b33843fb25c1c140a243df5e2a9f8294310ecb`. Worker round 1. This is implementation evidence, not an independent review or executable QA approval.

## Boundary and preservation

One Worker edited production/tests across Account and Compliance. The frozen inventory remains the mutation boundary. Account `config/services.php` was not edited; the rejected amendment was not implemented. The default-off Account readiness flag uses the already approved ProductModule registry and additive migration, as clarified in architecture-clarification-01.md. Platform sequencing follows architecture-clarification-02.md. Neither environment files nor production/provider systems were modified.

The parent recorded baseline HEAD and dirty path/hash inventories before dispatch. The Worker preserved the pre-existing Compliance translation content separately before appending feature translations. Unrelated tooling, source, documentation and approved planning artifacts remain outside the implementation commit. Feature formatting was restricted to explicit approved PHP paths.

## Automated evidence

All external calls in automated tests are fake. Private paired HTTP execution remains the independent QA gate's responsibility.

| Boundary | Actual result |
| --- | --- |
| Account full frozen feature-file sweep before last expectation corrections | 171 passed; 2 failed; 2 optional PostgreSQL checks skipped under SQLite. The two failures were obsolete Communications expectations: invitation-only status intentionally hides access labels; reconciliation is now a supported retry kind. |
| Account affected Communications suites after corrections | 26 passed, 201 assertions. Existing unsupported kinds and direct authorization denials retained. |
| Compliance full frozen feature-file sweep before fixture correction | 169 passed; 1 failed. New platform invitation replay fixture lacked AccountCompanyBinding. |
| Compliance platform causal suite after fixture correction | 5 passed, 22 assertions. Null-to-known identity retains original immutable membership envelope; lost invitation acknowledgement recovers original intent despite changed generation; revoked platform authority blocks role/audit after membership confirmation. |
| Compliance platform regression suite plus causal suite before last new replay case | 36 passed, 174 assertions. |
| Account invitation observation/delivery plus new platform ensure case | 3 passed, 25 assertions. Ensure confirms identity and pending membership, exact ProductPersonLink, and no invitation/projection target; repeated same operation does not create another provider identity. |
| Account import plus diagnostics | 57 passed / 436 assertions and 33 passed / 249 assertions. Local-only preview/apply and legacy immutable evidence included. |
| Compliance restored invitation synchronization cases | 23 passed, 92 assertions. See coverage reconciliation below. |
| Account existing synchronization browser cases | 5 passed, 97 assertions. Queued acknowledgement is observed before draining workers. |
| Account exhausted-operation manual retry browser cases | 2 passed, 20 assertions, narrow and wide viewports. |
| Account Communications browser suite | 6 passed, 156 assertions. |
| Compliance both approved people browser suites | 6 passed, 103 assertions. |

The canonical verification pair is separately run by the parent after source/tests stabilize. These focused results do not claim canonical success; the parent records its actual result and immutable checkpoint before independent gates.

## Real PostgreSQL SC-19 execution

The Worker actually executed the two Account concurrency tests on the disposable PostgreSQL test database: **2 passed, 15 assertions**. One launches a separate PHP process competing for the company reservation while the first process holds the company lock; both converge to the same durable operation and one row. The other replaces a lease owner through a separate PDO connection during a provider read; the stale worker cannot overwrite the new claim or advance observation sequence.

The Worker also executed Compliance snapshot ordering, replay and atomic receipt rollback on its disposable PostgreSQL test database: **2 passed, 12 assertions**. These are real PostgreSQL persistence checks; the ordering test is sequential out-of-order delivery, not a claim of two concurrent Compliance HTTP workers. The Account tests provide the genuine lock/lease interleaving. Independent paired HTTP QA still exercises the frozen QA-19 runtime scenario.

Only `_test` databases were refreshed. Live paired QA data and the parent's nonempty completed training/event evidence were preserved. Exact private identity guards reject another database/socket/environment. Logs are private; no tokens, provider fixture records or connection JSON are copied into this artifact.

## Regression sensitivity and synchronization coverage reconciliation

The no-UUID-list acceptance tests failed with 403 before the authorization change and passed after it. Local import tests retained source inventory validation, pagination failure/repeated cursor, provenance, explicit denial and immutable legacy input/receipt assertions while replacing remote migration effects with zero-product-request assertions. Queue/delivery tests exercise failure, retained confirmed reads, bounded exhaustion, manual retry and unchanged snapshot UUID/hash. Browser tests operate the actual existing retry control after exhaustion.

The initial Compliance sync test rewrite was self-audited against its original assertions. The resulting suite covers all supported invitation states plus `none` under both prior membership booleans, preserving independent local status, roles, membership and first access. It includes network/malformed receipt preservation, positive then revoked actor control, queued run status, terminal exhaustion, a newer invitation/access write interleaved with an Account result, malformed state/date/type/provider identity/organization/invitation identity rejection, and real new-format job tenant-context restoration. The separate receiver suite proves atomic receipt/projection rollback and retained accepted history; the Account invitation suite retains accepted-history/provider-identity checks.

Original Compliance tests that traversed provider population pages, selected arbitrary email inventory, or refreshed external sign-in directly from WorkOS cannot continue to expect those operational calls: that behavior is expressly removed by FR-006 and FR-009. Their applicable outcomes move to exact Account reads, Account import pagination/source validation, receiver identity/order checks and unchanged AuthKit regression tests. This is a boundary migration, not evidence that the removed direct-provider traversal still executes. No claim is made that planned QA or unexecuted canonical checks have passed.

## Runtime fixture handoff

Both approved `tests/Support/AccountOwnedInvitationFixture.php` helpers expose `bootHttpQa()` and `snapshotHttpQa()` guarded by exact private PostgreSQL identity. Account exposes seed/connect methods and fake provider state/page/fault controls. Compliance exposes `seedHttpQa(array $account)` and `seedPlatformHttpQa()`; the latter returns only the synthetic platform actor ID/WorkOS ID. `/__invitation-qa/platform-login` establishes a platform session and opens the actual platform companies screen. Existing grant/invite controls then exercise the real staged handshake through the paired Account API. No mocked receipt is claimed as runtime QA-23 proof.

Independent QA, Code Review, Test Analyst, Design Review and final architecture conformance are **NOT_RUN by this Worker**. Final delivery remains gated by their actual evidence and the canonical exit checks.

## Author verification update before independent gates

The first canonical pair found existing tests outside the original inventory: Account 411 passed / 6 skipped / 3 failed (2664 assertions), Compliance 1128 passed / 17 skipped / 3 failed (6711 assertions). Both stopped before build/browser. They are not recorded as passing canonical checks. The owner approved the two Account test-only paths in amendment 01; the supplemental Compliance proposal is pending. The exact proposed fixture corrections preserve original datasets and success/denial/evidence assertions.

A private process-only observation of the Compliance import result proved `created=1, existing=0, pending=1`: the new confirmed-first registration succeeds; the protected fixture without any stable provider/invitation identity cannot map by email. No registration production defect was inferred from the chained assertion's line number.

A final in-scope platform status guard prevents pending/unconfirmed Account work from being presented as a completed no-send result. The causal test fails when that exact guard is removed and passes with the exact source restored. Both affected platform files then pass **38 tests, 185 assertions**. Fault tests reset the HTTP factory before installing their fault callback, avoiding overlapping fake callback side effects. Source/helper writes stop while the parent's remaining build/browser stages execute.

## Canonical browser entrypoint inclusion

Actual command: `php vendor/bin/pest tests/Browser/PeopleAccountAccessTest.php --compact` with the prepared Node runtime on PATH. Result after moving the invitation cases intact into this existing canonical entrypoint: **6 passed, 103 assertions**. This means the original 3 executions / 76 assertions plus 3 invitation executions / 27 assertions. It is not nine cases. The original account-access positive/recovery cases remain, including both locale/width registration datasets. The added cases exercise invitation filters and mobile/desktop layout, checkbox selection after filter morphing, and cancellable one-person recovery confirmation. Composer's explicit existing entrypoint now runs them; no composer/dependency path was added. PeopleInvitationBrowserTest.php points readers to the canonical file rather than registering duplicate cases.

## Terminal author-verification status — waiting for owner approval

Account's final visible canonical verification **PASSED, exit 0**: 414 feature/unit tests passed, 6 optional PostgreSQL checks skipped in that canonical environment, 2670 assertions; 43 browser tests passed, 658 assertions; build and Pint passed. The separately executed real PostgreSQL feature checks above remain actual evidence and are not converted into canonical executions. Earlier failed attempts remain preserved by the parent.

Compliance build and full existing canonical browser stages passed; after canonical-entrypoint inclusion, the affected explicit people browser stage passed 6/103 as recorded above. Compliance's complete canonical verification still fails on the two existing out-of-inventory test files described in supplemental proposal v2. Their narrow fixture adaptations require an explicit owner decision. They have not been edited. No passing full Compliance canonical claim is made.

Worker terminal state: **BLOCKED — waiting for owner approval of exactly the two Compliance test paths**. Current implementation, approved Account test amendment and all evidence are retained. No final immutable implementation checkpoint, scoped source commit, independent gate approval, PR, deployment or completion claim is issued at this waiting point. Work resumes when the owner decision arrives; the parent then coordinates remaining verification and independent gates.

## Resumed after test amendment 02 approval

The owner explicitly approved both supplemental Compliance paths. Resumed Worker started events were recorded in both repositories while preserving earlier blocked events. The approved fixture adaptations pass both complete files: **13 tests, 109 assertions** after Pint. The import retains all original result/status counts; the migration binding-denial now has a valid ready200/no-write positive control and exact existing409 for the isolated binding fault. Detailed evidence: reviews/test-amendment-02-implementation.md. Source/tests are frozen for the parent-run final Compliance visible canonical check. No final checkpoint is claimed before that result.

## Final implementation checkpoint

Both visible canonical verifications passed. Account: 414 feature/unit tests passed, 6 optional PostgreSQL skips, 2670 assertions; 43 browser cases / 658 assertions; build and Pint passed. Compliance: 1132 feature/unit tests passed, 17 optional environment skips, 6731 assertions; editor JavaScript 30; PDF browser JavaScript 4; Pest browser groups 79/811, 40/800, 2/10, 4/48, 6/103; preview JavaScript 25; build and Pint passed. Compliance's final Pint corrected only whitespace in WorkosInvitationSyncTest; final formatted bytes are in the source commit.

Frozen pair checkpoint: `account-owned-invitations-v1-3734b6699a921fa4`. Account commit `90ef69866cfd37729d45db15986df86d305f4bd9` (57 approved paths); Compliance commit `20e707ff5b8c2861829808ff88fca7701c516578` (49 approved paths). Exact per-repository diff bases, full raw changed-path inventories and raw diff SHA-256 values are recorded in `implementation-checkpoint.json`. Account branch was created from origin/main only after proving its committed tree identical to captured baseline; every captured dirty file remained byte-identical through the switch.

Commits include only approved implementation source/tests/runbook paths. Specification/governance artifacts remain outside these commits. Compliance's pre-existing Select-all translation relocation is preserved unstaged; only Worker-added translation tail hunks were staged. No push, PR, deployment, independent review approval or final delivery claim was made by this Worker.

Worker implementation stage is complete at this checkpoint. Independent Code Review/Test Analyst, executable QA, Design Review, architecture conformance and final delivery gates remain the parent's responsibility and are not inferred from automated checks. T024's actual paired database-queue runtime confirmation is explicitly retained for QA rather than being claimed from fake queues.
