# Fork-current-layout to personal dashboard

When a user is viewing a `group_shared` (or `default`) dashboard and wants to customise it, MyDash MUST allow them to one-click "fork" the current layout into a brand-new personal dashboard. The original group-shared dashboard is untouched; the fork becomes the user's active dashboard immediately.

## Affected code units

- `lib/Service/DashboardService.php` — `forkAsPersonal(string $userId, string $sourceUuid, string $name): Dashboard` deep-copies the source's `widget_placements` rows under a new dashboard UUID
- `lib/Db/WidgetPlacementMapper.php` — `findByDashboardId($id)` already exists; needs a `cloneToDashboard($sourceId, $targetId)` helper
- `lib/Controller/DashboardController.php` — `POST /api/dashboards/{uuid}/fork` action
- `src/components/DashboardSwitcherSidebar.vue` — "+ New Dashboard" button calls fork using current layout
- Builds on `multi-scope-dashboards` and `allow-personal-dashboards-flag`

## Why a delta

Forking is a creation operation but with non-trivial extra semantics (deep-copy placements, become-active side effect, gating on `allow_user_dashboards` admin setting). Worth a dedicated REQ rather than overloading REQ-DASH-001.

## Approach

- Single transactional service method: insert dashboard row → bulk-insert cloned placements → set as active.
- Default name uses `t('My copy of {name}', source.name)` to make provenance obvious; user can rename later via REQ-DASH-004.
- Gated on app setting `allow_user_dashboards = '1'`; otherwise 403.
- Source can be ANY dashboard the user can read (user/group/default) — including their own personal dashboards (effectively duplicate).
- Does NOT carry over `isDefault` (always 0 on fork) or `groupId` (always null — fork is personal).

## Notes

- Resource references inside placements (e.g. uploaded image URLs) are NOT duplicated — both dashboards reference the same `/apps/mydash/resource/...` URL. Resource lifecycle is the resource-uploads change's concern.
- Tile fields on placements ARE copied (tileTitle, tileIcon, etc.) so a fork is a true visual clone.
