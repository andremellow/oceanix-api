# PDF library design contract

Design-agent authoring, proposed for owner approval; not runtime QA. Scope FR-001–009, B-01–16, QA-01–08 only. Root persisted the returned design contract.

## Foundations and composition

Reuse the unified PDF modal at resources/views/components/course-editor/root.blade.php, Flux heading/text/inputs/buttons/danger callouts, x-empty-state, existing pagination presentation and destructive-confirmation anatomy. Use semantic --ds-* colors, Instrument Sans, visible focus ring, neutral borders and existing control radii. No new navigation/library screen, file categories, owner switcher or archived-item page.

Expand Insert PDF to max-w-3xl with viewport gutters and vertical scrolling, shared by all three editors. Order: heading and owner-specific explanatory text; shared Link text field; existing upload panel; PDF library search; file list/pagination; Cancel. Owner text says “owned by this company” or “owned by the platform”, not “shared PDFs”.

Link text starts with captured selection; help says “Leave blank to use the filename.” Preserve selected/custom/default semantics. Upload remains a subtle panel with labelled file control, PDF-only/10MB help, associated validation and Upload and insert link. Use separate search/upload forms or explicit button types so Enter in Search cannot upload. Search/Clear reset page1, retaining label/selection/target.

## Rows and actions

Use semantic list rows, not a wide table: decorative PDF icon, full wrapping filename, human-readable size, allowed actions. Stable document keys keep duplicate names distinct; sizes and Open provide inspection without internal IDs.

- Open: native secondary/ghost link, accessible “Open :name (opens in a new tab)”, target=_blank, rel=noopener noreferrer. Private library route; no content/selection/dirty-state change.
- Reuse: primary button, accessible “Reuse :name”. Uses shared Link text or filename fallback, existing PDF result/event contract, closes on accepted success and restores exact caret after link. Explicit lesson Save remains required.
- Archive: secondary danger-labelled action, accessible “Archive :name”, opens confirmation only.

## Archive confirmation

Use existing destructive-confirmation presentation, adapted deliberately: title “Archive ‘:name’?”, consequence “This PDF will no longer appear in the library or be available for new reuse. Existing lesson links will continue to work.” Buttons Cancel and Archive PDF. No reason field, impact counts, restore or delete wording.

Focus Cancel initially. Child confirmation Cancel/Escape returns to its originating Archive action and MUST NOT bubble into PDF modal cancellation or invalidate the underlying insertion token. Existing destructive component's hardcoded cancelDestructiveConfirmation and operation-finished hooks need deliberate integration, not assumed compatibility.

During archive, disable duplicate submit/dismissal, expose progress and clear pending on every terminal result. On success remove row, correct empty last page, announce “PDF archived. Existing links still work.” Focus next surviving row action, previous if last, or Search when no rows remain. Failure retains file/confirmation context and retry.

## State contract

| State | Behavior |
| --- | --- |
| Initial loading | Loading PDFs… and busy library; do not flash empty state; preserve upload/editor state. |
| Search/page loading | Keep prior rows, busy region, prevent duplicate navigation, preserve search focus. |
| Empty | No PDFs in this library yet / Upload a PDF to make it available here. Keep upload visible. |
| No results | No PDFs match your search / Try another filename or clear the search; Clear search action. |
| List/network failure | Danger callout and Try again; preserve search/label/upload/editor; failure is not an empty list. |
| Reuse pending | Inserting PDF link…; block duplicate/conflicting insertion. Cancellation invalidates late completion. |
| Reuse denied | Accessible archived/stale/forbidden/unavailable error, no link or lost text. Archived: This PDF is no longer available for reuse. Stale: This lesson changed. Close this dialog and try again. |
| Archive failure | The PDF could not be archived. Try again. Keep context and idempotent retry; never claim deletion. |
| Missing bytes | Controlled destination-tab error without private path; original editor/modal unchanged. |
| Upload validation | Existing type/10MB help, inline aria-describedby errors and aria-invalid remain. |
| Missing capability | Omit unauthorized actions; without catalog access omit all metadata/counts/search and explain lack of access. Existing upload retains its existing capability. |
| Revoked capability | On denial clear unauthorized catalog metadata/actions, announce loss, preserve authored text; denied reuse inserts nothing. Full editor permission loss uses existing state. |

## Focus, responsiveness and localization

Use Flux dialog focus containment. Focus Link text when modal opens, independently retaining captured editor selection. Native Enter activation for Search/buttons; accessible pagination label and current-page indication. Search, Open and Archive do not recapture insertion state.

Successful reuse preserves existing closed-plus-rendered restoration: exact caret outside all anchors after asynchronous updates. Modal Cancel/X/Escape invalidate insertion and restore editor focus without changing HTML; child confirmation dismissal does not close the parent.

At390px use one column: full-width search, filenames wrap anywhere, metadata below, actions wrap/stack with Reuse always visible. Vertical modal scrolling, reachable confirmations, no horizontal page overflow. English source plus PT-BR for all visible/accessible copy. Status/polite announcements for progress/success, alerts for errors; color never sole state indicator.

## Validation boundary

Use existing QA-01–08/B-01–16 only. Runtime evidence must cover nested dialog handling, settled caret, keyboard,390px,PT-BR and interrupted requests. Company grant rollout remains subject to consolidated architecture approval; no broader scenario or product scope added.
