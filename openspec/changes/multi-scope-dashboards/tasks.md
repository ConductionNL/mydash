# Tasks — multi-scope-dashboards

## Tasks

- [ ] Task 1: Add `scope` property (enum: personal, shared, organisation) and `targetPersonas` array property to Dashboard entity; update `jsonSerialize()` to expose both fields
- [ ] Task 2: Create `DashboardScopeResolver` service with `findDashboardsForUser(IUser): Dashboard[]` method; queries all dashboards filtering by scope + targetPersonas rules (account for empty targetPersonas = all roles)
- [ ] Task 3: Create `PersonaLayoutSelector` service with `selectActiveLayout(IUser, Dashboard[]): Dashboard` method; uses user's role priority list to pick highest-priority matching dashboard, falls back to first available
- [ ] Task 4: Extend `DashboardService` to call scope resolver; add method `getAvailableDashboards(IUser): Dashboard[]` (returns all visible) and `getActiveDashboard(IUser): Dashboard` (uses layout selector)
- [ ] Task 5: Update `src/pages/Dashboard.vue` to call `getAvailableDashboards` instead of loading single per-user dashboard; show switcher dropdown when multiple dashboards available
- [ ] Task 6: Implement dashboard switcher in `src/components/DashboardHeader.vue` — dropdown showing available dashboards with active one highlighted; remember selection via localStorage per session
- [ ] Task 7: Add "Adopt" button to shared dashboard view; implement adoption flow: copy the dashboard, set scope to personal, activate for user
- [ ] Task 8: Update admin settings panel — add scope + targetPersonas configuration fields to dashboard form; use select dropdown for personas (not free-text)
- [ ] Task 9: Create seed data — 3-5 example shared dashboards (Board Member Overview, Chair Dashboard, Organisation Summary) with appropriate scope + targetPersonas values
- [ ] Task 10: PHPUnit — scope resolver returns correct visible dashboards per user/role combination; layout selector picks highest-priority match; adoption creates independent copy
- [ ] Task 11: Playwright — member sees shared start pages in a "Templates" section; clicking Adopt copies template and activates it; role-specific dashboards appear in switcher based on user's roles; multiple dashboards trigger switcher UI, single dashboard hides it
- [ ] Task 12: Quality gates — `composer check:strict`, ESLint+Stylelint, `nl`+`en` i18n for UI strings, SPDX-in-docblock on new PHP, all hydra-gates green
- [ ] Task 13: Deduplication check — verify `ObjectService` is used for all dashboard queries (no custom CRUD); no parallel link tables or duplicate RBAC logic

## Verification

`openspec validate` exits clean. Scope resolver returns correct dashboards per user; switcher shows when multiple available; adoption creates independent copy; role-based layout selection works per design decisions D3-D4.

## Tests (company-wide ADR-009)

PHPUnit per Task 10; Playwright per Task 11. No new API endpoints (uses existing CRUD).

## Documentation (company-wide ADR-010)

User guide covering template adoption and dashboard switcher; admin guide covering scope configuration and persona targeting.

## i18n (company-wide ADR-007)

`nl` + `en` for UI strings: "Available dashboards", "Adopt this template", "Your dashboard changed because your roles changed".
