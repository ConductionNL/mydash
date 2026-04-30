# Conditional Visibility

Conditional visibility allows widget placements to be shown or hidden based on dynamic rules evaluated at render time.

## Rule Types

| Type | Config | Description |
|------|--------|-------------|
| `group` | `{"groups": ["admin"]}` | Match user's Nextcloud groups |
| `time` | `{"startTime": "09:00", "endTime": "17:00", "days": ["mon"]}` | Match time of day and day of week |
| `date` | `{"startDate": "2026-12-01", "endDate": "2026-12-31"}` | Match date range |
| `attribute` | `{"attribute": "language", "operator": "equals", "value": "nl"}` | Match user attribute |

## Logic

- **Include rules**: OR logic (at least one must match to show)
- **Exclude rules**: AND logic (any match hides the widget)
- No rules + isVisible=1: always shown
- isVisible=0: always hidden (overrides rules)

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/widgets/{id}/rules` | List rules for placement |
| POST | `/api/widgets/{id}/rules` | Add rule to placement |
| PUT | `/api/rules/{id}` | Update rule |
| DELETE | `/api/rules/{id}` | Delete rule |

## Screenshot

![Dashboard Overview](../screenshots/mydash-dashboard-overview.png)
