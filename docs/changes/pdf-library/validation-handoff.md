# PDF library validation handoff

Run `pdf-library-20260916`; scope `pdf-library-v1`. Owner approval is in `specs/007-pdf-library/approval.md`; contract in `.toscanini/runtime/runs/pdf-library-20260916/execution-contract.json`. The contract is authoritative. Base commit: `bdd2b1718c096e9070a2d7ad155def51a0d4a33e`. Final implementation checkpoint: pending; do not review a moving implementation.

## Inputs and boundary

Read approved spec, scenarios, architecture and design under `specs/007-pdf-library/`, repository AGENTS and design system. Inspect only this extension's raw diff against the base, including added files, and causal dependencies required to evaluate AC-01–06 / INV-01–04. Original PDF upload/delivery is a regression surface, not permission for a broader training/editor audit. No cross-owner PDF library sharing, physical deletion, restore, authorization redesign, deployment, PR change or tooling repair.

Company catalogs are separate from each other and from the platform catalog. Existing authorized training delivery is distinct from library access. Reuse must retain identity/bytes and use explicit Save. Archive removes new discovery/reuse without breaking existing saved or previously attached uses. Company capabilities are separately grantable; platform retains existing active-admin authority.

## Independent review contract

Code Review and Test Analyst start with fresh contexts and inspect the same stable checkpoint. Return all material findings together with evidence, AC/INV or direct-regression basis, required outcome, detecting role and failure stage. No production/test edits. Follow-ups outside the frozen scope are non-blocking. Implementation explanations and earlier reviewer verdicts are not briefing inputs. QA begins after this checkpoint resolves.

## Runtime QA contract

QA exercises real UI/API, not source/test review. Use only a fresh disposable fixture directory described by tests/Support/Documents/README.md. Verify environment isolation before mutations. No .env, application database or real customer data. Record setup, action, expected, observed, evidence and cleanup per frozen scenario. A test run alone is not runtime QA.

- QA-01: Three editor catalogs, >60 files, search/clear/pages, duplicate names, empty states; foreign owners absent.
- QA-02: Native new-tab Open of a different-lesson PDF, bytes received, original editor unchanged.
- QA-03: Three editors, selected/custom/filename labels, exact settled caret outside anchors, explicit Save/reload and contextual access.
- QA-04: Positive granted controls, denied capabilities, both-direction foreign IDs, direct access and post-opening revocation.
- QA-05: Archive confirmation/cancel/retry while unsaved text exists; active row removal and retained published/copied links.
- QA-06: Archive-first rejection, reuse-first pending Save, stale/cancelled/published target rejection; bounded PostgreSQL two-session evidence required for actual serialization.
- QA-07: Keyboard, 390px, English/PT-BR, long names, busy controls and request-failure/retry. Inspect console/network/application errors within these flows.
- QA-08: Existing successful PDF upload, validation failure and cancel through the expanded modal.

Design reviewer compares rendered behavior to approved design.md at the same checkpoint, using separate fixtures if concurrent with QA. It does not create new requirements.

## Results and telemetry

Every independent result names run, scope, final checkpoint, verdict and all completed coverage IDs. Emit started and terminal events with fresh context, independent review mode, round and phase, evidence path and finding count. QA terminal coverage must list every actually completed QA ID; never mark unexecuted scenarios passed. Directed remediation is not another whole-scope review. Keep all evidence public-safe, without credentials or real user data.
