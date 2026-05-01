---
status: reviewed
---

# Prometheus Metrics Specification

## Purpose

Expose application metrics in Prometheus text exposition format at `GET /api/metrics` for monitoring, alerting, and operational dashboards. Additionally, provide a health check endpoint at `GET /api/health` for container orchestration and load balancer readiness probes.

## Data Model

Metrics are collected at request time from database queries and system information. No persistent metrics storage is used -- all values are computed on-demand.

### Metrics Architecture
- **MetricsController**: Handles HTTP request, formats output as Prometheus text exposition
- **MetricsCollector**: Orchestrates metric collection, delegates to MetricsQueryService
- **MetricsQueryService**: Executes database queries for entity counts
- **HealthController**: Handles health check requests, performs database connectivity test

## Requirements

### REQ-PROM-001: Metrics Endpoint

The system MUST expose a Prometheus-compatible metrics endpoint accessible to admin users.

#### Scenario: Metrics endpoint returns valid Prometheus format
- GIVEN a Nextcloud admin user
- WHEN they send GET /index.php/apps/mydash/api/metrics
- THEN the system MUST return HTTP 200
- AND the Content-Type MUST be `text/plain; version=0.0.4; charset=utf-8`
- AND the body MUST contain metrics in Prometheus text exposition format (lines of `# HELP`, `# TYPE`, and metric values)

#### Scenario: Metrics endpoint requires admin authentication
- GIVEN a regular (non-admin) Nextcloud user "alice"
- WHEN she sends GET /api/metrics
- THEN the system MUST return HTTP 403
- AND no metrics data MUST be exposed

#### Scenario: Metrics endpoint accessible without CSRF token
- GIVEN an admin user or monitoring system
- WHEN GET /api/metrics is sent without a CSRF token
- THEN the system MUST still return metrics
- AND the controller MUST have `@NoCSRFRequired` annotation to allow external monitoring tools

#### Scenario: Metrics response ends with newline
- GIVEN the metrics endpoint is called
- WHEN the response body is generated
- THEN the body MUST end with a newline character (Prometheus exposition format requirement)
- AND each metric MUST be on its own line separated by `\n`

### REQ-PROM-002: Application Info Metric

The system MUST expose an info metric with version labels.

#### Scenario: Info metric reports versions
- GIVEN the MyDash app version is "1.2.3", PHP version is "8.2.0", and Nextcloud version is "29.0.0"
- WHEN the metrics endpoint is called
- THEN the response MUST include:
  ```
  # HELP mydash_info Application information
  # TYPE mydash_info gauge
  mydash_info{version="1.2.3",php_version="8.2.0",nextcloud_version="29.0.0"} 1
  ```
- AND the value MUST always be 1

#### Scenario: Info metric reads app version from config
- GIVEN the MyDash app is installed
- WHEN the info metric is collected
- THEN the app version MUST be read from `IConfig::getAppValue(Application::APP_ID, 'installed_version', '0.0.0')`
- AND PHP version from `PHP_VERSION`
- AND Nextcloud version from `IConfig::getSystemValueString('version', '0.0.0')`

#### Scenario: Info metric with missing version
- GIVEN the app version is not set in config
- WHEN the info metric is collected
- THEN the version MUST default to "0.0.0"

### REQ-PROM-003: Application Up Metric

The system MUST expose an up metric indicating application health.

#### Scenario: Up metric when healthy
- GIVEN the application is running normally
- WHEN the metrics endpoint is called
- THEN the response MUST include:
  ```
  # HELP mydash_up Whether the application is up
  # TYPE mydash_up gauge
  mydash_up 1
  ```

#### Scenario: Up metric always returns 1 if endpoint is reachable
- GIVEN the metrics endpoint is accessible
- WHEN the response is generated
- THEN `mydash_up` MUST be 1 (if the endpoint can respond, the app is up)
- NOTE: The current implementation always returns 1. A degraded state (0) would only occur if the endpoint itself cannot respond.

### REQ-PROM-004: Dashboard Count Metrics

The system MUST expose dashboard count metrics grouped by type.

#### Scenario: Dashboard counts by type
- GIVEN 50 user dashboards and 5 admin templates exist
- WHEN the metrics endpoint is called
- THEN the response MUST include:
  ```
  # HELP mydash_dashboards_total Total dashboards by type
  # TYPE mydash_dashboards_total gauge
  mydash_dashboards_total{type="user"} 50
  mydash_dashboards_total{type="admin_template"} 5
  ```

#### Scenario: Dashboard counts with no dashboards
- GIVEN no dashboards exist in the database
- WHEN the metrics endpoint is called
- THEN the response MUST include both types with count 0:
  ```
  mydash_dashboards_total{type="personal"} 0
  mydash_dashboards_total{type="template"} 0
  ```
- NOTE: The fallback labels use "personal" and "template" when no data exists, while actual data uses the DB type values ("user", "admin_template").

#### Scenario: Dashboard count query failure
- GIVEN the database query for dashboards fails
- WHEN the metrics endpoint is called
- THEN the system MUST log a warning
- AND the response MUST include fallback values:
  ```
  mydash_dashboards_total{type="personal"} 0
  mydash_dashboards_total{type="template"} 0
  ```
- AND the error MUST NOT cause the entire metrics response to fail

### REQ-PROM-005: Widget Placement Count Metric

The system MUST expose the total number of widget placements.

#### Scenario: Widget placement count
- GIVEN 150 widget placements exist across all dashboards
- WHEN the metrics endpoint is called
- THEN the response MUST include:
  ```
  # HELP mydash_widgets_total Total number of widget placements
  # TYPE mydash_widgets_total gauge
  mydash_widgets_total 150
  ```

#### Scenario: Widget count query failure
- GIVEN the database query for widget placements fails
- WHEN the metrics endpoint is called
- THEN the system MUST return 0 for the widget count
- AND log a warning

### REQ-PROM-006: Tile Count Metric

The system MUST expose the total number of tile definitions.

#### Scenario: Tile count
- GIVEN 25 tile definitions exist
- WHEN the metrics endpoint is called
- THEN the response MUST include:
  ```
  # HELP mydash_tiles_total Total number of tiles
  # TYPE mydash_tiles_total gauge
  mydash_tiles_total 25
  ```

#### Scenario: Tile count query failure
- GIVEN the database query for tiles fails
- WHEN the metrics endpoint is called
- THEN the system MUST return 0 for the tile count
- AND log a warning

### REQ-PROM-007: Health Check Endpoint

The system MUST expose a health check endpoint for monitoring and container orchestration.

#### Scenario: Healthy status
- GIVEN the database is accessible
- WHEN GET /index.php/apps/mydash/api/health is called
- THEN the system MUST return HTTP 200 with JSON:
  ```json
  {
    "status": "ok",
    "checks": {
      "database": "ok"
    }
  }
  ```

#### Scenario: Database failure
- GIVEN the database is not accessible
- WHEN GET /api/health is called
- THEN the system MUST return HTTP 200 with JSON:
  ```json
  {
    "status": "error",
    "checks": {
      "database": "error"
    }
  }
  ```
- AND the error MUST be logged via `LoggerInterface::error()`

#### Scenario: Health check requires no CSRF token
- GIVEN a monitoring system
- WHEN GET /api/health is sent without CSRF token
- THEN the system MUST still respond (controller has `@NoCSRFRequired`)

#### Scenario: Health check database test
- GIVEN the health check is called
- WHEN the database check runs
- THEN the system MUST execute a simple `SELECT 1` query via `IDBConnection::getQueryBuilder()`
- AND if the query succeeds, the database check MUST be "ok"
- AND if the query throws an exception, the database check MUST be "error"

### REQ-PROM-008: Metrics Collection Architecture

The metrics collection MUST follow a clean architecture with separate concerns.

#### Scenario: MetricsCollector delegates to MetricsQueryService
- GIVEN the metrics endpoint is called
- WHEN `MetricsCollector::collectAll()` runs
- THEN it MUST delegate database queries to `MetricsQueryService`
- AND format results into Prometheus text lines
- AND add HELP and TYPE annotations for each metric

#### Scenario: MetricsController formats final output
- GIVEN `MetricsCollector::collectAll()` returns an array of metric lines
- WHEN the controller builds the response
- THEN lines MUST be joined with `\n` and a trailing newline appended
- AND the response MUST be a `TextPlainResponse` with the correct Content-Type header

#### Scenario: Individual metric collection failures are isolated
- GIVEN the dashboard count query fails but tile count succeeds
- WHEN the metrics endpoint is called
- THEN dashboard metrics MUST show fallback values (0)
- AND tile metrics MUST show the actual count
- AND the overall response MUST still be returned (partial failure is acceptable)

### REQ-PROM-009: Active Users Metric

The system SHALL expose the number of active users (users with at least one dashboard).

#### Scenario: Active users count
- GIVEN 30 unique users have at least one dashboard
- WHEN the metrics endpoint is called
- THEN the response SHOULD include:
  ```
  # HELP mydash_active_users Users with at least one dashboard
  # TYPE mydash_active_users gauge
  mydash_active_users 30
  ```
- NOTE: This metric is NOT currently implemented.

### REQ-PROM-010: Metrics Endpoint Performance

The metrics endpoint MUST respond quickly to avoid blocking Prometheus scrape intervals.

#### Scenario: Metrics response under load
- GIVEN a large installation with 10,000 dashboards, 50,000 widget placements, and 5,000 tiles
- WHEN the metrics endpoint is called
- THEN the response MUST return within 2 seconds
- AND database queries MUST use COUNT aggregation (not loading full entities)

#### Scenario: Concurrent scrapes
- GIVEN Prometheus scrapes metrics every 15 seconds
- WHEN two scrapes overlap
- THEN both requests MUST complete successfully
- AND no locking or caching issues MUST occur

## Non-Functional Requirements

- **Performance**: GET /api/metrics MUST return within 2 seconds for installations with up to 100,000 rows across all tables. COUNT queries MUST be used rather than loading entities.
- **Security**: The metrics endpoint MUST require admin authentication. No sensitive data (user IDs, passwords, API keys) MUST be exposed in metrics labels.
- **Reliability**: Individual metric collection failures MUST NOT cause the entire endpoint to fail. Fallback values (0) MUST be returned for failed queries.
- **Standards compliance**: Metrics MUST follow the Prometheus text exposition format (version 0.0.4). HELP and TYPE lines MUST be present for every metric.
- **Monitoring integration**: The health check endpoint MUST be usable by Kubernetes liveness/readiness probes and load balancer health checks.

### Current Implementation Status

**Fully implemented:**
- REQ-PROM-001 (Metrics Endpoint): `MetricsController::index()` in `lib/Controller/MetricsController.php` returns Prometheus text format with correct Content-Type header. Admin-only (no `#[NoAdminRequired]`). `@NoCSRFRequired` for external monitoring.
- REQ-PROM-002 (Application Info Metric): Version labels from `IConfig::getAppValue()`, `PHP_VERSION`, and system config.
- REQ-PROM-003 (Application Up Metric): Always returns 1.
- REQ-PROM-004 (Dashboard Count Metrics): SQL query with GROUP BY type. Fallback to 0 on error.
- REQ-PROM-005 (Widget Placement Count): `countTable('mydash_widget_placements')`.
- REQ-PROM-006 (Tile Count): `countTable('mydash_tiles')`.
- REQ-PROM-007 (Health Check): `HealthController::index()` in `lib/Controller/HealthController.php` with database connectivity check.
- REQ-PROM-008 (Architecture): `MetricsCollector` and `MetricsQueryService` exist as separate service classes alongside the controller.

**Not yet implemented:**
- REQ-PROM-009 (Active Users): No distinct user count metric.
- Standard metrics from original spec: `mydash_requests_total` (counter), `mydash_request_duration_seconds` (histogram), `mydash_errors_total` (counter) are NOT implemented. These would require middleware/event listeners to track per-request metrics.

### Standards & References
- Prometheus text exposition format: https://prometheus.io/docs/instrumenting/exposition_formats/
- OpenMetrics specification: https://openmetrics.io/
- Nextcloud server monitoring patterns
- OpenRegister MetricsService and HeartbeatController as reference implementation
