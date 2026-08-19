# Oceanix Training Compliance

Corporate training and compliance platform for offshore operations. The product goal is not to
serve videos and quizzes — it is to demonstrate, auditably, who had to take which training, under
which rule, against which exact version of the content, what they did, and when the obligation was
met, expired or renewed.

Full functional and technical specification: [`docs/product-spec.md`](docs/product-spec.md) (PT-BR).

## Stack

| Concern | Choice |
| --- | --- |
| Framework | Laravel 13 · PHP 8.3+ |
| Interface | Livewire 4 (single-file components) + Flux Pro + Tailwind 4 |
| Identity | WorkOS AuthKit |
| Video | Cloudflare Stream, behind the `VideoProvider` contract |
| Database | PostgreSQL (SQLite in tests) |
| Tests | Pest 4 |

## Getting started

```bash
composer setup
```

That installs dependencies, copies `.env`, generates the key, migrates and builds assets. Then:

```bash
composer dev
```

which runs the server, queue worker, log tail and Vite together.

Seed the access catalog and sample offshore data:

```bash
php artisan db:seed
```

Sample data (departments, job functions, three courses, five people with uneven deadlines) only
seeds in `local` and `testing`.

## Configuration

`.env.example` documents every variable. The ones that matter first:

| Variable | Purpose |
| --- | --- |
| `ADMIN_EMAILS` | Comma-separated emails that receive the admin profile on sign-in |
| `WORKOS_CLIENT_ID` / `WORKOS_API_KEY` | AuthKit credentials |
| `OCEANIX_AUTO_PROVISION_USERS` | `false` once Directory Sync provisions the workforce |
| `CLOUDFLARE_STREAM_ACCOUNT_ID` + `CLOUDFLARE_STREAM_API_TOKEN` | Private video ingestion and signed playback. Both are required — with either one missing, a local environment falls back to the file-backed development provider and every other environment fails loudly. |
| `OCEANIX_PLAYBACK_TOKEN_MINUTES` | Lifetime of a signed playback token |

### Verifying the video provider

```bash
php artisan oceanix:video-check
```

Checks the token, that it reaches this account's Stream, and that it may write — a valid
token can still point at the wrong account or lack write scope, and both would only surface
when someone uploads a lesson video. The write check creates an upload slot and removes it;
pass `--no-write` to skip it.

## Architecture

```text
Identity (WorkOS)  →  People & organization  →  Content & versioning
                                   ↓
                     Requirements  →  Assignments
                                   ↓
              Execution, attempts, append-only evidence
                                   ↓
           Certificates, notifications, dashboards, audit
```

Key invariants — see [`CLAUDE.md`](CLAUDE.md) for the full list:

- A published `CourseVersion` is immutable; assignments and certificates freeze it.
- A training requirement is a rule; a `UserTrainingAssignment` is the materialized obligation.
- `compliance_events` is append-only, idempotent on a client-generated UUID, and separates
  `occurred_at` (the device's claim) from `received_at` (the server's clock) — the schema is ready
  for the future offline iPad app even though offline is not implemented.
- Video authorization is minted per request behind `App\Contracts\VideoProvider`.

## Design system

[`docs/control-center-design-system.md`](docs/control-center-design-system.md) is the source of
truth for the interface: semantic tokens, page composition, role-aware navigation, component
contracts, accessibility and responsive behavior.

## Tests

```bash
composer test
```

```bash
./vendor/bin/pint --test
```

### Scheduled work

| Command | Cadence | Purpose |
| --- | --- | --- |
| `oceanix:materialize-requirements` | hourly | Create the assignments active requirements currently demand |
| `oceanix:update-overdue` | daily | Move open assignments past their deadline to overdue |
| `oceanix:sync-videos` | every 10 min | Reconcile videos still encoding at the provider |

All three are idempotent and safe to retry.

## Project status

Phase 1 (foundation) is in place: authentication, authorization, the complete data model, the
control center shell, compliance dashboards, the employee board, directories and certificate
verification. The course editor, the lesson player and the materialization engine are the next
phases — see §24 of the product spec.
