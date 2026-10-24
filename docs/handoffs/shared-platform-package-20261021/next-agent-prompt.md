# Continuation handoff

The required shared core is implemented in `/Users/andrepiresdemello/Code/Oceanix/support/sdk` as the single Composer package `oceanix/platform`. Compliance at `/Users/andrepiresdemello/Code/Oceanix/Compliance` consumes it via `../support/sdk`. Read the SDK README and this bundle's scope/extraction-status before making changes; do not restart extraction from the historical inventory.

Compliance retains adapters and thin App compatibility classes for model morph identity, old queued payloads, routes, tests and product fields. The User model intentionally implements Contracts\Person and composes EffectivePermissions; it is not replaced by the package User. Training catalogs/domain Policies, importer/organization extensions and domain presentation extensions remain local. Shared functional screens and their routes are in the SDK. Optional global platform administration/impersonation are outside mandatory core.

No boilerplate or layout/design-system work. Do not change external Account, publish, deploy or run live migrations based on this handoff. No live external WorkOS/Account validation is claimed by fake-HTTP tests. Preserve unrelated dirty files and never include credentials in reports. Previous conversation-provided AGENTS instructions were revoked and are not revived by this historical bundle.

Any future release packaging, external repository setup, optional platform module or shared UI integration is separate work. Use the final validation report to retain known skip/coverage limits.
