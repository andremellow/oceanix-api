# Implemented package boundaries

Version: 5. Updated: 2026-10-21.

One Composer package with internal modules: `oceanix/platform`, in `~/Code/Oceanix/support/sdk`. The Account SDK is an internal module, not a separately versioned package. This implements the required feature inventory in scope.md; the extraction report lists evidence.

## Ownership

The SDK owns AuthKit/OAuth/session orchestration; Account signing, HTTP, receipts and operation lifecycle; inbound API validation/handlers/controllers; company context/isolation and company/person product access; registration/adoption/invitation queues and recovery; profile writes, effective permissions, prerequisite resolution, Gate registration and shared-resource Policies; shared identity/operation/audit persistence defaults, translations and runtime commands.

The consumer owns its model adapters and domain relationships; feature permission definitions and domain Policies/admin exceptions; initial product-specific profiles; additional people fields/query projections; current migration/route history when adopting an existing installation; optional domain presentation extensions, existing theme assets and runtime credentials/infrastructure. Shared functional screens and their routes are SDK-owned.

The existing User is adapted through Contracts\Person and composition rather than replaced wholesale. Shared base models and consumer model mappings support new applications without importing the Compliance App namespace. CompanyInitializer, CompanyContextHook and PeopleDirectoryExtension preserve product-specific behavior explicitly.

Shared functional views and a minimal fallback shell are included; no design-system project, visual redesign or starter project was created. Optional global platform administration and impersonation are not mandatory-core extraction targets; their local behavior is retained. External distribution/repository/release/deployment decisions remain separate from this completed local extraction.
