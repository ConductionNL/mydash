# MyDash — Customizable Dashboard for Nextcloud

## Overview

MyDash is a dashboard app for Nextcloud that lets users and admins create personalized, widget-based dashboards. It integrates with Nextcloud's native Dashboard Widget API to discover and render all available widgets, adds custom "tiles" (shortcut link cards), and supports drag-and-drop grid layout via GridStack. Admins can create dashboard templates with permission controls, conditional visibility rules, and compulsory widgets for distribution to user groups.

## Architecture

- **Type**: Nextcloud App (PHP backend + Vue 2 frontend)
- **Data layer**: Own database tables (5 tables via Nextcloud ORM)
- **Pattern**: Full-stack — MyDash owns its data model and UI
- **License**: EUPL-1.2
- **Grid engine**: GridStack 10.3.1 (drag-and-drop, resize)
- **Widget bridge**: Nextcloud Dashboard Widget API v1/v2

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 8.1+, Nextcloud App Framework |
| Frontend | Vue 2.7, Pinia, @nextcloud/vue |
| Grid | GridStack 10.3.1 (12-column, 80px cell height) |
| HTTP | @nextcloud/axios |
| Build | Webpack 5, @nextcloud/webpack-vue-config |
| i18n | @nextcloud/l10n |

## Data Model

| Entity | Table | Description |
|--------|-------|-------------|
| Dashboard | `oc_mydash_dashboards` | User or template dashboard with grid config and permissions |
| Widget Placement | `oc_mydash_widget_placements` | Widget position/size on grid + styling + tile data |
| Tile | `oc_mydash_tiles` | Custom shortcut card (title, icon, link, colors) |
| Conditional Rule | `oc_mydash_conditional_rules` | Visibility rules (group, time, date, attribute) |
| Admin Setting | `oc_mydash_admin_settings` | Global key-value settings |

## Features

### Implemented

| Feature | Description | Status |
|---------|-------------|--------|
| Multiple Dashboards | Users create and switch between personal dashboards | Done |
| Widget Management | Discover/add/remove Nextcloud dashboard widgets | Done |
| Custom Tiles | Create shortcut cards with icon, colors, and link | Done |
| GridStack Layout | 12-column drag-and-drop grid with resize | Done |
| Widget Styling | Per-widget background, text color, border, title override | Done |
| Conditional Visibility | Show/hide widgets based on group, time, date, attribute rules | Done |
| Permission Levels | view_only, add_only, full — control widget editing per dashboard | Done |
| Admin Templates | Pre-configured dashboards distributed to target groups | Done |
| Compulsory Widgets | Widgets that users cannot remove (add_only permission) | Done |
| Admin Settings | Global config: allow user dashboards, default permission, grid columns | Done |

## Key Directories

```
mydash/
├── appinfo/          # App manifest and routes
├── lib/              # PHP backend (controllers, services, mappers, entities)
├── src/              # Vue frontend (views, components, stores, services)
├── templates/        # PHP templates
├── openspec/         # OpenSpec specs
└── js/               # Built frontend assets
```

## Development

- **Local URL**: http://localhost:8080/apps/mydash/
- **Docker**: Part of openregister/docker-compose.yml
- **Admin settings**: Settings → Administration → MyDash
