# Design — Newman integration collection

## Shape

One Postman 2.1 collection at
`tests/integration/mydash.postman_collection.json`. Top-level folders:

- `Health + Metrics`
- `Dashboards`
- `Tiles`
- `Widgets`
- `Rules`
- `Admin`

Each folder contains requests hitting one controller. Per-request
assertions live in `event[listen=test].script.exec[]`.

## Auth

HTTP basic over the OCS API. Collection-level `auth` uses
`{{admin_user}}` / `{{admin_password}}` variables. Requests that need
a non-admin caller override auth inline with `{{member_user}}` /
`{{member_password}}`.

Required OCS headers on every request:

```
OCS-APIRequest: true
Accept: application/json
```

## Test pollution strategy

The collection creates + tears down its own fixture dashboard. Order:

1. `Dashboards / Fixture setup` — `POST /api/dashboard` with a
   recognisable name (`newman-fixture-<timestamp>`). Captures the new
   dashboard id in a collection variable (`{{fixture_dashboard_id}}`).
2. All write tests target `{{fixture_dashboard_id}}` — never a pre-
   existing dashboard.
3. `Dashboards / Fixture teardown` — `DELETE /api/dashboard/{{fixture_dashboard_id}}`
   at the end of the folder.

If a test fails mid-run, the teardown still runs (Newman's `—
--bail` is NOT set). The fixture's timestamped name makes orphaned
fixtures easy to find with `newman-fixture-*` grep.

## Environment

`tests/integration/README.md` documents local run:

```bash
npm install -g newman
newman run tests/integration/mydash.postman_collection.json \
  --env-var base_url=http://nextcloud.local \
  --env-var admin_user=admin \
  --env-var admin_password=admin \
  --env-var member_user=regular \
  --env-var member_password=regular
```

CI passes `base_url=http://localhost:8080`.

## Assertion shape

Each request gets ≥ 2 assertions. Minimal pattern:

```javascript
pm.test('200 OK', () => pm.response.to.have.status(200));
pm.test('OCS envelope', () => {
    const json = pm.response.json();
    pm.expect(json).to.have.nested.property('ocs.meta.status');
    pm.expect(json.ocs.meta.status).to.equal('ok');
});
```

Forbidden / admin-gated endpoints get the mirror:

```javascript
pm.test('403 Forbidden for non-admin', () => pm.response.to.have.status(403));
pm.test('Error envelope', () => {
    const json = pm.response.json();
    pm.expect(json).to.have.property('error');
});
```

## CI wiring

`.github/workflows/code-quality.yml` already sets `enable-newman: true`.
The reusable quality workflow picks up any
`tests/integration/*.postman_collection.json` automatically. Soft
failures on network-dependent calls (app-store proxy) use
`pm.expect([200, 502, 503]).to.include(pm.response.code)` so CI
without egress passes the build.

## Risk

- **Tile / Widget / Rule shape drift**: the collection assertions
  match the payload shape as of 2026-04-24. If a request/response
  model changes (e.g., additional fields), the shape tests need to
  be updated alongside the code change. Each change proposal that
  modifies a controller should update this collection.
- **Admin role in CI**: the reusable workflow provisions an admin
  user by default. The member-level tests need a second user
  provisioned — design.md for the workflow may need an extension
  if this capability isn't already there.
