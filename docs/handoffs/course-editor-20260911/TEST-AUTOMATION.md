# Handoff A — independent editor tests and CI

Read README.md and SHARED-CONTRACT.md first. You are the test implementation agent, NOT the independent Test Analyst approving your own work. The other agent owns production unification; you are not alone in the codebase. Preserve existing dirty changes and coordinate before touching shared configuration. Do not edit application behavior or weaken criteria to obtain green results.

## Goal

Turn previously observed editor regressions into fast, reproducible automated checks, run in CI against the shared editor in company and platform contexts. Tests are authored from accepted behavior, independently of production implementation. Do not rebuild the entire historical QA workflow as slow browser tests; choose the lowest effective boundary.

## Existing evidence and its limits

- tests/Feature/Courses/CourseEditorTest.php: 58 tests in last focused execution; includes actual Livewire/DB behavior plus some source-string assertions. Source checks do not prove browser morph/layout behavior.
- tests/JavaScript/course-editor.test.mjs: 12 executed client-state checks. Loads current production inline state/installed directive; some coupling will need a genuine test seam after extraction, not blind text rewriting.
- tests/JavaScript/course-editor.browser.test.mjs: one real Chrome regression with multiple assertions: three distinct expanded lessons; both reorder directions at1440/320; stable values/focus; moved title/answer persistence/reload; rejecting/accepting native confirmation. Includes an old-key response mutation negative control. It requires localhost fixture env vars and currently SKIPS without them.
- Other relevant PHP tests: CourseAuthoringTest.php, CourseEditorActionAuthorizationTest.php, CoursePreviewContentTest.php, HybridCourseCompositionTest.php; platform SharedModuleAssessmentEditorTest.php, SharedCourseDraftIntegrityTest.php, SharedContentAdministrationTest.php and relevant exact-copy/preview tests discovered by rg.
- specs/004-fix-course-editor/scenarios.md contains earlier accepted scenario/test mapping, not proof that every browser case is automated.
- archive/lifecycle/qa.md.txt documents all three final QA scenarios. Actual server outage/unavailable retry/recovery was independently executed but is not wholly in the saved browser regression.
- archive/scripts/*.txt preserves smoke/setup helpers; they are historical developer diagnostics, not an independent QA recording, portable runner or secure CI configuration.

Current .github/workflows/tests.yml runs PHP, build and formatting plus PostgreSQL concurrency. composer verify runs PHP, preview JavaScript only, Pint and build. Neither invokes the new editor JavaScript/browser suites. package.json declares no Playwright and composer.json no Dusk/Pest Browser plugin as of handoff. Do not confuse browser automation under a bundled local cache with a project dependency.

## Ownership

Own tests/**, fixture/test-support files, and necessary dependency/CI script files under the shared agreement. No app/resources/routes edits; ask production owner to expose minimal semantic test hooks or fix behavior. No .env changes, real WorkOS/Cloudflare calls, production data or live tenant credentials. Use approved synthetic local auth only in isolated testing environment.

## Work order

1. Inventory accepted scenarios CE-01–10 and earlier spec scenarios; map each to existing effective test or concrete gap. D-01 is already owner-decided: explicit Save for both contexts. Do not ask that question again. Adapt legacy autosave steps to explicit Save without losing regression sensitivity. No full autonomy claim while required detailed contract approval or setup is missing.
2. Choose one browser runner compatible with project Pest conventions. Evaluate Pest Browser first; preserve/reuse existing Playwright scripts as scenario evidence even if porting. Dusk is an option, not mandated. Install only needed dependencies through normal approved workflow, prove headless browser starts in actual CI-equivalent environment. Never request secret values; existing Flux CI secret names are availability references only.
3. Build fresh per-test data with distinct IDs/text, tenants and platform owner. Do not rely on course5/6, global module7, current /tmp SQLite or existing cookies. Establish app/server lifecycle and fake providers. Required CI suite exits nonzero on missing browser/database/setup instead of silently skipping.
4. Keep validation/persistence/permissions combinations in fast Action/Livewire tests. Keep pure revision/error transitions in fast JS tests where effective. Browser tests cover only DOM identity/morph, actual typing/blur/confirmation, focus, width/responsiveness, and critical saved/reload/request-failure journeys.
5. Use one reusable behavior suite/data providers for company course, shared course and applicable standalone module paths. Explicitly record genuine contextual differences; do not assert every action exists in every mode.
6. Automate QA-01/02/03 gaps, including failure provenance through unrelated success and useful field widths. Prefer deterministic controlled failed responses for fast routine network coverage; retain a focused actual stop/restart integration check if required by final accepted contract. Do not silently replace required actual outage proof with interception.
7. Demonstrate red/green sensitivity for old lost-identity bug, false dirty blur, hidden module error and field-width regressions. If old negative control no longer fits new architecture, design an equivalent causal one with evidence, not an unconditional rejection that can pass on setup errors.
8. Run suite against integrated production checkpoint, wire mandatory commands into CI and publish useful failure artifacts (screenshots/trace/logs). Report measured runtime and avoid arbitrary waits; maintain independent DB/browser contexts when parallelizing.

## Deliverables

Reusable tests and fixtures; documented clean-checkout/local and CI commands; CI jobs; scenario-to-test/result matrix; failure artifacts policy; measured timings; list of explicitly unautomated cases. No declaration that all QA is automated merely because one browser test passes. Hand exact final test commands/checkpoint to production coordinator. Independent Test Analyst reviews effective assertions afterward.
