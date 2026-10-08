# UI design boundary

Source: docs/control-center-design-system.md. Only the company list and the organization-provision action area on company detail change.

- List: remove create form and its two-column form/list layout; use existing full-width detail-card with unchanged company rows. Hero/help text: “Create companies and enable Compliance in Account.” No new cross-app URL or environment variable.
- Detail: remove organization provisioning/synchronization button and corresponding error block; keep company identity and all unrelated operational/user/course controls unchanged. Brief Account guidance uses existing secondary text styling.
- Empty list: existing empty-state component, “No companies” and the Account guidance, no create button. Long names wrap within existing responsive layout.
- No new form, modal, loading or mutation state. Stale removed Livewire operations must fail on server with no effects. Existing authorization unchanged.
- Check 320px and 1440px list/detail navigation; retained links/buttons remain keyboard accessible. Do not audit unrelated regions of detail or change badge status semantics.

Owner approved on 2026-09-29. Implementation/browser evidence: verification.md; independent design review is recorded in the runtime run directory.
