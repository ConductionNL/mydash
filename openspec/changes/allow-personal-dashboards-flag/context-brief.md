# Allow-personal-dashboards flag — runtime gating

The existing REQ-ASET-003 declares the `allow_user_dashboards` setting but does not specify how it gates the personal-dashboard endpoints at runtime. This change adds the gating semantics: when the flag is OFF, every personal-dashboard creation/fork endpoint MUST return 403 with a specific error code so the UI can render a coherent state, and existing personal dashboards MUST remain readable.

## Placement & Information Architecture

**Placement type:** `DETAIL_TAB` — Tab on the detail view of an existing object. NOT a standalone page — appears inside the parent record's detail surface (e.g. an extra tab on the existing detail header).

**Lives at:** Beheer / Tab: Sharing & Publication

**Rationale:** Org-level policy toggle  
_Source: /tmp/ia-mydash-openregister.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Affected code units

- `lib/Controller/DashboardController.php` — every `POST /api/dashboards`, `POST /api/dashboards/{uuid}/fork`, and `POST /api/dashboards/active` (when target is personal) must check the flag
- `lib/Service/DashboardService.php` — `getAllowUserDashboards(): bool` becomes a precondition checker
- `src/views/WorkspaceApp.vue` — hide "+ New Dashboard" button when flag is off
- `src/views/AdminApp.vue` — toggle wired to `POST /api/admin/settings`
- Modifies REQ-ASET-003 (which already declares the setting)

## Why a delta to `admin-settings`

The setting itself is already declared. This change formalises:
1. The exact runtime behaviour when toggled (what 403 means)
2. The "do not auto-delete" semantics (existing personal dashboards survive, just become read-only-forking-disabled)
3. The error envelope so the frontend can localise the message

## Approach

- Modify REQ-ASET-003 to declare side effects on personal-dashboard endpoints.
- Personal dashboards already created remain visible and editable; only **creation/fork** is blocked while the flag is off.
- Surfaced in initial state as `allowUserDashboards: bool` so the frontend can render appropriate empty states / hide buttons.

## Notes

- Default value is `'0'` (off) — admins must opt in.
- Toggling off does NOT delete existing personal dashboards. It only blocks new ones. Document this clearly in the admin UI.



## Tasks

# Tasks — allow-personal-dashboards-flag

## 1. Backend

- [ ] 1.1 Add `DashboardService::assertPersonalDashboardsAllowed(): void` (throws `PersonalDashboardsDisabledException`)
- [ ] 1.2 Define `PersonalDashboardsDisabledException` mapping to HTTP 403 with `error: 'personal_dashboards_disabled'`
- [ ] 1.3 Call assert in `DashboardController::create` (when type=user) and `::fork`
- [ ] 1.4 Ensure read/update/delete endpoints do NOT call the assert
- [ ] 1.5 Update `WorkspaceController::index` to push `allowUserDashboards` initial state
- [ ] 1.6 Update admin endpoints to surface flag in their initial state too

## 2. Frontend

- [ ] 2.1 Hide "+ New Dashboard" sidebar button when `!allowUserDashboards`
- [ ] 2.2 Hide "Fork to personal" button when `!allowUserDashboards`
- [ ] 2.3 Surface 403 with `error === 'personal_dashboards_disabled'` as a localised toast
- [ ] 2.4 Document the toggle's "data is preserved" behaviour in the admin UI helper text

## 3. Tests

- [ ] 3.1 PHPUnit: 403 envelope shape exactly matches REQ-ASET-003 scenario
- [ ] 3.2 PHPUnit: existing personal dashboards remain readable/editable when flag off
- [ ] 3.3 PHPUnit: toggling does not mutate data (assert row counts before/after)
- [ ] 3.4 Playwright: button visibility matches flag state
- [ ] 3.5 Playwright: direct API call (bypassing UI) still returns 403

## 4. Quality

- [ ] 4.1 `composer check:strict` passes
- [ ] 4.2 OpenAPI updated with the 403 response variant
- [ ] 4.3 Translation file entries for `'Personal dashboards are not enabled by your administrator'`
