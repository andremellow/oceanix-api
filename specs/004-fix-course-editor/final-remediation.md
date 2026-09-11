# Final directed remediation

## Approved scope

This critical-assurance execution is limited to the seven blocking findings discovered by the parent run's complete design review and executable QA: `DR-001` through `DR-004` and `QA-F001` through `QA-F003`. The parent specification, scenarios, architecture, UI contract, invariants, and product decisions remain authoritative.

## Implementation tasks

- [x] FR-01 Reproduce nested alternative keyboard persistence loss with a failing automated test and identify the event/binding cause.
- [x] FR-02 Correct nested alternative persistence without weakening save-state or authorization boundaries.
- [x] FR-03 Reproduce and correct pointer lesson reorder through the existing exact-sibling Action; retain single-mutation semantics.
- [x] FR-04 Reproduce publication no-op for retain and replace, including the false-dirty modal/poll interaction, and correct the causal state transition.
- [x] FR-05 Ensure any real publication refusal renders an actionable error.
- [x] FR-06 Add unique lesson-context accessible names to move/remove/expand/drag controls.
- [x] FR-07 Render the approved mode-specific composition conflict explanations.
- [x] FR-08 Make mixed-mode primary publication genuinely unavailable before modal entry.
- [x] FR-09 Add focused Pest/JavaScript coverage for every changed branch and record defect sensitivity.
- [x] FR-10 Run focused verification, `git diff --check`, Pint, preview JavaScript tests, production build, and canonical `composer verify` with the documented temporary memory override.
- [x] FR-11 Freeze one implementation checkpoint and hand it to fresh independent Code Review and Test Analyst gates.

## Prohibited expansion

Do not address deferred `CR-006`, `CR-007`, `QA-NB001`, or unrelated editor improvements. Do not change the database schema, dependencies, authentication, authorization model, published-version immutability, assignment semantics, or non-gating assessment rule.

## Causal diagnosis and corrections

| Findings | Confirmed cause | Correction and evidence |
| --- | --- | --- |
| `QA-F003` | Nested answer text used a blur-only binding that issued no request in the 320-pixel keyboard flow. | Each alternative now uses an input-driven 500 ms Livewire binding. A focused test proves both rendered bindings, persistence, and an exact fresh-component reload of two distinct answers. |
| `QA-F001` | Lesson reorder depended only on native HTML drag events, which the real pointer gesture did not trigger. | The lesson handle now starts a typed pointer gesture and the target lesson submits one complete ordered-ID payload to the existing `reorderLessons` method and exact-sibling Action. The server reorder, mirror parity, reload, and preview assertions remain green. |
| `DR-001`, `QA-F002` | Root-level dirty capture treated every page input/change as authored work, so synthetic modal/poll morph activity could queue a hidden dirty flag. A real save refusal lived only in the error bag and was not rendered. | Dirty capture is limited to trusted events inside explicitly marked authored controls; transient publication choices, modal state, search, and video polling are excluded. Both the page and open modal render the actionable publication error. Existing retain and replace publication tests prove the unchanged atomic domain semantics. No Course Action or Service required modification. |
| `DR-002` | Lesson icon controls reused generic accessible names. | Move, remove, expand/collapse, and existing drag controls now announce the lesson position; boundary disabling and focus classes remain intact. |
| `DR-003` | Approved direct/module conflict strings existed but were not adjacent to disabled actions. | Direct and module modes now render the specific removal requirement, and disabled incompatible actions reference that explanation with `aria-describedby`. |
| `DR-004` | Alpine's client disabled binding could remove the server-rendered mixed-mode disabled attribute. | The primary binding now includes the live `compositionMode === 'mixed'` condition, so the Publish control remains unavailable before modal entry at every viewport. |

## Defect sensitivity

Before the correction, the six focused regression tests all failed: nested binding/reload, trusted authored dirty scope, pointer wiring, visible publication refusal, contextual lesson names, and composition explanations. After the correction, those six tests passed. The complete `CourseEditorTest` then passed with 44 tests and 250 assertions.

## Verification

- `php artisan test tests/Feature/Courses --compact`: 113 passed, 3 PostgreSQL-only checks skipped, 693 assertions.
- `npm run test:preview`: 25 passed.
- `./vendor/bin/pint --test`: passed.
- `npm run build`: passed; existing optional font optimization and chunk-size warnings only.
- `git diff --check`: passed.
- `PHPRC=/tmp/oceanix-course-editor-readiness.ZO6Qay/php.ini composer verify`: 701 passed, 13 environment-gated checks skipped, 3307 assertions; JavaScript, Pint, and build stages passed.

## Implementation boundary

Production changes are limited to the company course editor and contextual English/Portuguese UI strings. Automated changes are limited to the focused course editor feature tests. No schema, dependency, authentication, authorization, publication-domain, assignment-domain, video-provider, or assessment-gating code changed.

## Consolidated remediation round 1

Round 1 is limited to `FCR-001`, `FCR-002`, `FCR-003`, `FCR-004`, `TA-001`, and `TA-002` from the frozen finding ledger. It does not reopen or expand the approved acceptance criteria, invariants, regression surfaces, or QA matrix.

| Findings | Confirmed cause | Causal correction and automated evidence |
| --- | --- | --- |
| `FCR-001` | Every successful `touchSaved()` call cleared the browser's global unsaved flag and acknowledged its latest revision, even when the completing structural request had not persisted an unrelated pending or invalid scalar edit. | Scalar field saves now explicitly acknowledge their authored revision. Structural completions do not acknowledge unrelated browser work: pending work stays `dirty`, validation failures stay `validation-error`, publication stays blocked, and a separate structural completion event only ends the structural pending indicator. Tests prove both pending and invalid scalar work survive a successful structural action, while a correlated scalar save still clears its exact revision. |
| `FCR-002` | Capture-phase click tracking marked a destructive button dirty before Livewire's confirmation gate, so cancelling the confirmation left a false unsaved state without a request that could acknowledge it. | Generic capture-phase mutation click tracking was removed. Confirmed structural operations report their actual server outcome, while cancelled confirmations issue no mutation and create no dirty state. The focused rendered-contract assertion proves confirmation remains attached and no pre-confirm capture handler exists. |
| `FCR-003` | `ValidationException` from the exact-sibling reorder Action populated the error bag but did not update the editor state or render the `order` message, leaving the client in `saving`. | All lesson/question/option reorder entry points use one validation boundary that sets `validation-error`, preserves unsaved intent, and emits the matching client event. The lesson section renders the existing actionable reload-and-retry message. A Livewire test submits a stale order and proves the message, error state, unsaved block, event, and unchanged lesson/mirror snapshots. |
| `FCR-004` | Lesson drop depended on the `pointerup` event target. With implicit touch/pen pointer capture, that target remains the source handle even when the pointer is released over another lesson. | One window-level terminal handler clears the active drag before any work, resolves the physical release location through `document.elementFromPoint()`, validates the target's version and ID, and issues at most one `reorderLessons` request. Releases without a valid sibling and every `pointercancel` clear without a request. The rendered-contract test proves coordinate targeting, target metadata, window termination, clear-before-request ordering, and removal of target-card `pointerup`. |
| `TA-001` | The previous lesson persistence test called keyboard-oriented `moveLesson`; it did not execute the public `reorderLessons` Livewire boundary used by pointer ordering. | The test now sends a three-lesson ordered-ID payload through `reorderLessons` and proves contiguous lesson positions, compatibility-mirror positions, exact fresh-component reload order, and public-preview parity. |
| `TA-002` | Publication refusal coverage began with an already incomplete draft and did not prove the modal stays usable when readiness changes after a clean open. | A new test opens a publishable draft with an empty problem list, deterministically removes an option after modal open, invokes publication, and proves no redirect, draft status, open modal, refreshed problem list, heading, and exact visible refusal text. |

### Round-1 defect sensitivity

Before the correction, the focused five-test batch produced three causal failures: pointer lifecycle/targeting markup was absent, stale Livewire reorder left `saveState` clean, and a structural action dispatched `editor-saved` for unrelated dirty work. The two test-analyst additions already passed against the server behavior, correctly identifying missing regression coverage rather than another production failure. Source inspection also isolated the cancelled-confirmation defect to the pre-confirm `x-on:click.capture` handler; the final rendered assertion locks its removal while retaining `wire:confirm`.

### Round-1 verification

- Focused five-test remediation batch: 5 passed, 63 assertions.
- Complete `CourseEditorTest`: 46 passed, 291 assertions.
- `php artisan test tests/Feature/Courses --compact`: 115 passed, 3 PostgreSQL-only checks skipped, 724 assertions.
- `npm run test:preview`: 25 passed.
- `./vendor/bin/pint --test`: passed.
- `npm run build`: passed; existing optional font optimization and chunk-size warnings only.
- `git diff --check`: passed before the evidence/checkpoint update and is rerun afterward.
- `PHPRC=/tmp/oceanix-course-editor-readiness.ZO6Qay/php.ini composer verify`: 703 passed, 13 environment-gated checks skipped, 3348 assertions; JavaScript, Pint, and build stages passed.

Round 1 changed only `resources/views/components/courses/⚡editor.blade.php`, `tests/Feature/Courses/CourseEditorTest.php`, this evidence artifact, and the final execution checkpoint. No Action, Service, translation, schema, dependency, authentication, authorization, publication-domain, assignment-domain, video-provider, or assessment-gating change was required.

## Consolidated remediation round 2

The second and final critical remediation round is limited to the remaining `FCR-001` case. Round 1 separated structural acknowledgements, but a successful scalar save still cleared the global dirty flag even when a different scalar field retained a validation failure.

### Causal correction

Authored controls now record their `wire:model` field path and revision in a per-field pending map. Every scalar persistence branch acknowledges only its own field path and captured revision through `editor-field-saved`. The server removes only that field from the pending map, recomputes unsaved state from the remaining field revisions and authored validation errors, and never emits a global `editor-saved` acknowledgement for the scalar request. The browser likewise removes a field only when the returned path and revision match its current pending entry, so a stale response cannot clear a newer edit to the same field.

If one field fails validation and a different field saves, the successful field persists and is removed from the map while the failed field, its inline error, `validation-error` state, navigation warning, and publication block remain. Fixing that failed field later can acknowledge its own current revision normally. Structural acknowledgement behavior and all other resolved findings are unchanged.

### Defect sensitivity and evidence

The new regression test first failed because the editor had no per-field revision state and could only globally acknowledge the latest browser revision. After the correction it performs the exact remaining sequence: an invalid lesson title at revision 11 fails without changing the database, a separate description at revision 12 persists, only the description acknowledgement is emitted, the title revision remains pending, the title error stays visible, the editor remains `validation-error`, and publication remains blocked with no global saved acknowledgement. Rendered assertions also prove authored controls derive their field path from `wire:model`, send `clientFieldRevisions`, and consume the field-specific acknowledgement event.

### Round-2 verification

- Focused causal regression set: 4 passed, 56 assertions; final narrow rerun: 3 passed, 53 assertions.
- Complete `CourseEditorTest`: 47 passed, 313 assertions.
- `php artisan test tests/Feature/Courses --compact`: 116 passed, 3 PostgreSQL-only checks skipped, 756 assertions.
- `npm run test:preview`: 25 passed.
- `./vendor/bin/pint --test`: passed.
- `npm run build`: passed; existing optional font optimization and chunk-size warnings only.
- `PHPRC=/tmp/oceanix-course-editor-readiness.ZO6Qay/php.ini composer verify`: 704 passed, 13 environment-gated checks skipped, 3370 assertions; JavaScript, Pint, and build stages passed.
- `git diff --check`: passed before the evidence/checkpoint update and is rerun afterward.

Round 2 changed only the approved company course editor, its focused course editor feature test, this evidence artifact, and the execution checkpoint. No Action, Service, translation, or other production boundary changed.
