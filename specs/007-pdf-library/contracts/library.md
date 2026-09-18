# PDF modal and action contract — proposed

References: spec.md FR-001–009; scenarios.md B-01–16, QA-01–08; architecture.md finalizes method/route names.

## Owner boundary

Company library accepts current company actor context only and filters company_id plus is_shared=false. Platform library filters null company_id plus is_shared=true and uses the active platform Account. Global identifiers, route tampering and tenant admin bypass cannot cross this boundary.

## Read projection and Open

List inputs: bounded filename search string and valid positive page. Output: public document ID, display name, human-readable size/raw bytes as needed for rendering, authorized library-open URL and allowed actions; page/total navigation. Twenty rows per page, stable created_at/id descending. No private filesystem path, disk credentials or other-owner counts. All active records remain reachable. Search reset returns page1.

Library Open uses an authenticated GET in the relevant company/platform route group, permission middleware and fresh record owner/capability/archive checks. Success is private inline application/pdf in a new tab; denial returns no bytes, missing bytes is controlled failure. This route does not accept learner-assignment authority or create an attachment. Existing lesson delivery is not switched to this route.

## Reuse

Inputs: selected document public ID, server-resolved editor context/root, locked stable target key, current revision and insertion token. The server authorizes a fresh actor and editable draft and an active same-owner document, retaining an attachment. Output matches current PDF upload result. Existing client insertion logic handles label, selection and dirty state. No HTML is saved inside the Action; explicit editor Save remains required. Duplicate association requests are harmless, stale/cancelled browser completion cannot insert a link.

## Archive

The UI first requests a confirmation naming the file and stating existing links remain. Confirm sends only stable document identity to an authorized Action; no client-supplied owner or audit actor/time is trusted. Under document lock, insert the retained archival record once. Outcome removes the row from the active list (including correcting an empty last page) without replacing the editor snapshot or changing HTML/selection. Retry is idempotent; failure leaves the list/item and editor consistent and offers retry.

## UI states

Keep the upload form available. Use existing Flux modal and controls, labelled filename search, paginated file rows, Open as native target=_blank rel=noopener noreferrer anchor, Reuse as button and Archive as secondary destructive-labelled action. Names/sizes distinguish rows; duplicate names use stable keys. Empty/no-results, loading, error and access-denied states are explicit. The archived list/restore/rename/delete UI is out of scope.

Keyboard focus is trapped within the modal; confirmation cancellation returns to its row action; library navigation must not discard editor selection or custom link text. Narrow layouts stack metadata/actions and keep the primary action visible. English source/PT-BR translation. Reuse restores exact settled caret outside all anchors, including existing adjacent anchors. Cancel invalidates insertion state.
