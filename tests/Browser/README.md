# Unified course editor test suite

This directory contains the required real-browser checks for the company course, platform shared-course, and standalone shared-module editors. Fixtures create fresh synthetic records for every case; they do not depend on fixed IDs, an existing browser session, production data, or a developer cache path. WorkOS and Cloudflare are never contacted.

## Clean-checkout preparation

Use PHP 8.3+ and Node 22. From the repository root:

```bash
composer install --prefer-dist --no-interaction
npm ci --ignore-scripts
npx playwright install chromium
npm run build
cp .env.example .env
php artisan key:generate
```

Do not replace an existing `.env`; the copy step is for a clean checkout or CI workspace only.

## Commands

```bash
# Fast PHP suite (browser group excluded deliberately)
composer test

# Extracted client-state contract
npm run test:editor:unit

# Required Pest Browser matrix; missing browser/server setup is a failure, not a skip
composer test:browser

# Actual application stop/restart recovery (scheduled or manually dispatched in CI)
npm run test:editor:outage

# Complete local delivery gate
composer verify
```

Assets are built before every browser command, including the outage command and the browser phase of `composer verify`, so clean checkouts never rely on a pre-existing Vite manifest. The routine pull-request jobs prove deterministic request-failure provenance in the extracted client-state suite. The distinct outage job really stops the local Laravel process, observes the failed save, restarts the application, retries, and verifies persistence.

## Scenario matrix

| Contract scenarios | Effective automated boundary | Contexts | Principal evidence |
| --- | --- | --- | --- |
| UCE-01, UCE-17 | Livewire plus browser semantic contract | All three | `UnifiedCourseEditorContractTest`; `UnifiedCourseEditorBrowserTest` common-contract case |
| UCE-02–UCE-05 | Exact whole-graph snapshot, atomic Save/no-op, temporary and unavailable identity rollback; exact validation/conflict/network provenance; native course-code and single/multiple answer-key staging; first-invalid focus with retained local values, confirmed conflict reload, permission-loss locking with copyable values, browser blur, navigation warning, stale conflict, inline validation recovery without refresh, Save/reload, and real application outage | All three | `UnifiedCourseEditorDeepContractTest`; `UnifiedCourseEditorDirectedEvidenceTest`; browser Save/error cases; `course-editor.test.mjs`; outage test |
| UCE-06 | Extracted JavaScript state machine, exact listener lifecycle, navigation warning, failure provenance, explicit hydration loading state with every authored, operational, Save, and close control locked while the shell remains visible, pre-dispatch structure/media guards with target-specific accessible recovery, immediate local pending state with duplicate-action suppression, and controlled destructive dialogs whose Cancel path performs no operation | All three where the action applies | `course-editor.test.mjs`; hydration, pending, navigation, and controlled-confirmation browser cases |
| UCE-07–UCE-08 | Three-sibling stable reorder, exact positions/identities, stale immediate operations, the frozen structure/media hook vocabulary with finer action identity separated into `data-editor-action-detail`, state-specific disabled-control guidance, action-specific pending/success/failure text keyed to the actual nested question or answer target, lost-response failures rendered as assertive danger alerts while ordinary guard/pending guidance remains polite status, preserved unknown-outcome/no-automatic-retry copy, exact retry controls or explicit retry-unavailable guidance for every failed non-upload operation, accessible video/image failure alerts and associations, structure and image-media dirty/upload guards, retryable failed upload tokens, reactive upload locks on both Cancel and Save-and-close with exact accessible wait guidance, locked-context video-library isolation, revoked-after-mount library denial before provider or preview access, required actor/revision boundaries with exact zero-write snapshots for every company structural operation, authorized editor video synchronization and revoked denial before both the ready fast path and provider access, post-create focus on the first labelled module/lesson/question/option field, and direct representative Action execution with exact authorization/lifecycle failure types and an explicit applicability matrix | All three | `UnifiedCourseEditorArchitectureConformanceTest`; `UnifiedCourseEditorDeepContractTest`; `UnifiedCourseEditorDirectedEvidenceTest`; `UnifiedCourseEditorDirectActionEvidenceTest`; `CourseEditorActionAuthorizationTest`; `UnifiedCourseEditorContractTest`; `UnifiedCourseEditorBrowserTest`; `course-editor.test.mjs` |
| UCE-09 | Real DOM morph, focus, moved lesson/module/question/answer identity, authored-value retention, clean Alpine replacement, Save, and fresh-page reload | All three | `UnifiedCourseEditorBrowserTest` reorder journeys |
| UCE-10–UCE-12 | Existing Service/Action/Livewire suites plus a real-browser saved-draft preview panel that remains blocked for dirty, saving, validation, conflict, network, and permission-loss states and is restored only after a clean Save | Company/shared-course as applicable | `HybridCourseCompositionTest`; `SharedCourseDraftIntegrityTest`; `UnifiedCourseEditorBrowserTest`; publication, preview, and exact-copy suites |
| UCE-13 | Fake-provider Livewire/Action checks with stable upload tokens, visible target-identified rows that independently cover uploading, failed, processing, and ready states, retained retry identity after transfer failure, a fresh server allocation, and a genuine second client transfer of the retained file; accepted immediate-operation confirmation is also exercised in the real browser | All three | `UnifiedCourseEditorContractTest`; `course-editor.test.mjs`; `UnifiedCourseEditorBrowserTest`; existing video security/editor tests |
| UCE-14–UCE-16 | Existing sanitizer, threshold, Policy/Action, immutability, and newly revoked-save checks | All applicable contexts | renderer/training tests; authorization tests; `UnifiedCourseEditorContractTest` |
| UCE-18 | Exact rendered root/wrapper/control dimensions, save-bar alignment to each editor shell at desktop and mobile widths, unique accessible names and IDs, direct-child Flux labels with accessible hints and associated validation for watch threshold, passing score, and attempts, inline validation association, the `--ds-focus-ring` token on every visible native editor, authored, rich-text, preview, and Save control, exact mobile action/Save stacking, full-width mobile Save and controlled-dialog actions, wrapped long destructive targets and consequences, target-keyed destructive pending state that blocks Escape, Cancel, outside-click, and close controls, an actual unrelated default-hero regression control, and overflow at 1440 and 320 CSS px | All three | `UnifiedCourseEditorLayoutTest`; `UnifiedCourseEditorBrowserTest` pending-dialog case |
| UCE-19 | Rendered detail-row width and unrelated hero regression control | Company/shared course | `CourseDetailWidthTest` |
| UCE-20 / QA-10 | Setup smoke, mandatory CI jobs, deterministic client request failure, authenticated actual editor stop/restart/retry with DB readback, and real PostgreSQL competing Save/structure/media writers with exactly one accepted stale loser and unexpected-process failures rejected | CI and local | `BrowserEnvironmentSmokeTest`; workflow jobs; `course-editor.test.mjs`; `application-outage.browser.test.mjs`; PostgreSQL concurrency suites |

Context differences are explicit in `EditorContextCase`: standalone modules have no course-composition action. Course-only publication/composition behavior remains in the relevant fast suites rather than being duplicated as slow browser cases.

## Failure evidence

Pest Browser saves screenshots under `tests/Browser/Screenshots/`. The outage check writes a Playwright trace and application log under `tests/Browser/Artifacts/`. CI uploads these directories and `storage/logs/laravel.log` on browser failure; the outage job uploads its evidence on every run. CI retains artifacts for seven days and fails if required outage evidence is absent.

## Explicit automation limits

- The pull-request client failure path is deterministic and does not claim to be a stopped process. Actual stop/restart recovery is the scheduled/manual job.
- Browser checks intentionally cover DOM identity, focus, rendered width, controlled destructive confirmation, typing/blur, and critical save/recovery journeys. Validation combinations, authorization, concurrency, transactions, and persistence boundaries stay at the faster Action/Livewire level.
- These checks prove the frozen representative scenarios; they are not a claim that every possible browser, assistive technology, network failure, or provider behavior is automated.

## Reference runtime

At checkpoint `uce-final-20260911-09` on the local macOS runner, the extracted 21-case editor JavaScript suite ran in about 0.8 seconds; the complete browser suite contains 85 cases with 1,010 assertions; and the full non-browser PHP suite contains 794 passing tests, 3,842 assertions, and 16 environment-specific skips. The 36-case architecture-conformance suite contributes 73 direct assertions across the final authorization and revision boundaries. The 25-case preview JavaScript suite, production build, and Pint formatting gate also passed. The real PostgreSQL draft/concurrency suite ran separately in 7.9 seconds with 56 passing tests and 391 assertions, and actual authenticated application outage/recovery completed in 8.5 seconds. CI timings can differ because dependency installation is excluded from these measurements.
