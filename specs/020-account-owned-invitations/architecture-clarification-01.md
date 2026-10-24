# Architecture clarification 01: existing registry cutover storage

Date: 2026-10-20. Scope: `account-owned-invitations-v1`. Directed clarification only; no second whole-scope audit.

Approved architecture: `architecture.md`, SHA-256 `490eb8c361fc7cbdb60f3ae8d0b33843fb25c1c140a243df5e2a9f8294310ecb`.
Owner decision: **“Manter o escopo atual”** rejects amendment 01's proposed Account `config/services.php` addition. That rejected amendment is retained as history and supplies no implementation authorization.

## Assessment

The existing approved architecture permits the cutover flag to live in the Account ProductModule registry. Section 7.6 explicitly assigns configuration to the existing `services.account` and ProductModule registry, including an operational cutover flag through trusted operator setup. Section 7.7 specifies its default-off behavior and readiness conditions, but does not require Account environment-backed storage. The approved additive Account migration and `app/Services/Products/ProductRegistry.php` are already in the mutation inventory. Account `docs/production-integration.md` is also approved and already documents trusted ProductModule inspection/setup.

Actual inspected source supports this choice: `ProductRegistry::resolve()` selects a module by exact key, current environment and active state; `ProductModule` is an Eloquent model whose immutable identity is id/key. A query-builder update of a dedicated boolean control does not need a fillable/cast change or a new file. This preserves the required operational behavior and existing registry boundary. It is an implementation detail within approved sections 7.6–7.7, not a replacement architecture or new product decision.

## Concrete implementation within existing paths

1. In the already-approved Account migration `database/migrations/2026_10_20_000001_add_account_invitation_ownership.php`, add a non-null boolean column on `product_modules` named `account_owned_invitations_enabled`, default **false**. Existing rows therefore remain disabled. Do not repurpose `active` or capability lists: those retain their existing meaning.
2. In the already-approved Account `ProductRegistry`, expose a focused operational-readiness check selecting the `compliance` module for the current environment, with `active=true`, `protocol_version=2`, and `account_owned_invitations_enabled=true`. Use an explicit boolean query predicate/`exists()` so no new Model cast is required. Missing module/false flag fails closed. Normal `resolve()` credential, URL and protocol checks still run at the actual HTTP boundary; the flag does not replace them.
3. Already-approved Account operational entry/worker paths consult that registry check instead of the unconfigured `services.account_owned_invitations.enabled` key. Preserve all existing human/principal/company/mapping checks and live execution rechecks. Import remains independent of product registry and cutover readiness.
4. Document trusted operator setup in the already-approved Account `docs/production-integration.md`. An operator can inspect/update this one column via Tinker using an exact `key=compliance` and current-environment predicate, after the original pair-readiness conditions are satisfied. Require exactly one matching module; do not create a module implicitly. Enabling/disabling is deployment/operator work, not an action authorized or executed by this clarification. Do not print secrets or change identity/configuration fields. Existing operational audit practice applies.
5. Compliance keeps its originally approved `config/services.php` environment-backed gate. Both sides must be ready; differing storage mechanisms do not alter the wire contract or relax either product's readiness condition. Runtime configuration caching does not prevent Account from reading its database-backed registry flag. Tests may exercise the registry flag directly or fake the focused registry check; any retained config override must be explicitly test-only and cannot become a production bypass.

Illustrative additive schema entry:

```php
Schema::table('product_modules', function (Blueprint $table) {
    $table->boolean('account_owned_invitations_enabled')->default(false);
});
```

Illustrative registry predicate (method naming may follow the existing class):

```php
return ProductModule::query()
    ->where('key', 'compliance')
    ->where('environment', app()->environment())
    ->where('active', true)
    ->where('protocol_version', 2)
    ->where('account_owned_invitations_enabled', true)
    ->exists();
```

No ProductModule model edit, new production path, Account configuration-file edit, `.env` edit, new endpoint, new permission or new dashboard is required. No migration is run and no flag value is changed by this authoring task.

## Scope and readiness conclusion

Author assessment: **APPROVED — within the existing architecture and path boundary**. No unresolved material architecture conflict was found in this directed assessment. This is not approval of the rejected scope addition or a claim that the owner approved new requirements.

All original FR/AC/INV, regression surfaces and QA scenario IDs remain unchanged. Existing SC-22 / QA-22 already proves default-off cutover and readiness; its fixtures should set the Account registry field and Compliance configuration appropriately. Existing planned tests must demonstrate false/missing flag denial and explicit ready-state positive control. These checks implement the frozen scenario, not a new QA scenario. Original operational readiness and explicit deployment authorization conditions remain unchanged.

The hash-bound architecture, inventory and contracts were not edited. Only this clarification note was authored; production code, tests, databases and environment files were not changed by the architect.
