# PDF feature verification

Run: `pdf-editor-20260914`; scope: `pdf-links-v1`.
Approved architecture SHA-256: `39a2d19db41a966912b4a62b322abd964de519a49e8d265894ac98d9a88e8c87`.
Implementation base: `f6a6645`.

## Scope rule

Validate ONLY request.md's AC-01–06, INV-01–04 and QA-01–09. Adjacent media insertion is a regression surface only when a shared insertion hook changes. Pre-existing defects, unrelated application behavior and optional improvements must not block or expand this task. Independent reviewers receive raw diff and approved artifacts, not implementation conclusions or other review verdicts.

## Environment probes (before implementation)

- `npm run build`: PASS.
- `php vendor/bin/pest tests/Feature/CourseEditor/UnifiedCourseEditorContractTest.php --filter=image --compact`: PASS, 2 tests / 46 assertions.
- `php vendor/bin/pest tests/Browser/BrowserEnvironmentSmokeTest.php --compact`: PASS, 1 test / 4 assertions.
- Pest runtime: local SQLite memory database from phpunit.xml, local port escalation required and granted. No real WorkOS/Cloudflare calls.
- Private storage parent writable; PHP request/upload limits 200 MB; Livewire temporary upload default 12 MB permits proposed 10 MB.

These establish executable readiness only; they do not prove the new PDF behavior.

## Acceptance evidence

| Criteria | Required evidence | Implementation | Automated evidence | Executable QA |
| --- | --- | --- | --- | --- |
| AC-01 | All three contexts, selection/custom text, save/reload | Implemented | Focused Pest/browser passed | QA-01/02 passed r2; r3 caret delta pending |
| AC-02 | New tab with PDF response | Implemented | Focused/browser passed | QA-03 passed r2; unchanged |
| AC-03, INV-01/03 | Current containing-training authority; positive and denied controls | Implemented | Focused positive/denial tests passed | QA-04/05 passed r2; unchanged |
| AC-04 | Type/size/error/cancel and no broken insertion | Implemented | Focused/browser passed | QA-02/06 passed r2; cancellation delta pending |
| AC-05, INV-02 | Draft-only updates, immutable bytes and retained copy references | Implemented | Focused tests passed | QA-08 passed r2; domain unchanged |
| AC-06 | Accessible localized controls at desktop/mobile widths | Implemented | Focused/browser passed | QA-07 passed r2; directed caret QA pending |
| INV-04 | Surrounding text/link preservation; changed shared media hooks only | Implemented | r3 exact continuation browser checks passed | QA-09 passed r2; directed caret QA pending |

## Delivery gates

Implementation checkpoint: `pdf-links-20260914-r1`; source scope in implementation-files.txt.

`toscanini verify --run-id pdf-editor-20260914`: PASS (exit 0). The required contract preflight, composer verify, PDF Playwright flow, existing browser suites, JavaScript checks, build and Pint completed. Independent reviews/QA are still pending. Concurrent additional Pest attempts by reviewers were not used as canonical evidence; future Pest execution is serialized to avoid shared fake-storage interference.

Worker-specific evidence is recorded in implementation-evidence.md and is not independent review/QA evidence. No feature completion is claimed until the remaining gates approve.

## Corrected checkpoint

Current stable checkpoint: `pdf-links-20260914-r2`. One consolidated correction batch completed. `toscanini verify --run-id pdf-editor-20260914` again passed with exit 0, including the expanded PDF Playwright flow (13.49 seconds), required PHP/JavaScript/browser checks, build and Pint. Worker focused PDF tests: 37 passed / 282 assertions.

Directed Code Review and Test Analyst both APPROVE; CR-01/02 and TA-01–05 are resolved. DR-01 duplicates CR-02 and its correction is verified; the separate design gate and independent QA remain in progress. Evidence: code-review-directed.md and test-analysis-directed.md.

The completion gate currently has two bookkeeping defects described in proposed-gate-fix.md. No gate code/history was changed; owner authorization for that separate correction is pending. Expected QA/design/conformance gates are also still unfinished. This does not replace the required final gate.

## Final source checkpoint and process blocker

Source frozen at `pdf-links-20260914-r3`. Round 2 corrected PDF-DES-01 and the disposable fixture lookup issues. Focused checks passed: 37 Pest tests / 282 assertions, six selected/custom caret browser paths with cancellation and persistence, 30 editor JavaScript tests, build and Pint. Exact r2→r3 delta and defect sensitivity are recorded in remediation-evidence-r2.md.

Directed Code Review and Test Analyst APPROVE r3 (code-review-r3.md, test-analysis-r3.md). Design revalidation was BLOCKED by the reviewer's briefing restrictions without inspecting runtime; see design-revalidation-r3.md. The runtime closure remains unproved by that gate. No source implementation changes remain planned.

Fifteen specialist starts are recorded; replacing the unavailable design revalidator plus final QA and architecture conformance would require eighteen against the approved seventeen. Owner replan approval is required before additional dispatch. Separate minimal gate bookkeeping correction remains unapproved. The completion gate was run and exited 1; execution-report.md in the run directory records actual blockers. No telemetry was rewritten and no gate bypass is proposed.

Final `toscanini verify --run-id pdf-editor-20260914` at r3: PASS, exit 0. Contract preflight and complete `composer verify` passed, including PDF browser (19.97 seconds), the existing 79-test/811-assertion and 32-test/674-assertion browser suites, 25 preview JavaScript tests, PHP suite, build and Pint. This canonical success does not substitute the blocked independent completion gates. No deploy, commit, PR or shared-database migration was performed.
