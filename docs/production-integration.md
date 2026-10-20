# Account and Compliance production integration

This runbook publishes the existing applications. SDK, boilerplate and shared design-system extraction are deferred until this release passes an end-to-end production check. Commands below are operator instructions; preparing the PRs does not execute them in production.

## 1. Release prerequisites and order

Use separate application databases, separate Laravel APP_KEY values, distinct session cookies and HTTPS domains. Both applications must use APP_ENV=production and APP_DEBUG=false. Keep each application's existing APP_KEY: Account encrypts registered module credentials with it. Back up the databases before migrations; provisioning/history migrations deliberately cannot be rolled back. Preserve stable WorkOS user/organization IDs. Local numeric person and principal IDs can differ between systems.

Publish the **Compliance receiver first**, then Account, during a coordinated maintenance window. Pause queue workers and schedulers during migrations/configuration so old workers do not process new operations. Until both directions are configured, person registration or access confirmation can remain unavailable. Keep existing unrelated Compliance mail, storage, video and application settings.

In each release directory, install dependencies/build using your deployment platform, then:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
```

Ensure the private Flux Composer registry and Compliance's private `andremellow/laravel-tasks` dependency are available to the deployment account. The current Compliance Composer requirement points at `dev-codex/task-media-editor`; production needs access to that locked dependency. Never replace an existing production environment file with a local copy.

## 2. WorkOS and holding administrator

Both applications must resolve the **same production WorkOS users and organizations**. Configure production WORKOS_API_KEY and WORKOS_CLIENT_ID in each deployment's secret store. Do not mix test API keys with production user/organization IDs. Whether the applications share a client ID depends on your WorkOS application configuration; the identity source must agree.

Register the actual callbacks in WorkOS:

- Account: `https://ACCOUNT_HOST/auth/workos/callback` (also set WORKOS_REDIRECT_URI).
- Compliance: `https://COMPLIANCE_HOST/auth/workos/callback` (generated from APP_URL).

Set APP_URL to each application's own HTTPS origin. Compliance's SOCIAL_LOGIN_WORKOS_ENABLED must be true. No WorkOS webhook receiver exists in these repositories; do not configure a guessed webhook URL.

In Account, grant the intended holding administrator using the **production WorkOS user ID**, not an email or local database ID:

```sh
php artisan account:grant-operator user_PRODUCTION_ADMIN --admin
```

Then sign into Account so its local User exists. Import the existing production organizations if needed:

```sh
php artisan companies:import-workos --all --operator=user_PRODUCTION_ADMIN
```

This command writes company records; review the production organizations before running it. Retain Account company UUIDs for exact organization/binding checks; system credentials do not select companies. Creating/importing companies belongs to Account; Compliance's old oceanix:create-company command intentionally refuses creation.

## 3. Two distinct service tokens and one shared signing secret

| Stored in | Variable | Value comes from |
| --- | --- | --- |
| Account | COMPLIANCE_API_URL | Compliance HTTPS origin, no `/api` suffix |
| Account | COMPLIANCE_API_TOKEN | Sanctum token issued **inside Compliance** |
| Account | COMPLIANCE_PRINCIPAL_ID | ID of the principal created **inside Account**, below |
| Account | COMPLIANCE_ACTOR_ASSERTION_SECRET | New shared random signing secret |
| Compliance | ACCOUNT_URL | Account HTTPS origin, no `/api` suffix |
| Compliance | ACCOUNT_TOKEN | Sanctum token issued **inside Account** |
| Compliance | ACCOUNT_ACTOR_ASSERTION_SECRET | The exact same shared signing secret |

Generate the signing secret in your password manager (at least 32 random bytes). Keep tokens and secrets in the deployment secret store, not Git, chat, CLI arguments or build logs. Each token authenticates to the server that issued it; swapping the two tokens will fail.

There is currently no principal-creation Artisan command. Use a trusted production console (`php artisan tinker`) once per application, inspect whether the intended principal already exists, and create or deliberately rotate it. The following are **templates**, not scripts to run repeatedly. Capture the newly issued token privately from the console and save it immediately; Sanctum stores its hash.

Inside **Account**, create the principal receiving requests from Compliance:

```php
$abilities = ['person.register', 'person.adopt', 'person.access.remove', 'person.map-existing', 'person.invite', 'person.invitation.reconcile', 'person.membership.ensure', 'operations.read', 'actors.authorize'];
$principal = App\Models\ServicePrincipal::create([
    'name' => 'Compliance production', 'product' => 'compliance',
    'environment' => 'production', 'active' => true,
    'company_uuids' => [], 'capabilities' => $abilities,
]);
$principal->id; // Account-local ID -> COMPLIANCE_PRINCIPAL_ID in Account.
$principal->createToken('compliance-to-account-production', $abilities)->plainTextToken;
// Token above -> ACCOUNT_TOKEN in Compliance.
```

Inside **Compliance**, create the principal receiving Account calls:

```php
$principal = App\Models\ServicePrincipal::create([
    'name' => 'Account production', 'product' => 'compliance',
    'environment' => 'production', 'active' => true,
    'company_uuids' => [], 'capabilities' => [],
]);
$principal->createToken('account-to-compliance-production', [])->plainTextToken;
// Token above -> COMPLIANCE_API_TOKEN in Account.
```

Account-to-Compliance no longer requires capabilities or token abilities; the valid service identity/lifecycle and applicable company/actor/protocol checks remain. The reverse Compliance-to-Account direction still requires the operation capabilities in **both** the Account principal and its token; operations.read is required for polling. These machine credentials do not replace the initiating person's current permissions.

Use actual APP_ENV values; a local/staging principal is rejected in production. Leave deprecated company_uuids empty; establish exact company/organization bindings independently when adding a company. Do not use a Compliance local company ID where an Account company UUID is required.

## 4. Register the Compliance module in Account

After saving the six connection variables and WorkOS settings, rebuild configuration caches in both applications:

```sh
php artisan config:cache
```

Then, **in Account**:

```sh
php artisan products:register --bootstrap
```

Bootstrap reads Account's configured URL/token/secret/principal ID. It silently skips incomplete entries while exiting successfully, so verify the record rather than relying only on the success message. In the trusted Account console inspect only nonsecret fields:

```php
App\Models\ProductModule::where('key', 'compliance')->first(['key', 'base_url', 'environment', 'protocol_version', 'active', 'inbound_principal_id']);
```

Expected: correct public HTTPS origin, production, protocol_version=2, active=true and the **Account-local** incoming principal ID. Changing environment values alone does not update an existing module until registration is run again. URLs must have no query/fragment/embedded credentials; private/loopback product destinations are rejected in production.

Alternative interactive registration (asks privately for the Compliance-issued token and shared secret):

```sh
php artisan products:register compliance https://COMPLIANCE_HOST --name=Compliance --environment=production --principal=ACCOUNT_LOCAL_PRINCIPAL_ID
```

## 5. Bind existing companies and people

In Account, open each imported company and enable Compliance. Wait for confirmation. The receiver matches an existing Compliance company by **WorkOS organization ID** or creates a new local company; conflicting existing bindings/slugs stop the operation. Check the organization ID before enabling to avoid binding the wrong company.

For existing WorkOS users and invitations, run the independent local import **from Account**, first without writes:

```sh
php artisan workos:import-people ACCOUNT_COMPANY_UUID --actor=user_PRODUCTION_ADMIN
# Review Ready / Confirmed / Needs attention, then apply:
php artisan workos:import-people ACCOUNT_COMPANY_UUID --actor=user_PRODUCTION_ADMIN --apply
```

Omit the company UUID to process all imported companies; keep --actor explicit. Preview/apply does not require a Compliance connection, token, receipt or existing remote binding. Import includes existing pending/expired invitations and writes only Account's local identity/membership baseline and declared, unverified Compliance association. It does not send invitations, deliver mappings or grant remote access; ambiguous identities/conflicts need review. Reciprocal mapping is a separate first-use step for exact existing people after compatible receivers and bindings are ready. Do not assume email or local numeric IDs are sufficient identity proof. Existing confirmed access evidence is preserved; a newly imported invited person is not proof of active training access.

If historical Compliance-local people are not present in WorkOS, the WorkOS import cannot discover them. The separate Compliance adoption preview is:

```sh
php artisan account:adopt-people COMPLIANCE_COMPANY_PUBLIC_ID user_PRODUCTION_ACTOR
# After reviewing, append --apply to persist.
```

It requires a current authorized Compliance actor (people.manage), a company binding and stable WorkOS identity, and does not send invitations or alter access. Do not run both migrations blindly against conflicts.

Permanent invitation synchronization updates known invitation status. It is not the initial import, does not continuously download unrelated WorkOS users, and does not delete people. Cross-system IDs stay mapped explicitly.

## 6. Workers and scheduler

Use QUEUE_CONNECTION=database (or your existing supported persistent driver), with each application's own jobs tables. Run a supervised worker **for each application**, from its own release directory, for example:

```sh
php artisan queue:work --sleep=1 --tries=1 --timeout=60
```

Configure the supervisor to restart the worker automatically and give it the application's correct environment. Set queue retry_after greater than the worker timeout. Account's durable access-transition retry cycle is separate from queue job failure/retry; an exhausted transition can require manual retry even when queue:failed is empty.

Install the standard scheduler once per application (correct absolute path and deployment user):

```cron
* * * * * cd /ABSOLUTE/APPLICATION/PATH && php artisan schedule:run >> /dev/null 2>&1
```

Account runs company-users:retry-due every minute. Compliance also needs its existing training/notification/video schedules. Confirm with php artisan schedule:list. After every code or secret/config deployment:

```sh
php artisan config:cache
php artisan queue:restart
```

queue:restart asks workers to exit gracefully; **a process supervisor must start them again**. Restart other persistent runtimes if your platform uses them. Do not use queue:clear, flush failed jobs or delete durable transition records to resolve pending confirmations.

## 7. Production acceptance check

Bring both applications out of the coordinated maintenance window only after configuration and workers are ready. Use one designated production validation account/company, with an administrator controlling expected access and invitation email recipients.

1. Both `/up` endpoints respond successfully. This proves Laravel health only.
2. Check `php artisan route:list --path=api`, `php artisan schedule:list`, and `php artisan queue:failed` in each release. Do not dump full config or credential-bearing models.
3. Account sign-in and holding superadmin company selection work. Company users remain scoped to assigned companies.
4. Enable Compliance for the designated company and wait for confirmed binding/access.
5. Register a designated person through Account and verify its local Compliance mapping. Register another designated person through Compliance and verify Account confirmation before local creation. Creating a person does not automatically send an invitation; send one explicitly only when intended.
6. Disable the designated person's access in Account. Verify Compliance blocks the next protected request **and WorkOS membership becomes inactive**. Reactivate and verify both destinations confirm. WorkOS deactivate/reactivate use PUT.
7. Inspect the person's Communication card and the company's Communication page. A successful queue job is not sufficient: all destinations must confirm. An unknown destination remains pending; manual Retry incomplete targets reuses the same operation and confirmed destinations. Verify no accidental duplicate person/invitation.
8. Review application errors, failed jobs and communication operations. Do not reset evidence or create a new request to hide an unresolved operation.

If any destination remains unknown, keep the requested and last-confirmed access states distinct. Use the existing retry after fixing configuration; do not mark success manually. These checks require production deployment and operator action and are not claimed complete by the PR's local test results.

## 8. Package extraction after validation

After both applications pass the above flow, define package boundaries for Account transport/identity/mapping, reusable person/access communication UI and shared UI tokens/components (including company theme overrides). Keep product-domain/training behavior outside the shared package. The next-system boilerplate and package implementation are a separate task; this release does not silently extract them.

## Account-owned invitations (protocol 2, October 2026)

Deploy the compatible Account and Compliance versions together for operational invitations. Keep `ACCOUNT_OWNED_INVITATIONS_ENABLED=false` in Compliance until paired verification succeeds. Configure the Account URL, system bearer credential and shared actor assertion secret through the existing secure configuration mechanism. Do not publish credential values. Service principals authenticate a system/product/environment; `company_uuids` is deprecated compatibility data and does not authorize companies. Account-to-Compliance bearer abilities do not replace or add action capability checks. Real company UUID, WorkOS organization and stored company binding must agree, and every new effect requires current human authority.

Account's one-off `workos:import-people` preview/apply can run independently before this release, with no Compliance connection. Imported association is a declaration, never verified product access. Do not repair old unknown import outcomes by resending legacy migration requests.

Before cutover, stop old workers and inventory pending invitation attempts and sync runs. Run `php artisan account:quarantine-legacy-invitations` for a read-only count, and use `--apply` only during an authorized cutover to retain and mark legacy work for review. New job objects carry protocol version 2; old serialized payloads fail closed. A send that already started remains delivery-unconfirmed; do not erase its marker or blindly resend. Preserve all jobs, original operations and receipts as operational evidence.

Verify both inbound system identities, exact company bindings, reciprocal person mapping, current actor callback, database queue, scheduler, and queue `retry_after > 120` before enabling either side. Workers use a 60-second timeout and 120-second claim lease. Enable the Compliance configuration only after the Account registry is explicitly ready. Missing/older receivers must remain unavailable without any direct operational WorkOS fallback. AuthKit login continues using its existing provider client.

First-use invitation sync/send maps an existing person by exact company and stable WorkOS ID, or exact invitation ID for an invitation-only person. Mapping failures require correcting the identity/binding evidence; never merge by email or create a duplicate person to bypass a conflict. Mapping and status observations do not change roles, product access or training evidence.

A successful Account read and failed Compliance projection are separate results. Retry the failed projection with its original UUID and payload; retain the last verified invitation state. Ambiguous HTTP writes retrieve their stored receipt before replay. Account automatically bounds target attempts; after exhaustion use the existing authorized person/Communication retry control. Repair forward. Do not downgrade to an older worker that performs operational WorkOS calls or remove the new evidence fields.
