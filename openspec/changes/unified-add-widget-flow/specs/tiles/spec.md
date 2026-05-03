# Spec delta — tiles

## MODIFIED Requirements

### Requirement: REQ-TILE-001 Reusable tile entity model — DEPRECATED

The reusable tile entity model (rows in `oc_mydash_tiles`, accessed via `TileService::createTile / updateTile / deleteTile` and `POST/PUT/DELETE /api/tiles[/{id}]`) MUST be treated as deprecated as of this change. The unified add-widget flow (REQ-WDG-018) introduces `tile` as a registry-driven widget type that stores its data inline on each placement, removing the need for a separate reusable-entity table; existing rows MUST remain readable for backwards compatibility.

The following behaviour MUST hold during the deprecation window:

1. `POST /api/tiles`, `PUT /api/tiles/{id}`, and `DELETE /api/tiles/{id}` MUST return HTTP 410 Gone with envelope `{status: 'gone', message: '<localised>', replacement: 'POST /api/dashboards/{uuid}/widgets with type:tile'}`.
2. `GET /api/tiles` and `GET /api/tiles/{id}` MUST continue to return existing rows for backwards compatibility (read-only — admin tooling, migration scripts).
3. The `oc_mydash_tiles` table MUST remain in the schema. No destructive migration. A future `tile-table-removal` change MAY drop it after at least one full release of read-deprecation.
4. Existing tile placements (rows in `oc_mydash_widget_placements` with the legacy inline `tileTitle`/`tileIcon`/`tileIconType`/`tileBackgroundColor`/`tileTextColor`/`tileLinkType`/`tileLinkValue` fields) MUST continue to render. The new `TileWidget` renderer MUST handle the legacy field shape AND the new `placement.content.{title, icon, ...}` shape.

#### Scenario: Write endpoints return 410 Gone

- **GIVEN** an admin user authenticated to mydash
- **WHEN** they `POST /api/tiles` with any payload
- **THEN** the response MUST be HTTP 410 Gone
- **AND** the body MUST include `status: 'gone'`, a localised `message`, and `replacement: 'POST /api/dashboards/{uuid}/widgets with type:tile'`
- **AND** no row MUST be inserted into `oc_mydash_tiles`

#### Scenario: Read endpoints still serve existing rows

- **GIVEN** the database contains a tile row created prior to this change
- **WHEN** an authenticated user calls `GET /api/tiles`
- **THEN** the response MUST be HTTP 200 with the row's fields in the documented shape
- **AND** this MUST work as long as the `oc_mydash_tiles` table exists in the schema

#### Scenario: Existing tile placements still render

- **GIVEN** a dashboard with a tile placement created prior to this change (legacy field shape on the placement row)
- **WHEN** the dashboard renders via the merged `TileWidget` (REQ-WDG-018)
- **THEN** the tile MUST display with title + icon + colours + click-through correctly
- **AND** no console errors MUST occur

### Requirement: REQ-TILE-PLACEMENT Inline-content placement model — promoted to canonical

Tile placements (rows in `oc_mydash_widget_placements` with `tileType: 'custom'` historically, or `widgetId: 'tile'` going forward) MUST store their data INLINE on the placement. This MUST be treated as the canonical model for tile data on dashboards going forward.

New tile placements created via the unified add-widget flow (REQ-WDG-010 + REQ-WDG-018) MUST store their data in `placement.content.{title, icon, iconType, backgroundColor, textColor, linkType, linkValue}` — the standard widget-content shape. Legacy placements with the flat `placement.tileTitle / tileIcon / ...` shape MUST continue to work via the renderer's dual-shape support (REQ-WDG-018 scenario 3).

A future migration MAY rewrite legacy placements into the new content shape, but this is OUT OF SCOPE for the unified-add-widget-flow change.

#### Scenario: New tile placement uses standard content shape

- **GIVEN** a user opens the unified Add Custom Widget modal and picks "Tile"
- **WHEN** they fill the form and save
- **THEN** the resulting `oc_mydash_widget_placements` row MUST have its data in `content` (JSON column)
- **AND** the legacy flat `tileTitle`/`tileIcon`/etc. columns MUST NOT be populated for new placements
