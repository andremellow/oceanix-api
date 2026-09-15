# PDF links in lesson content

Status: approved by the owner on 2026-09-14 (“Pode executar”); frozen as scope `pdf-links-v1` in the execution contract.
Run: `pdf-editor-20260914`. Base: `f6a6645` (remote main).

## Confirmed request

Upload PDFs from the lesson text editor and insert a link that opens in a new tab. The owner explicitly chose access restricted to people with access to the training. The owner explicitly requires QA and code review to validate ONLY this change and direct regressions caused by its diff.

## Acceptance criteria

- AC-01: In each existing unified editor context (company course, shared course, shared module), an authorized author can upload a PDF and insert its link into lesson content. Selected text becomes the link label; without selection a labelled field defaults to the filename. Save and reload preserve the link.
- AC-02: Clicking the rendered link opens the PDF in a new tab, preserving the training page. The response uses the PDF content type and inline disposition; browser PDF settings may choose downloading.
- AC-03: File bytes are private. Each request checks current access to the specific lesson/version containing that PDF. Eligible learners, authorized authors and valid existing course-preview access can open it; unrelated users/companies, logged-out requests without preview authority and expired/revoked access cannot. Possession of a bare document URL grants no access.
- AC-04: Accept PDF files up to 10 MB, checking actual media type server-side. Invalid, oversized and failed uploads report an associated accessible error, preserve lesson text, and insert no broken link. Cancellation inserts nothing; duplicate submission is disabled while uploading.
- AC-05: Upload/link editing applies to editable drafts only. Published content and referenced PDF bytes remain immutable; draft copies retain working references. Removing a text link never deletes a previously uploaded operational file.
- AC-06: PDF controls use the existing editor/modal styles, labelled fields, visible focus, loading/error states, and fit desktop/mobile widths. English source strings have PT-BR translations.

## Invariants

- INV-01: Existing company/platform ownership and record authorization boundaries apply server-side on upload and read, including revoked access.
- INV-02: Published versions, assignments and retained evidence are not rewritten or deleted.
- INV-03: No public storage URL, permanent access token or arbitrary filesystem path is accepted as document authority.
- INV-04: Existing text formatting, ordinary links, and image/video insertion are changed only when essential to PDF insertion and preserve their existing behavior.

## Boundary

Only PDF upload/storage metadata, editor insertion, private delivery, context-specific link rendering, and focused tests may change. No general file manager, reuse gallery, document replacement/deletion, Office support, OCR, tracking of PDF reading, editor refactor, authentication redesign, deployment or PR is requested. Existing authorized preview mechanisms remain the authority for their own lesson documents; this does not introduce public PDF links.

Use existing course/module editing permissions for this subordinate editor operation, with server-side record checks. Do not create a separately grantable document-management feature. Default maximum is 10 MB to match existing content-image uploads. These are proposed implementation defaults for owner review.

## Frozen validation proposal

| Scenario | Action and observable outcome | Criteria |
| --- | --- | --- |
| QA-01 | For each unified editor context, select text, upload a valid PDF, insert, save and reload; label, surrounding text and PDF reference persist. | AC-01, AC-05 |
| QA-02 | With no selection, insert using filename/custom label; cancel a separate attempt; cancelled attempt leaves content unchanged. | AC-01, AC-04 |
| QA-03 | Eligible learner clicks a saved PDF link: a new tab receives the PDF and original lesson remains open. | AC-02, AC-03 |
| QA-04 | Request the same PDF as an unrelated user/company and anonymously without preview authority; deny bytes. Revoke formerly valid access and retry; deny bytes. | AC-03 |
| QA-05 | Open a linked PDF from existing authorized author/preview contexts; an expired preview and unrelated document reference are denied. | AC-03 |
| QA-06 | Try non-PDF and over-limit files, cancel, and simulate upload failure; no inserted link or lost text; associated error and usable retry. | AC-04 |
| QA-07 | Use keyboard and narrow viewport to open/upload/insert; controls remain accessible, busy state prevents duplicates, focus returns to editor. | AC-06 |
| QA-08 | Copy a draft containing a PDF and verify the reference; attempt published mutation; original content/file stays unchanged. | AC-05 |
| QA-09 | In the affected editor, preserve surrounding formatting/ordinary links and exercise adjacent image/video insertion only if their shared hooks are changed. | INV-04 |

Automated evidence: focused Pest action/route/Livewire tests for storage validation, permissions, version/reference membership and persistence; browser tests for actual selection preservation, save/reload and new-tab behavior. QA independently exercises the frozen matrix in a proven local ephemeral environment. No other scenarios may become blocking without owner amendment. Pre-existing failures and adjacent findings are outside this task.
