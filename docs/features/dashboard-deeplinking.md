# Dashboard deep-linking

Each dashboard has its own URL — paste, bookmark, or share it. Visit
`/apps/mydash/{slug-chain}` and the workspace opens directly on that
dashboard. Switching dashboards in the sidebar updates the URL via
`pushState`; back/forward navigates between dashboards.

## URL format

```
/apps/mydash/                              → resolver default
/apps/mydash/finance                       → top-level slug
/apps/mydash/finance/q1-roadmap            → nested via slug-chain
/apps/mydash/finance/q1-roadmap/details    → arbitrarily deep
```

Slugs use lowercase ASCII letters, digits, and dashes (per the
dashboards capability's REQ-DASH-024). Multi-segment chains reflect
parent → child hierarchy.

## How it works

### Inbound (URL → state)

1. User visits `/apps/mydash/some-slug`.
2. PHP catch-all route `page#deepLink` matches and dispatches to
   `PageController::deepLink($deepLink)`.
3. The controller resolves the slug-chain via
   `DashboardTreeService::resolvePath()`.
4. If resolved AND the user can read the dashboard → uses it as
   active.
5. If unresolved or no permission → silently falls back to the
   seven-step resolver, logs a warning. **Never 404s** — old
   bookmarks always land on something.
6. Server pushes `deepLinkPath` (the canonical slug-chain) into
   initial state.
7. Frontend reads `deepLinkPath` and `replaceState`s the URL to the
   canonical form. Stale URLs (parent renamed) get normalised
   in-place without a reload.

### Outbound (state → URL)

1. User clicks a row in the sidebar.
2. `switchDashboard()` updates `activeDashboard.uuid`.
3. A watcher on `activeDashboard.uuid` fetches the canonical path
   via `GET /api/dashboards/{uuid}/path`.
4. If non-empty → `history.pushState({uuid}, '', '/apps/mydash/' + path)`.
5. If empty (NULL slug — unaddressable dashboard) → no `pushState`.

### Back / forward

A `popstate` listener on `Views.vue` mount:

1. Strips `/apps/mydash/` from `window.location.pathname`.
2. Calls `getDashboardByPath(path)` to resolve.
3. Calls `switchDashboard(uuid)`.

Back / forward between dashboards then matches user expectation.

## Why the catch-all is safe for `/api/...`

The route registration:

```php
['name' => 'page#deepLink', 'url' => '/{deepLink}', 'verb' => 'GET',
 'requirements' => ['deepLink' => '(?!api(?:/|$)).+']],
```

The negative-lookahead `(?!api(?:/|$))` excludes any path starting
with `api/` (or just `api`). The Newman integration suite asserts
`GET /api/health` returns 200 as a regression check.

The catch-all MUST stay LAST in `appinfo/routes.php` so every literal
`/api/...` route matches first.

## Endpoints

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/api/dashboards/by-path/{path}` | Resolve slug-chain → dashboard envelope (requirements `path: .+`) |
| GET | `/api/dashboards/{uuid}/path` | Compute canonical slug-chain for a dashboard UUID |
| GET | `/{deepLink}` (catch-all) | Renders the workspace page with the dashboard pre-resolved |

## Tutorials

- [Bookmark or share a dashboard URL](/docs/tutorials/user/deep-link)

## Spec reference

[`openspec/specs/dashboard-deeplinking/spec.md`](../../openspec/specs/dashboard-deeplinking/spec.md)
— REQ-DDL-001 through REQ-DDL-007.
