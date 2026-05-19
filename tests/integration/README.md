# MyDash integration tests (Newman / Postman)

This folder contains the Newman / Postman integration suite that
exercises every public OCS route declared in
[`appinfo/routes.php`](../../appinfo/routes.php). The collection is the
ADR-008 deliverable for end-to-end API contract coverage.

## Files

| File | Purpose |
|------|---------|
| `mydash.postman_collection.json` | Postman 2.1 collection. 28 capability folders, 154 requests covering the 131 declared routes plus error-envelope branches. |
| `local.env.json` | Postman environment template. Set `baseUrl`, `adminUser`, `adminPassword` (and optionally `memberUser` / `memberPassword`). All other variables (`fixtureDashboardId`, `fixtureTileId`, ...) are populated at runtime by the fixture-setup folder. |
| `.coverage-check.js` | Internal Node script that asserts every route in `appinfo/routes.php` has at least one matching request in the collection. Run with `node tests/integration/.coverage-check.js` — exits non-zero on missing coverage. |

## How to run

The collection assumes a Nextcloud instance with the MyDash app
installed and reachable on `{{baseUrl}}` with the configured admin
credentials. The default `local.env.json` points at
`http://localhost:8080` with `admin:admin`.

```bash
# With Newman installed globally
newman run tests/integration/mydash.postman_collection.json \
  --environment tests/integration/local.env.json

# Or via npx (recommended — no global install required)
npx --yes newman run tests/integration/mydash.postman_collection.json \
  --environment tests/integration/local.env.json

# With Newman installed via composer:
composer test:integration
```

If the env credentials are different on your local box, use Newman's
`--env-var` flags to override the defaults inline:

```bash
npx newman run tests/integration/mydash.postman_collection.json \
  --env-var baseUrl=http://nextcloud.local \
  --env-var adminUser=admin \
  --env-var adminPassword=secret \
  --env-var memberUser=regular \
  --env-var memberPassword=regular
```

## What is covered

131 routes grouped into 28 capability folders. Per-route assertions
follow this contract:

1. **At least one happy-path request per route** with status code +
   payload-shape assertions. For destructive endpoints (PUT / DELETE)
   the happy-path targets a freshly created fixture record.
2. **At least one error-envelope assertion per capability**, exercising
   either the documented 400 (validation), 403 (admin-gated), 404
   (unknown id), 401 (unauthenticated) or 409 (conflict) response.
3. Every request asserts the OCS-style error envelope (`{ "error": ... }`
   or `{ "message": ... }`) when the response code is 4xx, mirroring
   the contract in `lib/Controller/ResponseHelper.php`.
4. Soft status assertions (`pm.expect([200, 502, 503]).to.include(...)`)
   are used for upstream-network endpoints (RSS / iCal proxies) so CI
   without egress still passes the build.

### Capability folders

| Folder | Routes covered |
|---|---|
| Health + Metrics | `health#index`, `metrics#index`, `page#index` |
| Dashboards - Fixture setup | seeds `{{fixtureDashboardId}}` + `{{fixtureDashboardUuid}}` |
| Dashboards - Personal scope | list/visible/getActive/create/update/activate/tree/by-path |
| Dashboards - Publication state | publish/unpublish/schedule/fork |
| Dashboards - View event | viewEvent |
| Dashboards - Translations | list/create/update/destroy/setPrimary/resolved |
| Dashboards - Comments | index/create/update/destroy |
| Dashboards - Group scope | listGroup/createGroup/setGroupDefault/get/update/delete |
| Dashboards - Locks | acquire/heartbeat/release/get/forceRelease |
| Dashboards - Sharing | index/create/replace/destroy/searchSharees/revokeForRecipient |
| Dashboards - Reactions | add/get/remove/getByEmoji |
| Dashboards - Versions | list/create/fetch/restore |
| Dashboards - Metadata | get/set per-dashboard |
| Tiles | index/create/update/destroy |
| Widgets | listAvailable/items/news/calendar/addWidget/addTile/updatePlacement/removePlacement |
| Rules | get/add/update/delete |
| Files Widget | contents/upload/destroy |
| Files | createFile |
| Resources | upload/list/getResource |
| Feeds | getToken/regenerate/revoke/publicFeed |
| Templates | gallery/saveAsTemplate |
| People Widget | getUsers |
| Admin - Templates | list/create/preview-image/get/update/delete |
| Admin - Settings | get/update + footer-settings get/update |
| Admin - Groups | listGroups/updateGroupOrder |
| Admin - Setup Wizard | state/storage/complete |
| Admin - Roles | list/create/delete + getMyRole |
| Admin - Metadata Fields | list/create/get/update/delete |
| Admin - Analytics | top/summary/export/dashboardDetail |
| Admin - Cleanup | scan/purge |
| Admin - Org Navigation | get/update + position get/update |
| Admin - Bulk Operations | bulk-delete/move/status/reindex |
| Admin - Demo Showcases | index/install/destroy |
| Admin - Confluence Import | dry-run/import |
| Admin - Export / Import / Feeds | export/import/refresh-now |
| Admin - Forbidden for members | non-admin 403 branch tests |
| Auth - Unauthenticated rejection | no-auth 401 branch test |
| Dashboards - Fixture teardown | DELETE fixture + 404 second-delete |

## Test pollution strategy

The collection creates and deletes its own fixture dashboard
(`newman-fixture-<timestamp>`), tile, placement, comment, rule, role,
metadata field and template. The teardown folder runs at the end and
deletes the fixture even if upstream tests fail (Newman's `--bail` flag
is intentionally NOT set). Orphaned fixtures are easy to find and
remove with a `newman-fixture-*` grep on dashboard names.

## CI wiring

`.github/workflows/code-quality.yml` already passes
`enable-newman: true` to the reusable quality workflow. Any
`tests/integration/*.postman_collection.json` is picked up and run
automatically.

## When to update this collection

Every PR that changes a controller in `lib/Controller/` MUST also
update the matching request(s) in
`mydash.postman_collection.json`. The reverse is also true: if you add
a route to `appinfo/routes.php`, add a request to the appropriate
capability folder before merging. Run
`node tests/integration/.coverage-check.js` locally to verify
coverage; missing routes fail the script with a non-zero exit.
