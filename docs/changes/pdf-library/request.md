# PDF library in the lesson editor modal — discovery

Run: `pdf-library-20260916`. This is a new requested extension, not completion of the earlier PDF-link run. No production changes or PR updates are authorized by this document alone.

## Confirmed user decisions

- List all PDFs available to the editor, not only the current lesson or training.
- Offer Open, Reuse and Archive in the existing PDF modal.
- Preserve isolation between companies and existing links.
- Archive removes a PDF from future library reuse without deleting its bytes or breaking existing lesson links. Physical deletion is not requested after this clarification.
- QA and code review remain restricted to this extension and its direct regression surfaces.
- Each company sees only its own PDFs; the platform sees only its own PDFs. No sharing/promotion between PDF-library owners is requested.

## Observed implementation

- LessonDocument metadata is immutable; model updates and deletion throw. A separate lifecycle record or narrowly constrained archival state needs an explicit design, not a blanket removal of immutability.
- Documents have company/shared ownership and retained lesson associations. Saved HTML membership is required by current delivery routes.
- The PDF modal currently only uploads. Reuse must create an authorized association with the target draft before existing save validation can accept its canonical link.
- The existing image library separates company-owned and platform-shared files; it is convention evidence, not a decision to broaden PDF access.
- New independently grantable library actions must participate in the repository's permission/policy system. Existing learner/preview access must not become a global PDF-library grant.

## Proposed bounded behavior for specification

- Add a searchable, paginated list of active PDFs with filename and human-readable size, keeping the existing upload workflow available.
- Confirmed ownership boundary: company editors see their company's private library; platform editors see the platform's own library. The platform does not aggregate company PDFs, and companies do not browse platform PDFs. Existing authorized lesson links remain separate from catalog access.
- Open uses an authenticated library read endpoint and fresh authorization, not a public storage URL or a learner's assignment authority.
- Reuse targets the captured editable lesson and existing selection, preserves custom text and surrounding formatting, and requires explicit Save as today.
- Archive requires confirmation, removes the item from active discovery/reuse, and retains metadata, bytes, associations and already-authored content. Concurrent reuse/archive and stale modal requests must recheck the current state.
- No physical deletion, restoration screen, renaming, replacement, file deduplication, unrelated media changes or general file manager.

## Planning status

Medium feature, critical assurance because library-wide authorization and archival state are affected. User authorized Spec Kit with “pode fazer”; no waiver. Active specification: `specs/007-pdf-library/spec.md`; concrete examples and proposed test/QA mapping: `specs/007-pdf-library/scenarios.md`. The initial checklist is 14/16, with consolidated ownership/grant approval pending. Architecture and execution contract approval must follow before implementation. The previous run's incomplete gates are retained as prior evidence, not silently cleared or folded into this request.
