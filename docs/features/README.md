# MyDash — Features

MyDash is a configurable dashboard and widget system for Nextcloud. It replaces Nextcloud's built-in dashboard with a multi-dashboard, drag-and-drop interface where users manage multiple personal dashboards, administrators distribute templated layouts via group membership, and individual widgets render live content from any Nextcloud app.

MyDash maps to the **BI-component** within the GEMMA reference architecture.

## Standards Compliance

| Standard | Status | Description |
|----------|--------|-------------|
| Nextcloud Dashboard Widget API v1/v2 | Beschikbaar | Native Nextcloud widget discovery and rendering |
| WCAG 2.1 AA | Via platform | Accessibility via Nextcloud and NL Design app |
| NL Design System | Via platform | Government theming via nldesign app |
| GDPR / AVG | Via platform | Data subject rights via OpenRegister / Nextcloud |

## Features

| Feature | Description | Docs |
|---------|-------------|------|
| [Dashboards](./dashboards.md) | Multi-dashboard management per user; one active dashboard at a time; types: personal and admin template | [dashboards.md](./dashboards.md) |
| [Widgets](./widgets.md) | Discover and place all registered Nextcloud Dashboard Widgets (v1 + v2) as grid placements | [widgets.md](./widgets.md) |
| [Grid Layout](./grid-layout.md) | 12-column drag-and-drop grid powered by GridStack 10.3.1; view mode and edit mode | [grid-layout.md](./grid-layout.md) |
| [Custom Tiles](./tiles.md) | Shortcut cards linking to Nextcloud apps or external URLs with icon, label, and inline-copy model | [tiles.md](./tiles.md) |
| [Permission Levels](./permissions.md) | Three-tier permission hierarchy: `view_only`, `add_only`, `full` — inherited from admin templates | [permissions.md](./permissions.md) |
| [Admin Templates](./admin-templates.md) | Pre-configured dashboards distributed to users by Nextcloud group membership | [admin-templates.md](./admin-templates.md) |
| [Admin Settings](./admin-settings.md) | Global configuration: allow user dashboards, max dashboards per user, default grid columns | [admin-settings.md](./admin-settings.md) |
| [Conditional Visibility](./conditional-visibility.md) | Show or hide widget placements based on time, date, group membership, or user attributes | [conditional-visibility.md](./conditional-visibility.md) |
| [Prometheus Metrics](./prometheus-metrics.md) | Monitoring endpoint: dashboard count, widget usage, tile counts, health check | [prometheus-metrics.md](./prometheus-metrics.md) |

## Architecture

MyDash integrates with Nextcloud's widget ecosystem via `OCP\Dashboard\IManager::getWidgets()`. Widgets are discovered automatically — any installed Nextcloud app that registers a Dashboard Widget (v1 or v2) appears in the MyDash widget library.

**Data model:**

- **Dashboard** — Container with grid config and permission level
- **Placement** — A widget or tile positioned on a grid cell (x, y, width, height)
- **Tile** — Reusable shortcut definition; inline-copied to placements (snapshot model)
- **ConditionalVisibilityRule** — Include/exclude rules evaluated at render time

## GEMMA Mapping

| GEMMA Component | MyDash Role |
|-----------------|-------------|
| BI-component | Configurable multi-dashboard with widget aggregation |
| Portaal | Entry point for Nextcloud apps via tiles and widgets |
