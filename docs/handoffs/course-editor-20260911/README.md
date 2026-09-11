# Course editor continuation package

Status: handoff preparation only. No unification or CI automation has been implemented or started by creating this package. The two handoffs share SHARED-CONTRACT.md. This package is not a signed execution contract, approved architecture, or new QA approval.

## Start here

- TEST-AUTOMATION.md: test-authoring and CI workstream.
- EDITOR-UNIFICATION.md: production editor workstream and integration coordination.
- SHARED-CONTRACT.md: common expected behavior, responsibilities, known decisions and integration exit criteria.
- archive/manifest.json: preserved text artifacts and their source paths.

The user explicitly wants two collaborating implementation agents: one owns tests, the other owns production unification. Independent test authorship does not replace independent review. Both workstreams ultimately must run against the SAME final application checkpoint, not merely pass separately in incompatible checkouts.

## Workspace preservation

Repository: /Users/andrepiresdemello/Code/oceanix-api. Captured parent HEAD: 28c2d7a. After preparing this package, the owner explicitly requested a commit of this work. Use the commit containing this README as the shared starting baseline: it preserves the course editor fixes, tests, Spec Kit artifacts, prior Toscanini update and handoff archive. Do not start from the default branch and assume it includes that commit. Separate worktrees must start from this exact shared baseline; coordinate production/test integration before final verification. No push or task creation is authorized merely by this handoff. An unrelated operational transfer script and Python caches are deliberately excluded and remain in the original checkout.

The archive contains inert text snapshots, not live production files. It preserves the two JavaScript tests, lifecycle reports and historical disposable scripts without depending on /tmp. Current production/test files remain authoritative; compare hashes and status before integrating.

The original QA SQLite database, screenshot binaries and browser sessions are NOT archived. Recreate synthetic fixtures; do not carry a live database or session into CI. Historical screenshots were supplemental evidence, not executable tests. Never execute archived .txt scripts unchanged: they contain historical absolute paths, fixture IDs, local auth assumptions and an intentionally public disposable APP_KEY.

## Decisions/readiness

Owner has requested one shared PHP/Blade/JavaScript editor, the two width corrections, independent automated tests and parallel workstreams. Owner explicitly selected a Save button with an unsaved-changes warning for BOTH contexts during handoff preparation. Neither the new detailed specification nor architecture/execution contract is approved by this package. Complete the repository planning/readiness procedure before claiming unattended execution is ready.

Original lifecycle run: course-editor-lifecycle-20260911; final checkpoint course-editor-lifecycle-remediation-43372bcbc810. Historical product verification passed (715 PHP tests, 13 skipped; 12 editor JavaScript checks; browser regression and full QA). Formal Toscanini completion did NOT pass: its QA prerequisite lookup ignores valid directed approvals. See archive/lifecycle/gate-compatibility.md.txt. Do not alter old events, rerun broad reviews to fake independence, or bypass the gate. A tooling fix is separate authority, not implicitly approved here.

## How to resume

Give the test agent TEST-AUTOMATION.md and SHARED-CONTRACT.md. Give the unification agent EDITOR-UNIFICATION.md and SHARED-CONTRACT.md. They may consult historical artifacts for discovery; fresh final reviewers must receive only the accepted contract, final code/raw diff and neutral fixtures, not these historical verdicts or worker conclusions.
