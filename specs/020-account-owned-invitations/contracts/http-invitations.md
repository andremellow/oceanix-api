# HTTP contract proposal

Architecture§7.2–7.5 is the implementation-ready wire authority; this index is not a second source of rules. Existing envelopev2 and paths are extended additively.

| Direction | Kind | Endpoint | Purpose |
| --- | --- | --- | --- |
| Compliance→Account | person.map-existing | PUT /api/product-access/v1/operations/{uuid} | Exact mapping of already-existing people |
| Account→Compliance | person.map-existing | PUT /api/control-plane/v2/operations/{uuid} | Reciprocal mapping without provisioning/access |
| Both UIs→Account | person.invitation.reconcile | Existing operation entrypoint | Provider read only; no send |
| Account→Compliance | person.invitation.observed | PUT /api/control-plane/v2/operations/{uuid} | Existing person's invitation projection |
| Compliance→Account | person.invite | Existing operation entrypoint | Explicit send/recovery intent only |
| Compliance→Account | person.membership.ensure | Existing operation entrypoint | Existing platform authority, separate from sync |
| Compliance→Account | live Account actor authorization | POST /api/product-access/v1/actor-authorizations | Verify holding provision or source mapping authority |

PUT routeUUID=operationUUID=Idempotency-Key; immutable businesspayload/hash; renewable signed actorproof not business intent. Authenticated service identity never replaces exact company binding or live human authority. Old recipients reject unknown kinds rather than accepting partial payloads. Receipt identity must match request, not merely return200.

Snapshot fields and field allowlist, validstates/none, sourceproof operation/hash binding, sequence rules, lostresponse replay, separate targets and error translation are specified in architecture§7.2–7.5. Mapping and observation have no enabled payload or access side effect.

Retries: initial attempt plus six existing offsets60/300/900/3600/14400/43200seconds; seventh failedattempt exhausts. No nested HTTP retry. Durable due recovery eachminute; claim120seconds/job60seconds/queue retry_after>120seconds. Readonly work never invokes send. Configuration/auth/oldrelease/conflict failures require attention without automatic hammering.
