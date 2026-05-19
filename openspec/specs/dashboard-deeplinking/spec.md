---
capability: dashboard-deeplinking
status: implemented
---

# Dashboard Deep-Linking Specification

## Purpose

Each dashboard has a stable, addressable URL based on its
slug-chain. Visiting `/apps/mydash/{slug-chain}` lands the workspace
on the matching dashboard; switching dashboards via the sidebar
pushes a new history entry; the browser back/forward buttons
navigate between dashboards.

Before this capability, dashboards were addressable only by the
in-memory `activeDashboard` state — no URL bookmarking, no sharing
a link with a colleague, no back-button between dashboards.

## Context

The implementation is **bidirectional**: URL → state on cold load,
and state → URL on every sidebar switch. A catch-all PHP route
captures the slug-chain and the controller resolves it through
`DashboardTreeService::resolvePath()`. Stale slugs fall back
silently to the resolver's seven-step default rather than 404'ing
— old bookmarks always land on something.

The frontend reads `deepLinkPath` from initial state (server has
already pre-resolved the active dashboard) and watches
`activeDashboard.uuid` to push history. A `popstate` listener
handles back/forward by calling `getDashboardByPath()` and
switching the active dashboard.

## URL format

```
/apps/mydash/                           → resolver default
/apps/mydash/{slug}                     → top-level slug
/apps/mydash/{parent}/{child}           → nested via slug-chain
/apps/mydash/{parent}/{child}/{grand}   → arbitrarily deep
```

Slugs use lowercase ASCII letters, digits, and dashes (REQ-DASH-024).
Multi-segment slug-chains are joined with `/` and supported by the
existing `GET /api/dashboards/by-path/{path}` endpoint
(`'requirements' => ['path' => '.+']` allows slashes in the captured
segment).

## Requirements

### Requirement: REQ-DDL-001 Catch-all route registration

`appinfo/routes.php` MUST register a route named `page#deepLink`
with URL `/{deepLink}` and `'requirements' => ['deepLink' =>
'(?!api(?:/|$)).+']`. The negative-lookahead requirement excludes
`/api/...` requests so the catch-all never shadows API routes.

The route MUST be the LAST entry in the route table so every
literal `/api/...` and explicit page route is matched first.

### Requirement: REQ-DDL-002 Server-side resolution

`PageController::deepLink(string $deepLink): TemplateResponse`
delegates to `index($deepLink)` which:

1. If `$deepLink` is non-empty, calls
   `DashboardTreeService::resolvePath(path: $deepLink)`.
2. If the path resolves to a dashboard, calls
   `DashboardService::getDashboardForUser($dashboard->getId(), $userId)`
   for permission-checked envelope. Uses that as the active dashboard.
3. If the path doesn't resolve OR the user lacks read access, falls
   back to `DashboardService::resolveActiveDashboard()` (the
   seven-step resolver) and logs a warning. **Never returns 404 for
   a stale slug.**

### Requirement: REQ-DDL-003 Canonical path round-trip

After active dashboard resolution, `PageController::index()` MUST
compute the canonical slug-chain via
`DashboardTreeService::computePath(uuid)` and pass it through
initial state as `deepLinkPath`.

The frontend reads `deepLinkPath` on mount and replaces the URL via
`history.replaceState()` to match the canonical form. A user
visiting `/apps/mydash/old-parent/child` after a parent rename gets
silently normalised to `/apps/mydash/new-parent/child` without a
reload.

### Requirement: REQ-DDL-004 New API endpoint for outbound URL sync

A new endpoint `GET /api/dashboards/{uuid}/path` MUST return the
canonical slug-chain for a dashboard UUID:

```json
{
  "data": {
    "path": "finance/q1-roadmap"
  }
}
```

Implementation calls `DashboardService::findPlacements()`-adjacent
helper that wraps `DashboardTreeService::computePath()`. Empty
result is a valid response (`""`) for dashboards with NULL slugs —
those are unaddressable.

### Requirement: REQ-DDL-005 Frontend `pushState` on switch

`Views.vue` MUST watch `activeDashboard.uuid` and on every change:

1. Fetch the canonical path via `api.getDashboardPath(uuid)`.
2. If the response path is non-empty, push history:
   ```js
   history.pushState({ uuid }, '', '/apps/mydash/' + path)
   ```
3. If empty path (NULL slug), skip the pushState — no addressable
   URL exists, leaving the URL unchanged.

The `pushState` MUST NOT fire on the initial mount (the URL is
already correct from the server-side render); it fires only on
subsequent transitions.

### Requirement: REQ-DDL-006 Frontend `popstate` listener

`Views.vue` MUST register a `popstate` listener on mount that:

1. Reads `window.location.pathname`.
2. Strips the route prefix `/apps/mydash/` to get the slug-chain.
3. Calls `api.getDashboardByPath(path)` to resolve it server-side.
4. Calls `switchDashboard(uuid)` with the resolved UUID.

Browser back / forward navigation between dashboards then matches
the user's expectation. Visiting any URL the user hasn't seen
before still works because step 3 hits the resolver.

### Requirement: REQ-DDL-007 API regression check

`tests/integration/mydash.postman_collection.json` MUST include a
regression check that `GET /api/health` still routes correctly
after the catch-all is registered. The negative-lookahead pattern
prevents API shadowing but a misconfiguration (e.g. moving the
catch-all above an API route) would silently break every API
endpoint.

The catch-all route MUST come AFTER every `/api/...` entry in
`routes.php`.

## Test coverage

- `tests/Unit/Controller/DashboardApiControllerComputePathTest.php` —
  4 cases pinning the canonical-path API (auth, missing UUID, happy
  path, empty path for NULL slug).
- `tests/integration/mydash.postman_collection.json` — Newman
  asserts the deep-link flow end-to-end:
  - `GET /apps/mydash/{slug}` returns 200 HTML
  - `GET /apps/mydash/{stale-slug}` returns 200 (silent fallback,
    not 404)
  - `GET /api/health` returns 200 (regression: the catch-all
    didn't shadow the API)
  - `GET /api/dashboards/{uuid}/path` returns the canonical
    slug-chain

## References

- Implementation: PR #131 (deep-linking).
- Slug uniqueness + tree structure: `REQ-DASH-023..029` in the
  dashboards capability.
- Frontend reference: [docs/features/dashboard-deeplinking.md](../../../docs/features/dashboard-deeplinking.md).
