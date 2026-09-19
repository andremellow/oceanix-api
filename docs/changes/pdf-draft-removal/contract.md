# Draft lesson PDF removal correction

Owner approved on 2026-09-19 with “sim”: remove only the deleted draft lesson's PDF associations, preserve document metadata/bytes and other uses, test the case and update PR25. New narrow task after delivered library run; not another review of the library.

Run pdf-draft-removal-20260919, scope pdf-draft-removal-v1. Critical assurance because existing deletion path must preserve retained documents. Small mechanical correction inside the existing Action transaction; no new specification/architecture decision, UI design or migration.

AC-01: authorized current draft deletion with uploaded/reused PDFs succeeds and ordering persists. AC-02: all other saved uses, document metadata and bytes survive and contextual access works. AC-03: denied/published/stale attempts and failed deletion transaction retain associations. INV-01 retains immutable PDFs; INV-02 retains draft authorization/revision/atomicity/ordering.

Frozen QA-01: actual disposable editor removal/reload with a PDF also linked elsewhere; surviving link, metadata, bytes and association verified. QA-02: current positive control then stale or permission-revoked UI/API removal denial preserves lesson/pivot. Tests cover exact Action positive, denied/published/stale and rollback seams. No unrelated workflow audit.

Only one Worker edits RemoveDirectCourseLesson.php and focused Documents tests; fresh Code Review/Test Analyst review final delta together, then independent runtime QA. Canonical verify, Pint/build and scope-only commit/push to existing PR. Preserve dirty compliance/composer/translation/tooling changes and never touch .env or real training data.
