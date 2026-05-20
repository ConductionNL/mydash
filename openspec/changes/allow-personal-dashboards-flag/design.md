# Design — allow-personal-dashboards-flag

## Context

REQ-ASET-003 (archived `admin-settings` change) already declares the `allow_user_dashboards`
setting and stores it in `oc_mydash_admin_settings`. What it does NOT specify is what happens
at the HTTP layer when that flag is `'0'`: the old REQ-ASET-003 only calls
`PermissionService::canCreateDashboard()` and returns a generic 403 with no machine-readable
error code. This leaves the frontend unable to distinguish "personal dashboards disabled by
admin" from "generic authorization failure", which means it cannot localise the message,
hide affordances proactively, or suppress toast noise when the state is known in advance.

This change formalises three things that REQ-ASET-003 left implicit:

1. **The exact 403 envelope** — `{status: 'error', error: 'personal_dashboards_disabled',
   message: '...'}` — so the frontend has a stable machine-readable signal.
2. **The non-destructive toggle contract** — toggling the flag MUST NOT mutate any dashboard
   rows; only the creation/fork paths are blocked.
3. **The initial-state mirror** — `allowUserDashboards: bool` is pushed into every workspace
   and admin page render so the frontend can hide buttons before the user attempts the action.

## Decisions

### D1: Guard lives in `DashboardService`, not in `DashboardController`

The check is expressed as `DashboardService::assertPersonalDashboardsAllowed(): void`, which
throws `PersonalDashboardsDisabledException` when the flag is off. The controller catches the
exception and translates it to 403.

**Why the service layer:** ADR-003 mandates thin controllers (<10 lines per method). Business
preconditions (like "feature flag must be on") are business logic, not routing logic. Keeping
the assert in the service means:

- The guard is testable in isolation from the HTTP layer.
- Any future non-HTTP entry-point (CLI, cron, event listener) that calls
  `DashboardService::create()` inherits the gate automatically.
- The controller remains a thin dispatcher.

**Why not middleware / request filters:** The flag is not a global access gate — it applies
only to personal-dashboard creation, not to reads or group-shared endpoints. A middleware
would fire on every request and require request-context inspection that belongs in the domain
layer anyway.

### D2: Introduce `PersonalDashboardsDisabledException` — dedicated exception, not a generic 403

A dedicated exception class (`lib/Exception/PersonalDashboardsDisabledException.php`)
maps exactly to `{status: 'error', error: 'personal_dashboards_disabled', message: <i18n>}`.

**Why not reuse a generic OCS exception:** The machine-readable `error` field is the
contract the frontend depends on. A generic `OCSForbiddenException` carries only `message`,
which varies by locale and cannot be tested as a stable API contract. Locking the `error`
field to `personal_dashboards_disabled` lets:

- PHPUnit assert the exact envelope shape (REQ-ASET-003 scenario "Flag off blocks personal
  dashboard creation").
- Frontend JavaScript test for `error === 'personal_dashboards_disabled'` rather than
  parsing the human-readable message, which would break on translation.

**Exception → HTTP mapping:** `DashboardController` catches
`PersonalDashboardsDisabledException` and returns `new JSONResponse(['status' => 'error',
'error' => 'personal_dashboards_disabled', 'message' => $this->l->t('Personal dashboards
are not enabled by your administrator')], Http::STATUS_FORBIDDEN)`.

### D3: Read / update / delete endpoints do NOT call the assert

`GET /api/dashboards/visible`, `GET /api/dashboards/{uuid}`, `PUT /api/dashboards/{uuid}`,
`DELETE /api/dashboards/{uuid}`, `POST /api/dashboards/active`, and all group-shared /
admin-template endpoints are not affected by the flag. Only the two creation paths check it:

- `POST /api/dashboards` when `type` is `user` (or omitted, which defaults to `user`)
- `POST /api/dashboards/{uuid}/fork` (fork target is always `type=user`)

**Rationale:** Toggling the flag is a forward-only gate: admins who turn it off should not
silently break users who already have personal dashboards. Existing dashboards retain full
read / edit / delete access. This is consistent with REQ-ASET-009 ("Settings Impact on
Existing Data") which states that admin settings changes MUST NOT retroactively modify
existing dashboards.

**Explicit list of endpoints that MUST NOT check the flag** is recorded in
`specs/admin-settings/spec.md` REQ-ASET-003 to prevent accidental over-gating in future
contributors.

### D4: Initial state push — `allowUserDashboards: bool` in every workspace and admin render

`WorkspaceController::index()` and the admin page controller MUST call
`$this->initialStateService->provideInitialState('allowUserDashboards',
$this->adminSettingsService->getAllowUserDashboards())`.

The frontend reads this via `loadState('mydash', 'allowUserDashboards', false)` and passes
it through the component tree (or Pinia settings store). The default is `false` — if the key
is absent (e.g. during a cold first render before the service is ready), the frontend is
conservative: it hides creation affordances rather than showing a 403 mid-flow.

**Why initial state rather than a dedicated API call:** An extra `GET /api/admin/settings`
on every workspace load would add latency and a waterfall. Initial state is a zero-latency
data injection already used for other workspace configuration. It also eliminates a flash of
the "+ New Dashboard" button on load (render → fetch → hide) that would otherwise be visible
on slow connections.

### D5: Defense in depth — both UI and API enforce the flag

The API check (D1/D2) and the UI check (D4) are independent layers. This is intentional:

- The UI check prevents a bad user experience (clicking a button that immediately 403s).
- The API check prevents direct API calls (curl, Postman, another app) from bypassing the UI.

REQ-ASET-015 scenario "Frontend honours the flag" (from `specs/admin-settings/spec.md`)
explicitly requires a Playwright test that issues a direct API call bypassing the UI and
still receives 403. This ensures neither layer can be removed without breaking the spec.

### D6: Default value is `'0'` (off) — admins must opt in

The factory default for `allow_user_dashboards` was already set to `'0'` (off) in the
original admin-settings change. This change codifies in REQ-ASET-003 that the **absence**
of a DB row (fresh install, no row in `oc_mydash_admin_settings`) evaluates to `false`.
`AdminSettingsService::getAllowUserDashboards()` returns `false` when the key is missing
rather than assuming true. The rationale is security-by-default: a new MyDash install does
not expose personal dashboard creation unless the admin explicitly enables it.

### D7: Admin UI helper text must document data preservation

The `AdminApp.vue` toggle for `allowUserDashboards` MUST include a sub-label such as:
"When disabled, existing personal dashboards remain visible and editable — no data is lost."
This prevents admins from incorrectly inferring that toggling the flag off will clean up
user data, and avoids support requests after a surprise data-loss assumption.

## Reuse Analysis

This change adds no new service classes, no new entities, and no new mappers.

| Need | Reused component | Notes |
|---|---|---|
| Read `allow_user_dashboards` setting | `AdminSettingsService::getAllowUserDashboards()` (already exists) | No change to the service read path |
| Store / retrieve setting toggle | `AdminSettingMapper` + `oc_mydash_admin_settings` table | Already present from `admin-settings` change |
| Push initial state | `IInitialStateService::provideInitialState()` | Platform-provided; already used in `WorkspaceController` for other keys |
| Error response shaping | `JSONResponse` + `Http::STATUS_FORBIDDEN` | Standard Nextcloud pattern; no custom response class |
| i18n | `IL10N::t()` injected in controller | Standard Nextcloud pattern per ADR-007 |
| Frontend flag read | `loadState('mydash', 'allowUserDashboards', false)` from `@nextcloud/initial-state` | Already used for other initial state keys in `WorkspaceApp.vue` |
| Toast notification | NC / ncvue toast primitive | No custom toast component |

**No OpenRegister interaction:** This change modifies settings and controller/service logic.
`allow_user_dashboards` lives in `oc_mydash_admin_settings` (a custom key-value table), not
in an OpenRegister register. No OpenRegister services (ObjectService, SchemaService, etc.)
are involved.

## Seed Data

Not required. This change introduces no new schemas or entities. The `oc_mydash_admin_settings`
table is a settings store, not domain data, and is not seeded via OpenRegister. Per ADR-001
(Seed Data — Exceptions): "Changes that only modify frontend components or non-schema backend
logic (e.g., settings, permissions) do not require seed data."

## Spec sizing (ADR-032)

`kind: code` — the change centre-of-mass is PHP and Vue:

- `lib/Exception/PersonalDashboardsDisabledException.php` — new PHP class
- `lib/Service/DashboardService.php` — new `assertPersonalDashboardsAllowed()` method
- `lib/Controller/DashboardController.php` — assert call-sites + exception catch
- `lib/Controller/WorkspaceController.php` — initial state push
- `src/views/WorkspaceApp.vue` — conditional button rendering
- `src/views/AdminApp.vue` — helper text on the toggle

No chain is needed. The declarative surface (`oc_mydash_admin_settings` row) already exists.
This spec adds only the runtime behavioural layer on top of it — a single `kind: code`
envelope per ADR-032's "pure code" case.

## Alternatives considered

### Alternative A: Guard in middleware rather than service

Rejected. The flag is not a universal access gate; it applies only to two specific controller
actions. Middleware fires on every request and introduces request-context inspection that
belongs in the domain layer. Service-level assertions are also more testable and
self-documenting.

### Alternative B: Reuse `OCSForbiddenException` instead of a dedicated exception

Rejected. `OCSForbiddenException` carries only a human-readable `message` field. The
frontend needs a stable machine-readable `error` key (`personal_dashboards_disabled`) to
distinguish this case from other 403 responses (IDOR, expired session, insufficient
permission level). Without it, the frontend either parses the message string (fragile,
breaks on translation) or cannot distinguish the states at all.

### Alternative C: Push `allowUserDashboards` only when flag is false

Rejected. Always pushing the value as initial state means the frontend has a definitive
answer on every render, regardless of direction. Omitting it when true would require the
frontend to treat absence as "true", which adds an implicit default assumption that is
error-prone to maintain.

### Alternative D: Delete existing personal dashboards on toggle-off

Explicitly out of scope. REQ-ASET-009 (admin settings change) states that settings changes
MUST NOT retroactively modify existing data. The toggle is a forward gate. Deleting data on
toggle-off would be a destructive side effect that no other admin setting in the codebase
exhibits, and it would surprise users who temporarily disable the feature for onboarding
and later re-enable it.

## Open follow-ups

- Whether to surface a per-user count of existing personal dashboards in the admin UI toggle
  helper text (e.g. "12 users have existing personal dashboards — these will remain intact").
  Deferred: requires a dashboard-count query in the admin settings load path; not needed for
  correctness.
- Whether `POST /api/dashboards` with explicit `type='admin_template'` or `type='group_shared'`
  should also check the flag. Current decision: no — the flag is named `allow_user_dashboards`,
  and those types are not user-owned personal dashboards. Admin and group-shared creation paths
  are unaffected.
