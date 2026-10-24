# Independent Code Review

Verdict: **REQUEST_CHANGES**. Five blocking findings; no follow-up findings.

- Run: `20261020-account-owned-invitations`
- Scope: `account-owned-invitations-v1`
- Checkpoint: `account-owned-invitations-v1-3734b6699a921fa4`
- Review: independent, fresh context, round 1, phase review
- sourceRole: `code-reviewer`; agent: `code-reviewer`
- Architecture: `490eb8c361fc7cbdb60f3ae8d0b33843fb25c1c140a243df5e2a9f8294310ecb`, with approved clarifications 01/02 and test scope amendments 01/02.

Inspected all 57 changed Account files and all 49 changed Compliance files in the checkpoint manifest, including tests, browser fixtures, migrations, views, translations, routes and runbooks. Traced the relevant unchanged authorization, transport, receipt, persistence, provider and scheduler dependencies. Source evidence is from committed checkpoint contents, not the dirty working tree. Both raw diff hashes were independently recomputed and match the manifest: Account `445b58c5621d167205784071125cf0d2b15dd766c7adef101725c9323478c3fc`; Compliance `ed13f7d2377c6bb029c77d16af4f18e7daccce05240a648b513edee037056399`.

This is code inspection, not executable QA. No source or test edits, provider calls, database mutations, or runtime QA were performed. No Worker report or previous review verdict was used. Findings concern implementation deviations from approved behavior; none requires new product scope or an alternative architecture.

## CR-01 — Product-origin delivery incorrectly requires an Account login User

- Severity: **P1 / high**; blocking: **yes**.
- Classification: `IMPLEMENTATION_DEVIATION`; failureStage: `implementation`.
- Discovery: round 1 / review; sourceRole: `code-reviewer`.
- Basis: **AC-02**, **INV-05**, SC-07/14/23; architecture §§7.2, 7.5 and clarification 02.
- Scope / changedPaths: Account `app/Actions/Integration/AuthorizeAccountOperator.php:29,48`; Compliance `app/Actions/ControlPlane/ApplyProductOperation.php:43`.
- Evidence / changeEvidence: Every new observation delivery now makes Compliance call Account's new actor authorization endpoint. That endpoint looks up an Account `User` and dereferences `$actor->workos_user_id` for mapping/observation authorization even when the source transition has `origin=product`. An authorized Compliance tenant actor can exist as an imported Account SharedPerson/CompanyMember without ever signing into Account; import does not create Account Users. The new platform actor contract likewise does not require an Account login User. For these valid actors `$actor` is null, so the delivery callback fails before the existing origin-aware `ReconcileCompanyInvitation::authorize($source)` can verify live Compliance authority. Account retains the read/send result, but the required product delivery cannot complete. The paired fixture creates Account Users for its synthetic actors and masks this condition.
- Required outcome: Validate the actor against the frozen source transition and use its origin-specific live authority. Product-origin observation delivery must work for a legitimate Compliance actor with no Account User row; Account-origin operations must retain current Account Policy enforcement. Exercise both tenant and platform origins without manufacturing an Account login identity.

## CR-02 — Concurrent reconciliation returns another request's receipt identity

- Severity: **P1 / high**; blocking: **yes**.
- Classification: `IMPLEMENTATION_DEVIATION`; failureStage: `implementation`.
- Discovery: round 1 / review; sourceRole: `code-reviewer`.
- Basis: **AC-03**, **INV-05**, SC-17/19; architecture §§7.2–7.4.
- Scope / changedPaths: Account `app/Actions/CompanyUsers/QueueCompanyInvitationSync.php:54-56`.
- Evidence / changeEvidence: The new member-level deduplication returns any open reconciliation transition directly, without recording the incoming UUID/hash or preserving its principal/product identity. Two distinct Compliance intents, or an Account request overlapping a Compliance request, therefore receive the first transition's operation ID (and potentially product/principal). `OperationResource` serializes the returned transition. Compliance `AccountUserClient::validateReceipt()` requires operation ID, product, subject and resource sequence to equal its submitted envelope, so it rejects this response. A later GET using the second immutable request ID returns 404 because no record was reserved for it. The existing concurrency test only invokes Account's local Action twice and asserts equal IDs; it does not exercise the HTTP receipt contract.
- Required outcome: Deduplicate provider work while maintaining a valid durable acknowledgment and recovery path for each submitted protocol operation identity. Both origins and concurrent distinct operation UUIDs must converge without mismatched receipts or an orphaned incoming UUID. Preserve principal isolation and payload-conflict checks.

## CR-03 — Manual retry routes a requeued invitation into recovery that cannot complete

- Severity: **P1 / high**; blocking: **yes**.
- Classification: `IMPLEMENTATION_DEVIATION`; failureStage: `implementation`.
- Discovery: round 1 / review; sourceRole: `code-reviewer`.
- Basis: **AC-03**, **INV-05**, SC-17/18; architecture §7.4.
- Scope / changedPaths: Compliance `app/Actions/People/QueueWorkosInvitations.php:38-42`, `app/Actions/People/SendWorkosInvitation.php:52-55`.
- Evidence / changeEvidence: The new queue branch reuses a delivery-unconfirmed attempt and changes its status to `Queued`, retaining `send_started_at`. The changed execute guard routes any non-null send marker into `recover()`. That method still requires the persisted attempt status to be exactly `Sending` at line 154. Thus, even with a valid confirmed receipt and unchanged generation/identity, every manually requeued attempt is marked delivery-unconfirmed/superseded instead of completing. If the original HTTP request never reached Account, recovery only GETs the nonexistent receipt and never reaches `sendThroughAccount()`'s safe same-ID PUT-after-404 path; the same retry remains permanently stuck. These are valid recovery states, not authorization or identity conflicts.
- Required outcome: Make same-attempt manual recovery accept its legitimate queued/recovery state, apply a confirmed receipt through the ordering guards, and recover a missing Account receipt by the approved immutable same-ID protocol when safe. Preserve the original actor, UUID/hash and provider mutation markers; never blindly create a new send intent. Cover response loss after commit and failure before Account acceptance.

## CR-04 — A transient invitation failure becomes permanent needs-attention in Compliance

- Severity: **P2 / medium**; blocking: **yes**.
- Classification: `IMPLEMENTATION_DEVIATION`; failureStage: `implementation`.
- Discovery: round 1 / review; sourceRole: `code-reviewer`.
- Basis: **AC-03**, **AC-05**, SC-18/23; architecture §7.4 and design constraints on honest progress.
- Scope / changedPaths: Compliance `app/Actions/People/ReconcileWorkosInvitations.php:148,156-164`.
- Evidence / changeEvidence: New polling treats every nonconfirmed receipt with a `safe_error` as terminal and changes the local operation to `needs_attention`. Account `SendCompanyInvitation.php:149-153` sets `safe_error=invitation_confirmation_unavailable` on the first transient failure as well as on exhaustion, while retaining `state=unknown` and a future automatic retry. Polling then excludes that local operation because its query only includes pending/unknown. Account can subsequently recover successfully, but Compliance no longer reads the confirmed receipt or finishes the associated invitation attempt through this path. The displayed claim that automatic retries stopped is false. The new exhaustion test explicitly uses `outcome=unknown` plus safe_error as its terminal signal, so it cannot distinguish these states.
- Required outcome: Expose and consume an unambiguous terminal-versus-retrying state, retain polling for scheduled work, and reconcile later confirmation. A transient first failure must not become terminal locally; actual exhaustion must remain visible and actionable. Verify initial transient failure followed by Account success separately from exhausted work.

## CR-05 — Membership worker bypasses the Account cutover readiness gate

- Severity: **P1 / high**; blocking: **yes**.
- Classification: `IMPLEMENTATION_DEVIATION`; failureStage: `implementation`.
- Discovery: round 1 / review; sourceRole: `code-reviewer`.
- Basis: **AC-05**, **INV-05**, SC-22/23; architecture §7.7 and clarification 01 step 3.
- Scope / changedPaths: Account `app/Actions/CompanyUsers/EnsureProductPersonMembership.php:64-70`, `app/Services/CompanyUsers/AccessTransitionProcessor.php:38-41`.
- Evidence / changeEvidence: The new membership operation checks `invitationOwnershipEnabled()` only in its request handler. The new processor branch invokes `resume()` directly, which checks time/lease but never readiness. A previously queued/due membership operation therefore continues actor callback and provider identity/membership creation after the Account registry flag is disabled. The existing scheduled `company-users:retry-due` command still dispatches all pending/unknown transitions regardless of the new recovery command's flag check, and already queued jobs also reach this branch. Send and reconcile workers have a readiness check, so the membership branch is a concrete omission in the new operational path.
- Required outcome: Recheck Account's registry readiness at membership execution before external work, including direct processor and existing due-dispatch entry points. With the flag false or module unavailable, retain durable evidence and perform no operational HTTP/provider effect. Prove enable-reserve-disable-execute and re-enable recovery without duplicate mutations.

## Gate disposition

All five findings are in-contract implementation deviations and form one consolidated correction batch. No spec/architecture amendment is requested by this verdict. Retain the frozen scenario matrix and review scope. Directed remediation verification should receive CR-01–CR-05 plus the remediation delta and causal regression checks; it must not replace this independent review with a new whole-scope audit.
