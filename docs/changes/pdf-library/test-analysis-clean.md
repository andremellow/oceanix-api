# Independent Test Analyst — REQUEST_CHANGES

Run pdf-library-20260916; scope pdf-library-v1; checkpoint pdf-library-20260916-r1. All32 hashes match. Reviewer inspected full raw scope/causal tests, with fresh independent context, no test execution or edits. Started/completed telemetry emitted. Five blocking TEST_GAP findings, detected by test-analyst, failure stage tests, round1 review. No product scope amendment requested.

## TA-01 — Independent write capability denials (high)

AC-05/INV-01/B-05/FR-003. LessonDocumentLibraryAccessTest.php:30/56/94–103 tests View or removes all capabilities together; an archive Gate check is not an actual write denial. Add actual Reuse/Archive calls retaining View and removing only each write permission, successful controls, independent combinations, unchanged pivots/archive/HTML, and runtime prerequisite checks with deliberately incomplete grants.

## TA-02 — Reuse from an existing source and contextual delivery (high)

AC-03/04,INV-02,B-06/08/11. LibraryTest.php:35 and ArchiveTest.php:19 successful fixtures are unattached; QaFixture.php:143 catalog files also lack source associations. Existing published source only tests rejected reuse. Successfully reuse a PDF already linked in another lesson, preserving source association/HTML/metadata/bytes; Save/reload destination and open its contextual link with authorized/unauthorized controls. Extend this representative fixture through archive and retained copy/Save; reuse lower-level tests where effective.

## TA-03 — Archive retry and interrupted transport (medium)

AC-04/06,INV-04,B-13/14. Browser:137–142 restores server archive fault then cancels; no retry. SQL faults return normal Livewire responses; held reuse response:147–159 tests cancellation, not transport failure. Retry retained failed confirmation to successful archive with one record, cleared pending and unchanged content. Interrupt representative list/archive/reuse transport requests and prove usable recovery preserving search/label/selection/editor. Retain existing deterministic server-failure coverage.

## TA-04 — New controls keyboard/focus/loading/empty (medium)

AC-01/06,INV-04,B-02/10/14/15,design contract. Browser:181–188 keyboard-checks toolbar only; new controls use clicks. Nested Cancel/Escape:62–75 lacks initial Cancel focus/return/postarchive assertions. No genuine empty library or held list/page loading. Add focused keyboard/active-element checks for new controls and archive lifecycle, localized narrow confirmation reachability, cheapest effective empty-vs-no-results check, held list/page busy state retaining rows. Do not duplicate unrelated upload keyboard tests.

## TA-05 — Exact custom/default caret and suffix (medium)

AC-03/INV-04/B-07. Browser:99–103 starts at anchorNode.parentElement, misclassifying element-node anchor selections as outside links. Custom/default branches lack typed suffix outside-all-anchors and Save/reload assertions. Handle text and element nodes correctly, require collapsed editor-focused selection, type suffix and verify exact position outside every anchor plus label/surrounding content after Save/reload in the existing three-context loop.

## Credited coverage

Actual Eloquent/Actions, >60 pagination/literal search, ownership, stale/published denial, idempotent immutable archival, pending Save, existing upload behavior. PostgreSQL probe has two processes, explicit lock barrier, observed lock wait and real pending Save in company context; no extra platform concurrency matrix required.
