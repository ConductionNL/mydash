---
sidebar_position: 3
---

# Architecture

MyDash is a dashboard container + layout manager for Nextcloud. It lets
each user (and each administrator, for the organisation as a whole)
compose personal dashboards from tiles and legacy Nextcloud widgets,
arranged on a responsive grid with per-tile conditional visibility.

Unlike thin UI utilities (e.g., app-versions), MyDash owns its own
domain model — dashboards, placements, tiles, conditional rules — and
persists everything in its own tables via Doctrine mappers.

## Component map

```
┌─────────────────────────────────────────────────────────────┐
│                         src/ (Vue 3 + TS)                   │
│                                                             │
│   views/Views.vue       — shell / routing                   │
│   views/Dashboard.vue   — grid canvas                       │
│   components/DashboardSwitcher — nav between dashboards     │
│   components/WidgetRenderer    — legacy-widget bridge       │
│   components/WidgetPicker      — "add widget" modal         │
│   components/WidgetWrapper     — per-tile chrome            │
│   components/TileCard / TileEditor / WidgetStyleEditor      │
│   components/admin/AdminSettings — admin console            │
└──────────────────────────┬──────────────────────────────────┘
                           │ OCS JSON via @nextcloud/axios
┌──────────────────────────▼──────────────────────────────────┐
│                    lib/Controller/ (11 classes)             │
│                                                             │
│   DashboardApiController — dashboard CRUD + activate        │
│   TileApiController      — tile CRUD + placement            │
│   WidgetApiController    — widget attach / detach           │
│   RuleApiController      — conditional-visibility rules     │
│   AdminController        — admin-only endpoints             │
│   MetricsController      — Prometheus `/api/metrics`        │
│   HealthController       — `/api/health`                    │
│   PageController         — admin SPA entry                  │
│                                                             │
│   ResponseHelper         — shared JSON envelope (ADR-005)   │
│   DashboardRequestValidator, RequestDataExtractor           │
└──────────────────────────┬──────────────────────────────────┘
                           │ constructor DI (ADR-003)
┌──────────────────────────▼──────────────────────────────────┐
│                    lib/Service/ (20 classes)                │
│                                                             │
│   DashboardService / DashboardFactory / DashboardResolver   │
│   TileService / TileUpdater                                 │
│   WidgetService / WidgetItemLoader / WidgetFormatter        │
│   PlacementService / PlacementUpdater                       │
│   PermissionService     — per-object auth (ADR-005)         │
│   ConditionalService / RuleEvaluatorService /               │
│      VisibilityChecker / UserAttributeResolver              │
│   AdminSettingsService / AdminTemplateService /             │
│      TemplateService                                        │
│   MetricsCollector / MetricsQueryService                    │
└──────────────────────────┬──────────────────────────────────┘
                           │ OCP\AppFramework\Db\Mapper
┌──────────────────────────▼──────────────────────────────────┐
│                      lib/Db/ (20 classes)                   │
│                                                             │
│   Entities:   Dashboard, Tile, WidgetPlacement,             │
│               ConditionalRule, AdminSetting                 │
│   Mappers:    DashboardMapper, TileMapper,                  │
│               WidgetPlacementMapper, ConditionalRuleMapper, │
│               AdminSettingMapper                            │
│   Traits:     OwnedEntityInterface, TimestampedEntity,      │
│               GridPositionInterface, DashboardEntityIface   │
│   Helpers:    ColumnTypeRegistry, JsonConfigHelper,         │
│               QueryHelper, TimestampHelper,                 │
│               EntitySerializer                              │
└─────────────────────────────────────────────────────────────┘
```

## Request flow — update a dashboard

1. UI sends `PUT /api/dashboard/{id}` via `@nextcloud/axios` with
   `{ name?, description?, placements? }`.
2. `DashboardApiController::update()` (admin check + body validation)
   delegates to `PermissionService::canEditDashboard(userId, id)` or
   `canEditDashboardMetadata` depending on whether placements changed.
3. On success, `DashboardService::updateDashboard()` runs the mutation
   inside a transaction. Ownership is re-verified at the service layer
   (`$dashboard->getUserId() !== $userId` → `'Access denied'`).
4. `PlacementUpdater` reconciles the placement array — inserts new
   placements, deletes removed ones, updates positions.
5. Controller wraps the result in `ResponseHelper::success`.

## Authentication & authorization posture

- **Routes**: all in `appinfo/routes.php` per ADR-016. No
  `#[ApiRoute]` / `#[FrontpageRoute]` attributes on controllers.
- **Auth attributes**: every controller method carries an explicit
  `#[NoAdminRequired]` / `#[PublicPage]` / `#[NoCSRFRequired]` /
  `#[AuthorizedAdminSetting]` per ADR-005.
- **Per-object auth**: every mutation on `Dashboard`, `Tile`,
  `WidgetPlacement`, `ConditionalRule` runs an ownership check through
  `PermissionService` or the service's own `getUserId()` guard before
  writing. See `DashboardService::deleteDashboard()`,
  `TileService::updateTile()`, etc.
- **Error responses**: `ResponseHelper::error` returns a generic
  message; the real exception is logged server-side when callers pass
  their `LoggerInterface` (work in progress — tracked in
  [adr-audit.md](./adr-audit.md)).

## Dependency injection

Every controller and service accepts its collaborators via constructor
(`private readonly` properties per ADR-003). No `\OC::$server->get()`,
no `OCP\Server::get()`, no `new \OC_App()`. Verified by `grep -rn
'\\\\OC::\$server\\|Server::get(\\|new \\\\OC_' lib/` returning zero
matches on `development`.

## Frontend stack

- **Vue 2.7** (not 3 — matches template baseline; shared component
  expectations with other Conduction apps).
- **Webpack** via `@nextcloud/webpack-vue-config`. No Vite.
- **Pinia** stores for dashboard + widget state.
- **TypeScript** optional — most components are plain JS; typed where
  it aids refactoring.
- **HTTP**: `@nextcloud/axios` exclusively. No bare `fetch()`.
- **Components**: every Nextcloud Vue import goes through
  `@conduction/nextcloud-vue` (ADR-004). No direct `@nextcloud/vue`
  imports.

## Capabilities (openspec)

Each capability has its own directory under `openspec/specs/` with a
Gherkin-style requirement list:

| Capability | Owns |
|---|---|
| `dashboards` | dashboard CRUD, activation, ownership |
| `tiles` | tile catalogue, config schema, placement |
| `widgets` | widget CRUD, style, positioning |
| `grid-layout` | responsive grid (cols, row heights) |
| `permissions` | view / add-only / full per role |
| `conditional-visibility` | rule-based tile hide/show |
| `admin-settings` | org-wide defaults, admin console |
| `admin-templates` | curated starter dashboards |
| `prometheus-metrics` | `/api/metrics` instrumentation |
| `legacy-widget-bridge` | Nextcloud dashboard-widget compat |

10 archived changes under `openspec/changes/archive/` document how each
capability arrived at its current shape.

## What MyDash explicitly does NOT do

- **No OpenRegister consumption** (ADR-001 / ADR-022 N/A). Dashboards
  and tiles live in MyDash's own tables. The app is intentionally
  self-contained.
- **No integration registry** (ADR-019 N/A). MyDash consumes the
  Nextcloud dashboard-widget API; it does not expose an extension
  point for third-party dashboards to register themselves.
- **No action-level authorisation** (ADR-023 N/A). Permission model is
  role-based (`view` / `add_only` / `full` / `admin`), not mapped to
  individual actions configured by the admin.
- **No government-theme targeting** beyond what comes via
  `@conduction/nextcloud-vue` (ADR-010 partial). If Conduction ships
  NL Design tokens in the wrapper, MyDash inherits them automatically.
