# Allow-personal-dashboards flag — runtime gating

The existing REQ-ASET-003 declares the `allow_user_dashboards` setting but does not specify how it gates the personal-dashboard endpoints at runtime. This change adds the gating semantics: when the flag is OFF, every personal-dashboard creation/fork endpoint MUST return 403 with a specific error code so the UI can render a coherent state, and existing personal dashboards MUST remain readable.

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
