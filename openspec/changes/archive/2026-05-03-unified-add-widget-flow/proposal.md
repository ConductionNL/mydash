# Unified add-widget flow

## Why

Today there are three separate "add something to a dashboard" affordances:

1. **Add Widget** (toolbar / gear menu) — picks a Nextcloud-discovered widget and places it. Backed by REQ-WDG-002 / REQ-WDG-010.
2. **Add Tile** (toolbar / gear menu) — opens a tile creation modal that writes to `oc_mydash_tiles` (REQ-TILE-001), then places a tile placement copying the tile's data inline.
3. **Add custom widget** (gear menu) — opens the `AddWidgetModal` (REQ-WDG-010) which lets the user pick a registry-driven type (label / text / image / link / etc.) and submit content.

Three entry points for one conceptual action. Tiles in particular have a two-step model (create tile entity → place tile) that confuses users and duplicates the placement-as-snapshot pattern: tile placements already store their data inline in `oc_mydash_widget_placements`, so the `oc_mydash_tiles` reusable-entity table is mostly redundant for the UX while still incurring schema + service complexity.

This change collapses all three flows into one — **Add custom widget** — by:

- Adding `tile` as a registry-driven widget type (renderer + form + registry entry, just like label/text/image)
- Adding `nc-widget` already exists as a registry type from `nc-dashboard-widget-proxy` — surfaces it in the unified picker
- Removing the standalone "Add Tile" and "Add Widget" entry points
- Deprecating the `oc_mydash_tiles` table for new tiles (existing rows preserved for backwards compat; per-placement inline content is the new source of truth)

Custom tiles are **still creatable** — they just appear as a widget type in the unified picker, with a form that collects icon + link + colors + label, identical to today's tile-creation form fields.

## What Changes

- **Add `tile` widget type** to `src/constants/widgetRegistry.js`:
  - Renderer: `TileWidget.vue` (renders the existing tile placement card — icon/title/colors, click → link). Reuses styling from the existing tile placement renderer.
  - Form: `TileForm.vue` — same six fields the current tile-creation modal collects (title, icon + iconType discriminator, backgroundColor, textColor, linkType {`app`|`url`}, linkValue).
  - Default content: `{title:'', icon:'', iconType:'class', backgroundColor:'#3b82f6', textColor:'#ffffff', linkType:'app', linkValue:''}`.
  - Display name: `t('mydash', 'Tile')`. Icon: a generic tile icon (e.g., `ViewGrid` from MDI).
- **Remove `Add Tile` and `Add Widget` menu entries** (covered by `runtime-shell-trim` in the gear menu; this change confirms the unified flow is the only path).
- **Deprecate the `oc_mydash_tiles` reusable-entity table:**
  - The table stays in the schema for backwards compat (existing rows readable) — no destructive migration.
  - `TileService::createTile`, `updateTile`, `deleteTile` are marked `@deprecated`. New code MUST NOT write new `oc_mydash_tiles` rows.
  - The "create tile from existing template" flow is removed — no UI lets a user pick from existing `oc_mydash_tiles` rows.
  - Migration path: a follow-up `tile-table-removal` change (out of scope here) MAY drop the table once consumers confirm no business-critical use.
- **Tile placements continue to render correctly.** They already store data inline in `oc_mydash_widget_placements` (per the existing `tiles` capability spec), so nothing breaks. The `tile` widget type's renderer reads the same `widgetPlacement.content` shape (with the existing `tileTitle` / `tileIcon` etc. fields aliased into `content.{title, icon, ...}` for consistency with other widget types).
- **REQ-TILE-001..N (existing tiles capability) are MODIFIED**: tile-as-reusable-entity creation flows are deprecated; placement-data-as-source-of-truth is documented as the long-term model.

## Capabilities

### Modified Capabilities

- `tiles` — the reusable-entity model (REQ-TILE-001 Create Custom Tile, REQ-TILE-002 update, REQ-TILE-003 delete) is deprecated in favour of inline-content tile placements created via the unified add-widget flow. The placement model (existing) becomes the canonical model.
- `widgets` — registry now includes `tile` as a built-in widget type alongside label/text/image/link/nc-widget.
- `widget-add-edit-modal` — picker MUST include the `tile` type entry. Tile sub-form added to the per-type sub-form list.

### Added Capabilities

(none — `tile` widget type is added inside the existing `widgets` registry, not as a new capability)

## Impact

**Affected code:**

- `src/components/Widgets/Renderers/TileWidget.vue` — new renderer (or rename + relocate the existing tile placement renderer)
- `src/components/Widgets/Forms/TileForm.vue` — new sub-form (lift fields from existing tile-creation modal)
- `src/constants/widgetRegistry.js` — register `tile` type
- `src/components/admin/DashboardConfigMenu.vue` — remove "Add tile…" + "Add widget…" menu entries (also covered by `runtime-shell-trim`)
- `src/components/AddTileModal.vue` (or equivalent) — DELETED (functionality moved into TileForm + AddWidgetModal)
- `lib/Service/TileService.php` — `createTile`/`updateTile`/`deleteTile` marked `@deprecated`; class kept for read paths and existing-row support
- `lib/Controller/TileController.php` — controller endpoints marked deprecated; reads still work; writes return HTTP 410 Gone with a payload pointing at `POST /api/dashboards/{uuid}/widgets` (the standard widget placement endpoint)

**Affected APIs:**

- `POST /api/tiles` and `PUT /api/tiles/{id}` and `DELETE /api/tiles/{id}` MUST return HTTP 410 Gone with `{status: 'gone', message: '...', replacement: 'POST /api/dashboards/{uuid}/widgets with type:tile'}`. Existing rows remain readable via `GET /api/tiles` for migration tooling.
- No new endpoints for the tile widget type — placements use the existing widget placement endpoints.

**Dependencies:**

- Soft-depends on `runtime-shell-trim` for the menu removal (both changes can land independently in either order).

**Migration:**

- No destructive schema migration in this change. `oc_mydash_tiles` data is preserved as-is.
- Existing tile placements continue to render via `TileWidget.vue` reading `placement.content.{title, icon, ...}`. The renderer MUST handle BOTH the legacy `placement.tileTitle / tileIcon / ...` field shape AND the new `placement.content.{title, icon, ...}` shape for forward compat.
- Future cleanup change (out of scope): drop `oc_mydash_tiles` table after a release-cycle of read-deprecation; rewrite tile placements to use the new `content` shape exclusively.

## Notes — what users gain and lose

Gain:
- One add-affordance instead of three
- Tile is now a normal widget type (consistent UX, same modal, same close discipline, same validation pipeline)
- Removes the "create tile / use existing tile" cognitive split
- Sets up nesting (`container-widget` capability) to work uniformly — containers can hold tiles like any other widget

Lose:
- Reusable tile templates (the "create once, place many" pattern). Users who want this MAY copy a tile placement to multiple dashboards manually. A future `widget-templates` capability could re-add reusability across all widget types if demand surfaces.
