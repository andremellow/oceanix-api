# UX Contract

## Existing design-system references

Use docs/control-center-design-system.md, resources/css/app.css, x-page-hero, x-empty-state, detail-card and status-pill contracts. Existing company/platform course show and editor screens define action placement. Public reader shares tokens and typography but has a dedicated layout without authenticated navigation or account identity. Existing guest layout is a reference, not a drop-in course reader.

## Components and tokens

- Draft link panel: only for the exact editable course draft, near its draft actions on detail and editor. Show Generate preview link, then read-only selectable URL, Copy link and localized absolute expiry with timezone. One generation click and one copy click. Include a concise note that anyone with the link can review the saved draft for seven days.
- Active state: reuse the existing link and expiration. Expired state: Generate new preview link. No early rotation/revoke controls. Unsaved editor changes are not included; explain this beside the link action without forcing unrelated saves.
- Public reader: one h1, draft badge, course description, ordered contents navigation, content body, video and read-only assessment section. No employee toolbar, training completion controls, answer radios, correct-answer badges, scores, or login call to action.
- Preview-ended state: friendly title and explanation, no course metadata or content. English canonical copy: “This preview has ended.” / “Please contact the person who shared this link for more information.” Proposed pt-BR rendering: “Esta prévia foi encerrada.” / “Entre em contato com quem enviou este link para obter mais informações.” Portuguese values will live only in lang/pt_BR.json in application code.
- Unknown link: “This preview is unavailable.” with a similarly helpful explanation; never display raw exception or credential.

## Responsive and accessibility behavior

Desktop uses contents beside reading column; mobile stacks an expandable labelled contents list above content. At 390px URLs wrap or scroll inside their own field, actions remain visible, and media stays within viewport. At 1440px line length remains readable. Keyboard-only visitors can navigate all items, play video and switch language; native controls, visible focus, one h1 and semantic headings. Status and clipboard confirmation are announced via an aria-live region and never conveyed only by color.

## Loading, empty, validation, error, permission, and destructive states

Generation: disable only initiating control while saving, retain current data on failure, show inline retry/error. Clipboard: success feedback; on rejection leave manual selection available. Empty draft: friendly content-empty state. Video: play/loading, processing/missing, failure/retry, expiry/end; stop requesting grants and replace controls when invalidated. Assessment: static questions/choices; empty assessment omitted with clear context if section is opened. Permission denial removes both actions and visible credential during subsequent requests; server-side authorization remains mandatory.

Public pt-BR is the default with no explicit locale preference, including error states. Language switch supports English and persists only an explicit supported choice; invalid preference falls back to pt-BR. Authored content is not auto-translated. Language action stays on the same preview and does not pass through tenant-specific locale side effects. Expired/published links show an explanation instead of a login redirect. Existing publication confirmation flow is unchanged.

## Review evidence

Planning uses repository source and design-system evidence. Runtime interaction evidence must be collected after implementation; this document does not claim UI QA has passed. Independent planning design review approved; implementation review remains required.
