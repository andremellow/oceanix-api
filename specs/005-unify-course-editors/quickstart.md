# Quickstart validation: Unified Course Editors

## Preconditions

- Approved specification, architecture, and execution contract.
- Node 22.x, PHP 8.4, locked PHP/JS dependencies.
- Approved Pest Browser and installed browser runtime, or documented project-owned Playwright fallback after failed probe.
- Isolated synthetic database; WorkOS and Cloudflare faked.

## Focused checks

```bash
php artisan test tests/Feature/Courses tests/Feature/Platform
node --test tests/JavaScript/course-editor.test.mjs
npm run build
```

New D-01/shared-context/width checks must first demonstrate intended failures, not setup failures.

## Browser matrix

The test owner adds one required Pest Browser project command after approval. It must create isolated three-record fixtures for every context, start the app on an isolated port, authenticate through test-only support, exercise the frozen desktop/320 scenarios, exit nonzero on missing setup, retain screenshot/trace/log failures, and report explicit N/A reasons.

Pull-request CI runs deterministic controlled request failures. Scheduled and manually triggered CI additionally stop the actual isolated application server, prove the network-error state, restart it, retry, and prove recovery. The controlled case must not be reported as actual outage evidence.

## Integrated verification

```bash
composer verify
python3 .toscanini/bin/toscanini_contract.py --run-id unified-course-editor-20260910
toscanini verify --run-id unified-course-editor-20260910
python3 .toscanini/bin/toscanini-gate.py --run-id unified-course-editor-20260910 --require-contract --require-architecture --require-design
```

Also run the accepted PostgreSQL 17 concurrency and browser commands at the same checkpoint. Record counts, durations, artifacts, and checkpoint; do not bypass the known Toscanini inconsistency.
