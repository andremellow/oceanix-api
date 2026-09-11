# Execution contract

Create `.toscanini/runtime/runs/<run-id>/execution-contract.json` from the installed template. Keep the artifact public-safe and repository-relative. It is the shared source of truth for every specialist in the run.

The contract records one goal, explicit scope and non-goals, expected change boundaries, stable `AC-*` observable acceptance criteria, stable `INV-*` invariants, specification and clarification status, direct product-owner architecture approval, assurance, an estimated no-orchestration baseline, convergence budgets, and explicit approval before implementation.

Each ledger finding records both `sourceRole` (who detected it) and `failureStage` (which stage should have prevented it). Optional learning proposals remain pending until explicitly accepted by the user; the workflow never applies them automatically.

For large work, specification is required. For medium work, require it when product behavior, domain meaning, data, security, or cross-system decisions are unresolved. Small mechanical work may mark it `not-required` with scope captured in the lightweight contract. If Spec Kit is enabled and a required artifact is missing, ask the user to run its flow or explicitly waive it. Record a waiver reason; never infer consent.

Architecture freezes constraints and test implications, not implementation minutiae. A later discovery may amend the contract, but the orchestrator must identify it as a contract change, return to the responsible stage, and reapprove before the Worker proceeds.

Validate before implementation:

`python3 .toscanini/bin/toscanini_contract.py --run-id <run-id>`

Validate the contract and unresolved finding ledger before completion:

`python3 .toscanini/bin/toscanini_contract.py --run-id <run-id> --completion`

New contracts use schemaVersion 3. The Architect author verdict is distinct from owner approval. Owner approval binds the architecture hash and scope; conformance binds those and the final checkpoint, and is required only after implementation.
