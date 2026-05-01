# Dashboards

Dashboards are the core organizational unit in MyDash. Each user can create and manage multiple personal dashboards, each acting as a container for widget placements, tiles, and layout configuration.

## Features

- Create personal dashboards with name and optional description
- Only one dashboard active per user at a time
- New dashboards auto-activate and receive default widget placements
- UUID v4 generated for each dashboard
- Dashboard types: `user` (personal) and `admin_template` (admin-managed)
- Grid columns configurable (default: 12)
- Permission levels: `view_only`, `add_only`, `full`

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/dashboards` | List all user dashboards |
| GET | `/api/dashboard` | Get active dashboard |
| POST | `/api/dashboard` | Create new dashboard |
| PUT | `/api/dashboard/{id}` | Update dashboard |
| DELETE | `/api/dashboard/{id}` | Delete dashboard |
| POST | `/api/dashboard/{id}/activate` | Activate dashboard |

## Screenshot

![Dashboard Overview](../screenshots/mydash-dashboard-overview.png)
