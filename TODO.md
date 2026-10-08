# TODO

## Deferred PR #30 review corrections

Deferred by the product owner on 2026-10-08 to prioritize company user management. These items remain unresolved; deferral is not verification approval or permission to merge/deploy.

- [ ] **Adapt the first-access test to Account-owned company creation.** `tests/Feature/Auth/TenantFirstAccessTest.php` still invokes the removed `CreateCompany` action. Prepare an existing company and grant administrator access through the supported action, preserving invitation and first-access assertions. This currently fails CI. [Review comment](https://github.com/andremellow/oceanix-api/pull/30#discussion_r4213125517).
- [ ] **Allow securely scoped global-editor uploads with a disabled company in session.** The generic Livewire upload route inherits stale tenant context and rejects global shared-course/module uploads. Distinguish trusted global uploads without bypassing disabled-company enforcement for tenant uploads. Require architecture/validation amendment before implementation; verify signed origin, actor/session binding, expiry, tampering, revocation and tenant denial. [Review comment](https://github.com/andremellow/oceanix-api/pull/30#discussion_r4213125522). Local proposal: `.toscanini/runtime/runs/20260929-account-company-ownership/pr30-upload-scope-amendment.md`.
