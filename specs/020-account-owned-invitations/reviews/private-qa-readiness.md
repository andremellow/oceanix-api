# Private executable QA handoff — preparation only

Run: `20261020-account-owned-invitations`; scope: `account-owned-invitations-v1`.

This note records setup, not a behavioral verdict. All frozen QA-01–QA-23 remain unexecuted until the independent QA evidence says otherwise. Start only after the stable implementation checkpoint and complete Code Review/Test Analyst checkpoint.

## Safe runtime boundary

Private root: `/private/tmp/account-owned-invitation-run-3r98wy0s`; root marker: `/private/tmp/account-owned-invitation-run.path`.

Account: `http://127.0.0.1:16586`. Compliance: `http://127.0.0.1:16587`. Both use actual Laravel HTTP kernels, four local PHP workers, private file sessions and distinct cookies. The router returns 503 for application routes until the private `activate-http-qa` marker exists. Do not use Herd or normal application databases.

Private PostgreSQL uses only a Unix socket under the private root, port 15587, role `qa_operator`. Live QA databases are `account_owned_account` and `account_owned_compliance`. Separate empty databases ending `_test` are reserved for Worker RefreshDatabase concurrency tests; do not RefreshDatabase the live pair.

The private `boot.php` refuses any repository/database/environment mismatch. No normal `.env` is loaded. HTTP prevents real provider calls; Account uses synthetic WorkOS fixtures. Compliance forbids operational WorkOS calls. The router's exact synthetic authenticate response allows the existing AuthKit callback positive control only. Do not print the fixture JSON credentials in artifacts or chat.

## Actual setup completed

- Additive migrations executed in both live QA databases.
- Synthetic north/south companies, manager/viewer/target identities, principal identities and reciprocal company/person bindings seeded through approved fixture helpers.
- Private pair connected with synthetic credentials. Readiness flags enabled only for this pair.
- Nonempty preservation evidence seeded for Compliance north target: published course version, completed training assignment, certificate and append-only event recorded through ComplianceEventRecorder.
- `training-evidence-baseline.json` contains count/hash baselines for courses, versions, assignments, certificates and events. These must remain unchanged through mapping and invitation observation.

## Runtime entrypoints

Private `qa-cli.php` supports `snapshot`, `artisan <input-json-path>`, and `drain`, selected with `INVITATION_QA_REPO=account|compliance`. It calls real Laravel commands/workers. `drain` processes only that private repository/database queue. Preserve intent IDs and immutable payloads when exercising retry.

The fixture exposes loopback-only `/__invitation-qa/state` and session login `/__invitation-qa/login/{company}/{actor}`. Account company route uses its UUID; Compliance uses `qa-north`/`qa-south`. Synthetic actor IDs have the `user_qa_` prefix. Inspect fixture-produced nonsecret identities as needed; never expose synthetic token values in evidence.

For AuthKit: use `/login/qa-north`, capture the redirect state from `/auth/workos/redirect` without visiting the real provider, then invoke `/auth/workos/callback` with that session/state and synthetic code. Router exact authenticate fake supplies the north manager identity. Observe normal redirect and authenticated page.

## Evidence obligations

Use the approved scenarios/contract, not this setup note, as acceptance authority. Exercise every frozen QA ID, including provider failure controls, concurrent real HTTP/queue requests, retry exhaustion and permission revocation. UI cases require actual navigation and control interaction at wide/narrow sizes; API-only evidence cannot replace these. Compare database/provider request snapshots and preservation hashes, capture safe rendered/network/console evidence, and report each scenario honestly. Existing automated tests are useful setup references, not runtime QA verdicts.

### Platform QA-23 setup

The approved Compliance fixture now exposes `seedPlatformHttpQa()` and `/__invitation-qa/platform-login`. Parent executed only the guarded synthetic seed and stored its nonsecret identity in private `platform-http-seed.json`. The private CLI supports `seed-platform` for this setup. Runtime QA must visit the platform login helper, actual `/platform/companies` and the company page's real grant/invite controls; no behavior is claimed proven by this seed. Use unique synthetic people to avoid disturbing north target preservation evidence.

### Default-off cutover scenario control

Private `qa-overrides.php` is loaded after the normal fixture boot in both HTTP router and CLI. An optional private `qa-config-overrides.json` may contain only the typed scenario control `{"compliance_cutover": false}` (or true) to exercise Compliance's real dispatch guard instead of always inheriting ready-fixture configuration. No override file was created during preparation. Remove it after the scenario. Account cutover remains the real private ProductModule database flag. This is disposable runtime instrumentation, not product/configuration source or an executed QA result.

### Private HTTP transport failure controls (QA-16/17/18)

The private router has a guarded, inactive-by-default fault instrument for **only** `/api/control-plane/` requests after exact disposable database bootstrap. Optional `qa-transport-faults.json` is an array of rules: `repo` (`compliance`), `method` (`PUT`), optional `path_prefix` and exact JSON `kind`, `type`, optional integer `remaining` (one for once; omit for persistent failure). `status-before` requires status401/404/429/503 and returns before app handling. `malformed-after` and `empty-after` let the real Laravel handler commit its receipt, then replace the HTTP response with invalid JSON or an empty body. Report the latter accurately as a response-body loss after commit, not a simulated TCP timeout. Matching reservations use file locks and safe path/type-only observations in `transport-fault-observations.jsonl`. No rule file was created in preparation, so no fault is active. Remove rules between scenarios. For security tests use actual principal/token/binding state rather than this transport instrument.

The token-free `qa-identities.json` contains synthetic company/person/platform identities for navigation and evidence. Service credentials remain confined to private fixture connection files and must never be echoed into reports.

### Actor profiles across the accepted flows

The initial paired seed creates Account login users for convenient navigation; that is not a requirement for a product user. In QA-23's real platform registration/grant flow, the newly created company administrator can subsequently act through Compliance without first signing into Account. Use that legitimate actor profile when exercising the accepted Compliance-origin permission/synchronization path; preserve the exact current company mapping and current grants. No manufacturing of an Account login should be needed to make that workflow pass.

### Private queue timing and clock

The actual private database queue retry_after is180seconds, greater than the120second claim lease; worker timeout remains60seconds. `/__readiness` exposes these nonsecret values. Optional private `qa-clock.json` with `{"now":"2026-10-20T19:00:00Z"}` applies Carbon test time consistently after fixture boot in both HTTP services and CLI for bounded due/backoff/lease scenarios. No clock file exists during preparation. Report simulated-clock advancement explicitly; it is not a wall-clock duration claim. Remove it after a scenario and reauthenticate synthetic sessions if needed. Never edit immutable operation payloads to make a retry due.

### Runtime static asset readiness correction

At executable UI validation, the private PHP router was found to forward compiled public build asset requests into Laravel. Parent added an allowlisted, traversal-checked static build-file route only in the disposable router; no application source/build/.env changed. The reported Account CSS returned HTTP200 text/css, and reviewers were instructed to reload and independently observe actual styled UI. This environment correction is not a product behavior approval.

### Runtime AuthKit fake isolation correction

The callback smoke path exposed overlapping HTTP fake callbacks: the exact synthetic authenticate response and the fixture-wide forbidden-provider stub were both evaluated. Parent replaced only the disposable callback HTTP client after fixture boot with one exact POST `/user_management/authenticate` branch and a rejection for every other provider request. Local Account loopback allowance and stray-request protection remain. PHP lint passed; QA must independently rerun actual callback/session behavior. No application source or production/provider communication changed.
