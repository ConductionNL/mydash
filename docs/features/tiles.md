# Custom Tiles

Custom tiles are user-created shortcut cards that provide quick access to Nextcloud apps or external URLs.

## Features

- Create reusable tile definitions with icon, colors, and link
- Icon types: CSS class, URL, emoji, SVG path
- Link types: Nextcloud app route or external URL
- Tile placements store independent copies of tile data
- Changes to tile definitions do not propagate to existing placements

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/tiles` | List user tiles |
| POST | `/api/tiles` | Create new tile |
| PUT | `/api/tiles/{id}` | Update tile |
| DELETE | `/api/tiles/{id}` | Delete tile |
| POST | `/api/dashboard/{id}/tile` | Place tile on dashboard |

## Screenshot

![Dashboard with Tiles](../screenshots/mydash-dashboard-overview.png)
