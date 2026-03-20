---
status: reviewed
---

# Custom Tiles Specification

## Purpose

Custom tiles are user-created shortcut cards that provide quick access to Nextcloud apps or external URLs. Unlike widgets (which render dynamic content from Nextcloud apps), tiles are simple, static cards with an icon, label, and link. Tiles are first created as reusable entities in the `oc_mydash_tiles` table, then placed onto dashboards via a special tile placement mechanism that stores tile data inline on the placement. This inline-copy model means tile placements are independent snapshots -- changes to the tile definition do NOT propagate to existing placements.

## Data Model

### Tiles (oc_mydash_tiles)
- **id**: Auto-increment integer primary key
- **userId**: Nextcloud user ID of the tile creator (STRING, NOT NULL)
- **title**: Display label for the tile (STRING)
- **icon**: Icon reference -- can be an emoji character, a CSS class name (e.g., `icon-folder`), or a URL to an image (STRING, up to 2000 chars for SVG paths)
- **iconType**: Type of icon: `class`, `url`, `emoji`, or `svg` (STRING)
- **backgroundColor**: Hex color for the tile background (e.g., `#3b82f6`) (STRING)
- **textColor**: Hex color for the tile text (e.g., `#ffffff`) (STRING)
- **linkType**: Either `app` (links to a Nextcloud app route) or `url` (links to an external URL) (STRING)
- **linkValue**: The actual link target (e.g., `/apps/files` or `https://example.com`) (STRING)
- **createdAt**: Timestamp string (Y-m-d H:i:s)
- **updatedAt**: Timestamp string (Y-m-d H:i:s)

NOTE: Tiles do NOT have a UUID field. They are identified by their auto-increment integer `id`.

### Tile Placements
Tiles are placed on dashboards using the same `oc_mydash_widget_placements` table. The tile data is stored INLINE on the placement (not as a foreign key reference):
- `widgetId` is set to `'tile-' + uniqid()` (NOT null -- the DB column is NOT NULL)
- `tileType` is set to `'custom'`
- `tileTitle`, `tileIcon`, `tileIconType`, `tileBackgroundColor`, `tileTextColor`, `tileLinkType`, `tileLinkValue` are copied from the tile data

This means tile placements store a COPY of the tile configuration at creation time, NOT a reference to the `oc_mydash_tiles` record. Changes to a tile definition in `oc_mydash_tiles` do NOT automatically propagate to existing tile placements.

## Requirements

### REQ-TILE-001: Create Custom Tile

Users MUST be able to create reusable custom tile definitions.

#### Scenario: Create a tile linking to a Nextcloud app
- GIVEN a logged-in user "alice"
- WHEN she sends POST /api/tiles with body:
  ```json
  {
    "title": "My Files",
    "icon": "icon-folder",
    "iconType": "class",
    "backgroundColor": "#3b82f6",
    "textColor": "#ffffff",
    "linkType": "app",
    "linkValue": "/apps/files"
  }
  ```
- THEN the system MUST create a tile with an auto-increment integer ID (no UUID)
- AND `userId` MUST be set to "alice"
- AND the response MUST return HTTP 201 with the full tile object

#### Scenario: Create a tile linking to an external URL
- GIVEN a logged-in user "alice"
- WHEN she sends POST /api/tiles with body:
  ```json
  {
    "title": "Company Wiki",
    "icon": "https://wiki.example.com/favicon.ico",
    "iconType": "url",
    "backgroundColor": "#10b981",
    "textColor": "#ffffff",
    "linkType": "url",
    "linkValue": "https://wiki.example.com"
  }
  ```
- THEN the system MUST create the tile with `linkType: "url"`
- AND the icon MUST be stored as a URL reference

#### Scenario: Create a tile with an emoji icon
- GIVEN a logged-in user "alice"
- WHEN she sends POST /api/tiles with body:
  ```json
  {"title": "Calendar", "icon": "\ud83d\udcc5", "iconType": "emoji", "linkType": "app", "linkValue": "/apps/calendar"}
  ```
- THEN the system MUST store the emoji character as the icon value
- AND the frontend MUST render the emoji directly as the tile icon

#### Scenario: Create a tile with SVG path icon
- GIVEN a logged-in user "alice"
- WHEN she sends POST /api/tiles with `iconType: "svg"` and `icon: "M12 2L2 7v10l10 5 10-5V7z"`
- THEN the system MUST store the SVG path data as the icon value
- AND the frontend MUST render the path inside an SVG element

#### Scenario: Create a tile with missing required fields
- GIVEN a logged-in user
- WHEN they send POST /api/tiles with body `{"title": "Incomplete"}`
- THEN the system MUST create the tile with default values: `iconType: 'class'`, `backgroundColor: '#0082c9'`, `textColor: '#ffffff'`, `linkType: 'url'`, `linkValue: '#'`
- NOTE: The current implementation does NOT validate required fields. All fields have defaults.

#### Scenario: Create a tile with invalid link_type
- GIVEN a logged-in user
- WHEN they send POST /api/tiles with body `{"title": "Bad", "linkType": "ftp", "linkValue": "ftp://server"}`
- THEN the system SHOULD return HTTP 400 with an error indicating that `linkType` must be either "app" or "url"
- NOTE: Link type validation is NOT currently implemented -- any string value is accepted

### REQ-TILE-002: List User Tiles

Users MUST be able to retrieve all their custom tile definitions, scoped to their user ID.

#### Scenario: List tiles for a user
- GIVEN user "alice" has 5 custom tiles
- WHEN she sends GET /api/tiles
- THEN the system MUST return HTTP 200 with an array of all 5 tiles
- AND each tile MUST include: id, userId, title, icon, iconType, backgroundColor, textColor, linkType, linkValue, createdAt, updatedAt

#### Scenario: Tiles are user-scoped
- GIVEN user "alice" has 5 tiles and user "bob" has 3 tiles
- WHEN "alice" sends GET /api/tiles
- THEN the response MUST contain only alice's 5 tiles
- AND bob's tiles MUST NOT be included

#### Scenario: List tiles when none exist
- GIVEN user "carol" has no custom tiles
- WHEN she sends GET /api/tiles
- THEN the system MUST return HTTP 200 with an empty array

### REQ-TILE-003: Update Custom Tile

Users MUST be able to update the properties of their custom tiles with ownership verification.

#### Scenario: Update tile title and colors
- GIVEN user "alice" has tile id 3 with title "My Files"
- WHEN she sends PUT /api/tiles/3 with body:
  ```json
  {"title": "Documents", "backgroundColor": "#6366f1", "textColor": "#ffffff"}
  ```
- THEN the system MUST update the title and colors
- AND the response MUST return HTTP 200 with the updated tile object

#### Scenario: Update tile link
- GIVEN user "alice" has tile id 3 with `linkType: "app"` and `linkValue: "/apps/files"`
- WHEN she sends PUT /api/tiles/3 with body `{"linkType": "url", "linkValue": "https://docs.example.com"}`
- THEN the system MUST update both linkType and linkValue
- AND the tile MUST now link to the external URL

#### Scenario: Update another user's tile
- GIVEN tile id 3 belongs to user "alice"
- WHEN user "bob" sends PUT /api/tiles/3
- THEN the system MUST return HTTP 403 (via `TileMapper::findByIdAndUser()` ownership check)
- AND the tile MUST NOT be modified

#### Scenario: Update tile does NOT reflect on existing placements
- GIVEN tile id 3 has been placed on 2 of alice's dashboards (tile data was copied inline to placements at creation time)
- WHEN she updates the tile's title from "My Files" to "Documents" via PUT /api/tiles/3
- THEN the tile definition in `oc_mydash_tiles` MUST be updated
- BUT existing placements MUST NOT be affected (they store a copy of the tile data, not a reference)

#### Scenario: Partial update preserves unspecified fields
- GIVEN tile id 3 with all fields populated
- WHEN the user sends PUT /api/tiles/3 with body `{"title": "New Title"}`
- THEN only `title` MUST be updated
- AND all other fields (icon, iconType, backgroundColor, textColor, linkType, linkValue) MUST remain unchanged

### REQ-TILE-004: Delete Custom Tile

Users MUST be able to delete their custom tile definitions with ownership verification.

#### Scenario: Delete a tile not placed on any dashboard
- GIVEN user "alice" has tile id 3 that is not placed on any dashboard
- WHEN she sends DELETE /api/tiles/3
- THEN the system MUST delete the tile
- AND the response MUST return HTTP 200

#### Scenario: Delete a tile that is placed on dashboards
- GIVEN user "alice" has tile id 3 placed on 2 dashboards
- WHEN she sends DELETE /api/tiles/3
- THEN the system MUST delete the tile from `oc_mydash_tiles`
- AND tile placements on dashboards SHOULD also be deleted
- NOTE: `TileService::deleteTile()` only deletes the tile entity. It does NOT cascade-delete tile placements. Since placements use inline copies (not foreign key references), there is no DB-level cascade.

#### Scenario: Delete another user's tile
- GIVEN tile id 3 belongs to user "alice"
- WHEN user "bob" sends DELETE /api/tiles/3
- THEN the system MUST return HTTP 403 (via `findByIdAndUser()`)
- AND the tile MUST NOT be deleted

### REQ-TILE-005: Place Tile on Dashboard

Users MUST be able to place tile data onto a dashboard, creating a widget placement with inline tile data.

#### Scenario: Place a tile on a dashboard
- GIVEN user "alice" has dashboard id 5
- WHEN she sends POST /api/dashboard/5/tile with body:
  ```json
  {"tileTitle": "My Files", "tileIcon": "icon-folder", "tileIconType": "class", "tileBackgroundColor": "#3b82f6", "tileTextColor": "#ffffff", "tileLinkType": "app", "tileLinkValue": "/apps/files", "gridX": 0, "gridY": 0, "gridWidth": 2, "gridHeight": 2}
  ```
- THEN the system MUST create a widget placement record with `tileType` set to `"custom"`
- AND `widgetId` MUST be set to `'tile-' + uniqid()` (NOT null)
- AND the tile data MUST be stored inline on the placement via `TileUpdater::applyTileConfig()`
- AND the response MUST return HTTP 201 with the placement object

#### Scenario: Place the same tile on multiple dashboards
- GIVEN user "alice" has tile data
- AND alice has dashboards id 5 and id 6
- WHEN she places the tile on both dashboards
- THEN both dashboards MUST have independent placement records with inline tile data copies
- AND deleting the placement from dashboard 5 MUST NOT affect dashboard 6's placement

#### Scenario: User cannot add tile to another user's dashboard
- GIVEN user "bob" has dashboard id 7
- WHEN user "alice" tries to POST /api/dashboard/7/tile with tile data
- THEN the system MUST return HTTP 403 (via `PermissionService::canAddWidget()` ownership check)

#### Scenario: Tile placement defaults
- GIVEN a tile placement is created
- WHEN default values are applied
- THEN `gridWidth` MUST default to 2 and `gridHeight` MUST default to 2 (different from widget default of 4x4)
- AND `isCompulsory` MUST default to 0
- AND `isVisible` MUST default to 1

#### Scenario: Tile placement on view-only dashboard blocked
- GIVEN user "alice" has a view-only dashboard id 5
- WHEN she sends POST /api/dashboard/5/tile with tile data
- THEN the system MUST return HTTP 403
- AND `canAddWidget()` MUST block tile additions on view-only dashboards

### REQ-TILE-006: Tile Icon Rendering

The frontend MUST support four icon formats: emoji, CSS class, URL, and SVG path.

#### Scenario: Render emoji icon
- GIVEN a tile with `iconType: "emoji"` and `icon: "\ud83d\udcc1"`
- WHEN the tile is rendered on the dashboard via `TileWidget.vue`
- THEN the system MUST render the emoji inside a `<span class="tile-widget__emoji">` at 64px font size

#### Scenario: Render CSS class icon
- GIVEN a tile with `iconType: "class"` and `icon: "icon-folder"`
- WHEN the tile is rendered
- THEN the system MUST apply the CSS class to an `<span class="icon">` element
- AND the icon MUST be 64px with `filter: brightness(0) invert(1)` for white appearance

#### Scenario: Render URL icon
- GIVEN a tile with `iconType: "url"` and `icon: "https://example.com/logo.png"`
- WHEN the tile is rendered
- THEN the system MUST render the URL as an `<img>` element with `object-fit: contain`
- AND the image MUST be constrained to the icon area (64px)
- NOTE: No fallback icon is displayed when a URL icon fails to load.

#### Scenario: Render SVG path icon
- GIVEN a tile with `iconType: "svg"` and `icon: "M12 2L2 7v10l10 5 10-5V7z"`
- WHEN the tile is rendered
- THEN the system MUST render the path inside an `<svg viewBox="0 0 24 24">` element
- AND the SVG fill MUST use the tile's textColor

### REQ-TILE-007: Tile Color Validation

Tile colors MUST be validated to ensure proper display and accessibility.

#### Scenario: Valid hex colors accepted
- GIVEN a tile creation request with `backgroundColor: "#3b82f6"` and `textColor: "#ffffff"`
- WHEN the tile is created
- THEN the system MUST accept the colors without error

#### Scenario: Invalid color format rejected
- GIVEN a tile creation request with `backgroundColor: "not-a-color"`
- WHEN the tile is created
- THEN the system SHOULD return HTTP 400 with a validation error
- NOTE: Color format validation is NOT currently implemented

#### Scenario: Default colors when not specified
- GIVEN a tile creation request without `backgroundColor` or `textColor`
- WHEN the tile is created
- THEN `backgroundColor` MUST default to `'#0082c9'` (Nextcloud primary) and `textColor` MUST default to `'#ffffff'` (white)

### REQ-TILE-008: Tile Link Navigation

Tiles MUST navigate correctly based on their linkType.

#### Scenario: App link navigation
- GIVEN a tile with `linkType: "app"` and `linkValue: "files"`
- WHEN the user clicks the tile
- THEN the system MUST navigate to `generateUrl('/apps/files')` in the same window (`target="_self"`)

#### Scenario: External URL navigation
- GIVEN a tile with `linkType: "url"` and `linkValue: "https://example.com"`
- WHEN the user clicks the tile
- THEN the system MUST open the URL in a new tab (`target="_blank"`)
- AND the link MUST have `rel="noopener noreferrer"` for security

#### Scenario: Tile link hover effect
- GIVEN a tile is rendered on the dashboard
- WHEN the user hovers over it
- THEN the tile MUST scale slightly (transform: scale(1.02)) with reduced opacity (0.95)

### REQ-TILE-009: Tile Styling

Tiles MUST apply their configured colors as CSS custom properties for consistent rendering.

#### Scenario: Tile background and text colors applied
- GIVEN a tile with `backgroundColor: "#3b82f6"` and `textColor: "#ffffff"`
- WHEN the tile is rendered
- THEN the `--tile-bg-color` CSS variable MUST be set to `#3b82f6`
- AND `--tile-text-color` MUST be set to `#ffffff`
- AND these variables MUST override any theme styles via `!important` declarations

#### Scenario: NL Design System CSS override
- GIVEN the nldesign theme applies aggressive CSS rules
- WHEN a tile is rendered
- THEN `TileWidget.vue` MUST inject a dynamic `<style>` element to override nldesign's CSS for the tile title color
- AND the style element MUST use `data-tile-id` for scoping

#### Scenario: Tile fills grid cell completely
- GIVEN a tile placement on the grid
- WHEN it is rendered
- THEN the tile MUST fill the entire grid cell (`position: absolute; top: 0; left: 0; width: 100%; height: 100%`)
- AND the tile MUST have no border radius and no border (overriding grid item defaults)

### REQ-TILE-010: Tile Edit Mode

Tiles MUST support an edit mode that allows configuration changes.

#### Scenario: Edit button visible in edit mode
- GIVEN a tile on a dashboard in edit mode
- WHEN the tile is rendered
- THEN an edit button (settings icon) MUST appear at the top-right corner of the tile
- AND the button MUST have `aria-label="Edit tile"` for accessibility

#### Scenario: Edit button hidden in view mode
- GIVEN a tile on a dashboard in view mode
- WHEN the tile is rendered
- THEN no edit button MUST be visible

#### Scenario: Edit button click emits event
- GIVEN a tile in edit mode
- WHEN the user clicks the edit button
- THEN the `TileWidget` component MUST emit an `edit` event
- AND `click.prevent` MUST prevent link navigation when clicking the edit button

### REQ-TILE-011: Tile Management UI

Users MUST be able to manage their tile definitions through a dedicated UI.

#### Scenario: Tile card display
- GIVEN user "alice" has custom tiles
- WHEN she views the tile management panel
- THEN each tile MUST be displayed as a `TileCard` component with its icon, title, and colors

#### Scenario: Tile editor
- GIVEN the user wants to create or edit a tile
- WHEN the tile editor opens
- THEN `TileEditor.vue` MUST provide fields for title, icon, iconType, colors, linkType, and linkValue
- AND changes MUST be saved via the tile API

## Non-Functional Requirements

- **Performance**: GET /api/tiles MUST return within 300ms for users with up to 100 tiles.
- **Data integrity**: The tile-placement inline copy model means changes to tile definitions do NOT affect existing placements. This is by design but should be clearly communicated in the UI.
- **Accessibility**: Tiles MUST be accessible as links with proper `aria-label` attributes derived from the title. Color combinations MUST meet WCAG AA contrast ratio (4.5:1 for normal text).
- **Security**: External URL icons MUST be loaded with appropriate CSP headers. External linkValues MUST be rendered with `rel="noopener noreferrer"`.
- **Localization**: Validation error messages MUST support English and Dutch. Tile titles are user-defined and not localized.

### Current Implementation Status

**Fully implemented:**
- REQ-TILE-001 (Create Custom Tile): `TileService::createTile()` with defaults: `iconType: 'class'`, `backgroundColor: '#0082c9'`, `textColor: '#ffffff'`, `linkType: 'url'`, `linkValue: '#'`.
- REQ-TILE-002 (List User Tiles): `TileService::getUserTiles()` via `TileMapper::findByUserId()`.
- REQ-TILE-003 (Update Custom Tile): `TileService::updateTile()` with `findByIdAndUser()` ownership check.
- REQ-TILE-004 (Delete Custom Tile): `TileService::deleteTile()` with `findByIdAndUser()`.
- REQ-TILE-005 (Place Tile on Dashboard): `PlacementService::addTileFromArray()` with `widgetId: 'tile-' + uniqid()`, inline data via `TileUpdater::applyTileConfig()`.
- REQ-TILE-006 (Tile Icon Rendering): `TileWidget.vue` renders all four icon types (svg, class, url, emoji).
- REQ-TILE-008 (Tile Link Navigation): `TileWidget.vue` uses `generateUrl()` for app links, `target="_blank"` with `rel="noopener noreferrer"` for external URLs.
- REQ-TILE-009 (Tile Styling): CSS custom properties `--tile-bg-color` and `--tile-text-color` with `!important` overrides. Dynamic style injection for nldesign override.
- REQ-TILE-010 (Tile Edit Mode): Edit button with `aria-label="Edit tile"`, `click.prevent`, and `edit` emit.
- REQ-TILE-011 (Tile Management UI): `TileCard.vue` and `TileEditor.vue` components exist.

**Not yet implemented:**
- REQ-TILE-001 validation: No required field validation, no linkType validation.
- REQ-TILE-004 cascade-delete placements: Tile deletion does NOT cascade-delete placements.
- REQ-TILE-007 (Tile Color Validation): No hex color format validation.
- REQ-TILE-006 URL icon fallback: No fallback when URL icon fails to load.

### Standards & References
- Content Security Policy (CSP): External URL icons should comply with Nextcloud's CSP headers
- WCAG 2.1 AA: Color contrast ratio 4.5:1 for tile text on background
- WAI-ARIA: Tile links need proper `aria-label` attributes
- Nextcloud Router: `generateUrl()` used for internal app links
