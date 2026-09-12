# Residual course-editor remediation

## Frozen scope

This owner-approved critical execution corrects only five behaviors proven at the preceding fresh rendered checkpoint: exact nested-field acknowledgement, course-title persistence/validation, keyboard reorder focus restoration, mixed-mode adjacent recovery guidance, and unavailable-module retry error translation.

## Tasks

- [x] RR-01 Reproduce nested dotted-key acknowledgement drift with a defect-sensitive failing test.
- [x] RR-02 Preserve exact pending revision identity across Livewire serialization and acknowledge only the persisted nested field.
- [x] RR-03 Reproduce invalid and recovered valid title editing, including exact persistence after reload.
- [x] RR-04 Correct title validation/save binding without changing permanent course code behavior.
- [x] RR-05 Reproduce and correct focus loss across keyboard lesson reorder morph, including boundary controls.
- [x] RR-06 Render adjacent mixed-mode guidance for every unavailable composition action at desktop and 320 CSS pixels.
- [x] RR-07 Reproduce unavailable-module retry after reconnect and translate it to actionable UI state with zero partial writes.
- [x] RR-08 Add focused automated coverage for each corrected branch and retain all previously resolved course-editor regressions.
- [x] RR-09 Run focused tests, preview JavaScript tests, Pint, build, diff check, and canonical verification with the temporary 512 MB PHPRC override.
- [x] RR-10 Freeze one checkpoint for fresh independent review, executable QA, design review, and architecture conformance.

## Defect sensitivity

The first focused execution failed five of the six new checks, each at its intended causal boundary:

- AC-01 dispatched revision `22` for the first dotted alternative instead of its exact pending revision `21` and retained the wrong map shape.
- AC-02 still rendered the title with a blur-only binding, so the keyboard-oriented recovery contract was absent.
- AC-03 rendered neither a stable lesson focus token nor a post-morph restoration event.
- AC-04 rendered only the page-level mixed warning; the disabled module and lesson actions had no adjacent mixed-mode descriptions.
- AC-05 let the known stale selected-module refusal escape as `LogicException`, reproducing the uncaught failure. The unrelated-exception positive control already passed.

After the corrections, the same six focused checks passed with 51 assertions. This before/after result establishes that the new checks detect the five residual behaviors rather than merely restating existing implementation.

## Implementation evidence

- AC-01 / INV-01 / INV-02: browser pending revisions now cross the Livewire boundary as a list of exact `{property, revision}` records, avoiding dotted-key interpretation. The server removes only the matching property record and returns that request's revision. The browser accepts an acknowledgement only when both property and revision match its current pending record, so newer and unrelated edits remain dirty.
- AC-02 / INV-01 / INV-03: the course title uses the same input-driven debounce boundary as nested alternatives, keeps explicit field feedback beside the control, and continues through the approved `UpdateCourseEditorField` scalar Action. The regression check proves invalid storage remains unchanged, valid recovery reaches Saved, the draft version title stays aligned, and reload returns the exact title.
- AC-03: each lesson move control exposes the stable lesson ID plus direction. A successful reorder dispatches the moved lesson token after reload; post-morph restoration first targets the corresponding enabled control and then the moved lesson's nearest enabled move control at a boundary.
- AC-04 / INV-01 / INV-04: mixed mode now renders separate adjacent guidance for the module and direct-lesson creation regions. Every disabled creation action references the applicable guidance with `aria-describedby`; the test proves both inventories and publication refusal remain unchanged.
- AC-05 / INV-01 / INV-03: module availability continues to be re-resolved by `UpdateCourseModuleComposition` inside its locked, authorized transaction. The editor translates only the known `One or more selected modules are unavailable.` domain refusal into the existing `modules` validation surface, restores persisted composition, and marks a truthful validation-error/unsaved state. Other exceptions are explicitly proven to propagate.

No translation, schema, dependency, authorization, publication, assignment, or assessment-gating behavior changed.

## Verification

- Defect-sensitive focused checks: PASS, 6 tests / 51 assertions.
- Course editor plus direct authorization, hybrid composition, and shared archive regressions: PASS, 69 tests / 439 assertions.
- Full `CourseEditorTest`: PASS, 52 tests / 356 assertions.
- Existing JavaScript suite: PASS, 25 tests.
- Pint: PASS.
- Production Vite build: PASS; only the existing chunk-size and optional font fallback notices were emitted.
- `git diff --check`: PASS.
- Canonical `PHPRC=/tmp/oceanix-course-editor-readiness.ZO6Qay/php.ini composer verify`: PASS, 709 tests / 3413 assertions with 13 PostgreSQL-only tests skipped by the SQLite environment; JavaScript, Pint, and build stages also passed.

Implementation checkpoint: `course-editor-residual-remediation-impl-r0-20260910`.

## Consolidated remediation round 1

Findings covered: `CR-001`, `TA-001`, and `TA-002` only.

### Defect sensitivity and correction

- The strengthened retry check began with one persisted module composition plus a real question and two answer rows. The known unavailable candidate left every identity, position, count, and non-empty row snapshot exact, proving the transactional refusal creates no partial writes.
- Before correction, making that candidate available and retrying persisted the valid composition but left `clientHasUnsavedChanges=true`; the test failed before its Saved-state assertion. The editor now records a module-specific error-pending marker. A valid retry clears that marker and the `modules` error, then recomputes the global state only from exact authored revisions and authored validation errors. It reaches Saved when none exist and remains dirty or validation-error when unrelated authored state exists.
- A separate recovery check proves a pending `courseForm.title` revision and its validation error survive a successful module retry unchanged, while the module-specific marker and error clear.
- The unrelated-exception control now injects a distinct `LogicException('unrelated composition invariant')` through the Livewire Action boundary and asserts the identical exception object, type, and message propagate. This executes the selective `LogicException` guard rather than bypassing it with another exception type.

### Round-1 verification

- Directed finding checks: PASS, 3 tests / 44 assertions.
- Course editor plus direct authorization, hybrid composition, and shared archive regressions: PASS, 70 tests / 472 assertions.
- Existing JavaScript suite: PASS, 25 tests.
- Pint, production Vite build, and `git diff --check`: PASS; only the existing build notices were emitted.
- Canonical `PHPRC=/tmp/oceanix-course-editor-readiness.ZO6Qay/php.ini composer verify`: PASS, 710 tests / 3446 assertions with 13 PostgreSQL-only tests skipped by the SQLite environment; JavaScript, Pint, and build stages also passed.

Round-1 implementation checkpoint: `course-editor-residual-remediation-impl-r1-20260910`.

## Consolidated remediation round 2

Finding covered: `CR-001` only.

### Defect sensitivity and correction

- The final directed check seeded an unresolved browser network failure, reproduced the known unavailable-module refusal, then made that module valid and retried. Before correction, module recovery recomputed from only authored revisions and validation errors, cleared the global pending flag, and falsely reached Saved; the test failed at the expected pending-state assertion.
- The browser now records unresolved request-failure provenance explicitly in Livewire state. Module recovery includes that provenance when removing only its module-specific marker and error, so the valid module write can succeed while the editor remains truthfully unsaved in `network-error` and publication stays blocked.
- A matching authored field acknowledgement clears the browser network marker along the existing successful-retry path. Unrelated successful structural mutations do not clear it.
- The final causal regression set retains the round-1 controls: module-only recovery still reaches Saved, unrelated authored revision/validation state remains exact, exact dotted acknowledgement remains correlated, and unrelated `LogicException` still propagates unchanged.

### Round-2 verification

- Directed causal regression set: PASS, 5 tests / 62 assertions.
- Course editor plus direct authorization, hybrid composition, and shared archive regressions: PASS, 71 tests / 484 assertions.
- Existing JavaScript suite: PASS, 25 tests.
- Pint, production Vite build, and `git diff --check`: PASS; only the existing build notices were emitted.
- Canonical `PHPRC=/tmp/oceanix-course-editor-readiness.ZO6Qay/php.ini composer verify`: PASS, 711 tests / 3458 assertions with 13 PostgreSQL-only tests skipped by the SQLite environment; JavaScript, Pint, and build stages also passed.

Final implementation checkpoint: `course-editor-residual-remediation-impl-r2-final-20260910`.

## Exclusions

Do not address deferred hardening, provider-fixture setup, preview-share console initialization, schema/dependency changes, unrelated UI redesign, or any assessment-gating behavior.
