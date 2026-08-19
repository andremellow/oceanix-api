# Oceanix Control Center — Design System

This document defines the design system for the Oceanix Training Compliance control center. It
is a direct descendant of the YesWeEat Control Center system: same principles, same layer
separation, same component contracts and the same composition rules. What changes is the theme —
Oceanix reads as offshore and operational, so the accent moves from green to petrol blue while
every structural decision stays identical.

Repository paths and Tailwind examples show how the system is implemented today. A port to
another framework should preserve the semantic contracts, not the class strings.

## 1. Product character

The control center should feel calm, precise and operational. It is a compliance tool for people
who need to know, quickly, who is out of compliance and why — not a generic admin template.

Core principles:

1. **Lead with the decision.** Overdue work, expiring deadlines and the primary action come
   before configuration or raw data.
2. **Human-readable first.** Never render JSON, enum keys, database timestamps or internal IDs
   when a formatted representation is possible.
3. **Evidence over inventory.** What matters is the materialized obligation and its trail, not a
   catalog of available courses.
4. **Quiet hierarchy.** Spacing, surface color, typography and small accents. No heavy borders,
   dense toolbars or competing colors.
5. **Permission is part of presentation.** A hidden element is not authorization. Every screen is
   protected server-side by a Gate or Policy; navigation only reflects it.
6. **Responsive by composition.** Cards and summaries reflow naturally; there is no separate
   visual language for mobile web.

## 2. Portability contract

The system has four layers. Keep them separate:

1. **Principles** — calm hierarchy, readable data, permission-aware presentation, decision-first
   composition.
2. **Semantic tokens** — names such as `surface-canvas`, `text-primary`, `status-negative`.
3. **Component contracts** — anatomy, variants, states, behavior, accessibility, content rules.
4. **Product patterns** — compliance dashboard, employee board, directory, detail page.

When porting, preserve layers 1–4 in that order. Do not start by copying hex values into new
components.

### 2.1 Reference implementation

| Concern | Source |
| --- | --- |
| Global tokens and reusable classes | `resources/css/app.css` |
| Authenticated shell and role navigation | `resources/views/components/layouts/app.blade.php` |
| Sign-in shell | `resources/views/components/layouts/guest.blade.php` |
| Dashboard composition | `resources/views/components/⚡dashboard.blade.php` |
| Employee board | `resources/views/components/training/⚡my-training.blade.php` |
| Operational table | `resources/views/components/compliance/⚡assignments.blade.php` |
| Shared primitives | `resources/views/components/page-hero.blade.php`, `empty-state.blade.php`, `training/assignment-card.blade.php` |
| Compliance projections | `app/Services/Compliance/ComplianceOverview.php`, `EmployeeTrainingBoard.php` |
| Authorization | `app/Enums/Permission.php`, `app/Policies/`, `app/Http/Middleware/EnsureUserHasPermission.php` |

When a pattern appears on two or more screens, move it into `resources/css/app.css` or a Blade
component. Do not solve a recurring visual problem with one-off class combinations per page.

## 3. Foundation

### 3.1 Typography

- Primary family: **Instrument Sans**.
- Body copy: `text-sm`, typically `#5f6a71` or `#707a80`.
- Page title: `text-2xl font-bold tracking-tight`.
- Section title: `text-xl font-bold tracking-tight`.
- Card title: `text-base font-bold`.
- Operational labels: `text-[10px] font-bold uppercase tracking-[.10em–.16em]`.
- Metric values: `text-3xl font-bold tracking-[-.04em]` where space permits.
- Avoid light font weights — interfaces must stay legible on low-contrast displays offshore.

Sentence case for headings. Uppercase is reserved for kickers, compact labels and navigation
section names.

### 3.2 Color palette

Components consume **semantic names**, never palette names such as `blue-600`.

| Semantic token | Oceanix value | Usage |
| --- | --- | --- |
| `surface-canvas` | `#f2f5f7` | Application background |
| `surface-navigation` | `#f9fbfc` | Sidebar surface |
| `surface-card` | `#ffffff` | Primary content card |
| `surface-subtle` | `#f4f8fa` / `#f8fafb` | Nested values and inactive cells |
| `text-primary` | `#1f262b` / `#262d33` | Titles, values, primary actions |
| `text-secondary` | `#5f6a71` / `#707a80` | Descriptions and metadata |
| `text-muted` | `#8a9298` / `#8f979d` | Labels, timestamps, inactive states |
| `border-default` | `#dde3e7` / `#dae0e4` | Card and section boundaries |
| `action-primary` | `#1b2328` | Primary button background |
| `accent-default` | `#1c6b84` | Links, active navigation, icons |
| `accent-subtle` | `#e4f0f5` | Active and highlighted surfaces |
| `focus-ring` | `#3e8ba3` | Keyboard and field focus |
| `status-positive` | `#2f7d4f` | Compliant, passed, valid |
| `status-negative` | `#c64242` | Overdue, revoked, failed |
| `status-warning` | `#d9932f` | Due soon, processing, paused |

These are published as CSS variables (`--ds-*`) at the top of `resources/css/app.css` and mirrored
in the Tailwind `@theme` block for Flux.

Compliance color convention:

- **Overdue is red, due soon is amber, satisfied is green, neutral states are slate.**
- Never rely on color alone: every status pill carries a text label.
- Critical overdue (past `CRITICAL_OVERDUE_DAYS`) escalates to a filled pill, not just a darker
  tint.

### 3.3 Theme

The authenticated shell is intentionally light-only. The layout calls
`window.Flux.applyAppearance('light')` so OS dark mode cannot mix dark Flux text with the light
application canvas. Any future dark theme must be implemented comprehensively before that call is
removed.

### 3.4 Shape, borders and shadow

- Page heroes: `24px` radius (dashboard hero: `28px`).
- Feature, metric and data cards: `22px` radius.
- Nested panels and detail areas: `16–20px` radius.
- Controls: `12px` radius.
- Pills and status labels: fully rounded.
- One-pixel neutral borders plus a soft, low-opacity shadow.
- Hover lift is subtle: no more than `-translate-y-0.5`.
- No pure black borders, no large opaque shadows.

### 3.5 Spacing

- Main page stack: `space-y-7` or `space-y-8`.
- Section/card padding: `p-5`, increasing to `sm:p-6` or `sm:p-7`.
- Card grid gaps: `gap-4`.
- Compact nested values: `gap-2` or `gap-3`.

## 4. Application shell

1. A sticky 256px desktop sidebar.
2. A checkbox-controlled off-canvas sidebar on smaller screens.
3. A 72px sticky context header.
4. A centered canvas with `max-w-[1480px]`.

### 4.1 Navigation by role

Navigation reflects capability, not labels:

- Everyone authenticated: Dashboard, My training.
- Compliance section (Courses, Requirements, Assignments, Certificates): only the entries whose
  permission the user holds.
- Organization section (People, Departments, Job functions): same rule.
- Administration (Users, Access profiles): administrators; Audit log follows its own permission.

The route always carries the matching middleware. Conditional navigation is usability, not
security — `tests/Feature/Access/RouteAuthorizationTest.php` asserts both halves.

### 4.2 Header

Date, a short operational message, system status and account identity. Page-specific actions
belong in the page hero, never in the header.

## 5. Page composition

### 5.1 Page hero

`x-page-hero` (`.admin-hero`) opens data and management pages with:

- `.admin-kicker` for context,
- one clear `h1`,
- a one-line explanation of what the screen enables,
- optional trailing count, status pill or primary action.

No forms, long instructions or multiple equally weighted actions in the hero.

### 5.2 Dashboard

The dark hero is the only large inverse surface. It states the role-specific purpose and exposes
one primary route.

- For an operator: metric cards, then the "needs attention" table, then program shortcuts.
- For an employee: the personal overdue banner, and nothing operational at all.

One component renders both, branching on `compliance-dashboard.view`.

### 5.3 Employee board

Action first: overdue, due soon, in progress, assigned, then completed history. A free catalog, if
one ever exists, stays secondary to mandatory compliance.

### 5.4 Detail pages

Identity and context first, then high-value summaries, then supporting data:

1. Status and deadline metrics.
2. Structure (lessons, versions, targets).
3. Evidence trail.

Never lead with serialized model data.

## 6. Reusable patterns

### 6.1 Feature cards

`.saas-feature-card` for directory and navigation objects, `.saas-feature-icon` for its leading
icon. White surface, neutral border, soft shadow, subtle hover lift.

### 6.2 Metric cards

`.metric-card` with one modifier: `--teal`, `--amber`, `--violet`, `--slate`, `--rose`. Inside,
`.metric-label`, `.metric-value` (`--text` for non-numeric values) and `.metric-detail`. A metric
card presents one fact; it is never a mini-form.

Modifier choice is semantic on the compliance dashboard: teal for population, amber for due soon,
rose for overdue, violet for rates.

### 6.3 Status pills

`.status-pill` plus `--positive`, `--warning`, `--negative`, `--critical`, `--neutral`,
`--accent`. Every enum that reaches the UI exposes a `pillModifier()` so the mapping lives in one
place — see `App\Enums\AssignmentStatus`.

### 6.4 Detail cards

`.detail-card`, `.detail-card-icon`, `.detail-card-title` for grouped record data. Prefer
formatted description lists or compact value grids. Missing values use "Not provided" or `—`,
depending on whether the absence needs explaining.

### 6.5 Forms

Flux controls are normalized globally in `app.css`: white background, explicit dark text, neutral
visible border, petrol focus border and ring, and disabled controls that stay legible without
relying on opacity. Primary admin action uses `.admin-primary-action`.

Every input needs a visible label. Placeholder text is supplementary and never the only label.

Help text goes inline only where the column is wide enough for prose. In a narrow field —
a percentage, a count — inline help wraps and drags the whole row out of alignment, so the
explanation moves behind `x-field-hint`: an icon beside the label, the text in a tooltip and
in the trigger's accessible name, so it is reachable by keyboard and by screen reader.

Compose it with `x-field-label`, never by wrapping the label in a div. Flux styles and spaces
a label only while `ui-label` is a direct child of the field, and the element is `inline-flex`
with a small natural offset, so a wrapper silently lifts the control a few pixels above every
other field in the row.

### 6.6 Tables

White body, muted header, uppercase compact column labels, comfortable cell padding, subtle row
hover. Always wrap wide tables in an overflow container. Numeric columns (days overdue, counts)
align right and turn `status-negative` only when the number means risk.

### 6.7 Empty states

`x-empty-state` provides: a pale icon surface, a specific title naming what is absent, one
sentence about when data will appear, and an action only when the user can resolve the state.
Dashed neutral border. Never an empty white rectangle or a raw `[]`.

### 6.8 Loading and action states

Livewire actions that can take noticeable time expose `wire:loading`, disable the initiating
control and preserve existing content until replacement data is ready.

### 6.9 Component contracts

| Component | Required anatomy | Variants/states |
| --- | --- | --- |
| `AppShell` | Navigation, context header, main canvas, account control | Desktop, off-canvas mobile |
| `NavItem` | Icon, label, destination | Default, hover, active, focus, unauthorized/omitted |
| `PageHero` | Kicker, title, description, optional trailing content | Default, inverse dashboard |
| `FeatureCard` | Icon/identity, title, description, action | Default, hover, disabled |
| `MetricCard` | Label, value, unit, supporting detail | Teal, amber, violet, slate, rose; loading, missing |
| `DetailCard` | Icon, title, structured content | Populated, partial, empty |
| `StatusPill` | Text label, optional leading dot | Positive, warning, negative, critical, neutral, accent |
| `AssignmentCard` | Course, version, progress, deadline, action | Overdue, due soon, neutral, completed |
| `EmptyState` | Icon, title, explanation, optional action | Informational, actionable |
| `Field` | Label, control, help/error text | Default, hover, focus, invalid, disabled |
| `Button` | Label/icon and action | Primary, secondary, ghost, destructive |

Every component specification must answer: what decision does it support, which slots are
required, which variants are semantic rather than decorative, what happens with long, missing,
loading, invalid and unauthorized data, what its keyboard interaction and accessible name are, how
it reflows at narrow widths, and which tokens it may consume.

## 7. Data presentation

### 7.1 Formatting

- Dates: localized labels in the UI; ISO `Y-m-d` internally.
- Deadlines: absolute date plus a relative qualifier ("12 days overdue"), never relative alone.
- Durations: `m:ss` for videos, whole minutes for course estimates.
- Percentages: whole numbers with a visible progress bar where the value drives a decision.
- Timestamps: human-readable localized output; never a raw ISO string on a visible card.

### 7.2 Language

English is the canonical source and fallback for all visible copy — headings, labels,
placeholders, validation messages, status text and accessible names. Portuguese lives only in
`lang/pt_BR/**` and `lang/pt_BR.json`, mapping English source strings to localized values. Never
use Portuguese as a translation key, and never branch on locale with inline translated text. Enum
values are translated through the translation system, never rendered as storage keys.

### 7.3 Raw and sensitive data

Never render JSON blobs, internal IDs as user-facing content, identity-provider payloads, or
answer keys. The public certificate page exposes only validity, holder name, course, issue and
expiry — never employee ID, email, department, score breakdown or history.

## 8. Authorization and privacy

Every new authenticated operational feature participates in the access-profile system from its
first release:

1. Define atomic permission keys in `App\Enums\Permission` as `<resource>.<action>`.
2. Declare prerequisites explicitly — granting a dependent permission persists its prerequisites
   (`Permission::withPrerequisites()`).
3. Define global abilities as Gates and record abilities as Policies. Protect the canonical route
   with `EnsureUserHasPermission` and authorize every state-changing Livewire method or Action
   through the same Gate or Policy.
4. Keep `User::hasPermission()` as the active-profile union resolver behind Gates. Keep the
   administrator bypass in `Gate::before`, never inside domain Actions.
5. Render controls with `@can` / `$user->can()` — presentation only; direct requests are still
   denied server-side.
6. Re-authorize during Livewire hydration and on sensitive actions, so revoking access takes
   effect even on a page that was already open.
7. Add Pest coverage for granted access, missing-permission denial, direct route attempts,
   prerequisite behavior, admin bypass, archived-profile revocation, and cross-user access.

## 9. Accessibility

- Interactive elements are native links, buttons, inputs or summaries.
- Every icon-only button has an `aria-label`.
- Keyboard focus is visibly outlined (`focus-ring`).
- Status is never conveyed by color alone.
- Contrast stays readable on white and pale-blue surfaces.
- No placeholder-only fields.
- Semantic heading order, one `h1` per page.
- Tables have real headers; wide content scrolls inside its own container.

## 10. Responsive behavior

Mobile-first, then enhanced:

- Base: one column, compact padding, off-canvas navigation.
- `sm`: two-column value grids, larger card padding.
- `lg`: multi-column detail cards, persistent sidebar.
- `xl`: four-across metric rows and wider filter grids.

Never hide the primary action, the deadline, the person's identity or the compliance status on
small screens. Supporting metadata may be hidden.

## 11. Testing requirements

Every new role-aware UI behavior needs Pest coverage for:

- Expected content for the authorized role.
- Direct URL denial without the permission.
- A different user attempting the same record URL.
- Permission revoked after the screen was opened.
- Ordering and pagination for aggregate views.
- Distinct source records staying distinct in the rendered projection.

Use factories with intentionally different names, deadlines and statuses. Repeated fake data hides
projection bugs and makes visual validation unreliable.

Before pushing:

```bash
php artisan test && npm run build && vendor/bin/pint --test
```
