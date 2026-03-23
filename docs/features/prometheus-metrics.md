# Prometheus Metrics

MyDash exposes application metrics in Prometheus text exposition format for monitoring, alerting, and operational dashboards.

## Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/metrics` | Prometheus metrics (admin-only, no CSRF) |
| GET | `/api/health` | Health check (database connectivity) |

## Metrics

| Metric | Type | Description |
|--------|------|-------------|
| `mydash_info` | gauge | App version, PHP version, Nextcloud version |
| `mydash_up` | gauge | Whether the application is up |
| `mydash_dashboards_total{type}` | gauge | Dashboard count by type |
| `mydash_widgets_total` | gauge | Total widget placements |
| `mydash_tiles_total` | gauge | Total tiles |

## Health Check Response

```json
{"status": "ok", "checks": {"database": "ok"}}
```

## Notes

- Metrics computed on-demand (no persistent storage)
- Content-Type: `text/plain; version=0.0.4; charset=utf-8`
- `@NoCSRFRequired` for external monitoring tool access

## Screenshot

![Dashboard Overview](../screenshots/mydash-dashboard-overview.png)
