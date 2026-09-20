# Feature Specification: PDF library in the lesson editor

**Feature Branch**: `feature/lesson-pdf-links` (existing checkout; no new branch or PR change in this phase)

**Created**: 2026-09-16

**Status**: Approved by product owner on 2026-09-16; see approval.md.

**Input**: List all PDFs available to the editor in the existing modal, with Open, Reuse and Archive; preserve company isolation and existing links. User approved preparing the Spec Kit flow with “pode fazer”.

## Clarifications

### Session 2026-09-16

- Q: Only PDFs from this lesson/training, or all available PDFs? → A: All PDFs available to the editor, preserving isolation between companies.
- Q: Can removal archive the file rather than erase files used by existing lessons? → A: Yes: Open, Reuse and Archive, preserving existing links.
- Q: Use Spec Kit or waive it? → A: “pode fazer”; proceed with Spec Kit, no waiver.

- Q: How does a PDF become shared, and which files should each library show? → A: Each company sees its own PDFs; the platform sees its own PDFs. There is no PDF-library sharing or promotion between these owners.

The ownership matrix and concrete permission rollout are approved; see approval.md. Existing access to a PDF through an authorized training is distinct from access to its owner's library.

## User Scenarios & Testing

### User Story 1 — Find and open an existing PDF (Priority: P1)

As an authorized content editor, I can browse every active PDF in my authorized library inside the existing Insert PDF modal, regardless of its originating lesson, and open it before choosing it.

**Why this priority**: Authors need to discover existing files without re-uploading them.

**Independent Test**: Populate more than one result page with distinct PDFs from different lessons in the same ownership scope; find and open a file from the final page without changing lesson content.

**Acceptance Scenarios**:

1. Given an authorized editor and active files from multiple lessons, opening the modal shows filenames and sizes, with a way to reach every result (B-01).
2. Given a filename search, only matching authorized files appear; clearing the search restores the full accessible catalog (B-02).
3. Given a listed PDF, Open displays its bytes in a new tab without inserting a link or marking the lesson dirty (B-03).
4. Given a foreign-company identifier or revoked library access, neither names nor file bytes are exposed (B-04/05).

### User Story 2 — Reuse a PDF in another lesson (Priority: P1)

As an authorized editor, I can insert an existing PDF into the current draft, using selected text or a label, then save normally.

**Why this priority**: Avoid duplicate uploads while preserving each lesson's access rules.

**Independent Test**: Reuse a file originating in another lesson, save and reload the destination, then open the link as an authorized learner. The file's contents and identity stay unchanged.

**Acceptance Scenarios**:

1. Given selected formatted text, Reuse inserts a link without changing the text unless a custom label is supplied (B-06).
2. Given only a caret, Reuse defaults to the filename; typing after insertion remains outside the link and at the intended position (B-07).
3. Given a saved reused link, authorized training/preview access works; unrelated users are denied (B-08).
4. Given a cancelled modal, stale target or published target, no insertion or published-content mutation occurs (B-09).

### User Story 3 — Archive an obsolete library item (Priority: P2)

As an editor with archival permission, I can remove a PDF from future discovery/reuse without breaking its existing uses.

**Why this priority**: Keep the active list useful without erasing training evidence.

**Independent Test**: Archive a file already linked in a published lesson; it disappears from the active library while the existing link still opens with normal authorization.

**Acceptance Scenarios**:

1. Given an active item, Archive asks for confirmation identifying the file and explaining that existing links remain; cancelling changes nothing (B-10).
2. Given confirmed archival, the item disappears and cannot be newly reused; existing saved links and bytes remain intact (B-11).
3. Given a stale list open in a second session, reuse after archival is refused without inserting a broken link (B-12).
4. Given repeated confirmation or a transient failure, the outcome is idempotent or an actionable failure, not lost editor text or deleted bytes (B-13).

### Edge Cases

- Empty library and no search matches are distinct useful states; loading and failed requests retain editor content (B-02/14).
- Same-name files remain separate; long names wrap; all pages are reachable, without a fixed “latest N” truncation (B-01/15).
- Library permission loss after modal opening is enforced on the next list/open/reuse/archive action (B-05).
- A PDF successfully attached to a draft before archival may still be saved afterward: archive prevents new selection, not the completion of an existing authorized attachment (B-12).
- Concurrent archival/reuse has a deterministic ordering: archive first rejects new attachment; attachment first is retained (B-12).
- Published lessons are never edited by reuse. Archival is library state, not a published-content edit (B-09/11).
- Missing private bytes yield a controlled failure without exposing storage paths (B-14).

## Requirements

### Functional Requirements

- **FR-001**: The existing modal MUST offer a library alongside upload in all three existing editor contexts, listing all active PDFs in the authorized ownership scope across lessons/trainings. Search by filename and pagination MUST make every authorized item reachable.
- **FR-002**: Confirmed ownership matrix: each company library contains only that company's PDFs; the platform library contains only platform-owned PDFs. The platform library MUST NOT aggregate company files, and company libraries MUST NOT list platform files. Open, Reuse and Archive from the library obey the same ownership boundary. No sharing, promotion or transfer of PDFs between these owners is included. Existing access through an authorized training remains unchanged and does not grant access to the owner's library.
- **FR-003**: Within company libraries, viewing/opening, reuse and archival MUST be separately grantable capabilities under the existing access-profile system. Reuse also requires edit access to the destination draft. Platform libraries retain the existing active-platform-administrator authority, with explicit named abilities; no new platform profile system is introduced. All operations recheck current active identity and authorization. Administrators retain the existing scoped bypass; other company profiles receive no automatic new grants. Existing upload capability remains unchanged.
- **FR-004**: Open MUST show the selected authorized active PDF in a new tab without modifying lesson content, using no permanent public file URL.
- **FR-005**: Reuse MUST preserve the original document identity/bytes and authorize an attachment to the selected editable lesson. It MUST use the existing selected-text/custom-label/filename insertion rules, leave the caret after the link, reject cancelled/stale completion, and participate in explicit Save.
- **FR-006**: Archive MUST require explicit confirmation, hide the item from active listing and prevent new reuse. It MUST retain original bytes, metadata, associations and existing saved links, record actor/time, and be idempotent. No physical delete or restore UI is included.
- **FR-007**: A successful attachment made before archival MUST remain usable by its lesson, including a pending explicit Save; an attachment attempted after archival MUST fail. Published content and existing draft-copy behavior MUST remain unchanged.
- **FR-008**: The modal MUST preserve current lesson edits during list/search/open/archive/failure, expose loading/error/empty states, prevent duplicate in-flight submissions, and provide keyboard-operable, narrow-screen controls with English source and Portuguese localization.
- **FR-009**: Existing PDF upload validation, cancellation, saved-link delivery and training authorization MUST continue unchanged on directly affected paths. No unrelated media or training workflow changes are included.

### Key Entities

- **PDF document**: original name, size, ownership scope and immutable private contents/identity.
- **Library archival record**: identifies an archived document, responsible actor and time without altering its original contents or destroying evidence.
- **Lesson attachment**: retained association permitting a lesson to reference a document; reuse adds this to an authorized destination draft.
- **Library capability**: controls catalog/open, reuse or archive; distinct from learner access to a containing training.

## Success Criteria

### Measurable Outcomes

- **SC-001**: In all three editor contexts, every active authorized file in a multi-page fixture is reachable and no foreign-scope filename or byte response is exposed.
- **SC-002**: A PDF from a different lesson can be reused, saved, reloaded and opened without a second upload; selected/custom labels and surrounding text remain exact.
- **SC-003**: After archival, 100% of attempts to newly reuse that item are refused while all previously authorized saved uses retain the same PDF contents.
- **SC-004**: The complete frozen browser matrix passes with keyboard controls and a 390-pixel viewport; cancellation and failure leave lesson content unchanged.

## Assumptions

- User approval covers the product goal, FR-002 ownership matrix and detailed FR-003 permission rollout; no automatic grant/backfill is authorized.
- Search is case-insensitive filename matching; results are newest first with a stable tie-break and 20 items per page. Pagination is navigation, not a maximum total count.
- Archived files do not appear in this active-only library. Existing lesson links remain the route to retained uses. Restoration, an archive-management page, rename/replace and deduplication are outside this request.
- Platform-to-company or company-to-platform catalog reuse is outside the confirmed boundary; a global or cross-owner catalog would require an explicit change to FR-002.
- No production database migration, deploy, PR update, tooling repair or instruction change is performed by the specification phase.
