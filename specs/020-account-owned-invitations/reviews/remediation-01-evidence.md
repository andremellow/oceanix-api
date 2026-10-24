# Remediation round 1 — Worker evidence

Scope: `account-owned-invitations-v1`. Correction delta starts at Account `90ef69866cfd37729d45db15986df86d305f4bd9` and Compliance `20e707ff5b8c2861829808ff88fca7701c516578`. Original immutable pair checkpoint: `account-owned-invitations-v1-3734b6699a921fa4`. This is implementation evidence for directed verification, not finding closure or independent approval.

## Candidate corrections and causal checks

| Finding | Correction / effective boundary |
|---|---|
| CR-01 | Account product-origin callbacks use the persisted actor identity and current remote authority without dereferencing an absent Account login user. Real callback HTTP tests cover tenant/platform actors, immutable source fields, revocation and holding provision/mapping sources; local authorization is not faked. |
| CR-02 | Each external UUID/principal retains its own immutable operation and status receipt. Reference targets share one canonical provider reconciliation. Both normal completion and manual alias retry exercise actual PUT/GET envelopes, altered UUID payload conflict, principal isolation and exactly one non-null provider invitation read. Manual retry resets only unconfirmed canonical delivery work after rechecking both authorities. |
| CR-03 | Legitimate queued recovery retains the original intent and accepts an already-applied exact observation. Missing receipt recovery resubmits the original persisted envelope under current authority; no new generation or UUID. Sending, requeued committed receipt and requeued missing receipt datasets execute real queue/action recovery. |
| CR-04 | New kinds expose persisted needs_attention explicitly; transient unknown with safe_error keeps polling. Compliance records terminal reason without destroying previous verified evidence. Tests advance transient unknown to later confirmed and separately exercise terminal exhaustion and manual retry. |
| CR-05 | Membership resume checks the persistent readiness flag before claiming or external work. Disabled execution leaves durable evidence unchanged; reenable completes once without invitation creation. |
| TA-01 | SystemPrincipalTest in both repositories exercises real local callbacks and proof validation, malformed/expired/reused proofs, actor/source/company/hash mismatches and revocation, with positive controls. |
| TA-02 | Exact invitation-only mapping and expired/revoked observations preserve people, access, roles and history. Duplicate invitation, occupied link, wrong organization/binding/local person and different stable identity fail closed while a valid peer succeeds. Same-UUID changed payload conflicts at both receivers. |
| TA-03 | Actual separate-process PostgreSQL receiver contention serializes an older generation against a committed replacement. Equal-pair hash conflict and same-generation invitation mismatch retain immutable receipts and final projection. |
| TA-04 | Five actual non-null invitation delivery failures cover 401, 404, malformed/mismatched receipt and response loss after receiver commit. Manual retry preserves target UUID/hash/body, one provider read and one application; lost acknowledgement recovers the committed receipt. |
| TA-05 | Real recovery command selects due/expired leases and excludes live/future/confirmed/terminal work; serialized scalar jobs execute. PostgreSQL reservation test commits work before recovery command/job execution and proves confirmed provider-read evidence, explicit unknown awaiting deliberately blocked projection, and no WorkOS call. Compliance real quarantine preview/apply/repeat preserves markers/history and emits no job/HTTP. |
| TA-06 | Six canonical browser cases interact with changed sync/poll controls at desktop/mobile sizes: confirmed/partial results and mid-run revocation. They prove disabled/enabled controls, current authority, pending polling, preserved search/focus, polite live status, terminal reason, no-invitation peer and retained verified evidence. |
| TA-07 | Actual platform actions exercise ambiguous identity/membership writes, known membership, occupied links, malformed receipt and local link rollback. Separate-process PostgreSQL workers contend on one platform operation and produce one identity/membership mutation pair, one link, no invitation. Compliance grants no role/audit before valid reciprocal confirmation. |
| TA-08 | Later local import write failure occurs after partial writes while an earlier source is committed. Failing-source writes roll back, old immutable unknown evidence remains, and two restarts preserve the first source and produce exactly one baseline per source with no product/provider mutation. |

## Executed focused verification

After explicit-path scoped Pint, complete affected feature files passed:

- Account: 87 passed, 3 PostgreSQL-only skips, 744 assertions. Raw log `/private/tmp/remediation-a-focused.log`.
- Compliance: 58 passed, 1 PostgreSQL-only skip, 372 assertions. Raw log `/private/tmp/remediation-c-focused.log`.
- Changed sync browser cases: 6 passed / 104 assertions. `PATH=/Users/andrepiresdemello/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin:$PATH php vendor/bin/pest tests/Browser/PeopleAccountAccessTest.php --filter=TA-06 --compact`. Prior 6/96 run preceded added focus/live-region assertions; the final 6/104 result supersedes that count.
- Alias HTTP normal/manual retry: 2 passed / 60 assertions, within Account complete-file run.

The deterministic feature runs intentionally skip guarded PostgreSQL tests; these were executed separately, not reported as covered by SQLite.

Raw terminal logs are preserved beside this report as `remediation-a-focused.log`, `remediation-c-focused.log`, `remediation-a-pg-platform.log`, and `remediation-c-pg-receiver.log` (terminal color escapes removed only).

## Actual PostgreSQL commands and raw results

Account (3 passed / 35 assertions; `/private/tmp/remediation-a-pg-platform.log`):

```sh
ACCOUNT_INVITATION_PG_CONCURRENCY=1 APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=/private/tmp/account-owned-invitation-run-3r98wy0s/pgsocket DB_PORT=15587 DB_DATABASE=account_owned_account_test DB_USERNAME=qa_operator DB_PASSWORD='' DB_URL='' php artisan test --compact tests/Feature/CompanyUsers/InvitationConcurrencyTest.php
```

Compliance (3 passed / 40 assertions; `/private/tmp/remediation-c-pg-receiver.log`):

```sh
ACCOUNT_INVITATION_PG_CONCURRENCY=1 APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=/private/tmp/account-owned-invitation-run-3r98wy0s/pgsocket DB_PORT=15587 DB_DATABASE=account_owned_compliance_test DB_USERNAME=qa_operator DB_PASSWORD='' DB_URL='' php artisan test --compact tests/Feature/ControlPlane/InvitationObservationTest.php
```

Both use exact guarded disposable test databases and separate PHP processes / PostgreSQL connections. Live QA databases, provider accounts and environment files were not used. The test role has an empty password; no credential fixture files are reproduced.

## Defect sensitivity and execution limits

The real Account product callback initially failed with HTTP 500 before CR-01. Legitimate queued invitation recovery datasets failed before CR-03. Browser partial-state checks exposed missing terminal projection reason and passed after its correction. Additional failure injection occurs at actual provider write, receiver delivery, receipt validation, link transaction and later import write boundaries. These are assertions about executed behavior, not proof of all independent review outcomes.

An automatic approval review rejected dynamic formatter path discovery because it could include unrelated dirty files. Formatting was subsequently executed with an explicit approved path allowlist. Another review rejected simply deleting a new terminal-state assertion in the committed recovery test; the final test retains an explicit correct unknown state alongside confirmed read evidence because its downstream projection is deliberately blocked. No blocked action was bypassed.

Source/tests were frozen for the orchestrator's single canonical paired rerun. Results and corrective checkpoint are recorded below after actual execution. All thirteen findings remain subject to their original reviewers' directed verification. Product QA and final architecture conformance remain unexecuted at this stage.

### Canonical formatting correction

The Account canonical functional/browser stages passed, but its final Pint check reported only `InvitationConcurrencyTest.php` and `InvitationObservationDeliveryTest.php` (`method_argument_space`, `unary_operator_spaces`, `not_operator_with_successor_space`). Applied Pint to exactly these two approved files, with no behavioral change. The explicit two-file Pint check passed. No complete suite was repeated for this whitespace-only correction; the orchestrator retains canonical execution evidence and performs impact-based final checks.


## Final deterministic checkpoint

The orchestrator's real visible canonical commands both exited zero. Account: 438 feature/unit passed, 7 optional PostgreSQL skips, 2940 assertions; browser 43/658; build/Pint pass. Compliance: 1154 feature/unit passed, 18 skips, 6936 assertions; editor JS30, PDF JS4, browser groups79/811 +40/776 +2/10 +4/48 +12/207, preview JS25; build/Pint pass. Formatting-only final corrections from canonical are included in committed bytes. Guarded PostgreSQL tests are separately evidenced above rather than inferred from skipped canonical cases.

Corrective source/test commits: Account `33375e42da9f5c33b2761686229cbfcc90787559` (13 paths), Compliance `5ff9d2089115dee98f9d8c18ec0819289d207ca1` (10 paths). Immutable pair checkpoint `account-owned-invitations-v1-remediation1-cd88c0078bf46580`; `remediation-01-checkpoint.json` SHA-256 `92ee0f72076d73d36481868383d1257daab8eb54d7a1e16044480453fc73a240` contains exact corrective bases, raw changed paths and binary-diff hashes. The original checkpoint manifest is retained unchanged.

No push/PR was performed. Original language/tooling/specification dirties remain unstaged; Account rejected tooling paths remain unchanged. Directed reviewer decisions, executable QA and architecture conformance remain pending; this checkpoint is not delivery approval.
