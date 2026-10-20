# Independent code review

Verdict: **REQUEST_CHANGES**

Run `20261020-invitation-validation-repair`; scope `invitation-validation-repair-v1`; checkpoint `invitation-validation-repair-v1-0e82b8595ce44ed2`. Agent/sourceRole `code-reviewer`; context fresh; review-mode independent; discovery round 0, phase review. Finding count: 1 blocking, 0 follow-up.

Reviewed all 27 checkpoint files against their recorded per-repository baselines. All checkpoint hashes match. Unrelated dirty changes excluded. Read approved repair architecture/approval/runtime contract, original specification and relevant architecture/design constraints, repository rules, and necessary authorization, receipt, transaction and presentation dependencies. No prior verdict or Worker conclusion was used. Executable QA remains a separate gate.

## CR-R01 — Live membership GET now runs while holding the company lock

- Severity: P2 / medium.
- Classification / causal classification: IMPLEMENTATION_DEVIATION; newly introduced synchronous I/O in an existing locked revalidation path.
- sourceRole: code-reviewer.
- failureStage: implementation.
- Discovery round: 0; phase: review.
- Scope / changedPaths: Account `app/Services/Integration/ProductActorAuthorization.php:53` (new membership read; lines 47–68).
- Approved scenarios: QA-R01, QA-R06; existing registration and product access regression surface.
- Blocking: yes. Basis: direct regression on frozen existing registration/current-authority/concurrency surface (`validationScope.regressionSurfaces`, AC-05 existing register behavior). Approved architecture §§5, 7.1 and transaction contract explicitly require no network under locks. This is an implementation deviation, not an alternative authorization policy.

Evidence: `ProductActorAuthorization::authorize()` now always calls `WorkosUserLifecycleClient::membership()` for an ordinary actor, including `consumeNonce: false` calls. Existing `RegisterCompanyPerson::handle()` starts `DB::transaction`, acquires Company `lockForUpdate()` at line 74, then calls the guard at line 75. `FinalizeProductRegistration::handle()` locks Company at line 32 then calls it at line 33. `ChangeUserProductAccess::fromProduct()` repeats the pattern at lines 35–38. The new line 53 therefore performs a provider GET while those company locks remain held. Previously these locked rechecks performed only local queries/proof checks. The client has a 5-second connect timeout and 12-second request timeout. A slow/unavailable membership read now holds the company serialization lock during the provider wait, blocking registration, invitation and access work for that company and exposing normal registration to avoidable lock waits/timeouts.

Change evidence: the unconditional membership read is in the repair raw diff; unchanged callers establish the directly affected execution path. Direct guard tests run without those Action transactions and do not expose this integration effect.

Required outcome: obtain provider evidence outside transactions and retain local principal/company/member/binding/proof revalidation under the existing lock without another network request. Preserve fail-closed behavior, proof-before-provider ordering and nonce semantics. Cover ordinary registration/finalization and product access through their actual Actions with an HTTP fake checking membership reads occur outside the Action-owned transaction; do not drop current authority checks. Obtain a scope amendment first if necessary caller edits exceed the frozen inventory.

## Inspected inventory

The complete 18 Account and 9 Compliance paths in `checkpoint.json`, including every production, view, localization, test and fixture delta, were inspected. Hash verification confirmed all 27 match this checkpoint. Read-only caller context used for CR-R01 does not enlarge the mutation scope.
