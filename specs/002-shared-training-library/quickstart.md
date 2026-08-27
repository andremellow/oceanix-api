# Quickstart: Shared Training Library Validation

## Prerequisites

- PHP 8.3+, Composer and Node dependencies installed.
- Test database available; tests use SQLite and `RefreshDatabase` by default.
- WorkOS and Cloudflare calls faked; no real external request is permitted.

## Automated validation

```bash
php artisan test tests/Feature/SharedContent tests/Feature/Modules tests/Feature/Platform tests/Feature/Access
php artisan test tests/Feature/Courses tests/Feature/Compliance tests/Feature/Training tests/Unit/CourseVersionImmutabilityTest.php
```

Before completion:

```bash
composer test
./vendor/bin/pint --test
npm run build
```

## End-to-end scenarios

### 1. Ownership and isolation

1. As platform administrator, create/publish a shared Module and Course.
2. In Company A, grant only `shared-courses.view`; browse succeeds and add is denied.
3. Grant add; associate once and verify an idempotent duplicate request.
4. Confirm Company B sees no Company A private content/association.
5. Attempt direct tenant edits of shared content, including as tenant admin, and expect denial.

### 2. Hybrid company course

1. Create a Company A draft with a company Module and Shared Module.
2. Publish and verify exact module-version snapshots.
3. Submit a Company B module ID and expect denial.
4. Verify learner playback/questions resolve through frozen composition.

### 3. Propagation

1. Use one Shared Module in platform, Company A and Company B published courses; retain a separate Company A draft.
2. Publish a new ModuleVersion with restart disabled.
3. Verify one eventual successful item per course and new versions derived from each prior published version.
4. Verify Company A draft is unchanged.
5. Confirm not-started assignments replaced, in-progress unchanged, and completed/waived/cancelled history untouched.
6. Retry jobs and confirm no duplicate versions, assignments or events.

### 4. Restart and concurrency

1. Publish with restart enabled; confirm in-progress assignments restart without copied progress and retain schedule/origin.
2. Race assignment start against migration and verify one valid lock-ordered outcome.
3. Race manual publication with propagation and verify no version collision or lost update.

### 5. Promotion and archive

1. Create a company Course whose company Module is reused by another course.
2. Preview promotion and verify all affected courses.
3. Promote; Course and Module become platform-owned atomically, source association is active, and IDs do not change.
4. Archive Course; new associations/compositions/assignments are blocked.
5. Existing assignments remain executable and evidence/certificates resolvable.

### 6. Failure recovery and UI

1. Force one propagation item failure after another succeeds.
2. Verify `completed_with_failures`, useful counts and retry without tenant-data leakage.
3. Retry and confirm idempotent completion.
4. Validate keyboard/focus, loading/disabled states, textual ownership, responsive platform navigation and PT-BR localization.

## Expected evidence

- Authorization covers grant, denial, direct access, prerequisites, admin behavior and revocation.
- Audit identifies platform actor, affected company/content and propagation provenance.
- Cancel/create compliance events remain append-only and exactly once per replacement.
- PostgreSQL constraints and SQLite validation produce equivalent ownership/uniqueness behavior.

## Execution record — 2026-08-26

The automated end-to-end groups above were executed with the project's PHP 8.4 runtime:

```bash
/opt/homebrew/bin/php artisan test tests/Feature/SharedContent tests/Feature/Modules tests/Feature/Platform tests/Feature/Access --compact
# 113 passed, 363 assertions

/opt/homebrew/bin/php artisan test tests/Feature/Courses tests/Feature/Compliance tests/Feature/Training tests/Unit/CourseVersionImmutabilityTest.php --compact
# 168 passed, 451 assertions
```

The scale scenario covers one shared module propagated across 100 companies. It verifies that the impact projection remains bounded to at most eight database queries and that publication creates exactly 100 propagation items and queues exactly 100 jobs. The deterministic demo seed also verifies a shared course composed from a shared module and associated with two companies without external service calls.

Deviations and remaining manual evidence:

- The shell's default PHP is 8.3.27 while the installed dependencies require PHP 8.4.1, so validation used `/opt/homebrew/bin/php` explicitly.
- No functional deviations were observed in the automated scenarios.
- Keyboard/focus and responsive visual behavior still require browser-based manual validation; the server-side authorization, localization, propagation, isolation and idempotency paths are covered automatically.
