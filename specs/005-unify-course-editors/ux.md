# UX contract: Unified course editors

This is the approved authoring contract for scope `unified-course-editor-scope-v1`. It binds the shared editor presentation to `spec.md`, `scenarios.md`, `contracts/editor-ui.md`, and `docs/control-center-design-system.md`. It is implementation guidance, not implementation evidence or an independent post-implementation verdict.

## Existing design-system references

- Preserve the control center's calm, operational hierarchy: one page `h1`, decision-first ordering, white cards on `surface-canvas`, quiet borders/shadows, visible ownership and status text, and no internal IDs or raw payloads.
- Keep the existing authenticated canvases and layout ownership. Company routes use `layouts::app`; platform routes use `layouts::platform`. Both retain the `max-w-[1480px]` canvas and their current mobile gutters (16px company, 20px platform). Unifying the editor does not unify shells, navigation, authorization, or route context.
- Reuse `x-page-hero`, `x-empty-state`, `x-field-label`, `x-field-hint`, `x-status-message`, Flux fields/buttons/callouts/modals/editor, `.detail-card`, `.form-panel`, `.status-pill`, `.admin-primary-action`, and the shared `x-editor-save-bar`. Common editor behavior must not be reproduced as context-specific markup or Alpine state.
- Preserve the `x-page-hero` composition established by the current component: kicker/action row first, then title and description. Actions may wrap but never share the title's width track or squeeze title/description.
- Preserve current rich-content integration and its toolbar, image/video library patterns, preview panel, sanitizer/renderer boundary, and light-only theme. Upload ownership and library content remain server-resolved by context.
- English remains the canonical visible source and accessible-name language. Portuguese is translation-only. New status, validation, confirmation, recovery, and upload strings require Portuguese mappings.
- D-01/D-04/D-05 are binding: authored scalar, rich-content, and assessment fields use one explicit atomic Save. Dedicated structural operations and media actions persist immediately only through named server-authorized operations with dirty/upload guards and action-specific state. Typing, change, blur, and answer selection only make the authored graph dirty. Every failed authored Save retains that complete graph and commits none of its payload.

### Existing behavior to preserve or correct

| Surface | Preserve | Required correction |
| --- | --- | --- |
| Company course editor | Course/draft identity, direct-versus-module composition guidance, module picker grouping, lesson/content/assessment affordances, company media ownership, publication impact | Replace per-field autosave and independent browser state with the common explicit-save contract |
| Shared-course editor | Explicit save bar, shared ownership labels, prepared module lineage, create/attach/remove flows, upload tokens, publication impact/restart control | Render the common editor while keeping each dedicated structural/media operation server-authorized, safety-gated, and truthful about independent persistence |
| Standalone shared-module editor | Shared-module update/publish permissions, propagation impact, shared media library, explicit save | Render the same applicable module/content/assessment component and common state machine |
| Question/answer controls | Flux fields with visible labels and stable question/option keys | Apply `min-w-0 flex-1` to the generated labelled field wrapper, not only the inner control |
| Company and shared-course detail heroes | Actions above title/description and independently wrapping | Both pass the course-specific wide-description variant; unrelated `x-page-hero` instances retain `max-w-2xl` |

## Components and tokens

### Shared editor anatomy

One `[data-editor-root]` owns, in order:

1. `x-page-hero` with context kicker, draft title, concise purpose, ownership/status labels, and context actions.
2. Preview availability, publishability/composition callouts, and a textual editor status region.
3. Course details when the context is a course.
4. Reusable composition controls when the context supports composition.
5. Repeated content/module records with stable identity, rich content, media, thresholds, and the common assessment editor.
6. Context-specific publication impact and controls.
7. One fixed shared save bar, with enough calculated bottom padding that it never covers the last control at any width.

The save bar has a single primary **Save changes** action. A context may additionally expose **Save and close** using the same atomic Save. Cancel/back is navigation and therefore observes the unsaved guard; it is not a second persistence path. `Ctrl+S` and `Cmd+S` invoke **Save changes** only when the editor is dirty, is not already saving, and no modal owns focus.

### Common primitives

| Primitive | Required anatomy and behavior | Allowed context variation |
| --- | --- | --- |
| Editor status | `[data-editor-status]`, `role="status"`, `aria-live="polite"`, `aria-atomic="true"`; one textual state at a time | Dirty-module count may supplement, never replace, the common state label |
| Editor error | `[data-editor-error]`; danger `flux:callout`; heading plus cause-appropriate recovery; first invalid field also receives inline `flux:error` | Conflict may include server revision/time if human-readable; no internal token is displayed |
| Save action | `[data-editor-save]`; Flux primary button; disabled when clean or saving; remains visible | Save-and-close is optional; it is disabled during active upload and uses the same Save payload |
| Record | `[data-editor-record][data-record-type][data-record-key]`; `wire:key` derives from persisted ID or temporary UUID, never array position | `record-type` is `lesson`, `module`, `question`, or `option` |
| Labelled field | `[data-editor-field][data-field-name]` on the Flux field wrapper; visible label; inline error associated with the control | Course/module applicability only |
| Structural action | `[data-editor-structure-action="<name>"]`; native/Flux button; capability-gated; dirty/upload safety gate; pending/success/failure/confirmation behavior; may persist independently of authored Save | Add/remove/reorder/composition actions are omitted or disabled with explanation according to server capability; the named context collaborator owns persistence |
| Media action | `[data-editor-media-action="<name>"]`; stable record key/upload token binding; dirty/upload safety gate; textual pending/success/failure status; may persist independently of authored Save | Company library is company-owned; platform library is shared/platform-owned; the named context collaborator owns association persistence |
| Empty state | `x-empty-state` with icon, specific title, one-sentence explanation, and an action only if the current capability can resolve it | Empty lessons, modules, questions, images, videos, and search results use context-specific nouns |

### Context capability matrix

Absence is explicit in the shared test matrix as N/A with the server-domain reason. The browser never selects these capabilities.

| Capability | Company course | Platform shared course | Standalone shared module |
| --- | --- | --- | --- |
| Course code/title/description and employee description | Yes; permanent code remains read-only if current policy says so | Yes, under platform authority | N/A: no course record |
| Direct lessons | Yes, only in direct/empty composition | N/A: shared courses compose prepared shared modules | N/A |
| Reusable composition | Published eligible company/shared modules; mutually exclusive with direct lessons | Prepared shared-module drafts; create/attach/remove association with lineage protection | N/A: this is the module itself |
| Common module/content/assessment fields | Yes for direct lessons and applicable composed content presentation | Yes | Yes |
| Media library/transfer | Company-owned scope | Platform/shared scope; token bound to module identity | Platform/shared scope; token bound to module identity |
| Course publication | Company policy and assignment update impact | Shared-course publication plus module propagation/restart policy | N/A |
| Module publication | N/A as a standalone action | Coordinated through approved shared-course publication behavior | Shared-module propagation impact and restart policy |
| Discard/archive/remove lineage actions | Existing company course lifecycle only | Draft discard, association removal with reason, archive where authorized | Existing shared-module discard/archive/publish rules where authorized |

### Semantic tokens

Use the published CSS variables or their existing shared classes: `--ds-surface-canvas`, `--ds-surface-card`, `--ds-surface-subtle`, `--ds-text-primary`, `--ds-text-secondary`, `--ds-text-muted`, `--ds-border-default`, `--ds-action-primary`, `--ds-accent-default`, `--ds-accent-subtle`, `--ds-focus-ring`, `--ds-status-positive`, `--ds-status-negative`, and `--ds-status-warning`. Do not add context-specific palette values for equivalent editor states.

- Cards/panels: existing 20–22px radii, 1px neutral border, `p-5 sm:p-6`, soft existing shadow.
- Nested assessment records: existing 18px radius and neutral border; use `min-w-0` at every grid/flex ancestor that owns long authored text.
- Controls: existing 12px radius, at least 44px control/action height where the shared Flux primitive supports it, white surface, dark text, neutral border, petrol focus border/ring.
- State semantics: saved uses positive text; dirty and upload/processing use warning text; validation, conflict, network, upload failure, and permission loss use negative text. Every state also has words and/or an icon with an accessible name.

## Responsive and accessibility behavior

### Width and reflow contract

Desktop evidence uses a 1440 × 1000 CSS-pixel viewport; narrow evidence uses exactly 320 × 800 CSS pixels. Measurements are taken after fonts and Livewire rendering settle and with representative long natural-language and unbroken strings.

- At both widths, `document.documentElement.scrollWidth <= document.documentElement.clientWidth`; no editor, save bar, modal, toolbar, card, labelled field wrapper, or action row creates page-level horizontal overflow. A rich-editor toolbar may scroll within its own labelled container only if all commands remain keyboard reachable.
- The editor root fills the shell content box: at 320px it is 288px wide in the company shell (16px gutters) and 280px wide in the platform shell (20px gutters), allowing at most 1px rounding variance. Shared components use `w-full min-w-0 max-w-full` behavior and do not impose `min-w-72` or another minimum wider than their container.
- Base layout is one column. Hero actions, course-detail fields, question metadata, answer controls, picker controls, publication metrics, modal actions, and save-bar actions stack or wrap without overlap. At `sm`, suitable action rows and small grids may become horizontal; the full question metadata desktop grid is reserved for `lg` so 320px never inherits fixed 180px/140px tracks.
- At 320px, each prompt and answer labelled field wrapper starts on a full available row after any drag/correctness control group. At the deepest nested assessment level it is at least 200 CSS pixels wide in all three contexts, its rendered input is at least 196 CSS pixels wide, and both are at least 90% of their immediate available content row. Reorder/remove controls move to a separate wrapping action group; they may not consume the text field's width track.
- At 1440px, a prompt wrapper is the `minmax(0,1fr)` track and each answer wrapper flexes through all space remaining after the correctness selector and action buttons. For every question/answer, wrapper width is at least 70% of the immediate row and the control width is at least 98% of its wrapper. Long values remain editable rather than truncated inside inputs; summary headings may truncate only when the expanded labelled field retains the full value.
- Course details and editor panels never exceed the shell canvas. The fixed save bar's inner content aligns to the same `max-w-[1480px]` canvas and uses the active shell's mobile gutter.
- Company and platform shared-course detail pages pass a named course-wide description variant to `x-page-hero`. The title and description each occupy their own full-width row below the independent action row; each measures at least 98% of the hero's inner width at both test viewports. The wide description has no `max-w-2xl`; every unrelated hero continues to use the current default `max-w-2xl` constraint.

### Labels, semantics, focus, and keyboard

- One `h1` is supplied by `x-page-hero`; content, assessment, publication, and modal headings follow a logical `h2`/`h3` order.
- No authored field is placeholder-only. Every question prompt label is unique by visible position (for example, **Question 2**); every answer label and correct-answer control includes its answer position and is unique within the question. `for`/`id` or Flux label association and `aria-describedby` connect help and validation text.
- Use `x-field-label` directly inside `flux:field` for passing score, watch threshold, and attempts. Help text uses `x-field-hint` where the narrow numeric column cannot hold prose.
- All actions are native buttons/links or Flux primitives. Icon-only move, drag, remove, preview, collapse, image, and video actions have record-specific accessible names. Disabled composition actions reference visible conflict/recovery copy with `aria-describedby`.
- Every interactive element shows the existing petrol `focus-ring`. Keyboard focus remains on the same stable record after Livewire morph/reorder; after keyboard move it returns to the moved record's corresponding move action (or nearest enabled move action). Adding a question/option/module focuses its first labelled authored field. Closing a modal returns focus to its opener.
- Up/down buttons are the required keyboard reorder path; pointer drag is supplementary. Tab order follows visual order and never enters omitted unauthorized controls. `Escape` closes dismissible libraries/creation dialogs, but not a non-dismissible destructive confirmation while an action is pending.
- The save status is polite; validation, conflict, network, upload, and permission-loss messages use `role="alert"` when newly surfaced. Do not announce every keystroke. Saving disables duplicate Save and sets `aria-busy="true"` on the save region.
- Unsaved navigation is guarded once for in-app navigation and browser unload. Choosing **Stay** preserves focus and the entire staged graph and dispatches nothing. Choosing **Leave** performs the navigation once. Clean/saved navigation does not warn.

### Stable test hooks

The following are semantic API and must survive visual refactoring:

- `[data-editor-root][data-editor-context="company-course|shared-course|shared-module"]`
- `[data-editor-save]`, `[data-editor-status][data-editor-state]`, `[data-editor-error][data-error-kind]`
- `[data-editor-record][data-record-type][data-record-key]`
- `[data-editor-field][data-field-name]`
- `[data-editor-structure-action="add|remove|move-up|move-down|reorder|change-composition"]`
- `[data-editor-media-action="upload|retry-upload|open-library|attach|replace|remove"]`
- `[data-editor-upload][data-record-key][data-upload-state]`
- `[data-course-hero][data-description-width="wide|default"]` on the scoped hero contract

`data-record-key` is a persisted ID or temporary UUID and remains stable across reorder and morph. Hooks expose no permission grants, owner flags usable as authority, revision values, provider secrets, public media URLs, or internal error payloads.

## Loading, empty, validation, error, permission, and destructive states

### Editor state model

| State | Visible contract | Actions and persistence |
| --- | --- | --- |
| Initial loading | Preserve hero/page composition; show textual **Loading editor…** and skeleton/subtle placeholders in the content region | Authored and structural actions disabled; no empty state until canonical load resolves |
| Clean | **All changes saved** | Save disabled; navigation allowed without warning; no revision is created by focus/blur or Save shortcut |
| Dirty | **Unsaved changes**; optional truthful dirty-module count | Save enabled; navigation/close/preview/publication guarded. Authored, structural, and media-association changes have not persisted |
| Saving | **Saving changes…**, `aria-busy=true` | Save and staging actions that could race are disabled; fields remain visible; duplicate click/shortcut is ignored |
| Saved | **All changes saved** (optionally human-readable saved time) only for the latest local generation | Canonical graph/revisions refresh; newer edits remain dirty and are never cleared by an older acknowledgement |
| Validation error | Summary **Some changes need attention** plus inline errors; focus first invalid field on attempted Save | Entire graph remains dirty and unchanged in storage; correction and Save retry remain available |
| Conflict | Danger callout **This draft changed in another session** and **Your unsaved changes are still here** | No write and never Saved. Keep staged values visible. Offer deliberate **Reload latest draft** behind a leave-local-changes confirmation; otherwise user may review/copy values. A blind stale retry is not presented as merge |
| Network/request error | **Changes weren't saved. Check your connection and try again** with error provenance attached to the failed action | Staged graph and prior errors remain; **Try save again** invokes the same atomic Save. Unrelated search/upload refresh success cannot clear it |
| Permission lost | **You no longer have permission to edit this draft** | Current request is denied with zero writes; disable/omit mutation and publication actions, preserve local values long enough to copy, and provide context-safe back navigation. Never imply that re-enabling a hidden control authorizes it |
| Operation pending | Name the action and target, for example **Moving Question 2…** or **Attaching video…**; expose a local busy state on its region | Disable duplicate/conflicting controls, keep unrelated authored values visible, and do not report the editor Saved |
| Operation failed | Action-specific alert with retained provenance and **Try again** when safe | The protected server snapshot remains unchanged for the failed operation; unrelated success does not clear this failure or authored dirty/error state |

### Empty and composition states

- Empty course content names what is absent and offers **Add lesson** only for eligible empty/direct company composition, or module creation/selection only for eligible shared-course composition.
- Empty assessment uses `x-empty-state` with **No assessment questions yet**, an explanation, and **Add question** when authorized. Assessment remains available to the learner independently of watch progress; editor help must not describe the threshold as a gate.
- Empty media library/gallery and zero search results retain the modal title/search field and offer the capability-appropriate upload or query-reset action.
- Direct-only and reusable-only composition state names the active mode and disables the incompatible add path with adjacent explanation. Legacy mixed composition shows a danger callout, preserves both inventories, disables conversion, preview, Save transitions that would erase content, and publication, and provides non-destructive recovery guidance. It is never rendered as an ordinary empty state.

### Structural and destructive actions

- Authored assessment values, including prompt/answer text, type, attempts, and correct-answer selection, persist only through authored Save. Dedicated add, remove, reorder, and composition operations may persist immediately through named context Actions after fresh authorization and revision/scope checks.
- Before dispatch, each structural action applies the context's dirty/upload safety rule. If proceeding could invalidate authored values or an active upload target, it is blocked with specific guidance. Unrelated values and error provenance remain visible.
- Confirmation acceptance first exposes the exact operation/target as pending and dispatches one authorized request; declining dispatches nothing. Success refreshes only the affected canonical projection and reports operation success without changing authored Dirty/error state to Saved. Failure leaves the protected database graph unchanged and retains retryable action-specific provenance.
- Removal confirmations name the exact lesson/module/question/option and state what is and is not deleted. Shared-course module removal additionally requires the existing reason and explains that only the draft association is removed and module content remains. Long identities wrap; action buttons stack full-width at 320px, Cancel before the danger action in keyboard/DOM order.
- **Discard draft**, archive, association removal, and publication remain context-specific actions outside the common Save. Their existing impact/reason requirements and immutable-history copy remain. They are disabled or guarded while dirty, saving, errored, or uploading; they never silently save first. Restart-in-progress remains unchecked by default.
- Publication confirmation shows impact counts before the boundary, names preserved history, disables the primary action when validation/composition/upload/save state is unsafe, and presents loading text in the action. Partial publication/propagation failure displays a textual failed state and only the approved retry path.

### Media transfer and attachment

- Transfer may begin immediately only after context authorization. Each visible upload row exposes filename/title, percent or **Uploading**, then **Processing**, **Ready**, or **Upload failed**, with a text retry action. Multiple concurrent uploads remain separately identified after reorder.
- Attaching, replacing, or removing a draft media association is a dedicated server-authorized media Action and may persist immediately independently of authored Save. Its context collaborator defines the operation; the browser never infers authority from a flag. Pending/completion text names the stable target.
- A completed transfer or media action does not make the authored editor Saved and does not clear unrelated dirty, validation, conflict, network, or operation-failure state. Failure preserves the protected association snapshot and stable identity needed for retry.
- While an upload is active, closing, publication, and structural actions that could invalidate its stable target are disabled with **Wait for active uploads to finish…** guidance. Ordinary unrelated authored edits remain visible and must not be discarded.

## Review evidence

Authoring inspection covered the complete feature specification, scenario map, semantic UI contract, execution contract, control-center design system, the company-course/shared-course/standalone-module editor SFCs, both company and platform shared-course detail pages, `x-page-hero`, the existing save/status primitives, shell gutters, and current CSS tokens.

The contract preserves resolved D-01/D-04/D-05, separates shared presentation/state from server-owned context capabilities, defines the course-only hero-width variant, and supplies deterministic 1440px/320px, keyboard, accessibility, state, and semantic-hook expectations for UCE-01–UCE-19 / AC-01–AC-08.

**Author readiness:** READY FOR PRODUCT-OWNER REVIEW. This is a pre-implementation design contract. It does not approve an implementation, claim browser evidence, freeze the execution contract, or replace the required post-implementation independent design review.
