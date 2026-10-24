# WorkOS migration executable QA fixture

Run `20261014-workos-initial-migration`; scope `workos-initial-migration-v1`. This is an execution handoff, not a QA verdict. Use the approved specification, architecture and seven frozen QA scenarios. Work exclusively with the synthetic fixture below; do not visit operational application hosts.

## Environment and guard

Fixture directory: `/private/tmp/oceanx-workos-migration-qa`. PostgreSQL is on `127.0.0.1:55447`, user `qa`, with separate databases `account_migration_test` and `compliance_migration_test`. The `run` and `serve` wrappers explicitly set `APP_ENV=testing`, synthetic APP_KEY, database/host/port, array mail/cache, file sessions, sync queue and disabled telemetry. PHP bootstrap checks app environment, selected driver/database, host/port and actual `current_database()` before any operation. The fixture never reads another application's database through application code.

Account HTTP: `http://127.0.0.1:18101`; Compliance migration receiver: `http://127.0.0.1:18102`. Retained servers are started with `serve account` and `serve compliance`; if stopped, start each wrapper in a retained terminal. Both bind loopback only. Temporary `router.php` bootstraps the real application and then registers the guarded Account test fixture. No production provider/bootstrap changes or operational credentials are used. The synthetic migration token is stored in `token.json` with mode0600; use it programmatically and never print it.

`/private/tmp/oceanx-workos-migration-qa/reset` resets and seeds only the guarded disposable databases and configures the synthetic HTTP connection. It is the clean starting point for a full independent run. Servers may remain running. This resets fixture evidence, so save desired observations first. No reset touches environment files or operational data. Initial setup source: `runner.php`, `seed-account.php`, `seed-compliance.php`, `configure-account.php`, `router.php`, `run`, `serve`, `reset`, `control.php`, `provider-control`, `race.php` in that directory. The reusable Account fake provider/login guard is `tests/Support/WorkosPeopleMigrationFixture.php`.

## Synthetic records and controls

North: Account UUID `20000000-0000-4000-8000-000000000001`, org `org_qa_north`. South UUID ends `0002`, org `org_qa_south`. Empty West UUID ends `0003`. Holding actor is `user_qa_holding`; company manager is `user_qa_manager`; view-only actor is `user_qa_viewer`.

North provider data includes `user_known` active, `user_inactive` inactive, `user_revoked` active with an already disabled Account membership/entitlement, `user_collision` conflicting only by email with an existing different Compliance WorkOS identity, and `invitation_pending` with no WorkOS user. Existing Compliance local IDs are501,503,701,504 and502 respectively. Their integer IDs differ from Account UUIDs. Existing product access/version and statuses are seeded independently. South has known invitation IDs `invitation_known`, `invitation_expired`, `invitation_revoked`; fake provider states are accepted, expired and revoked. Accepted proof uses `user_sync_known`, distinct from North's identity.

Read-only state snapshots:

```sh
/private/tmp/oceanx-workos-migration-qa/run account control snapshot
/private/tmp/oceanx-workos-migration-qa/run compliance control snapshot
```

Save before/after snapshots for persistence and forbidden-side-effect comparisons. `provider-requests.jsonl` records fake WorkOS method/path only. Account import items and Compliance operation receipts are durable application evidence. Application errors are observable in each application's normal `storage/logs/laravel.log` and retained HTTP terminals. Inspect safe relevant lines, without dumping secrets or unrelated historical logs.

Provider control:

```sh
/private/tmp/oceanx-workos-migration-qa/provider-control normal
/private/tmp/oceanx-workos-migration-qa/provider-control failure
/private/tmp/oceanx-workos-migration-qa/provider-control partial
/private/tmp/oceanx-workos-migration-qa/provider-control cursor
/private/tmp/oceanx-workos-migration-qa/provider-control foreign
```

The JSON file is exclusively synthetic. `failure` returns provider503; `partial` fails the known expired-invitation lookup; `cursor` repeats membership cursor; `foreign` injects foreign organization evidence. Restore `normal` before a clean retry.

Authorization controls:

```sh
/private/tmp/oceanx-workos-migration-qa/run account control revoke-manager
/private/tmp/oceanx-workos-migration-qa/run account control restore-manager
/private/tmp/oceanx-workos-migration-qa/run account control remove-prerequisite
/private/tmp/oceanx-workos-migration-qa/run account control restore-prerequisite
/private/tmp/oceanx-workos-migration-qa/run compliance control revoke-principal
/private/tmp/oceanx-workos-migration-qa/run compliance control restore-principal
```

## QA-01: CLI selectors, preview and actor boundary

After reset, take snapshots. Run North preview:

```sh
/private/tmp/oceanx-workos-migration-qa/run account artisan workos:import-people 20000000-0000-4000-8000-000000000001 --actor=user_qa_holding
```

Omit company for all registered companies, supply a malformed/unknown company, omit actor or use `user_qa_viewer`. Observe actual exit status and per-company/source outcomes; compare snapshots for preview/denial zero writes. Intentional North email conflict makes incomplete runs nonzero. All-company includes South/West; missing remote binding for South is an explicit incomplete result.

## QA-02: initial exact mapping and invitation-only identity

Run North command with `--apply` after reset. Inspect both real applications' snapshots/receipts for existing Compliance IDs501/502/503/701 and Account person/member UUIDs; verify no duplicate local users or email merge. Observe invited/disabled invitation-only membership and differing local IDs. Repeat the exact command and compare counts and immutable mappings. No real WorkOS/provider writes are allowed.

## QA-03: conflicts and restrictions

Inspect `user_collision` and the preserved existing Compliance504/user_other identity. Inspect Account revoked membership/entitlement version9 and Compliance701 suspended/access-disabled projection. Check inactive503 remains suspended. Use `foreign` provider mode for foreign evidence, then `normal`; compare local identities, access/roles, first/last access and product activation. Only exact provider evidence may be associated.

## QA-04: partial, recovery and concurrent imports

Use `cursor` before command and observe explicit incomplete results without truncated imports. For a pre-dispatch denial, reset, revoke the synthetic Compliance principal, apply North, snapshot durable Account unknown items, restore principal and repeat. To exercise the distinct remote-committed/local-unknown seam, reset and run `/private/tmp/oceanx-workos-migration-qa/provider-control drop-next-response` before North apply. The guarded Account-only HTTP middleware lets the actual Compliance receiver commit/return its200 receipt, then drops exactly that one response before Account receipt application. The marker is consumed once. Inspect `lost-response.json` (opaque operation ID/stage), Account unknown item and Compliance committed receipt at that same ID. Repeat the same command and compare stable operation IDs/hashes, one remote receipt and eventual local confirmation. This does not simulate provider-side or operational mutations. Do not treat linkage or metadata as success without the actual receiver receipt.

`/private/tmp/oceanx-workos-migration-qa/run account race` adds synthetic `user_race`, holds the actual company lock, starts two independent command processes, waits for two PostgreSQL lock waiters, then releases them. It prints nonsecret lock/count observations. Inspect both databases for a single race person/item/link/receipt and preserved restrictions. The deliberate independent collision still makes command exit1. This is a disposable concurrent-run control, not a predeclared QA verdict.

## QA-05: known invitation-only synchronization

Use IAB to open `http://127.0.0.1:18101/__migration-qa/login/user_qa_holding/20000000-0000-4000-8000-000000000002/en`. Take Account protected-field/entitlement/link/profile counts and provider log snapshot. Click Synchronize invitations. Inspect known accepted/expired/revoked observations and separate accepted identity proof. No unrelated source identity/member, effective access, product entitlement, first-access event, email or provider mutation may appear.

Use `partial`, retry the actual UI, then `failure`, then restore `normal`. Inspect rendered counts/errors, preserved known facts, logs and safe recovery. Test missing/contradictory evidence by editing only the corresponding synthetic `provider.json` invitation record; retain the original and restore afterward. Never change operational provider data.

## QA-06: company and permission boundaries

Login as `user_qa_viewer` at the same South login URL: list is readable, sync control absent. Login as `user_qa_manager`: control present. After rendering, `revoke-manager` or `remove-prerequisite`, invoke the actual rendered control and observe server denial/no external effects. Restore controls for positive checks. Try a North URL with the company-only manager and observe tenant denial. Holding actor supplies the admin bypass positive control. The permission-grant migration is executable through the normal disposable migration path; inspect explicit Company Administrator profile grants and ordinary profiles after reset.

## QA-07: desktop/mobile EN/PT UI

Use IAB at desktop and390px; login route final locale is `en` or `pt_BR`. Observe existing list/search/register navigation, keyboard focus, completion/partial/error/empty states and overflow. For empty invitations use West with holding actor. For observable loading without browser interception: run `/private/tmp/oceanx-workos-migration-qa/provider-control hold`, click the actual Sync button, observe the translated loading/disabled control with the retained list, then run `/private/tmp/oceanx-workos-migration-qa/provider-control release` from the private CLI. `hold-entered` marks provider entry; the provider callback waits at most25seconds and fails503 if unreleased. Release is a private file marker, so it works even while the single-thread HTTP server is busy. Restore `normal` afterward. No production delay or route is added. Do not manufacture an imitation page. Inspect console/network/application errors, keyboard trigger/focus restoration, retry, back/refresh. Record actual completed QA IDs in the independent terminal event; partial coverage is not approval.
