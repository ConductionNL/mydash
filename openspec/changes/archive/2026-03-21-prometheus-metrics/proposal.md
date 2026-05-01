# Prometheus Metrics Specification

## Problem
Expose application metrics in Prometheus text exposition format at `GET /api/metrics` for monitoring, alerting, and operational dashboards. Additionally, provide a health check endpoint at `GET /api/health` for container orchestration and load balancer readiness probes.

## Proposed Solution
Implement Prometheus Metrics Specification following the detailed specification. Key requirements include:
- See full spec for detailed requirements

## Scope
This change covers all requirements defined in the prometheus-metrics specification.

## Success Criteria
- Metrics endpoint returns valid Prometheus format
- Metrics endpoint requires admin authentication
- Metrics endpoint accessible without CSRF token
- Metrics response ends with newline
- Info metric reports versions
