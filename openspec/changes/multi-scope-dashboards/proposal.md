# Multi-scope dashboards

## Why

MyDash currently offers only per-user private dashboards or a single organisation-wide dashboard. This limits how organisations can serve different roles effectively. A chair needs different visibility from a board member; a new member should be able to start with a pre-configured "Board Member Overview" template rather than building from scratch. This change introduces two capabilities: **shared start pages** (pre-configured templates that members can adopt as their starting dashboard) and **multi-persona layouts** (role-specific dashboards optimised for each persona in the organisation).

## What Changes

- Dashboard entity gains `scope` property: enum {personal, shared, organisation} and `targetPersonas` array (role slugs)
- New service `DashboardScopeResolver` — queries visible dashboards for a user based on scope + persona rules
- New service `PersonaLayoutSelector` — selects active dashboard when a user has multiple persona-specific options
- `src/pages/Dashboard.vue` — calls scope resolver instead of loading single per-user dashboard
- `src/components/DashboardHeader.vue` — renders dashboard switcher dropdown when multiple scoped dashboards available
- Admin settings panel — scope + persona configuration UI for dashboards
- Seed data — 3-5 example shared dashboards (Board Member Overview, Chair Dashboard, Organisation Summary)

## Capabilities

**New Capabilities:**

- `shared-start-pages` — organisations can publish pre-configured dashboards; members adopt them with one click
- `multi-persona-layouts` — assign role-specific dashboard layouts; system picks the right variant per user

## Notes

- Scope resolution is independent of Nextcloud's permission model — it is dashboard-specific and managed entirely in MyDash
- When a user has multiple matching dashboards (e.g., both a "member" layout and an "admin" layout), the system picks the highest-priority role match
- Shared start pages are copied (not linked) so each adopter can customise their copy without affecting the template
