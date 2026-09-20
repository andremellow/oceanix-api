# Directed r3 validation scope

Run pdf-library-20260916; scope pdf-library-v1; checkpoint pdf-library-20260918-r3. Owner-approved round2 addresses only QA-R2-01 (AC-06, INV-04, B-14/QA-07).

Exact baseline files are retained at .toscanini/runtime/runs/pdf-library-20260916/snapshots/r2/. Compare those files with the current counterparts using git diff --no-index. Product/test correction comprises only resources/js/course-editor.js, resources/views/components/course-editor/root.blade.php, and tests/JavaScript/lesson-documents.browser.test.mjs. Read the assigned QA finding and these raw deltas; do not expand to unrelated workflows or repeat the full audit.

checkpoint-r3.json records the final 33 feature file hashes. lang/pt_BR.json additionally contains six unrelated audience/job-function translation entries from concurrent work; preserve and exclude those additions from PDF review. Concurrent compliance files, their tests and composer.json changes are outside scope. No PDF domain, policy, route, migration, translation or persistence correction exists in this batch. Prior full review and complete QA matrix evidence remains retained; only causally affected gates reopen.
