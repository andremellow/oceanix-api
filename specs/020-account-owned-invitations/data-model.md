# Data model proposal

Authoritative definitions: architecture§7.1–7.3; exact migration/model boundaries: architecture-paths.json. No schema was changed.

| Owner | Entity/change | Identity and lifecycle | Forbidden side effects |
| --- | --- | --- | --- |
| Account | CompanyProduct/UserProductEntitlement declared association provenance | Source=legacy-workos-import; nullable declarationtime/provenance; existing denial wins | No remote confirmation, productlocalID or enabledflag invented |
| Account | WorkosPeopleImportItem local baseline outcome and legacy disposition | Independent local outcome/version; old input/hash/receipt immutable; old uncertainty quarantined | No remote replay/rollback |
| Account | CompanyMember/CompanyInvitationObservation | Independent monotonic observationsequence; generation/currentinvitation check; last successful observation retained | No accessversion reuse or status-only grant |
| Account | AccessTransition/Target delivery metadata | Unique immutable targetUUID/payload/hash; read/mapping/delivery attempts separate | No reopening confirmed effect to attach new payload |
| Both | ProductPersonLink/PersonAccountLink | Exact company+provideridentity, distinct localIDs, reciprocal acknowledgement | No duplicate person, emailjoin or conflicting link overwrite |
| Compliance | User invitation observation projection | Monotonic(generation,sequence); acceptedhistory=max timestamp; strict field allowlist | No operational status, access, role or training changes |
| Both | ServicePrincipal | System/product identity; active/expiry/environment remain; company_uuids deprecated/no authorization meaning | No actor/tenant bypass |
| Both | Durable operation receipts | Same UUID/hash returns immutable receipt; changedhash conflicts | No duplicate effect after response loss |

Additive migration only. Diagnose existing duplicates before uniqueness constraints; do not merge/delete. New protocol cutover and old job quarantine precede worker activation. No shared database or cross-product Eloquent imports. Rollback after new evidence exists is forward repair, not schema deletion or directWorkOS fallback. See architecture§7.6/7.7.
