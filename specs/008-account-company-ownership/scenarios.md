# Behavior scenarios and test evidence

## Coverage examination

| Dimension | Rules | Evidence | Status |
|---|---|---|---|
| Happy/alternative paths | FR-001–005 | SC-01,02,05,06 | Planned |
| Roles/permissions/isolation | FR-002,004 | SC-03,08 | Planned; existing grants unchanged |
| Data/boundaries/invalid identity | FR-003,004 | SC-06,07,08 | Planned; no name/slug heuristic |
| Lifecycle | FR-002,003 | SC-03,04,05 | Planned; disable lifecycle excluded |
| Empty/loading/error | FR-005 | SC-01 includes empty list and long name; removed forms have no loading/error state | Planned |
| External failure/recovery | FR-004 | SC-07; existing Account enable failure/retry tests | Planned |
| Retry/ordering/concurrency/time | FR-004 | SC-07 existing operation semantics; no protocol/lock changes | No additional time/concurrency redesign |
| Affected existing behavior | FR-005 | SC-01,02,05–08 | Bounded to company entry points |

## Concrete examples

| ID | Rules | Preconditions | Action | Expected outcome | Forbidden side effects |
|---|---|---|---|---|---|
| SC-01 | FR-002 FR-005 | Platform administrator; populated list with linked and unlinked companies | Open company list at desktop and mobile widths | No create form or organization actions; readable Account guidance; company list/details/entry available | No writes or provider requests |
| SC-02 | FR-002 FR-005 | Platform administrator; existing company detail | Open detail screen and navigate back | No organization provision/sync action; existing unrelated controls preserved | No writes or provider requests |
| SC-03 | FR-002 | Old list/detail component requests, authorized and unauthorized actor | Invoke create/provisionWorkos methods directly | Requests rejected server-side; no persisted side effects | No company/user/role/binding change; no provider request |
| SC-04 | FR-002 | Valid unused company name and slug | Run oceanix:create-company | Command fails with use-Account guidance | No company/user/profile/audit mutation; no provider request |
| SC-05 | FR-001 FR-003 | Authorized Account operator; fake WorkOS organization; no Compliance tenant | Create/import in Account, then Enable Compliance | Existing creation/import succeeds; enable creates and binds one tenant only after confirmation | No human grants, duplicate organization or tenant |
| SC-06 | FR-003 FR-004 | Existing Compliance tenant with matching WorkOS ID, user and assignment | Enable from Account | Existing public ID retained; binding created; records unchanged | No duplicate tenant or rewritten user/evidence |
| SC-07 | FR-004 | Completed/pending enable; same operation ID and conflicting identity/slug fixtures | Retry identical operation; submit conflicting identity | Existing replay succeeds without duplication; conflict fails without mutation | No false enabled confirmation, duplicate rows or identity reassignment |
| SC-08 | FR-004 | Missing/incorrect machine token; authorized positive control | Call provisioning endpoint | Unauthorized call rejected; authorized control still works | No unauthorized persistence/provider requests |

## Questions and decisions

User confirmed Account ownership and exclusion of other findings. Owner approved consolidated artifacts on 2026-09-29; see approval-record.md. Automated execution evidence follows; independent executable QA completed QA-01–08 with PASS; runtime qa-report.md and evidence.

## Test plan and execution evidence

| Scenarios | Boundary | Proposed test file | Assertions | Execution |
|---|---|---|---|---|
| SC-01 | Livewire feature + browser | tests/Feature/Platform/PlatformAdministrationTest.php; tests/Browser/AccountCompanyOwnershipTest.php | Outcome and prohibited side effects above; positive control for denial tests | PASS: focused Compliance suite (46 tests / 234 assertions) and browser suite (4 tests / 48 assertions); worker-green.log / worker-browser.log |
| SC-02 | Livewire feature + browser | tests/Feature/Platform/PlatformAdministrationTest.php; tests/Browser/AccountCompanyOwnershipTest.php | Outcome and prohibited side effects above; positive control for denial tests | PASS: focused Compliance suite (46 tests / 234 assertions) and browser suite (4 tests / 48 assertions); worker-green.log / worker-browser.log |
| SC-03 | Livewire feature | tests/Feature/Platform/PlatformAdministrationTest.php | Outcome and prohibited side effects above; positive control for denial tests | PASS: focused Compliance suite; worker-green.log; red sensitivity in worker-red.log |
| SC-04 | Artisan feature | tests/Feature/Tenancy/TenantIsolationTest.php | Outcome and prohibited side effects above; positive control for denial tests | PASS: focused Compliance suite; worker-green.log; red sensitivity in worker-red.log |
| SC-05 | Existing Account tests + receiver feature + isolated two-app QA | Account: tests/Feature/Companies; tests/Feature/Products; Compliance: tests/Feature/ControlPlane/ProvisionCompanyTest.php | Outcome and prohibited side effects above; positive control for denial tests | Automated PASS: receiver included in 46 tests / 234 assertions; unchanged Account create/import/enable 45 tests / 221 assertions. Independent QA PASS: corresponding QA-01–08 evidence in runtime qa-report.md. |
| SC-06 | Existing receiver feature + isolated API QA | tests/Feature/ControlPlane/ProvisionCompanyTest.php | Outcome and prohibited side effects above; positive control for denial tests | Automated PASS: receiver included in 46 tests / 234 assertions; unchanged Account create/import/enable 45 tests / 221 assertions. Independent QA PASS: corresponding QA-01–08 evidence in runtime qa-report.md. |
| SC-07 | Existing Account/receiver feature + isolated API QA | Account: tests/Feature/Products; Compliance: tests/Feature/ControlPlane/ProvisionCompanyTest.php | Outcome and prohibited side effects above; positive control for denial tests | Automated PASS: receiver included in 46 tests / 234 assertions; unchanged Account create/import/enable 45 tests / 221 assertions. Independent QA PASS: corresponding QA-01–08 evidence in runtime qa-report.md. |
| SC-08 | Existing receiver feature + isolated API QA | tests/Feature/ControlPlane/ProvisionCompanyTest.php | Outcome and prohibited side effects above; positive control for denial tests | Automated PASS: receiver included in 46 tests / 234 assertions; unchanged Account create/import/enable 45 tests / 221 assertions. Independent QA PASS: corresponding QA-01–08 evidence in runtime qa-report.md. |

## Automation exceptions
None proposed. Browser QA must exercise list/detail navigation and the isolated Account enable action; login/setup is navigation only, not an additional review scope.

## Executed checks (2026-09-29)
- SC-01–02: `keeps company inspection and entry while directing creation to Account`, empty-list feature check, and both new browser tests at 320/1440px. Browser list-to-detail uses keyboard Enter; back navigation and retained tenant entry execute in the browser.
- SC-03: six `rejects removed company methods without persistence or provider side effects` cases cover list create/list provision/detail provision, authorized and revoked stale sessions. Real Livewire MethodNotFoundException rejection; complete table snapshots for companies/users/roles/permissions/grants/bindings/receipts/audits; HTTP fake verifies no requests.
- SC-04: `rejects local company creation with Account guidance and no side effects` verifies failure status, guidance and unchanged persistence snapshots/provider calls.
- SC-05–08: existing receiver tests prove scoped machine-token creation/no-human-grants, adoption preserving published evidence, replay/conflict, unauthorized tokens and failures. Existing Account Companies suite and EnableComplianceTest prove creation/import and confirmed acknowledgement, timeout retry identity, malformed acknowledgement rejection and denial. These tests retain their original scenario IDs from earlier features.
- Test-first red: nine intended failures before production changes; final focused suite 46 checks / 234 assertions. Browser 4 checks / 48 assertions. Compliance absent-.env warnings are baseline and unchanged. Account 45 passed / 221 assertions. Raw logs are in `.toscanini/runtime/runs/20260929-account-company-ownership/worker-{red,green,browser,account-green,pint}.log`.
- No manual automation exception. Independent executable QA evidence remains the QA specialist's responsibility.
