# Behavior scenarios and test evidence

Rules and decisions live in `spec.md`. This map supplies representative examples and planned evidence for approved scope `unified-course-editor-scope-v1`; planned checks are not implementation evidence.

## Coverage examination

| Dimension | Relevant rules | Scenario IDs / open questions | Status or N/A reason |
| --- | --- | --- | --- |
| Happy paths and alternative flows | FR-001–FR-008 | UCE-01–UCE-06 | COVERED |
| Roles, permissions and isolation | FR-002, FR-011–FR-013 | UCE-15–UCE-17 | COVERED |
| Data classes, boundaries and invalid input | FR-003–FR-010, FR-014–FR-016 | UCE-02–UCE-14, UCE-18 | COVERED |
| Lifecycle and state transitions | FR-004–FR-012 | UCE-03–UCE-12, UCE-16 | COVERED |
| Empty, loading and error states | FR-006–FR-008, FR-014, FR-018 | UCE-05–UCE-08, UCE-13 | COVERED |
| External failures, partial effects and recovery | FR-004, FR-006, FR-008, FR-014, FR-022 | UCE-04, UCE-07, UCE-13, UCE-20 | COVERED |
| Time, retries, ordering and concurrency | FR-004–FR-009, FR-011, FR-014, FR-022 | UCE-03–UCE-08, UCE-13, UCE-20 | COVERED |
| Affected existing behavior and integrations | FR-010–FR-022 | UCE-09–UCE-20 | COVERED |

## Concrete examples

| Scenario ID | Spec rule reference | Preconditions / representative data | Action | Observable expected outcome | Forbidden side effects |
| --- | --- | --- | --- | --- | --- |
| UCE-01 | FR-001, FR-019 | Equivalent company, shared-course, and standalone-module drafts | Open each route | Common labels, selectors, state transitions, content and assessment controls behave alike; context-only actions are identified | No cloned common state/save/browser implementation or client-selected authority |
| UCE-02 | FR-003, FR-005 | Distinct persisted title, content, question, and answers | Change every field, blur, inspect storage before Save | UI is dirty; storage/revisions unchanged | No autosave or false Saved |
| UCE-03 | FR-004, FR-005, FR-009 | Three distinct persisted records; a separate immediate structure action has returned a stable ID | Reorder through the guarded action, edit the moved record, Save, reload | Immediate structure result and authored Save each acknowledge truthfully; stable IDs survive and values/order reload | No sibling mutation, identity swap, partial save, or stale acknowledgement |
| UCE-04 | FR-004–FR-006 | Valid early fields and invalid later answer; separately request failure | Save | Error visible, pending values remain, database snapshot unchanged | No partial write or Saved acknowledgement |
| UCE-05 | FR-004–FR-006 | Another author changes the draft after load | Save stale snapshot | Conflict visible, zero overwrite, local values retained | No last-write-wins or destructive reload |
| UCE-06 | FR-005–FR-007 | Every clean/dirty/saving/saved/error state | Navigate/unload; accept and decline | Unsafe states warn; decline dispatches nothing; acceptance proceeds once | No loss, duplicate navigation, or clean warning |
| UCE-07 | FR-006, FR-008 | Dirty authored value, module failure, unrelated request | Attempt unsafe operation, then Save authored data and retry invalid/valid operation | Dirty guard refuses without dispatch; provenance survives unrelated success; valid retry persists only its dedicated effect and resolves only its cause | No deletion or unrelated dirty/error clearing |
| UCE-08 | FR-008, FR-009 | Three distinct siblings; clean authored state | Decline then accept confirmation; exercise valid reorder and invalid ID variants | Decline dispatches nothing; accepted valid operation immediately persists contiguous order; invalid requests preserve the snapshot | No duplicate request, cross-parent move, unguarded persistence, or partial resequence |
| UCE-09 | FR-009, FR-016 | Three expanded records at 1440 and 320 CSS px | Reorder, type in moved fields, Save/reload | Values stay with stable records; focus and fields remain usable | No position-key mutation, clipping, or overflow |
| UCE-10 | FR-010 | Empty, direct-only, module-only, mixed drafts | Add opposite composition or preview/publish | Empty chooses requested mode; conflict fails closed; mixed inventories remain | No silent conversion/deletion |
| UCE-11 | FR-011 | Shared references with/without lineage drafts | Prepare/open/save/discard | Exact-copy lineage/revisions preserved; divergent draft refuses; allowed references rewire | No replacement of another draft or graph deletion |
| UCE-12 | FR-011, FR-012 | Prior published versions and assignments in all states | Publish defaults/explicit restart; induce later failure | Atomic publish; immutable history; only approved effects occur | No partial publish, historical rewrite, or implicit restart |
| UCE-13 | FR-008, FR-014 | Multiple uploads, reordered modules, provider failure | Upload/complete/select/remove/retry | Transfer and association Actions stay bound to stable targets; failure is retryable; private reference only; authored dirty state is unaffected | No public URL, wrong-record link, false authored Saved state, or real test API call |
| UCE-14 | FR-014, FR-015 | Safe/unsafe rich content and below-threshold learner | Save content/threshold and answer | Safe canonical content and threshold persist; assessment remains available | No unsafe markup or progress gate |
| UCE-15 | FR-002, FR-013 | Authorized controls plus revoked/foreign/forged contexts | Open/invoke every operation | Authorized succeeds; invalid denied with zero writes | No UI/client-flag authorization or data leak |
| UCE-16 | FR-011–FR-013 | Eligibility/status changes after mount | Attempt each mutation | Fresh checks reject and preserve current data | No stale authorization or published mutation |
| UCE-17 | FR-001, FR-002, FR-011 | Context-specific capabilities | Render/invoke applicable and absent actions | Common editor calls named server collaborators; absent capability omitted/rejected | No giant owner branch or crossed publication effects |
| UCE-18 | FR-016–FR-018 | Long text and unique labels at 1440/320 | Measure wrapper/container boxes, tab/type, trigger error | Wrapper is at least 70% of its row at desktop and 90% of the inner container when stacked at 320; focus/error association is unique; no overflow | No inner-only flex sizing, clipping, ambiguity, color-only state, or CSS-source-only proof |
| UCE-19 | FR-017 | Long course text/actions plus unrelated hero | Render and measure at both widths | Both course details use remaining width beside actions and full width when stacked; unrelated hero is unchanged | No global widening or squeezed text |
| UCE-20 | FR-019–FR-022 | Clean environment without fixed IDs/session/cache | Run pull-request controlled-failure checks; run scheduled/on-demand actual stop/restart recovery; exercise negative setup/regression controls | Isolated fixtures execute contexts; failures exit nonzero; artifacts and N/A are explicit; actual outage recovers; old editors are removed only after integrated green | No silent browser skip, live API, separate-checkout proof, intercepted failure mislabeled actual outage, or premature deletion |

## Questions and decisions

| Question ID | Affected rules/scenarios | Material decision needed | Answer / spec reference | Status and consequence of deferral |
| --- | --- | --- | --- | --- |
| D-01 | FR-003–FR-008 | Persistence boundary | Explicit Save with unsaved warning in both contexts; typing/blur do not persist. | RESOLVED by owner |
| D-02 | FR-019–FR-020 | Browser runner | Pest Browser with project-owned Playwright dependencies; standalone project-owned Playwright is fallback only if the compatibility probe cannot satisfy accepted scenarios. | PLANNING PROPOSAL; contract approval binds it and probe remains required |
| D-03 | FR-002, FR-011–FR-013 | Authority/publication selection | Server-resolved context collaborators; never client `is_shared`; preserve approved effects. | RESOLVED by owner |
| D-04 | FR-008, FR-014 | Structural/media boundary | Dedicated structural and media operations persist immediately through named server-authorized Actions with dirty/upload guards and action-specific state; authored fields/assessments remain atomic Save. | RESOLVED by owner: Option A on 2026-09-10 |
| D-05 | FR-004–FR-006 | Authored Save failure behavior | Reject the complete authored scalar/content/assessment graph atomically and retain all pending values on every failed or stale Save. | RESOLVED by owner: Option A on 2026-09-10 |
| D-06 | FR-022 | Actual outage automation | Controlled failures gate pull requests; real stop/restart recovery runs in scheduled and manually triggered CI. | PLANNING PROPOSAL; contract approval binds it |
| D-07 | FR-016–FR-017; UCE-18–UCE-19 | Useful rendered width | Assessment text wrappers are at least 70% of their row at desktop and 90% of the inner container when stacked at 320; course title/description use their own full-width row below actions. | APPROVED |

## Test plan and execution evidence

| Scenario IDs | Test level and real boundary | Fixtures / controls | Assertions | Task ID | Test file / name | Defect-sensitivity evidence | Execution command / result |
| --- | --- | --- | --- | --- | --- | --- | --- |
| UCE-01–UCE-05 | Pest Livewire/Action with shared context data set | Three persisted records, temporary records, invalid/stale snapshots | No blur writes, atomic Save, stable identity, conflict, unchanged failure snapshot | T012–T017 | `tests/Feature/CourseEditor/UnifiedCourseEditorContractTest.php`, deep/directed evidence suites, and `tests/Browser/UnifiedCourseEditorBrowserTest.php` | No-op, invalid, rollback, stale, and retained-local-value controls execute the missing/regression branches | PASS at `uce-final-20260911-09` |
| UCE-06–UCE-09 | Client-state checks and focused browser | All save states, confirmations, reordered records, 1440/320 | Status/guard, provenance, DOM identity/focus/persistence/reload | T017, T023, T026–T028 | `tests/JavaScript/course-editor.test.mjs`, `tests/Browser/UnifiedCourseEditorBrowserTest.php`, `tests/Browser/UnifiedCourseEditorLayoutTest.php` | False-dirty, lost-response, duplicate-dispatch, focus, overflow, and navigation controls are explicit | PASS; editor JS 21/21 and browser matrix included in 85/1,010 |
| UCE-10–UCE-17 | Pest Service/Action/Policy/Livewire | Modes, lineages, assignments, permissions, uploads, fake provider | Preservation, denial controls, transactions, history, exact effects | T018–T022 | `tests/Feature/CourseEditor/**` plus retained course/platform/shared-content/video suites | Direct authorized controls pair with revoked/foreign/published/stale zero-write cases; provider calls remain faked | PASS; architecture boundary suite 36/73 |
| UCE-18–UCE-19 | Real browser dimensions/accessibility | Long text, wrapper metrics, unrelated hero control | Usable width, no overflow, focus/labels, scoped header width | T023–T025 | `tests/Browser/UnifiedCourseEditorLayoutTest.php` and `tests/Browser/CourseDetailWidthTest.php` | Exact desktop/mobile thresholds, focus token, field composition, shell alignment, and unrelated-hero negative control execute | PASS at desktop and 320 CSS pixels |
| UCE-20 | CI-equivalent runner | Clean setup; missing prerequisite and failing assertion controls | Required execution, nonzero failures, artifacts, explicit N/A, same checkpoint | T029–T031, T033 | `composer verify`, browser runner, outage browser script, PostgreSQL matrix, and CI workflow | Missing-browser control exits nonzero; authenticated outage/restart and competing-writer paths execute separately | PASS; PHP 794/3,842, browser 85/1,010, PostgreSQL 56/394, outage 1/1 |

## Automation exceptions

| Scenario IDs | Why automation is infeasible | Alternative evidence | User decision and residual manual work |
| --- | --- | --- | --- |
| None currently accepted | All frozen scenarios have an automated or executable browser boundary; feasibility remains to be proven. | N/A | Any exception requires contract amendment and owner acceptance. |

## Readiness

- D-01–D-07 are resolved; runner installation and compatibility readiness passed.
- User confirmation of consolidated behavior: APPROVED on 2026-09-10.
- Every scenario has an approved boundary with executed evidence mapped above.
- Implementation evidence: PASS at `uce-final-20260911-09`; see `implementation.md` and `tests/Browser/README.md`.
