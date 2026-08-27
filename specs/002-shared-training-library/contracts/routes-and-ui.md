# Route and UI Contract

## Platform control plane

All routes require `EnsureUserIsPlatformAdmin`; every Livewire mutation reauthorizes through `PlatformAccess` before calling an Action.

- `GET /platform/shared-courses` (`platform.shared-courses.index`) — searchable directory and create action.
- `GET /platform/shared-courses/{course}` (`platform.shared-courses.show`) — ownership, lifecycle, associations and versions.
- `GET /platform/shared-courses/{course}/editor` (`platform.shared-courses.editor`) — draft editor and publication impact.
- `GET /platform/shared-modules` (`platform.shared-modules.index`) — searchable directory and create action.
- `GET /platform/shared-modules/{module}` (`platform.shared-modules.show`) — details, dependents, versions and propagation status.
- `GET /platform/shared-modules/{module}/editor` (`platform.shared-modules.editor`) — version editor and publication impact.

Promotion starts from a platform-visible company-course impact view. Confirmation lists the source company, all modules whose ownership changes and every other affected course.

Publication confirmation displays not-started and in-progress assignment counts. `Restart in-progress assignments` is unchecked by default. Module publication returns a durable propagation status with retryable failed items.

## Tenant control center

Routes remain inside `/c/{company:slug}`, tenant identification and control-center middleware.

- `GET /c/{company}/shared-courses` (`shared-courses.index`) — requires `shared-courses.view`.
- `GET /c/{company}/shared-courses/{course}` (`shared-courses.show`) — requires `shared-courses.view`; read-only catalog detail.
- Add/remove association are Livewire mutations and reauthorize `shared-courses.add`/`shared-courses.remove` inside their Actions.
- Shared-module browsing is embedded in the course editor; browsing requires `shared-modules.view`, composition changes require `shared-modules.use` and CoursePolicy update authorization.

No tenant editing, publication, archive or promotion route accepts platform-owned content.

## Navigation and presentation

- Platform navigation adds `Shared courses` and `Shared modules`, including a compact small-screen menu.
- Tenant navigation retains one `Courses` destination. The directory distinguishes `Company Courses` from `Browse Shared Courses`.
- Every shared record shows textual `Shared` and `Managed by platform`; meaning never depends on color alone.
- `Add to Company` appears only with its exact permission. Removal failures identify blockers without exposing another tenant.
- Module picker separates `Company Modules` and `Shared Modules`, with search and empty/loading/error states.
- Long operations disable duplicate submission, announce progress accessibly and expose actionable retry failures.
- Components use design-system page heroes, detail cards, status pills, empty states and labeled Flux controls.
- Source strings are English; PT-BR strings live only in locale resources.

## Tenant permission contract

| Permission | Prerequisites | Grants |
|---|---|---|
| `shared-courses.view` | none | Browse shared course catalog/details |
| `shared-courses.add` | `shared-courses.view`, `courses.view` | Associate/reactivate a shared course |
| `shared-courses.remove` | `shared-courses.view`, `courses.view` | Remove an unblocked association |
| `shared-modules.view` | none | Browse eligible shared modules |
| `shared-modules.use` | `shared-modules.view`, `courses.view`, `courses.update` | Compose shared modules into an owned draft |

Tenant administrators retain the Gate bypass for tenant actions, but CoursePolicy/ModulePolicy ownership checks still prohibit mutation of platform-owned content.

