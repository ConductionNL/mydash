# Design — Multi-scope dashboards

## Context

MyDash currently supports two dashboard modes: per-user private dashboards and organisation-wide dashboards. In both cases, a user sees a single active dashboard at a time. Organisations using MyDash need two features:

1. **Shared start pages** — A library admin can design dashboard templates (e.g., "Board Member Overview", "Staff Planning Board") and publish them. New organisation members see these templates and can adopt one as their starting dashboard, customising it from there.

2. **Multi-persona layouts** — Different roles (chair, secretary, board member, staff) should see different dashboard layouts optimised for their responsibilities. Today, a single dashboard serves all roles, forcing compromises.

Both features require the dashboard system to support **scoped visibility** (which dashboards does a particular user see?) and **role-based selection** (which dashboard should be active for this user's current roles?).

## Goals / Non-Goals

**Goals:**

- Make organisation-wide dashboard templates discoverable and adoptable by organisation members
- Enable role-specific dashboard layouts so chairs, secretaries, and members see optimised views
- Introduce scope metadata (`personal`, `shared`, `organisation`) as a first-class concept
- Implement a declarative scoping model that admins can configure without code changes
- Preserve backward compatibility — existing dashboards continue to work as personal/organisation mode

**Non-Goals:**

- Cross-organisation dashboard sharing (out of scope; each organisation has its own template library)
- Dynamic re-scoping of existing dashboards via UI (admin panel only, not user-facing)
- Hierarchical personas or persona composition (flat list of role strings; composition via priority ordering)
- Persona-specific widget visibility within a single dashboard (dashboard-level scoping only, not widget-level)
- Dashboard versioning or rollback (templates are snapshots; updates create new versions, old versions stay available)

## Decisions

### D1: Scope as an enum property on dashboard entity, not a separate role table

**Decision**: Dashboard entity gains a `scope` field (enum: personal | shared | organisation). Persona targeting is stored as `targetPersonas: string[]` (array of role slugs) on the same entity.

**Alternatives considered:**

- Create a separate `DashboardScope` linking table. Rejected — adds DB churn for what is fundamentally a dashboard property, and scope changes are rare (set-once at creation time).
- Store scope rules in `IAppConfig` (app settings). Rejected — dashboards are objects, not app settings; mixing them violates ADR-001 (all domain data in OpenRegister).

**Rationale**: Scope is a dashboard property, not a configuration concern. Storing it on the dashboard entity makes it queryable (find all shared dashboards) and auditable (scope changes are audit-trailed).

### D2: Persona targeting via explicit role-string array, not inheritance/hierarchy

**Decision**: `targetPersonas: ['chair', 'secretary', 'member']` is an explicit array on the dashboard. No inheritance or persona parent-child relationships.

**Alternatives considered:**

- Persona hierarchy (chair inherits from member). Rejected — adds complexity without clear benefit; role hierarchies vary per organisation and are already handled by OpenRegister's RBAC.
- Implicit personas (dashboard name matching role name auto-maps). Rejected — fragile and non-discoverable; explicit is clearer.
- Target-audience annotations (public, internal, admin). Rejected — too coarse; doesn't support multi-role scenarios.

**Rationale**: Explicit role arrays are discoverable, auditable, and flexible. Admins define "this dashboard is for chairs + secretaries" in one place and the system respects it.

### D3: Scope resolver returns **all** visible dashboards, layout selector picks the active one

**Decision**: Two distinct services:
- `DashboardScopeResolver::findDashboardsForUser(user)` → returns `Dashboard[]` (all dashboards the user can see)
- `PersonaLayoutSelector::selectActiveLayout(user, dashboards)` → returns `Dashboard` (the one to activate)

They are not combined into a single "get active dashboard" service.

**Alternatives considered:**

- One service returns just the active dashboard. Rejected — the UI needs to show a switcher dropdown listing all visible options.
- Resolver returns dashboards ranked by priority. Rejected — selection logic should be testable and replaceable independently.

**Rationale**: Separation of concerns. The resolver answers "which dashboards are in scope?"; the selector answers "which one should be active now?" Allows independent testing and future swappable selection strategies.

### D4: Layout selector uses highest-priority **role match**, not highest-priority dashboard

**Decision**: When a user has multiple matching dashboards (e.g., one for 'chair', one for 'member'), priority is determined by the user's **most-privileged matching role**, not by dashboard creation order.

Example:
- User has roles: ['member', 'chair']
- Available dashboards: DashA (targetPersonas: ['member']), DashB (targetPersonas: ['chair'])
- Selection: DashB (chair is higher-priority than member)

**Alternatives considered:**

- First-created-wins. Rejected — unintuitive when roles change or new dashboards are added.
- Last-created-wins. Rejected — same issue.
- Alphabetical order. Rejected — stable but arbitrary and user-hostile.

**Rationale**: Role priority is an external concern (defined in org settings), not a dashboard property. The selector defers to the role priority list, making the decision consistent and auditable.

### D5: Shared start pages are copied, not linked

**Decision**: When a user adopts a shared start page, MyDash creates a **copy** of the dashboard (new object with same widgets but independent state) rather than creating a reference to the template.

**Alternatives considered:**

- Create a link/reference to the template. Rejected — template updates would affect all adopters' dashboards, breaking the "template is a starting point, not a live binding" expectation.
- Create a versioned link. Rejected — adds complexity (template versioning, migration on version bump) without clear benefit.

**Rationale**: Start pages are **templates**, not live instances. Users adopt them as a starting point and customise from there. Independence is expected.

### D6: Adopting a shared page is a one-time action, not reversible via UI

**Decision**: When a user clicks "Adopt" on a shared dashboard, MyDash copies it and sets it as the user's active dashboard. There is no "revert to template" button.

**Alternatives considered:**

- Add a "revert to template" option. Rejected — if the user has customised their dashboard, reverting silently discards their changes. A confirmation dialog feels punitive for an action that should feel low-risk.
- Allow reverting to template and merging customisations. Rejected — merge logic is complex and error-prone.

**Rationale**: Copy-once-and-own is simple and safe. If a user wants a fresh start, they can delete their dashboard and adopt the template again (creating a new copy).

## Risks / Trade-offs

- **Risk:** Role priority order is a global setting that can change, breaking users' expectations about which dashboard they see. → **Mitigation:** Document the priority order prominently in settings; announce changes to users; provide a way to manually pin a preferred dashboard per user (future work).
- **Risk:** Persona matching is string-based (role slug equality), so a typo in targetPersonas breaks visibility. → **Mitigation:** Admin UI (task) validates persona strings against known roles; form uses a select dropdown, not free-text input.
- **Trade-off:** Separate scope resolver + layout selector adds two service calls per page load instead of one combined query. → **Mitigation:** Both are in-app, no network round-trip; caching can be added later if profiling shows contention.
- **Trade-off:** Shared start page adoption creates a copy, duplicating widget configurations. → **Mitigation:** This is intentional (independence), and the UI makes it clear "you're creating your own copy".

## Reuse Analysis

**Existing OpenRegister services leveraged:**

- `ObjectService` — dashboard objects are stored in OpenRegister; scope resolver queries them via `findObjects(register, schema, {scope: 'shared', ...})`
- `AuthorizationService` — per-object permissions (a shared dashboard still respects schema-level read permissions even if scope says it should be visible)

**No custom CRUD or query logic** — scope resolution is a thin filter layer on top of `ObjectService::findObjects`.

## Data Model

**Dashboard schema changes** (OpenRegister object):

```
scope: enum {personal, shared, organisation}
targetPersonas: string[] (empty = all roles)
```

No new database tables; both fields are properties on the existing dashboard entity.

## Migration Plan

1. Schema migration: add `scope` + `targetPersonas` columns/properties to dashboard entity (all existing dashboards default to `scope: personal` + `targetPersonas: []`)
2. New services: `DashboardScopeResolver` + `PersonaLayoutSelector` (no callers yet)
3. Refactor dashboard loading in `src/pages/Dashboard.vue` to call the new resolver
4. Add dashboard switcher UI to `DashboardHeader.vue`
5. Admin panel: add scope + persona configuration to the dashboard form
6. Seed data: create 3-5 example shared dashboards (Board Member Overview, Staff Planning Board, Finance Summary)
7. Tests: unit tests for scope resolver + layout selector; Playwright tests for switcher UI and adoption flow

## Open Questions

- Should users be able to manually pin a preferred dashboard (override automatic selection)? Current decision: no (keep it simple), revisit if priority conflicts become a support burden.
- Should template updates propagate to adopted copies? Current decision: no (they are independent). Documented in the admin guide.
- What happens when a user's roles change and their active dashboard is no longer in scope? Current decision: fall back to the first-available dashboard in scope, with a toast notification ("Your dashboard changed because your roles changed").
