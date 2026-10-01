# Isolated QA operator guide

This is fixture/control documentation, not independent QA execution evidence. Frozen matrix remains QA-001–018. Use the actual rendered People/detail/assignment/platform screens and actual action/job boundaries below. Never run a production provider, real email or another database.

## Safety and launch

Server is currently available at `http://127.0.0.1:8768`; executable router is `tests/Support/InvitationQa/router.php`. It refuses startup unless testing environment, explicit isolated flag and exact disposable SQLite path all agree. It installs Laravel Http::fake and prevents stray HTTP requests for every request; provider mutations only change `/private/tmp/oceanix-invite-provider.json`. No .env is needed or created.

If restarting, create the empty disposable SQLite file and launch from the worktree:

```sh
APP_ENV=testing OCEANIX_INVITATION_QA=isolated DB_CONNECTION=sqlite DB_DATABASE=/private/tmp/oceanix-invite-qa.sqlite SESSION_DRIVER=file APP_KEY=base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY= php -S 127.0.0.1:8768 -t public tests/Support/InvitationQa/router.php
```

`GET /qa/reset` explicitly resets ONLY that disposable database, seeds representative distinct names/statuses, two isolated companies, published course/requirement and materialized evidence. Reset between scenario groups that need clean preconditions. `GET /qa/state` returns allowlisted person, run, attempt, assignment, audit and fake-provider request evidence. Save before/after evidence yourself; fixture output is not a verdict.

`GET /qa/login/QA%20Admin` establishes a fixture administrator session and opens the real People screen. Other fixture actors: `QA Sync Operator` (sync + its PeopleView prerequisite), `QA Invite Operator` (invite + prerequisite), `QA Denied Operator` (view only). Login fixture itself is not successful tenant-access evidence; actual callbacks/entry below prove access.

Click Synchronize on the real UI: queued state remains visible until `GET /qa/drain` executes actual scoped Jobs/Actions. Refresh/wait for polling to observe terminal state. The drain response reports context restoration. Invitations similarly remain queued until drain. Inspect fake-provider request records to distinguish GET from POST. The queue database is disposable; drain consumes actual database queue payloads through the Laravel Worker and reports the number of queued jobs processed; provider requests remain fake.

`GET /qa/control` changes provider fixture conditions: `failure=429`, `failure=503`, `failure=timeout`, or empty to restore; `malformed=yes|no`; `race=login|invitation` (one-shot Race Recipient read interleaving); `uncertain=yes` or empty. Controls never directly declare product results.

For direct-action denial and representative blocked selections, POST `/qa/queue-sync` or `/qa/queue-invitations` with the current browser CSRF token, IDs from state and JSON `{ "ids": [id], "all": false }`. These test-only transport seams call the actual authorized production Actions; no permission bypass occurs. Use real UI for ordinary authorized actions and interactions.

## Frozen scenarios

| ID | Fixture/action and evidence to observe |
| --- | --- |
| QA-001 | Synchronize as QA Admin with malformed=no. Pending/Accepted/Expired/Revoked Recipient retain distinct state pills. Drain; inspect state and provider requests: GET only; local invited status/access unchanged. |
| QA-002 | No Invitation Recipient has accepted-old plus expired-new invitations across pages, plus foreign lookalike. Synchronize; after cursor qa-next appears, newest valid current ID inv_discovered chosen and accepted_history retained. Open detail and observe distinct timestamps. |
| QA-003 | Same discovery fixture contains org_other lookalike newer than valid current; sync only invitation-qa. Verify selected ID belongs to org_qa; other company's Switch Target unchanged. |
| QA-004 | Deleted Invitation Recipient has stored missing ID; Malformed Recipient returns unsupported state while malformed=yes. Sync; deleted becomes Unverified, malformed check failure/unverified presentation; no Pending claim or local activation. Repair malformed=no and retry via sync UI. |
| QA-005 | Failure Recipient starts with verified Pending yesterday. Set failure=429/503/timeout individually, sync+drain; observe Partial failure, preserved old verification and Latest check failed, successful controls, bounded three failed GETs. Clear failure and retry; completed sync time advances only after all checks succeed (set malformed=no). |
| QA-006 | External Signin Recipient has validated WorkOS identity with global last-sign-in but no local access. Sync, open detail: Last WorkOS sign-in shown separately; First/Last Oceanix access remain absent; status remains invited. |
| QA-007 | Compare Sync Operator, Denied Operator and Admin controls/direct POST. Sync prerequisite grants People screen. Queue as Sync Operator, revoke through /qa/revoke/QA%20Sync%20Operator, then direct action/hydration and drain: deny/permission-revoked; no cross-company write. |
| QA-008 | Set race=login, sync+drain: Race Recipient's first access and active status preserved. Reset and set race=invitation: newer inv_newer_race/generation survive; superseded snapshot skipped. Drain reports context restoration. Repeat isolated PostgreSQL probe below to verify actual row locking. |
| QA-009 | Select Pending and Expired Recipient on real UI, confirm recovery, drain. Request trail: pending /resend POST; expired /invitations POST. Inspect new current ID, sent server time, successful attempt and audit. |
| QA-010 | Select/direct-submit Accepted, Member, Suspended, Terminated plus positive Pending recipient. Drain: accepted/current-member/suspended/terminated skipped with reasons, positive sends once. Member's old expired invitation cannot trigger email. UI disables known blocked selection. |
| QA-011 | Default all-pending modal explicitly says company scope independent of filters. Confirm/drain with malformed repaired. Observe accepted/revoked/blocked excluded, fresh GET checks; explicitly select Revoked and confirm warning: new invitation POST. Provider-unverified/deleted recipient cannot send without valid discovery. |
| QA-012 | Queue same selected IDs twice before drain; one open intent. As Invite Operator queue, revoke then drain: permission-revoked and no POST. Separately set uncertain=yes and recover positive recipient: delivery-unconfirmed, retry drain yields no second POST. Explicit new operator intent remains a new decision. Check outcome audit and context restoration. |
| QA-013 | /qa/callback/Callback%20Recipient drives real signed WorkOS callback/session with fake identity. Inspect active and first/last server access. Repeat callback after time passes: first preserved, last advances. |
| QA-014 | Real callbacks for Suspended Recipient, Terminated Recipient, Inactive Account Recipient and Invalid Identity Recipient deny, preserve protected status and null access timestamps. Callback Recipient is the positive control. |
| QA-015 | /qa/platform-login establishes authorized global session; use real Platform company screen to enter Invitation QA Company as Platform Entry Recipient. Entry activates/records tenant evidence. Callback Recipient then /qa/switch-form submits actual company.switch endpoint to invited Switch Target in other company. Reset then /qa/block-switch?status=suspended or terminated: actual switch denied without access evidence. |
| QA-016 | Capture state/assignments/events. /qa/transition runs actual approved legacy migration: Legacy Active/other active no-evidence becomes invited; suspended/terminated stay; roles/assignments/events retained. New admin provisioning uses existing platform UI with fixture POST response/membership support; import real UI may use /private/tmp/oceanix-invite-import.xlsx (download/regenerate via /qa/import-fixture); new people invited, no fabricated dates. |
| QA-017 | Seeded targeted requirement assignments cover invited/active/suspended/terminated recipients, same published version. Use actual Assignments screen to manually assign QA Published Safety Course to each status; query state for frozen version/event evidence. /qa/materialize repeats actual materialization: no duplicate existing obligation, untargeted Pagination people excluded. |
| QA-018 | Real directory combine search, department QA Operations, function QA Offshore technicians, person/invitation/no-access filters; compare table/count/state, empty and clear filters, second page, selection cleared on page/filter changes, long names, mobile320px, keyboard checkbox/modal Escape/cancel/focus, loading/queued/failed/terminal polling. Headings Open training/Overdue training. Save old assignments/state; /qa/complete-cycle prepares satisfied historical cycles, then /qa/materialize twice: one renewal each, preserved old IDs/version/history. |

## PostgreSQL lock probe

`tests/Support/InvitationQa/postgres-probe.php` refuses any database except `oceanix_invitation_probe` on the isolated UNIX socket. It runs two real competing processes against production QueueWorkosInvitations under a person row lock; expected output describes blocked workers and one open attempt. The temporary cluster is currently running; setup/reset affects this disposable probe DB only.

```sh
APP_ENV=testing OCEANIX_INVITATION_QA=isolated DB_CONNECTION=pgsql DB_HOST=/private/tmp/oceanix-invite-pg DB_PORT=15433 DB_DATABASE=oceanix_invitation_probe DB_USERNAME=oceanix_qa APP_KEY=base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY= php tests/Support/InvitationQa/postgres-probe.php
```

Independent QA must collect its own observations and list every completed QA ID; no partial run replaces the full matrix.
