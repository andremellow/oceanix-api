# Behavior scenarios and test evidence

Rules and product decisions are in spec.md. Owner approval is recorded in approval.md. Run `pdf-library-20260916`, frozen scope `pdf-library-v1`. The examples remain unchanged; actual Worker execution evidence is recorded below and does not substitute for independent QA.

## Coverage examination

| Dimension | Rules | Scenarios | Status |
| --- | --- | --- | --- |
| Happy/alternative flows | FR-001/004/005 | B-01/03/06/07/08 | Planned |
| Permissions/isolation | FR-002/003 | B-04/05 | Ownership and explicit grant rollout approved |
| Data/boundaries | FR-001/008 | B-01/02/15 | Planned: >20 and >60 files, duplicates, long names |
| Lifecycle | FR-006/007 | B-09/10/11/12 | Planned |
| Empty/loading/errors | FR-008 | B-02/14 | Planned |
| External failure/recovery | FR-004/008/009 | B-13/14/16 | Private file/network failure; no new external API |
| Time/retry/concurrency | FR-003/006/007 | B-05/12/13 | Revocation and archive/reuse order; no scheduled expiry |
| Existing affected behavior | FR-005/007/009 | B-06/07/08/09/11/16 | PDF insertion, draft save/copy, delivery only |

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

## Questions and decisions

| ID | Rules/scenarios | Decision | Status |
| --- | --- | --- | --- |
| D-01 | FR-001 | All available PDFs across lessons/trainings | Confirmed by user |
| D-02 | FR-006/007 | Archive rather than physical delete; retain old links | Confirmed by user |
| D-03 | FR-002; B-04 | Each company sees its own PDFs; platform sees its own PDFs; no cross-owner library sharing | Confirmed by user |
| D-04 | FR-003; B-05 | Explicit within-owner grants for non-admins | Approved; see approval.md |

## Test plan and execution evidence

| Scenarios | Level / boundary | Fixtures and assertions | Planned file | Sensitivity | Result |
| --- | --- | --- | --- | --- | --- |
| B-01/02/15 | Pest projection + browser pagination | 65 unique records, duplicate labels, other-company/platform-owned controls, exact reachable IDs/order/search | tests/Feature/Documents/LessonDocumentLibraryTest.php + tests/JavaScript/lesson-documents.browser.test.mjs | Exact 65-ID order and literal-search outcomes | PASS; mapping below |
| B-03/04/05 | HTTP/Policy/Action integration | Real PDF bytes, granted/denied profiles, prereqs, admin, revoked/archived profile and inactive actor | tests/Feature/Documents/LessonDocumentLibraryAccessTest.php | Initial 3 failures on absent capabilities/service; positive controls and forbidden writes | PASS; mapping below |
| B-06/07/09 | Real browser + Action integration | Exact HTML, caret and enclosing anchor; stable token/record, draft/published states | tests/JavaScript/lesson-documents.browser.test.mjs + LibraryTest | Initial six missing method/Action failures across contexts | PASS; mapping below |
| B-08/11 | HTTP + database/file integration | Published and copied uses; compare hashes, HTML and associations before/after | tests/Feature/Documents/LessonDocumentArchiveTest.php | Actual retained learner bytes and copy pivot; no active filter in contextual delivery | PASS; mapping below |
| B-10/12/13 | Action/database and browser | Both operation orders, pending save, repeated archive, cancel and unsaved HTML | tests/Feature/Documents/LessonDocumentArchiveTest.php + browser + tests/Support/Documents/PostgresRace.php | Actual lock wait/commit barriers and pending Save; unchanged retained evidence | PASS; mapping below |
| B-14/15 | Actual browser/network + HTTP | Held response, failure, missing bytes, keyboard and narrow viewport | browser and LibraryAccessTest | Actual query faults and held real response, no content change on failure | PASS; mapping below |
| B-16 | Existing focused suites + expanded-modal browser | Existing real PDF and invalid controls | tests/Feature/Documents/ + existing PDF browser suite | Earlier upload/selection/validation assertions unchanged | PASS; mapping below |

## Approved executable QA matrix

- QA-01: all three catalogs, search, page through >60 files, duplicates/empty states (B-01/02/15).
- QA-02: Open different-lesson PDF in new tab; no editor mutation (B-03).
- QA-03: reuse selected/custom/default text across all editors, exact settled caret, Save/reload and contextual access (B-06/07/08).
- QA-04: allowed/denied catalog routes and actions, cross-scope identifier controls and revocation (B-04/05).
- QA-05: archive cancel/confirm/retry with unsaved text; library disappearance and retained existing published/copied links (B-10/11/13).
- QA-06: stale reuse after archive, pre-archive attachment save, cancelled/stale/published target rejection (B-09/12).
- QA-07: keyboard, 390px modal, English/PT-BR, long names, busy/failure/retry (B-14/15).
- QA-08: existing upload and invalid/cancel path through expanded modal (B-16).

## Automation exceptions

None. Browser and PHP checks complement each other; runtime QA uses separate safe fixtures and is not substituted with authored tests. PostgreSQL row-lock behavior was verified using the approved disposable database and actual two-process Actions; no development data was used.

## Readiness

- Ownership clarification integrated into FR-002 and B-04 in both directions. D-03/D-04 and consolidated behavior approved; approval.md.
- Worker implementation and focused evidence executed; independent QA and completion gates remain pending.

## Actual Worker test mapping, 2026-09-16

Detailed outcomes and sensitivity: `docs/changes/pdf-library/worker-evidence.md`. Executed command `php artisan test --compact tests/Feature/Documents`: 58 passed/444 assertions, followed by three final cancelled/published tests passing/15 assertions. Root owns the full final canonical result.

- B-01/02/15: LibraryTest “reaches every active document beyond sixty and searches literal filenames”; browser “Owner PDF library pagination, private Open, reuse caret, retained archive, revocation and narrow localization” (all three contexts).
- B-03/04/05: LibraryAccessTest “grants library view through a profile and revokes it on the next request”, “keeps the admin bypass inside its owner and never grants learners the catalog”, “persists explicit prerequisites without granting unrelated library actions”, “checks all owner boundaries for open reuse and archive in each editor”, “denies new writes after profile archival and actor deactivation without exposing metadata”, and “returns controlled missing bytes and rejects anonymous and inactive platform access”.
- B-06/07/09: LibraryTest “reuses the same bytes in every draft context and saves only explicitly”, “rejects stale revisions without creating attachments in every context”, “denies cancelled and published reuse without altering draft or published attachments”; browser selected bold text, custom/default labels, exact settled caret, explicit Save/reload and held cancelled reuse response.
- B-08/11: ArchiveTest “rejects archive-first reuse with no attachment and retains old learner delivery”, plus existing LessonDocumentTest contextual author/learner/preview and retained new-draft checks. Archive test validates existing published HTML, learner bytes and copy relation after archival.
- B-10/12/13: ArchiveTest “archives once retaining original actor time bytes metadata and pending explicit save” across three contexts; “cancels confirmation without archive or editor mutation and corrects an empty last page”; “enforces exactly one archival actor even for direct database writes and prevents destructive rollback”. Native `PostgresRace.php` passed archive-first and reuse-first with observed physical lock waits and actual subsequent draft Save. Evidence fixture `/tmp/oceanix-pdf-qa-HhaDkJ`.
- B-14/15: Library browser includes real harness list/reuse/archive faults/retry, held committed reuse cancellation, revoked grants, keyboard and 390px PT-BR. LibraryAccessTest includes missing bytes. Final library browser pass23.42s, fixture/screenshot `/tmp/oceanix-pdf-qa-BWDYdM`.
- B-16: Existing “PDF selection, upload, save, reload, cancellation, errors and real learner popup” passed21.57s in final combined browser run, fixture `/tmp/oceanix-pdf-qa-I6pX2s`. Two combined browser tests passed, no skips, total45.28s.

QA-01–08 above remain the complete unchanged frozen matrix for independent QA; these Worker assertions are not an independent QA verdict.
