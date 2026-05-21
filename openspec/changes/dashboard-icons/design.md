# Design — dashboard-icons

## Context

MyDash dashboards (and the dashboard-list items in the switcher sidebar and admin UI) need visual indicators. The icon system today is fragmented: various consumers import icons directly from `vue-material-design-icons` without a central registry, making the icon set non-deterministic and the render path duplicated across components. There is also no contract for future custom icons (the parallel `custom-icon-upload-pattern` change depends on a stable discriminator).

This change introduces a single, curated registry of built-in dashboard icons and a pair of pure functions to resolve, discriminate, and render them. The backend stores `dashboards.icon` as an opaque string — the registry lookup and URL/name discrimination happen entirely in the frontend.

## Goals / Non-Goals

**Goals:**

- Maintain a curated registry of at least 15 built-in icons from `vue-material-design-icons` to give a consistent, branded UX.
- Provide a single source-of-truth for icon resolution so all consumers (sidebar, admin list, tile editor, widget picker) stay in sync.
- Export pure functions (`getIconComponent()`, `isCustomIconUrl()`) that future consumers (like `custom-icon-upload-pattern`) can depend on without circular imports.
- Use tree-shakeable individual imports so only referenced icons land in the production bundle.
- Establish the field-format convention for `icon` columns (NULL, registry name, or URL) so the backend and frontend agree on semantics.

**Non-Goals:**

- A dynamic icon search or infinite library. The registry is deliberately small for a curated UX; future work can add search if needed.
- Server-side icon logic. The backend treats `icon` as opaque; discrimination and rendering are frontend-only.
- Breaking existing `icon` consumers. This change extends the existing `oc_mydash_dashboards.icon` column with no schema migration.
- Admin configuration of the icon set. The registry is hard-coded in the frontend bundle; it grows only via new releases.

## Decisions

### D1: Explicit 15-icon registry in `src/constants/dashboardIcons.js`

**Decision**: Define exactly 15 icons (`ViewDashboard`, `Home`, `ChartBar`, `Cog`, `AccountGroup`, `Calendar`, `FileDocument`, `Bell`, `Star`, `Heart`, `BookOpenVariant`, `Lightbulb`, `RocketLaunch`, `Earth`, `Briefcase`) via separate `import` statements with no barrel imports.

**Alternatives considered:**

- Wildcard import `import * as icons from 'vue-material-design-icons'` with a registry key listing. Rejected because every icon in the library lands in the bundle, bloating by ~200 KB.
- Dynamic registry built from a JSON file at runtime. Rejected because it moves the single source-of-truth away from the code, adds a fetch/parse step, and breaks tree-shaking.
- Lazy-load each icon on demand. Rejected because the whole set is needed at init time for the picker `<select>` rendering; lazy-loading adds complexity without benefit.

**Rationale**: Explicit imports make the palette visible in the code, enable tree-shaking, and allow the build system to compress them into a single ~5 KB chunk. The small count is intentional — it keeps the picker fast and the UX curated.

### D2: `DEFAULT_ICON = 'ViewDashboard'` as a named constant

**Decision**: Export `DEFAULT_ICON` as the string `'ViewDashboard'` and assert at module load that `DASHBOARD_ICONS[DEFAULT_ICON]` exists.

**Alternatives considered:**

- Use the first entry in `Object.keys(DASHBOARD_ICONS)`. Rejected because the order of object keys is implementation-dependent in practice and the assumption is fragile.
- Parameterise the default at runtime. Rejected because it adds a backend API call and makes the fallback unpredictable.

**Rationale**: Named constants in code are explicit and testable. The assertion at module load catches mistakes immediately.

### D3: `getIconComponent(name)` returns the component or falls back to DEFAULT_ICON

**Decision**: `getIconComponent()` returns the Vue component for the given registry name, or the `DEFAULT_ICON` component when the input is null, undefined, empty string, or an unknown registry name.

**Alternatives considered:**

- Return `null` for unknown names and let callers decide the fallback. Rejected because it forces every consumer to duplicate the fallback logic.
- Throw an error on unknown names. Rejected because it makes the function brittle — a typo in the dashboard data would crash the page.

**Rationale**: Returning a component guarantees that callers always have something to render. The fallback to `DEFAULT_ICON` is predictable and safe.

### D4: `isCustomIconUrl(name)` uses `/` or `http` prefix heuristic

**Decision**: `isCustomIconUrl()` returns `true` when `name` is a non-null string AND begins with either `'/'` or `'http'`; all other inputs (including registry names, null, undefined, empty string) return `false`.

**Alternatives considered:**

- Use a MIME-type check or file extension. Rejected because the URL might not have a file extension (e.g., `/apps/mydash/resource/abc`).
- Store a typed discriminator object `{kind: 'name'|'url', value: ...}` in the database. Rejected because it triples the surface area of every read path and breaks the single-column convention (REQ-ICON-009).
- Validate against a known resource-uploads URL pattern. Rejected because it creates a tight coupling and fails for future custom-upload endpoints.

**Rationale**: The prefix heuristic is simple, stable, and covers the existing use cases (Nextcloud app URLs and external HTTPS resources). Future built-in registry names MUST avoid `/` and `http` prefixes, which is enforceable in code review.

### D5: `IconRenderer` component branches on `isCustomIconUrl()`

**Decision**: Create `src/components/Dashboard/IconRenderer.vue` that accepts `name` (string or null) and `size` props, then renders either `<img :src="name">` (when `isCustomIconUrl()` is true) or `<component :is="getIconComponent(name)">` (for registry names and fallback).

**Alternatives considered:**

- Two separate components (`IconComponent` + `IconImage`) that callers choose between. Rejected because it duplicates branching logic across the codebase.
- A single `<component>` that somehow renders both modes. Rejected because Vue components and `<img>` are fundamentally different — one is a live component, one is a passive element.

**Rationale**: A single `IconRenderer` component centralises the branching and ensures all consumers render consistently. The contract (null input → fallback to default) is documented in the component's docblock.

### D6: `IconPicker` offers both registry `<select>` and file upload side-by-side

**Decision**: Create `src/components/Dashboard/IconPicker.vue` that exposes a `v-model` and renders a `<select>` driven by `Object.keys(DASHBOARD_ICONS)` plus an `<input type="file">` button side-by-side, with a 24×24 live preview via `IconRenderer`. Both inputs update the same `v-model`.

**Alternatives considered:**

- Tabs (one for registry, one for upload). Rejected because it hides the upload option and forces the user to switch tabs to explore both modes.
- A single dropdown with mixed options (registry names + "Upload custom..."). Rejected because it pollutes the registry list and doesn't clearly signal the two input paths.
- A separate upload button in a parent component. Rejected because it distributes the icon-picker logic across components.

**Rationale**: Side-by-side layout makes both options visible and equally discoverable. The shared `v-model` and preview ensure the user always sees the current state.

### D7: No database migration — use existing `icon` column with field-convention docblock

**Decision**: The `oc_mydash_dashboards.icon` column already exists; no schema change is required. Document the convention (NULL, registry name, or URL) in the `Dashboard` entity's docblock.

**Alternatives considered:**

- Create a new `icon_type` discriminator column. Rejected because it violates REQ-ICON-009 (single-column convention) and requires a data migration.

**Rationale**: The existing column is perfectly serviceable. The field convention is documented; discrimination happens at runtime via `isCustomIconUrl()`.

### D8: Seed data (N/A for this capability)

**Decision**: This change does not introduce OpenRegister schemas and does not require seed data per ADR-001.

**Rationale**: `dashboard-icons` is a frontend-only registry — it does not create or manage domain objects through OpenRegister. Seed data would be part of a future `dashboards` or `widgets` change that creates those entities.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Registry is hard-coded in the frontend bundle — cannot be extended at runtime | Deliberate design choice for a curated palette; extensibility (e.g. admin-configured icons, dynamic search) is a future change |
| `/` and `http` prefix heuristic could conflict with future registry names | Registry review in code review enforces the constraint; a test asserts no existing names start with `/` or `http` |
| Icon imports are tree-shake-dependent on the bundler not inlining large libraries | Explicit imports without barrel imports ensure tree-shaking works; webpack / Rollup are well-tested here |
| File upload integration depends on the parallel `resource-uploads` change | The `IconPicker` component is designed as-if `resource-uploads` exists; parallel development is OK as long as both merge before the release |

## Reuse Analysis

**Existing services leveraged:**

- Vue 2 + Pinia for state management (existing app pattern).
- `@conduction/nextcloud-vue` for any shared components (future integration with `IconPicker` render preview).
- No new dependencies — `vue-material-design-icons` is already a dependency.
- No OpenRegister services required (frontend-only).

**Deduplication check:**

- No overlap with existing `DashboardService` or `WidgetService` — this capability owns the icon namespace and rendering, which are not covered by those services.
- `IconRenderer` and `IconPicker` are new components; no equivalent exists in the codebase.
- `getIconComponent()` and `isCustomIconUrl()` are pure functions; no equivalent service layer.

## Migration

No data migration. The `oc_mydash_dashboards.icon` column already exists and continues to store either a registry name, a URL, or NULL as before. Existing dashboards with built-in icon names continue to render unchanged. New dashboards may assign either a registry name or (once `resource-uploads` lands) a custom URL.

## Open Questions

- Should future versions of this capability include a search picker for the full MDI library? (Out of scope — current focus is on a curated, fast picker.)
- Should the default icon (`ViewDashboard`) be configurable per instance? (Out of scope — hard-coded for predictability; future change if needed.)
