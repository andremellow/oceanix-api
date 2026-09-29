# Approval summary — company ownership

Status: approved by owner on 2026-09-29. Scope ID: account-company-ownership-v1. See approval-record.md.

## Requested outcome
Company creation/import and WorkOS organization provisioning originate in Account. Enable Compliance creates or links the corresponding Compliance company. Remove alternate Compliance entry points only.

## Exact intended change
1. Remove the create form and create/provision handlers from the company list.
2. Remove the provision/synchronize organization control and handler from company detail.
3. Remove the two unused legacy Actions and organization client. Retain the legacy create-company command only as an explanatory failure with no writes.
4. Preserve and verify the existing Account creation/import/enable and receiver behavior; no Account production edits expected.
5. Update only associated tests and register the new UI browser check.

## Explicit exclusions
User management and permissions, operational suspension, product disabling, identity repair/migration, the synchronized badge, environments/Herd, and every other finding.

## Validation
Eight scenarios in scenarios.md and runtime contract: list, detail, direct/stale methods, old CLI, new enable, existing-company adoption, retry/conflict and authorization. Existing data and IDs preserved. No real provider or Herd mutations.

Prepared baseline: Account 38 tests/154 assertions pass; Compliance 42 tests/201 assertions with no failures and absent-.env warnings; frontend build and browser runtime check succeed. These are baseline/readiness evidence, not completed implementation evidence. Separate two-app HTTP fixture preparation completed before Worker dispatch; runtime readiness-http.json records actual created/existing outcomes, with no user credentials.

## Approval artifacts
- spec.md — rules, boundaries, criteria and invariants
- scenarios.md — concrete test/QA cases
- plan.md — exact files and implementation approach
- architecture.md — implementation-ready architecture and framework suitability
- design.md — the two changed UI regions
- ../../.toscanini/runtime/runs/20260929-account-company-ownership/execution-contract.json — machine-readable scope, matrix, budgets and readiness

Owner approval must bind architecture SHA-256 and scope ID in the execution contract. Approval does not authorize unrelated findings or deployment to Herd.

Architecture SHA-256 for this approval: `41b1f1ae427d7e941169bfa05531f96d32270ebc2a79b0c5b8f98ecc9109269e`.
