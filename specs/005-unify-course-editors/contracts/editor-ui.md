# Unified editor UI and test-seam contract

## Stable semantic surface

- `[data-editor-root]` with server-rendered context name.
- `[data-editor-save]`, `[data-editor-status]`, and `[data-editor-error]`.
- `[data-editor-record][data-record-type][data-record-key]` for persisted IDs or temporary UUIDs.
- `[data-editor-field][data-field-name]` on labelled field wrappers.
- `[data-editor-structure-action]` and `[data-editor-media-action]` with a named capability/action.

Hooks are observable semantics only. They do not expose authority, provider secrets, or persistence decisions.

## Save behavior

- Input/change marks the latest local generation dirty; blur sends no request.
- Save sends the complete applicable authored snapshot and expected server revisions.
- Its control is disabled while active.
- Success becomes Saved only if no newer generation exists.
- Validation/conflict/network failure remains dirty and accessible, and unrelated success cannot clear it.
- Ctrl/Cmd-S invokes the same Save.
- Unsafe navigation/unload warns once; clean/saved navigation does not.

## Structural and media behavior

- Structural actions are capability-gated, named server operations that persist immediately only when authored state is clean and uploads are safe.
- Confirmed actions expose pending before dispatch; declining dispatches nothing; acceptance persists exactly one intended change and refreshes the canonical snapshot.
- Upload transfer and attaching, replacing, or removing a draft media association use distinct immediate operations with stable tokens and dirty/upload guards.
- Operation and transfer success/failure resolve only their own provenance, never announce authored Saved, and never clear unrelated authored values or errors.
- Stable record keys, never positions, bind DOM identity, immediate operation targets, and upload tokens.

## Responsive/accessibility behavior

- Question/answer shorthand controls apply `min-w-0 flex-1` to the generated field wrapper.
- At desktop, measured question/answer wrappers occupy at least 70% of their content row. At 320 CSS pixels they stack and occupy at least 90% of the inner content container; the page does not overflow, labels/errors remain associated, and keyboard focus is visible.
- Both course-detail pages use the existing wide description variant; unrelated heroes keep the default.
- Status is textually announced and does not rely on color.

## Context applicability

The shared suite reports company course, shared course, and standalone module. Missing capabilities are explicit N/A with a server-domain reason, never hidden skips.
