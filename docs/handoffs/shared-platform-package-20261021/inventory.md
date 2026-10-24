# Current shared inventory

Version: 5. Updated: 2026-10-21. Paths are relative to Compliance unless stated otherwise. The source manifest lists actual files.

| Surface | Shared owner | Compliance adapter/boundary |
|---|---|---|
| WorkOS login and session | `../support/sdk/src/Auth`, Actions/Auth and controller | ComplianceAuthenticationContext; configured guest layout/logo and optional local/platform access extensions |
| Account transport/signing/hash/receipts | SDK Account and Services/Account | Existing services config; product/column/protocol compatibility configuration |
| Registration/adoption/access | SDK Actions/People | Product person fields and importer remain local |
| Invitations and queues | SDK Actions/People, Jobs, attempt/run models | Old App job names and queue-lock identity retained |
| Inbound control plane | SDK Actions/ControlPlane and HTTP contracts/controllers | Existing endpoint names and public-ID field retained |
| Identity/evidence models | SDK Models, configurable through Models registry | App model entry points preserve IDs/morph names/factories; existing schema unchanged |
| Tenant context/isolation | SDK Tenancy and BelongsToCompany | App context delegates to same scoped instance |
| People directory | SDK PeopleDirectory | CompliancePeopleDirectoryExtension supplies employee/organization/training projections |
| Profiles and permissions | SDK Access, shared Policies/middleware | Local permission enum/domain Policies/admin exceptions; profile screen markup unchanged |
| Audit/runtime/localization | SDK audit model/logger, Console, migration, translations | Existing evidence and migration history retained |

The local model named Account represents identity; it is distinct from the central Account service. Role remains the access-profile model. No whole-model User move: the host's person model implements Contracts\Person and delegates permission queries to the package.
| Shared UI | `src/Livewire`, `resources/views`, `routes/ui.php` | Only domain People/Person subclasses and `resources/views/platform-extensions` in Compliance |

Optional global platform administration and its direct legacy WorkOS administrator invitation remain local. Courses, training, videos, certificates, assignments, operational compliance events, spreadsheet parsing/import, organization structures/management scopes and Tasks remain local. Shared functional screens and their routes are in the SDK; design-system assets and the existing application shells remain local.
