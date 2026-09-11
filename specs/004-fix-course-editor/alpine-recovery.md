# Course editor Alpine recovery

## Approved mechanical scope

Correct only the rendered Alpine root quoting defect and the unavailable-module recovery guidance. Preserve all server-side behavior and existing per-field revision semantics.

## Tasks

- [x] AR-01 Add a defect-sensitive assertion that renders/parses the complete `x-data` attribute without leaked selector source.
- [x] AR-02 Correct HTML-safe selector construction without changing the client state machine.
- [ ] AR-03 Prove mutually exclusive save/error states, 320-pixel width, and focus restoration in browser QA.
- [x] AR-04 Add actionable English-first unavailable-module recovery copy and Portuguese mapping.
- [x] AR-05 Prove safe retry, zero partial writes, exact exception boundary, and adjacent accessible recovery guidance.
- [x] AR-06 Run focused tests, editor JavaScript tests, Pint, build, diff check, and canonical verification.
- [x] AR-07 Freeze a checkpoint for independent Code Review, Test Analyst, Design Review, QA, and completion gates.

## Implementation evidence

Checkpoint: `course-editor-alpine-recovery-impl-r2-final-20260910`

- Defect sensitivity: before the repair, the rendered-root regression test parsed the `x-data` attribute only through `document.querySelector(`[data-lesson-focus-id=` and the unavailable-module test could not find linked recovery guidance.
- Alpine repair: the existing focus selectors now use single quotes around their CSS attribute values, so the surrounding HTML attribute remains complete while the client state machine and focus token behavior are unchanged.
- Recovery guidance: only the known unavailable-module refusal receives adjacent English-first refresh, reselect, and retry guidance, with a Portuguese mapping and `aria-describedby` linkage from the module section.
- Safety coverage: the existing unavailable-module cases continue to prove the exact typed refusal, unchanged non-empty composition identities, positions, and counts, zero new rows, module-only recovery to Saved, unrelated network/validation/pending provenance, and propagation of the exact unrelated `LogicException`.
- Deterministic verification: the focused repair checks passed 2 tests with 36 assertions; the frozen Course Editor regression surface passed 72 tests with 495 assertions; all 25 editor JavaScript tests passed; Pint, the production build, `git diff --check`, and the canonical 512 MB PHPRC `composer verify` gate passed.
- AR-03 remains assigned to independent browser QA at this checkpoint; implementation evidence does not substitute for that executable QA scenario.

### Round 1 — TA-001

- The rendered-boundary helper now parses the final Livewire HTML and proves exactly one editor Alpine root and one `x-data` attribute, every required state, request, pointer, and focus method inside the complete expression, the expected expression start and terminal boundary, and both intact focus selectors.
- The helper rejects every attribute outside the frozen Alpine/Livewire allowlist and proves selector source appears only inside `x-data`, never as visible text or a bogus attribute. The unavailable-module refusal runs the same helper after rendering its actionable, `aria-describedby`-linked recovery state.
- No production change was required. The directed TA-001 surface passed 4 tests with 82 assertions, including exact non-empty zero-write snapshots, network-error provenance, and exact unrelated `LogicException` propagation. Canonical verification passed at the round-1 checkpoint.

### Round 2 — TA-001

- Replaced the mirrored recovery-translation assertion with concrete rendered semantics that independently require `Refresh the module list`, `reselect an available module`, and `then retry`.
- The final HTML is parsed to prove exactly one module section names `module-unavailable-recovery`, exactly one element owns that ID, and the named callout is a descendant of—and therefore uniquely resolves from—the describing section.
- The complete `x-data` boundary/leak proof runs beside the accessible recovery proof on the same refusal render. Existing exact non-empty zero-write snapshots, unresolved network provenance, and unrelated `LogicException` propagation remain direct controls.
- No production change was required. The final directed TA-001 surface passed 4 tests with 88 assertions, and canonical verification passed at the final round-2 checkpoint.

## Exclusions

No schema, dependency, domain, Action, authorization, publication, assignment, assessment, or general editor refactor is permitted.
