---
status: reviewed
---

# Custom Tiles Specification

## Purpose

Custom tiles are user-created shortcut cards that provide quick access to Nextcloud apps or external URLs. Unlike widgets (which render dynamic content from Nextcloud apps), tiles are simple, static cards with an icon, label, and link. Tiles are first created as reusable entities, then placed onto dashboards via a special tile placement mechanism. This two-step model allows the same tile definition to be placed on multiple dashboards.

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

#### Scenario: Create a tile with missing required fields
- GIVEN a logged-in user
- WHEN they send POST /api/tiles with body `{"title": "Incomplete"}`
- THEN the system MUST return HTTP 400 with validation errors for missing required fields
- AND `linkType` and `linkValue` MUST be required
- NOTE: The current implementation does NOT validate required fields -- all tile fields have defaults (e.g., `linkType` defaults to `'url'`, `linkValue` defaults to `'#'`)

#### Scenario: Create a tile with invalid link_type
- GIVEN a logged-in user
- WHEN they send POST /api/tiles with body `{"title": "Bad", "linkType": "ftp", "linkValue": "ftp://server"}`
- THEN the system SHOULD return HTTP 400 with an error indicating that `linkType` must be either "app" or "url"
- NOTE: Link type validation is NOT currently implemented -- any string value is accepted

### REQ-TILE-002: List User Tiles

Users MUST be able to retrieve all their custom tile definitions.

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

Users MUST be able to update the properties of their custom tiles.

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
- THEN the system MUST return HTTP 403
- AND the tile MUST NOT be modified

#### Scenario: Update tile does NOT reflect on existing placements
- GIVEN tile id 3 has been placed on 2 of alice's dashboards (tile data was copied inline to placements at creation time)
- WHEN she updates the tile's title from "My Files" to "Documents" via PUT /api/tiles/3
- THEN the tile definition in `oc_mydash_tiles` MUST be updated
- BUT existing placements MUST NOT be affected (they store a copy of the tile data, not a reference)
- NOTE: This is the current behavior due to the inline-copy tile placement model. Future versions may add tile-by-reference support.

### REQ-TILE-004: Delete Custom Tile

Users MUST be able to delete their custom tile definitions.

#### Scenario: Delete a tile not placed on any dashboard
- GIVEN user "alice" has tile id 3 that is not placed on any dashboard
- WHEN she sends DELETE /api/tiles/3
- THEN the system MUST delete the tile
- AND the response MUST return HTTP 200

#### Scenario: Delete a tile that is placed on dashboards
- GIVEN user "alice" has tile id 3 placed on 2 dashboards
- WHEN she sends DELETE /api/tiles/3
- THEN the system MUST delete the tile
- AND all associated tile placements on all dashboards MUST be cascade-deleted
- AND the grid positions of remaining widgets MUST NOT be affected

#### Scenario: Delete another user's tile
- GIVEN tile id 3 belongs to user "alice"
- WHEN user "bob" sends DELETE /api/tiles/3
- THEN the system MUST return HTTP 403
- AND the tile MUST NOT be deleted

### REQ-TILE-005: Place Tile on Dashboard

Users MUST be able to place a custom tile onto a dashboard with grid coordinates.

#### Scenario: Place a tile on a dashboard
- GIVEN user "alice" has tile id 3 and dashboard id 5
- WHEN she sends POST /api/dashboard/5/tile with body:
  ```json
  {"tileTitle": "My Files", "tileIcon": "icon-folder", "tileIconType": "class", "tileBackgroundColor": "#3b82f6", "tileTextColor": "#ffffff", "tileLinkType": "app", "tileLinkValue": "/apps/files", "gridX": 0, "gridY": 0, "gridWidth": 2, "gridHeight": 2}
  ```
- THEN the system MUST create a widget placement record with `tileType` set to `"custom"`
- AND `widgetId` MUST be set to `'tile-' + uniqid()` (NOT null -- the column is NOT NULL)
- AND the tile data MUST be stored inline on the placement (tileTitle, tileIcon, etc.)
- AND the tile MUST render at position (0, 0) with the specified size
- NOTE: Tile placements do NOT reference the `oc_mydash_tiles` table by ID. The tile data is passed directly in the request and stored on the placement.
- AND the response MUST return HTTP 201 with the placement object

#### Scenario: Place the same tile on multiple dashboards
- GIVEN user "alice" has tile id 3
- AND alice has dashboards id 5 and id 6
- WHEN she places tile 3 on both dashboards
- THEN both dashboards MUST have independent placement records referencing tile 3
- AND deleting the placement from dashboard 5 MUST NOT affect dashboard 6's placement

#### Scenario: User cannot add tile to another user's dashboard
- GIVEN user "bob" has dashboard id 7
- WHEN user "alice" tries to POST /api/dashboard/7/tile with tile data
- THEN the system MUST return HTTP 403 (via `PermissionService::canAddWidget()` ownership check)
- AND the placement MUST NOT be created
- NOTE: Since tile data is passed inline (not by reference), there is no concept of "another user's tile" in the placement flow. The permission check is on dashboard ownership, not tile ownership.

### REQ-TILE-006: Tile Icon Rendering

The frontend MUST support three icon formats: emoji, CSS class, and URL.

#### Scenario: Render emoji icon
- GIVEN a tile with `icon: "\ud83d\udcc1"`
- WHEN the tile is rendered on the dashboard
- THEN the system MUST detect the emoji and render it as a large character centered in the tile

#### Scenario: Render CSS class icon
- GIVEN a tile with `icon: "icon-folder"`
- WHEN the tile is rendered on the dashboard
- THEN the system MUST apply the CSS class to an icon element
- AND the Nextcloud icon set MUST be used

#### Scenario: Render URL icon
- GIVEN a tile with `icon: "https://example.com/logo.png"`
- WHEN the tile is rendered on the dashboard
- THEN the system MUST render the URL as an `<img>` element
- AND the image MUST be constrained to fit within the tile's icon area
- AND if the image fails to load, a fallback icon MUST be displayed

### REQ-TILE-007: Tile Color Validation

Tile colors MUST be validated to ensure proper display.

#### Scenario: Valid hex colors accepted
- GIVEN a tile creation request with `backgroundColor: "#3b82f6"` and `textColor: "#ffffff"`
- WHEN the tile is created
- THEN the system MUST accept the colors without error

#### Scenario: Invalid color format rejected
- GIVEN a tile creation request with `backgroundColor: "not-a-color"`
- WHEN the tile is created
- THEN the system MUST return HTTP 400 with a validation error for the color field
- AND only hex color values (3-digit or 6-digit with `#` prefix) MUST be accepted
- NOTE: Color format validation is NOT currently implemented -- any string value is accepted for backgroundColor and textColor

#### Scenario: Default colors when not specified
- GIVEN a tile creation request without `backgroundColor` or `textColor`
- WHEN the tile is created
- THEN the system MUST apply default colors (e.g., Nextcloud primary color for background, white for text)
- AND the tile MUST be visually readable with the defaults
- NOTE: The current implementation uses empty string defaults for backgroundColor and textColor

## Non-Functional Requirements

- **Performance**: GET /api/tiles MUST return within 300ms for users with up to 100 tiles.
- **Data integrity**: Deleting a tile MUST cascade-delete all placements referencing it. NOTE: Since tile placements store inline copies (not foreign key references), cascade-delete must search for placements matching the tile data, not a foreign key. The tile-placement relationship MUST be consistent.
- **Accessibility**: Tiles MUST be accessible as links with proper `aria-label` attributes derived from the title. Color combinations MUST meet WCAG AA contrast ratio (4.5:1 for normal text).
- **Security**: External URL icons MUST be loaded with appropriate CSP headers. External linkValues MUST be rendered with `rel="noopener noreferrer"`.
- **Localization**: Validation error messages MUST support English and Dutch. Tile titles are user-defined and not localized.
