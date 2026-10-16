# Implementation verification handoff

Run `20261014-workos-initial-migration`; scope `workos-initial-migration-v1`. Worker implementation is ready for the independent gates. This is not delivery approval or an independent review verdict.

## Implementation

Account has a thin `workos:import-people {company?} --actor=<holding WorkOS user ID> [--apply]` command; omitted apply is read-only preview. The import Action reads tenant-scoped membership/invitation pages and referenced users, rejects malformed/repeated/foreign evidence, matches exact identities, creates durable immutable requests, calls the separate Compliance migration endpoint and validates receipts. Unknown requests reuse their operation/hash; existing effective access/entitlements are preserved and new projections remain conservative. The command reports company counts and individual source outcomes. No real operational migration was run.

Compliance has a separately capability-scoped PUT migration route, validated request, thin controller and exact-match transactional Action. It preserves existing local IDs/roles/status/first/last access/training evidence and existing product projection versions, rejects email-only collisions and immutable link conflicts, and creates only safely invited genuine absences. It leaves normal invitation/provision/adoption flows unchanged.

Permanent Account sync uses company/member Policies and the new atomic permission with view prerequisite. The permission migration explicitly grants active Company Administrator profiles. Sync only refreshes known invitation observations/history and compatible identity proof; effective access, entitlements, product links, roles and first/last access are protected. UI includes localized loading, empty/completed/partial/error outcomes, a disabled trigger, retained list and restored keyboard focus.

## Intended failure evidence

Before receiver implementation, `tests/Feature/AccountPeopleMigrationTest.php` failed all3 initial scenarios with HTTP404. After implementation these reached persisted-state assertions. Before Account implementation, the two initial tests failed with missing `workos:import-people` and missing `SyncCompanyInvitations`; both passed after implementation. Initial sandboxed Pest runs also encountered the test plugin's local socket permission restriction; rerunning with authorized socket access resolved that environmental failure. Actual browser verification caught focus loss when the native button disabled; completion now restores focus, and the original expectation passes.

## Deterministic checks

Explicit testing environment used synthetic APP_KEY and normal isolated SQLite tests. WorkOS/product test boundaries prevent stray requests.

- Account new scope: the four new feature test files plus `tests/Browser/CompanyInvitationSyncTest.php`,19passed/127assertions. Final execution after last import evidence guard and fixture controls passed in2.48s.
- Compliance `tests/Feature/AccountPeopleMigrationTest.php`:5passed/39assertions, including an existing completed materialized training assignment, existing role, suspended status and first/last access preservation.
- Compliance migration plus existing `tests/Feature/ControlPlane/ProductOperationTest.php`:10passed/93assertions before the final two history-preservation assertions were added; the changed receiver scope was rerun afterward.
- Account `composer verify`: backend206passed/1190assertions,4skipped; frontend build passed. Composer terminated the existing full browser suite at its default300second wrapper timeout, without a terminal browser verdict. A subsequent direct full browser run was cancelled with SIGINT/exit130 at the orchestrator's instruction because the separately authorized navigation follow-up will own the next full canonical run. Neither is presented as a browser pass.
- Compliance `composer verify`:1076passed,17skipped,1failed/6306assertions. The sole failure is unchanged `tests/Feature/Auth/TenantFirstAccessTest.php:122` resolving missing `App\Actions\Platform\CreateCompany`. Baseline commit `2b4ecfa26a3d2bf86679ffc181e75ca3868fbc5c` also lacks that file and the test has no diff. No out-of-scope repair was made. Build and full Pint checks were run separately because canonical verification stopped before them; both passed.
- Account full `pint --test` reports only unchanged `tests/Feature/Companies/CompanyNavigationTest.php` array indentation. That file has zero diff against baseline `221d3888cfea2b7487bdc357d1c61397c63cbc7e`. Approved PHP paths were formatted; a remaining new-client adjustment was applied and passed. No formatting of the baseline test was performed.
- Both scopes pass `git diff --check`.

Logs remain in `/private/tmp/oceanx-workos-migration-qa/`: `account-verify.log`, `compliance-verify.log`, `account-focused-final.log`, `account-browser-full.log`, `account-pint-final.log`, `compliance-build.log`, `compliance-pint.log`. Build used bundled Node24.19 on PATH. No environment file or operational configuration was changed.

## Disposable interoperability and concurrency evidence

The concrete isolated setup is documented neutrally in `qa-fixture.md`. It uses separate PostgreSQL17.11 databases at loopback55447, fake read-only WorkOS and the actual Account client→Compliance HTTP route/request/middleware/Action/receipt path at18102. Account UI is at18101. Temporary wrappers guard exact app environment/host/port/database/current_database before operations.

Synthetic North preview produced4ready/1 intentional email conflict; apply produced4confirmed/1conflict. Existing Compliance IDs501/502/503/701 were linked without replacing them; synthetic collision504 remains separate. Provider log contains method/path evidence. HTTP UI login returned200 with the real sync control; unauthenticated migration PUT returned401.

The PostgreSQL concurrency probe held the actual Account company lock and observed2 independent import processes waiting on locks before release. The new synthetic race identity produced exactly1 durable item,1 shared identity,1 link and confirmed state; the real Compliance receiver contains1 matching user and1 receipt, with Invited status. Both command processes exit1 because the separately intentional collision source remains incomplete.

The guarded fixture middleware then dropped one successful actual Compliance HTTP response after receiver commit and before Account receipt application. Snapshot evidence showed Account item unknown with1 real remote receipt. Explicit retry confirmed the same operation ID and hash. Files: `lost-response.json`, `account-after-lost.json`, `compliance-after-lost.json`, `account-after-recovery.json`, `compliance-after-recovery.json`, `lost-response-first.log`, `lost-response-retry.log`. These are synthetic records only; tokens remain in a mode0600 private fixture file and are not in reports.

The fixture-only provider supports a private hold/release marker with a25second timeout for independent actual UI loading observation. Its marker entry/release path was probed. No production delay, route or provider mutation is introduced. Current provider mode is normal. Servers remain running for independent QA. The handoff contains reset and all seven frozen scenario controls without predeclaring a QA verdict.

## Remaining gates

Orchestrator owns the separately authorized Switch company presentation follow-up, final combined implementation checkpoint, independent complete Code Review/Test Analyst checkpoint, executable seven-scenario QA, architecture conformance and final Toscanini gates. Canonical baseline failures and wrapper timeout are reported honestly; they are not converted into approval. No reviewer, QA or final completion result is claimed by this Worker.
