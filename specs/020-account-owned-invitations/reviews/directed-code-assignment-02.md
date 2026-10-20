# Directed code verification assignment

Run: 20261020-account-owned-invitations. Scope: account-owned-invitations-v1. Checkpoint: account-owned-invitations-v1-remediation2-9a02b0261bbf387d.

Use the approved spec, architecture, execution contracts and frozen acceptance/invariant IDs as authority. Inspect raw final code and correction deltas in remediation-02-checkpoint.json and remediation-01-checkpoint.json. This assignment contains required outcomes, not approval or implementation conclusions. Do not read other review reports or Worker verification narratives.

| ID | Required outcome | Local surface |
| --- | --- | --- |
| CR-01 | Validate actors against immutable source transition and origin-specific current authority. Legitimate tenant/platform product actors need no Account login User; Account-origin Policy remains enforced. | Account AuthorizeAccountOperator; Compliance ApplyProductOperation |
| CR-02 | Coalesced provider work preserves durable receipt/recovery and payload-conflict checks for each caller UUID/hash/principal/product. | Account QueueCompanyInvitationSync and reconciliation |
| CR-03 | Manually requeued send recovery completes using its immutable operation identity; receipt404 permits only safe same-intent submit, with authority/generation checks and no blind resend. | Compliance SendWorkosInvitation |
| CR-04 | Scheduled/transient unknown work remains observable until actual confirmation or exhaustion; terminal state is explicit and legacy access behavior remains intact. | Account OperationResource/ProductOperationEnvelope; Compliance ReconcileWorkosInvitations |
| CR-05 | Resumed membership worker rechecks readiness before provider effect; disabled operation remains preserved and reenable safely resumes. | Account EnsureProductPersonMembership |
| CR-06 | Consume/version a manual alias retry authorization atomically so it rearms canonical work at most once. Renewed exhaustion remains terminal until another authorized manual action, under either scheduler order, preserving confirmed work. | Account ReconcileCompanyInvitation |
| CR-07 | Finalizing a historical send receipt must preserve newer same-generation accepted/expired/revoked observations, acceptance history, access and monotonic sequence/hash. | Compliance SendWorkosInvitation |

Account local finding IDs: CR-01, CR-02, CR-05, CR-06. Compliance: CR-01, CR-03, CR-04, CR-07. Verify all seven outcomes and only causal regression checks. No new open-ended audit or source edits. Write code-review-directed-02.md. Emit accurate started/terminal events role code-reviewer, phase remediation, round2, review-mode directed, context-mode fresh, current scope/checkpoint and local finding IDs; revalidation reason: assigned correction outcome verification. Account writes need filesystem escalation. Never alter .env, production data, verifier tooling or ledger status.
