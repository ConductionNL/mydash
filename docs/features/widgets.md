# Widgets

Widgets are the primary content blocks on MyDash dashboards. MyDash integrates with the Nextcloud Dashboard Widget API (v1 and v2) to discover all registered dashboard widgets across installed apps.

## Features

- Discover widgets from all installed Nextcloud apps via IManager
- Support for v1 (IAPIWidget) and v2 (IAPIWidgetV2) widget APIs
- Widget placements track grid position, styling, and visibility
- Custom title and icon override per placement
- Style configuration via JSON blob (borders, colors, etc.)
- Compulsory flag for admin-mandated widgets

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/widgets` | List available widgets |
| GET | `/api/widgets/items` | Get widget items |
| POST | `/api/dashboard/{id}/widgets` | Add widget to dashboard |
| PUT | `/api/widgets/{id}` | Update widget placement |
| DELETE | `/api/widgets/{id}` | Remove widget placement |

## Screenshot

![Dashboard with Widgets](../screenshots/mydash-dashboard-overview.png)
