# Approved PDF library scenarios — neutral review input

Run pdf-library-20260916; scope pdf-library-v1; checkpoint pdf-library-20260916-r2. This is a verbatim extraction of approved behavior definitions from specs/007-pdf-library/scenarios.md; the frozen QA matrix is in review-contract.json. It deliberately excludes execution results and author assessments. Requirements are unchanged; approval is recorded in specs/007-pdf-library/approval.md. Use this input rather than reading execution sections of scenarios.md. Derive validation independently.

## Concrete examples

| ID | Rules | Preconditions | Action | Expected | Forbidden effects |
| --- | --- | --- | --- | --- | --- |
| B-01 | FR-001/002 | 65 distinct active files across own-scope lessons plus foreign files | Visit first/last library pages in three contexts | All own active files reachable in deterministic order; foreign absent | No 60-file truncation, no foreign metadata |
| B-02 | FR-001/008 | Empty scope or populated library with no match | Open; search; clear search | Separate empty/no-results state; cleared search restores page1 | No lost editor text |
| B-03 | FR-004 | Authorized actor, active PDF from another lesson, current HTML snapshot | Click Open | New tab PDF bytes/inline response; original editor unchanged | No attach, dirty state or public URL |
| B-04 | FR-002/003 | Companies A/B plus platform-owned file; anonymous/user lacking grants; positive control per owner | List and direct open/reuse/archive using other-owner IDs in both directions | Each company sees only its own files; platform sees only its own files; foreign owner/grants deny metadata/bytes/write | No company files in platform catalog, no platform files in company catalog, no foreign attachment/archive |
| B-05 | FR-003 | Explicit view/reuse/archive profiles and scoped admin; open modal then revoke/deactivate | Execute each affected action; test prerequisite grants | Fresh authorization enforced; prerequisites persist; admin cannot cross scope | No reliance only on hidden controls |
| B-06 | FR-005 | Three editors, selected bold/italic text and ordinary anchor | Reuse other-lesson PDF; Save/reload | Exact selection/formatting retained; canonical link persists; source bytes unchanged | No duplicate file or wrong target |
| B-07 | FR-005/008 | Three editors, caret/no selection; custom then filename label | Reuse; type plain suffix after settled updates | Exact caret prefix; suffix outside every anchor; labels persist | No suffix in unrelated/PDF anchor |
| B-08 | FR-005/009 | Saved reused attachment and assigned learner; author preview; unrelated identity | Open contextual link | Authorized PDF succeeds; unauthorized denies | No catalog grant inferred from learner access |
| B-09 | FR-005/007 | Captured target then cancel, remove target, stale revision or published destination | Resolve pending reuse | No insertion; no published HTML/attachment change | No stale token accepted |
| B-10 | FR-006/008 | Active item, unsaved editor HTML | Request Archive then cancel | Named confirmation states links retained; item/content unchanged | No archival before confirmation |
| B-11 | FR-006/007 | Same document linked in published lesson and draft copy | Confirm Archive; reopen old links; copy preserved lesson | Catalog excludes item; new reuse denied; old PDF hash/HTML/associations unchanged | No deletion, changed bytes or broken saved link |
| B-12 | FR-007 | Two sessions; one active item | Execute archive before reuse and reuse before archive; then save already-attached link | Archive-first rejects attachment; reuse-first persists and save succeeds | No lost authorized association or late new reuse |
| B-13 | FR-006/008 | Archive confirmed twice or retry after failed response | Retry same action | One retained archive record; success/clear state without editor mutation | No duplicated side effect or physical delete |
| B-14 | FR-004/008 | Missing bytes; interrupted list/archive/reuse; slow response | Open/request/retry | Controlled error; editor unchanged; busy controls; retry usable | No paths exposed, false success or broken insertion |
| B-15 | FR-001/008 | Duplicate filenames, 200-character names, 390px viewport, PT-BR | Search/page/open/reuse/archive by keyboard | Distinct records stable; labels/help and controls visible/localized | No inaccessible actions or name-key collision |
| B-16 | FR-009 | Existing upload positive, invalid/oversized PDF, cancellation | Upload from expanded modal; Save/reload and open | Existing 10MB/type validation and upload/link flow retained | No image/video behavior changes |
