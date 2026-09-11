# Shared behavioral contract — draft for continuation

## Status and owner intent

This records the latest request, NOT approval of a detailed implementation architecture. Unify company and platform editor PHP behavior, Blade/HTML, and browser interaction logic; do not merely share HTML while leaving duplicated save logic. Fix the narrow assessment inputs and course description width divergence. Add reproducible automated tests/CI so known regressions do not require agent-driven QA every time.

D-01 OWNER-DECIDED: explicit Save button with unsaved-changes warning for BOTH company and platform. Exact answer: "Salvar explicitamente, com botão Salvar e aviso de alterações pendentes". Typing/blurring is not implicit persistence; Save is the draft persistence boundary, distinct from Publish. Update old autosave-specific spec/test steps to this accepted decision while preserving no-data-loss, correct-identity and truthful acknowledgement assertions. Do not discard existing tests wholesale or retain accidental per-field autosave in one context. Detail staged structural/media operations and failure/retry behavior in the new specification before implementation; do not treat this choice as approval of every persistence design.

D-02 Framework choice: no final owner choice between Dusk and Pest Browser. Recommendation is to evaluate Pest Browser first because project uses Pest4 and existing browser regression uses Playwright; Dusk is acceptable if justified. Preserve existing executable evidence rather than rewriting everything solely for tool preference. Select and document a supported solution at planning; benchmark rather than promise a runtime.

D-03 Authorization/publication are server-side context differences, not an editable client is_shared flag. Preserve tenant isolation, shared module lineage/revision protection, immutable published versions, assignment/certificate history, audited publication and discard behavior. Confirm current product sources; earlier owner-approved course work made assessments non-gating. Do not reverse that decision from stale comments.

## Candidate acceptance inventory

Freeze the final matrix after discovery and D-01; these IDs are a proposed common vocabulary, not new blanket approval of every adjacent feature.

- CE-01: company course and platform shared-course routes use one editor implementation; standalone platform module uses the same relevant module/content/assessment editor. Thin context entry points are allowed, cloned view/state/save implementations are not.
- CE-02: question and answer controls use available horizontal space at desktop and remain usable at320px. Course title/description display is not squeezed by action buttons; company/shared detail descriptions use the agreed wide layout. Check rendered dimensions, not only CSS substrings or absence of overflow.
- CE-03: create/edit question and answer text, choose correct answer and change question type without losing other authored values. Reordering lessons/modules/questions/options retains identity, values, positions and intended persistence. Three distinctly populated records, not repeated empty fixtures.
- CE-04: after a moved record is edited and reloaded, only that record and intended nested answer change. Preserve contextual keyboard focus and prevent page overflow.
- CE-05: save UI is truthful under D-01. Unchanged blur of persisted text cannot create a new revision. Validation, stale acknowledgements and failed requests cannot be mislabeled Saved. New real edits persist at the chosen save boundary.
- CE-06: confirmed structural operations enter pending before dispatch; failure stays visible through unrelated search/structural requests. Unavailable module retry refuses safely without composition/content deletion; valid retry resolves its own failure while retaining unrelated dirty fields/errors. Declining confirmation dispatches nothing; acceptance has a real positive control.
- CE-07: no silent transition between direct/module/mixed composition that erases content. Reuse original accepted scenarios in specs/004-fix-course-editor/scenarios.md; do not label previously manual cases automatically covered.
- CE-08: authorizations and ownership checked server-side for reads and writes. Published data/history remain immutable; preserve shared-editor conflict detection, exact-copy behavior, draft discard, media and publication effects through extraction.
- CE-09: a documented command prepares synthetic fixtures and runs the relevant regression suite without a local person's cache path, manually chosen database IDs or pre-existing browser session. Required browser suite must fail CI setup rather than silently skip when prerequisites are missing.
- CE-10: same behavioral test suite runs with company/platform contexts and reports exactly which cases apply; unsupported actions are explicit N/A with reason, not hidden skips. Standalone module shares applicable content/assessment cases.

## Separation of responsibilities

Test agent owns tests/**, isolated test fixture/support scripts, and test-runner/CI configuration (composer.json/lock, package.json/lock, .github/workflows/**) when needed and approved in the execution contract. It does not edit production to make a test pass. It derives expected results from accepted behavior and user-observable outcomes, not implementation details.

Unification agent owns app/**, resources/**, routes/** and translations needed for the shared editor. It does not delete, weaken or rewrite test expectations. It may request test changes through the test owner with a concrete contract reason. It coordinates integration and final checkpoint.

This is the user's explicitly requested two-author split. No two agents edit the same file. Neither reverts another's work. Test fixtures are independent per case/context; cleanup must not interfere with another worker. Ports, browser contexts and databases must be isolated; prior CUA tab reuse caused collisions. Independent code/test reviewers remain fresh agents separate from both authors.

## Shared interface before parallel implementation

Agree stable user-facing labels or test identifiers for course title/description, content block, question, answer, save status, save action (if any), reorder and remove controls. Stable identities must not depend on array position. Test selectors should not require a particular Alpine variable name, inline x-data shape or exact wire:model spelling. Add only minimal semantic hooks in production through its owner. Do not share expected database values by deriving them from the same save code being tested.

## Integration protocol

1. Establish a shared working-tree baseline and apply the already-resolved explicit-Save D-01. Follow required spec/architecture/readiness approval; no invented approval or silent waiver, and do not repeat the save-mode question.
2. Agree/freeze behavioral scenarios and test seams; test author can start fixtures/characterization while production author plans/extracts independent modules.
3. Tests should demonstrate intended failure before relevant fix where feasible. Historical old-key negative control is useful evidence but must be adapted if the new DOM no longer uses those keys; preserve causal sensitivity, not obsolete markup.
4. Integrate both streams in the SAME checkout/checkpoint. Run core Livewire/Action tests, client-state tests and minimal headless browser scenarios for both contexts, then canonical composer verify. Do not treat two separate green branches as integration proof.
5. Run independent whole-scope review/QA at that checkpoint, consolidate findings once, revalidate corrections by impact. Preserve rejected/blocked evidence honestly. No open-ended rediscovery loops.
6. Report passed/failed/skipped cases, durations, fixture setup, artifacts and remaining limitations. UI automation is not a proof of every possible defect or security property. No commit/push/deploy without authorization.
