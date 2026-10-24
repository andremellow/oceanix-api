# Extraction sequence and validation

Version: 5. Updated: 2026-10-21.

Required core extraction has been implemented incrementally and checked after each module. The actual implementation/validation report is extraction-status.md.

Completed sequence: protocol transport/signing/receipt validation; shared models and company context through compatible host entry points; access/audit services; middleware; full authentication and company switching; people directory extensions; inbound handlers and HTTP contracts; confirmed registration/adoption/access/profile assignment; invitation jobs/recovery; profile writes, permission resolution and catalog Gates; package runtime defaults, independent installation schema, routes, commands and translations.

The existing User model uses a contract and composition, preserving training relationships. Each host model migration was validated separately before proceeding. Broad rewrite attempts rejected by automatic approval review were replaced with narrower validated increments; no rejected broad command was executed.

Validation includes current feature tests; explicit new permission/profile/session boundaries; a default consumer with no App imports, another product and in-memory schema; existing browser access/Account workflows and a new browser profile workflow. Tests fake external HTTP. The final report distinguishes passed, skipped and unexecuted checks.

No production schema changes or deployment. Existing compatibility wrappers, IDs, schemas, protocol versions and queue payload identities are retained. Boilerplate and visual design remain excluded.

Owner clarification: move functional screens and route registration into the same SDK, requiring only consumer configuration/menu links. Implemented shared Livewire screens, configurable view slots, package-owned UI/auth routes and an isolated UI consumer. No boilerplate or existing-layout redesign.
