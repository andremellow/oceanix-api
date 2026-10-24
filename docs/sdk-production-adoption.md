# Published SDK and Accounts integration for existing Compliance

Compliance consumes `oceanix/platform:^0.1.0`, locked to `v0.1.0` (`69eb008c6abc00d9f6763745d2d0181215445291`). Composer downloads it into vendor from the private GitHub repository. The local SDK directory is no longer required. Package isolation tests belong to the SDK repository; Compliance tests exercise its adapters and existing routes against the installed release.

## Preserved boundaries

Keep the existing APP_KEY, database, WorkOS identifiers, administrator lists, company/person identities, bindings, local model class names, historical operation receipts and queued job names. Existing App classes delegate to the SDK so serialized jobs, model morph identities and old API endpoints remain compatible. Compliance still owns its migrations; SDK migrations are disabled. No layout or design-system changes accompany this adoption.

The Compliance middleware also restores an existing tenant login to its own active platform-administrator account before the SDK clears tenant context on global routes. This preserves older sessions without granting global access from a company role or matching email; mismatched company sessions and inactive/global-role revocations remain denied.

Existing configuration remains `services.account` and `config/oceanix.php`: keep `ACCOUNT_URL`, `ACCOUNT_TOKEN`, `ACCOUNT_ACTOR_ASSERTION_SECRET`, `ADMIN_EMAILS` and `PLATFORM_ADMIN_EMAILS`. The product identifier is fixed to `compliance` by the host adapter. Set `ACCOUNT_OWNED_INVITATIONS_ENABLED=true` only with a working registered Accounts connection and validated invitation ownership. Do not copy Certs credentials.

## Build and release

Use a production build secret `GITHUB_SDK_TOKEN` with read access to Oceanix-SDK and the existing private laravel-tasks dependency. A fine-grained token must include both repositories and meet public dependency organizations' token lifetime policies; a 90-day expiry worked for the Certs setup. Keep credentials outside Git. Keep existing Flux Pro authentication:

```bash
composer config http-basic.composer.fluxui.dev "${FLUX_USERNAME}" "${FLUX_LICENSE_KEY}"
composer config --auth github-oauth.github.com "${GITHUB_SDK_TOKEN}"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci --ignore-scripts --no-audit
npm run build
```

GitHub Actions also requires the repository secret `GITHUB_SDK_TOKEN` with the same repository access. Add it in the Compliance repository Settings → Secrets and variables → Actions. The workflow configures Composer authentication in all four jobs and stops with an explicit message when the secret is missing. Existing Flux secrets remain unchanged.

Do not leave an obsolete COMPOSER_AUTH variable overriding the configured GitHub credential. Production uses the committed lockfile, never `composer update` or the development setup script. Back up existing databases/configuration, drain in-flight work and coordinate workers/schedulers during deployment. Apply the application's pending migrations with `php artisan migrate --force --no-interaction`, refresh cached configuration through the hosting deployment, and restart workers with the new code. Do not clear durable operation/cache evidence.

Run `php artisan oceanix:doctor`; it checks local configuration/schema, not network access. No migration or production configuration change was performed while preparing this adoption.

## Why Compliance can be absent in Accounts

The product list now reads active protocol-v2 ProductModule rows for the current environment. Having a Compliance URL in Accounts' environment alone does not create this row. Check the registration before rotating any production credential or changing existing company entitlements.

### Existing legacy Accounts connection

If the original integration is already configured, preserve its credentials. Accounts supports importing the existing configuration without Tinker:

```dotenv
COMPLIANCE_API_URL=https://YOUR_COMPLIANCE_HOST
COMPLIANCE_API_TOKEN=EXISTING_ACCOUNTS_TO_COMPLIANCE_TOKEN
COMPLIANCE_ACTOR_ASSERTION_SECRET=EXISTING_SHARED_SECRET
COMPLIANCE_PRINCIPAL_ID=EXISTING_ACCOUNTS_LOCAL_PRINCIPAL_ID
```

COMPLIANCE_PRINCIPAL_ID is the service identity inside Accounts which issued Compliance's existing ACCOUNT_TOKEN; it is not an ID from Compliance. After applying these variables/configuration in Accounts, run:

```bash
php artisan products:register --bootstrap --no-interaction
```

All four fields are required; incomplete bootstrap entries are skipped by the existing command. This imports or updates the registry connection; it does not enable company access or replace company/person bindings. Review the existing values before running it: supplying different credentials updates that connection. Existing invitation readiness is not automatically changed by bootstrap. Once the compatible receiver is deployed and the preserved connection has been verified, enable invitation ownership for this product in Accounts:

```bash
php artisan products:enable-invitations compliance --no-interaction
```

Then set `ACCOUNT_OWNED_INVITATIONS_ENABLED=true` in Compliance and apply cached configuration. This command changes only the registered product readiness flag and sends no invitations. Validate one invitation and reconciliation end to end before resuming normal dispatch.

Do not run `oceanix:configure-receiver --rotate` during this path: operation receipts are principal-bound, and old in-flight work must retain its principal/credential until settled. A legacy valid receiver does not have to be replaced just to adopt the SDK.

### Genuinely new connection only

If Accounts has neither a Compliance registration nor a Compliance service identity and there is no live legacy integration, use the same fresh registration flow as Certs:

1. In Compliance, run `php artisan oceanix:configure-receiver --no-interaction`. Save the emitted ACCOUNT_RECEIVER_TOKEN in Compliance. The command writes only the receiver's token hash to the database, not the environment file.
2. In Accounts, set PRODUCT_RECEIVER_TOKEN to that value and apply configuration.
3. Run `php artisan products:connect compliance https://YOUR_COMPLIANCE_HOST --name=Compliance --no-interaction` in Accounts.
4. Save the emitted ACCOUNT_TOKEN and ACCOUNT_ACTOR_ASSERTION_SECRET in Compliance; set ACCOUNT_URL to production Accounts. Apply configuration and run oceanix:doctor.
5. Remove temporary PRODUCT_RECEIVER_TOKEN from Accounts and apply configuration.

Identical `products:connect` reruns preserve credentials without printing them. Conflicting existing registrations/service identities stop the command; do not delete them to force registration. The receiver command adds its managed principal without deleting unrelated legacy principals or tokens, and refuses silent replacement of an installed managed token.

## Acceptance and rollback

Verify the registered product appears in Accounts, then use an agreed test company to confirm access, existing bindings, platform and tenant login, SDK People/profiles, registration/adoption, invitation/reconciliation, revocation and sequential retry. Verify an existing production company retains its IDs, roles, training assignments, progress and certificates. Queue workers and scheduler must be running in both applications. Do not disable/reprovision a live company simply to make the product appear.

If validation fails, stop new dispatch, preserve unknown outcomes and restore the prior application release/lockfile and compatible configuration after checking in-flight operation evidence. Do not reset the database, recreate identities, delete receipts, or rotate keys as a rollback shortcut. A production rollout still requires the operator's separate approval; local tests do not prove production integration.

## Local release validation

The final application run passed 1,215 tests with 7,214 assertions; 18 environment-dependent scenarios were skipped. All 138 Pest browser scenarios passed (1,880 assertions), including SDK People/profiles, plus four PDF browser scenarios, 30 editor unit scenarios and 25 preview scenarios. Production assets built successfully; strict Composer validation, Pint and diff whitespace checks passed. The final tests used the downloaded SDK archive, not a symlink. No other locked dependency version changed.

The complete Composer verification was first interrupted by the local 128 MB PHP limit, then exposed legacy session restoration failures. After the compatibility correction, every canonical verification stage was rerun successfully; the final full application run used a process-local 1 GB limit. Production settings were not changed. WorkOS/Accounts automated calls were faked and databases were disposable; production end-to-end acceptance is still a separate operator task.
