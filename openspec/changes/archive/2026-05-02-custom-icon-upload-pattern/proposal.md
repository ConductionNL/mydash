# Custom-icon upload pattern

## Why

MyDash currently lets administrators and users pick a dashboard or tile icon only from a fixed registry of built-in MDI components (per `dashboard-icons`). Real organisations want to brand dashboards with their own logos, departmental icons, or scenario-specific imagery — none of which fit the curated registry. We could split the storage into two columns (`iconName` + `iconUrl`) and a discriminator field, but that triples the surface area of every read path, breaks existing `icon`-aware code, and forces a data migration. Instead we extend the existing `icon` field to accept either a built-in name OR a resource URL, with a single runtime discriminator and a single dual-mode render component. This change formalises the field-format convention, the picker UX, and the renderer contract — the actual upload endpoint is owned by the parallel `resource-uploads` capability.

## What Changes

- Add a pure `isCustomIconUrl(name)` discriminator to `src/constants/dashboardIcons.js` that returns `true` when the value starts with `/` or `http`.
- Update `getIconComponent(name)` to return `null` for URL inputs, signalling to callers that they must render an `<img>` instead of a `<component>`.
- Introduce a shared `IconRenderer` Vue component that branches internally so consumers stop duplicating the if/else.
- Introduce an `IconPicker` Vue component that surfaces a registry `<select>` and a file-upload input side-by-side, both writing to the same `v-model` and previewing via `IconRenderer`.
- Refactor `DashboardSwitcher`, the admin dashboard CRUD UI, the link-button widget icon, and the tile editor to use `IconRenderer` and `IconPicker` instead of inline branching.
- Document the single-column convention in the `Dashboard` and `WidgetPlacement` entity docblocks. No database schema change.

## Capabilities

### New Capabilities

(none — this change extends the existing `dashboard-icons` capability)

### Modified Capabilities

- `dashboard-icons`: adds REQ-ICON-005 (URL/name discriminator), REQ-ICON-006 (`getIconComponent` returns null for URLs), REQ-ICON-007 (`IconRenderer` dual-mode), REQ-ICON-008 (`IconPicker` dual-input UX), REQ-ICON-009 (single-column field convention). Existing REQ-ICON-001..004 are untouched.

## Impact

**Affected code:**

- `src/constants/dashboardIcons.js` — add `isCustomIconUrl`, update `getIconComponent` URL handling
- `src/components/Dashboard/IconRenderer.vue` — new shared dual-mode renderer
- `src/components/Dashboard/IconPicker.vue` — new combined select-plus-upload picker with live preview
- `src/components/DashboardSwitcher.vue`, admin dashboard CRUD UI, link-button widget icon component, tile editor — refactored to use `IconRenderer` / `IconPicker`
- `lib/Db/Dashboard.php`, `lib/Db/WidgetPlacement.php` — update `icon` / `tileIcon` field docblocks to document the dual-format convention

**Affected APIs:**

- No new backend routes (this change relies on the upload endpoint owned by `resource-uploads`)
- No change to existing `/api/dashboards` or widget endpoints — the `icon` field already exists and is opaque to the backend

**Dependencies:**

- Depends on the parallel `resource-uploads` change for the upload endpoint and resource URL format (`/apps/mydash/resource/{id}`)
- No new composer or npm dependencies

**Migration:**

- Zero-impact: no schema change. Existing rows with built-in icon names continue to render unchanged. New rows may store either a name or a URL in the same column.
- No data backfill required.

## Notes

- The `'/'` and `'http'` prefix heuristic is fragile against future built-in registry entries that happen to start with `/`. The registry today contains none; future additions MUST be validated against the discriminator.
- File upload size and MIME validation are owned by `resource-uploads` — `IconPicker` only forwards the file and reads the returned URL.
- Switching a dashboard from a custom URL back to a built-in name does NOT delete the previously uploaded resource. Resource lifecycle (orphan cleanup) is owned by `resource-uploads`.
