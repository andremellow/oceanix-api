# Behavior scenarios and test evidence

Rules and product decisions live in `spec.md`; this map supplies representative examples and planned evidence for frozen scope `course-editor-reliability-scope-v1`.

## Coverage examination

| Dimension | Relevant rules | Scenario IDs / open questions | Status or N/A reason |
| --- | --- | --- | --- |
| Happy paths and alternative flows | FR-001, FR-004, FR-005, FR-009, FR-012, FR-014 | SCN-01, SCN-05, SCN-07, SCN-09, SCN-11 | COVERED |
| Roles, permissions and isolation | FR-009, FR-017 | SCN-06, SCN-12 | COVERED |
| Data classes, boundaries and invalid input | FR-002, FR-003, FR-005, FR-010, FR-014 | SCN-02, SCN-03, SCN-05, SCN-06, SCN-10 | COVERED |
| Lifecycle and state transitions | FR-001, FR-003, FR-011, FR-012 | SCN-03, SCN-07, SCN-08 | COVERED |
| Empty, loading and error states | FR-006, FR-007, FR-015, FR-016 | SCN-04, SCN-13, SCN-14 | COVERED |
| External failures, partial effects and recovery | FR-002, FR-007, FR-010 | SCN-02, SCN-06, SCN-13 | COVERED |
| Time, retries, ordering and concurrency | FR-008, FR-010, FR-014, FR-015 | SCN-06, SCN-10, SCN-13, SCN-14 | COVERED |
| Affected existing behavior and integrations | FR-004, FR-005, FR-012, FR-017 | SCN-03, SCN-05, SCN-08, SCN-12 | COVERED |

## Concrete examples

| Scenario ID | Spec rule reference | Preconditions / representative data | Action | Observable expected outcome | Forbidden side effects |
| --- | --- | --- | --- | --- | --- |
| SCN-01 | FR-001, FR-004 | Empty company draft | Add a direct lesson with one question | Editor, preview and publication use direct-lessons mode and include the question | No reusable pivot is inferred from the compatibility mirror row |
| SCN-02 | FR-001, FR-002 | Direct lesson, question and options exist | Attempt to add the first reusable module | Inline composition error and exact row snapshot remains unchanged | No lesson, question, option or pivot deletion |
| SCN-03 | FR-003, FR-004 | Legacy draft contains direct lessons and reusable modules | Open editor, preview and publish controls | Both inventories are visible; preview/publication fail closed with recovery guidance | Neither inventory is silently preferred or rewritten |
| SCN-04 | FR-001, FR-006 | Direct lesson video is uploading, processing, ready or failed | Render editor | Exact accessible text status appears beside that lesson; failed/processing is not ready | No provider secret or public URL is rendered |
| SCN-05 | FR-005, FR-017 | Lesson threshold is 90 and watch progress is below 90 | Change threshold to 95, reload, and answer a question | 95 persists for tracking/reporting and assessment remains available when lesson opens | No new server-side assessment gate |
| SCN-06 | FR-009, FR-010 | Same normalized code exists in same or different company; include a racing collision | Submit whitespace/case variant | Different company succeeds normalized; same company shows inline error with zero partial course/version | No cross-tenant leak; unrelated query errors are not translated |
| SCN-07 | FR-011 | Course was created successfully | Open draft editor | Permanent normalized code is readable and not editable | No supported mutation changes code |
| SCN-08 | FR-012 | New version has pending and in-progress assignments | Open publish modal, publish default, then separately opt into replacement | Default retains all assignment version IDs; explicit option uses existing audited replacement | No completed/waived history rewrite or implicit restart |
| SCN-09 | FR-014 | Three lessons, questions and options | Reorder each level by pointer and keyboard, reload and preview | Persisted order is contiguous and preview matches | No duplicate/missing positions or cross-parent moves |
| SCN-10 | FR-014, FR-017 | Reorder payload is stale, duplicated, incomplete or cross-tenant | Submit reorder command | Validation error and exact sibling snapshot unchanged | No partial position updates |
| SCN-11 | FR-013, FR-016 | Question has multiple alternatives at 320 CSS pixels | Navigate with keyboard/screen reader | Each text/correct selector has unique positional label and visible focus with no page overflow | No ambiguous repeated accessible name or clipped primary action |
| SCN-12 | FR-017 | User lacks permission, uses foreign-company record, or permission is revoked | Directly invoke affected route/action | Server denies and positive authorized control succeeds | No reliance on hidden UI for authorization |
| SCN-13 | FR-007, FR-008 | Local edit succeeds, validation fails, or request loses network | Edit and attempt navigation while each state is active | Clean/dirty/saving/saved/error is truthful; dirty/error navigation asks; saved does not | Failed request never becomes saved and listeners do not duplicate |
| SCN-14 | FR-015, FR-016 | Create, save, media or publish request is running at desktop and 320 px | Activate initiating control repeatedly | Control disables, noticeable waits have text, layout wraps/stacks | No duplicate operation or horizontal page overflow |

## Questions and decisions

| Question ID | Affected rules/scenarios | Material decision needed | Answer / spec reference | Status and consequence of deferral |
| --- | --- | --- | --- | --- |
| Q-001 | FR-001–FR-004; SCN-01–SCN-03 | How should direct lessons and reusable modules coexist? | Mutually exclusive; preserve and block legacy mixed state. User approved 2026-09-09. | RESOLVED |
| Q-002 | FR-005, FR-017; SCN-05 | Does watch threshold block assessment? | No. It is tracking/reporting only and assessment opens immediately, per product spec §7 and user confirmation “Não é pra bloquear” on 2026-09-10. | RESOLVED |
| Q-003 | FR-012; SCN-08 | What is the publication assignment default? | Keep existing assignments; replacement is explicit. User approved 2026-09-09. | RESOLVED |

## Test plan and execution evidence

| Scenario IDs | Test level and real boundary | Fixtures / controls | Assertions | Task ID | Test file / name | Defect-sensitivity evidence | Execution command / result |
| --- | --- | --- | --- | --- | --- | --- | --- |
| SCN-01–SCN-03 | Pest Service/Action/Livewire | Empty, direct, module and mixed provenance | Mode agreement, preserved snapshots, blocked conflict | T003, T007–T011 | `tests/Feature/Courses/HybridCourseCompositionTest.php`; `CourseEditorTest.php` | New composition tests detected omitted/misidentified preview items during canonical verification | PASS — included in 96-test focused run and 681-test canonical run |
| SCN-04, SCN-05 | Pest Livewire and training Action | Four video states; below-threshold progress positive control | Text states, threshold persistence, immediate answer | T012–T017 | `tests/Feature/Courses/CourseEditorTest.php`; existing training tests | Existing hidden threshold behavior and the unchanged immediate-answer positive control prove the regression boundary | PASS — included in 74-test scoped run and canonical run |
| SCN-06, SCN-07 | Pest Action/Livewire with real SQLite transaction | Two companies, same-company duplicate, forced named collision | Normalized rows, inline error, no partial row, read-only render | T018, T020–T021 | `tests/Feature/Courses/CourseAuthoringTest.php` | Forced query-listener race reaches the database collision after friendly validation | PASS — included in focused and canonical runs |
| SCN-08 | Pest publication Action/Livewire | Pending, in-progress, completed assignments | Default retention and explicit audited replacement | T019, T022–T024 | `tests/Feature/Courses/CourseEditorTest.php` | Prior replacement-default expectation required explicit opt-in after the safe default change | PASS — included in focused and canonical runs |
| SCN-09, SCN-10 | Pest structural Action plus browser | Exact sibling sets and invalid payload variants | Contiguous order, unchanged rejection snapshot, preview parity | T025–T027 | `tests/Feature/Courses/CourseEditorTest.php` | New question/option and invalid-order cases execute the transactional rejection branches | PASS automated boundary; pointer interaction remains assigned to QA |
| SCN-11, SCN-13, SCN-14 | Real browser at desktop/320 px, keyboard and pointer | Authenticated isolated QA database; network failure simulation | Names/focus, state announcements, guard, loading, overflow, duplicate prevention | T012, T015, T027–T030 | Browser QA artifact for this run | Browser-only interaction evidence required | NOT_RUN |
| SCN-12 | Pest route/Policy/Action | Authorized positive control, denied/revoked/foreign actors | Exact denial and zero writes | T007, T018, T025 | Focused course authorization tests | Revocation-after-mount and foreign-record cases execute Action-side reauthorization | PASS — included in focused and canonical runs |

## Automation exceptions

| Scenario IDs | Why automation is infeasible | Alternative evidence | User decision and residual manual work |
| --- | --- | --- | --- |
| SCN-11, SCN-13, SCN-14 | Pest rendering cannot prove pointer drag, browser focus, cancellable navigation, network failure hooks or viewport overflow | Executable browser QA on the isolated authenticated local environment | Accepted as required browser evidence in the approved contract; no residual manual-only claim |

## Readiness

- Known material questions resolved; no deferrals remain.
- User confirmation of consolidated behavior: CONFIRMED on 2026-09-10, including immediate assessment availability.
- Every accepted scenario has a planned automated or executable browser check.
- Implementation evidence: automated checks are recorded above and in `implementation.md`; browser-only scenarios remain pending for the independent QA gate.
