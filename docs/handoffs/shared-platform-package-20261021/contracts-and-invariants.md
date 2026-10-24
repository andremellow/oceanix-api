# Contracts and preservation rules

## Ownership and identity

Keep four concepts separate: identity, company membership, per-product access and local permissions. Central Account holding superadmin is not the product's local administrator. An accepted invitation is evidence, not an unconditional enable command. Mapping an existing person must preserve access, roles and training history.

Local IDs can differ between applications. Maintain explicit Account-company UUID, shared-person/member IDs and WorkOS organization/user identity mappings. Match within the correct company and environment. Conflicting identity evidence must stop that subject with an actionable conflict; an old invitation must not create a second person or silently relink by email. Invitation reference/status belongs to the existing identity when safely resolved.

## HTTP operation contract

Preserve existing endpoint/version and envelope compatibility until a separate coordinated change is approved. Outbound product operations currently use `/api/product-access/v1/operations/{operation_id}`, receipt lookup and local-person acknowledgment; actor authorization is a separate endpoint. Inbound receivers expose control-plane v1/v2 and migration routes in `routes/api.php`. The SDK should not normalize version numbers merely because the route and body versions differ.

An operation binds protocol version, operation ID, kind, product, Account company UUID, WorkOS organization ID, subject, resource sequence and payload. Keep idempotency keys stable on retries. Validate receipt identity and business outcome before committing confirmed local effects. Distinguish confirmed, pending, unknown, rejected, conflict and needs-attention; transport success alone does not imply business confirmation.

Confirmed-first registration must not create a local person before Account's validated confirmation. Preserve durable request identity, mapping/finalization recovery and existing local defaults. Resolve uncertainty by checking/retrying the same operation, not by creating a new subject or resending a fresh invitation.

## Receiver and ordering

Authenticate active, unexpired, environment/product-bound system credentials. Preserve actor proof and host record-level authorization where required. Validate mapping before modifying a person. Apply request/receipt and access changes atomically under existing locks; preserve sequence checks and idempotent replay/conflict behavior. HTTP calls must not be moved inside database locks merely to simplify extraction.

Invitation observations are ordered by generation and observation sequence. Same version with different content is a conflict; older observations cannot overwrite newer evidence. Preserve provider states and safe unknown/unverified handling. Keep accepted history without inferring current access. Do not turn map-existing or invitation-observed into an enable operation.

## Authorization and tenancy

Preserve atomic permission keys, prerequisites, archived-profile exclusion, protected profiles, explicit Policies, route middleware and revocation checks. Product catalogs remain independently defined. Admin bypass is host policy with exceptions, not a universal package shortcut. No selected tenant means no tenant-owned query results; background jobs must explicitly restore/clear tenant context. Preserve direct-route and Livewire action/hydration enforcement, not only navigation visibility.

## Reliability and operational visibility

Preserve durable pending/error/uncertain states, job uniqueness and dispatch reservation, queue-unavailable handling, explicit retry and per-person progress. Never discard a failed operation or label it confirmed because it was enqueued. Do not promise automatic retries not present in current jobs. A future architecture must state retry limits, recovery entry points, worker/cache requirements and how an operator sees terminal failures.

Never erase operational evidence, training history or local restrictions during extraction. Existing active users remain active when simply linked. Account owns invitation lifecycle; WorkOS login remains separate. Keep audit and diagnostics useful without logging tokens, signed assertions, secrets or unnecessary identity payloads.

## Configuration compatibility

No `.env` changes in this documentation task. Future installation documentation must enumerate settings, defaults, queue requirements, callbacks, routes, cache/build steps and rollback. Do not assume a credential is configured or a feature flag enabled. Do not embed product names, local IDs, production URLs or credentials in reusable defaults.
