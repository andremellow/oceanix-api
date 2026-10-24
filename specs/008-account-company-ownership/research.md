# Research and observed behavior

- Compliance baseline cc621c77fb0901dd15b72873e3a8d346205e7631; Account reference 3e4978016dd8ab6e260ce3fad90aa58ddbb9358c.
- `platform/⚡companies.blade.php` calls CreateCompany and ProvisionCompanyInWorkos. Detail component also calls ProvisionCompanyInWorkos. `oceanix:create-company` calls CreateCompany. Search found no other application callers of those two Actions or WorkosOrganizationService.
- Existing receiver `EnsureCompany` creates by identity or binds a matching WorkOS organization; refuses conflicting slug/binding; transaction/receipts preserve idempotency. Existing ProvisionCompanyTest covers creation, adoption with evidence preservation, retry, authorization, conflicts and failure translation.
- Account already owns creation/import and its Product flow calls the receiver. Prefer reuse; no new service, endpoint or Account production change.
- Decision: remove unused legacy Actions/client and Livewire handlers. Retain the CLI signature only as a failing, explanatory compatibility entry point. Alternative of hiding controls alone is insufficient because stale component/CLI calls remain possible.
- Decision: no automatic repair of legacy WorkOS IDs, no status-badge correction and no other cleanup. Those are outside the user's scope.
- Compliance constitution is an unfilled template; use AGENTS.md and design-system rules, do not edit governance.
- Neither repository has `.specify/extensions.yml`; no Spec Kit extension hooks apply.
