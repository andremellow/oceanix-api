# Company ownership contract

Account owns human company creation/import and WorkOS organization provisioning. Compliance company list/detail are inspection/operational screens, with no create/provision/sync organization handlers. Legacy CLI `oceanix:create-company` returns nonzero and instructs use of Account without side effects.

Existing `PUT /api/control-plane/v1/companies/{account_company_uuid}` is unchanged: scoped active service principal, Idempotency-Key, validated organization identity/name/slug/actor/correlation; 201 created, 200 existing, replayed saved response, 409 conflict, unauthorized/validation/unavailable outcomes unchanged. Account only confirms enable after the existing validated receipt.

Link existing tenants by WorkOS identity only. Product disable/re-enable and user provisioning are outside this change.
