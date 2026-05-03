# Spec delta — widgets

## ADDED Requirements

### Requirement: REQ-WDG-018 Tile widget type registered

The widget registry (`src/constants/widgetRegistry.js`) MUST include a `tile` widget type that:

- Renders a clickable card with title + icon + background/text colours
- Supports `iconType` discriminator (`class` | `url` | `emoji` | `svg`)
- Supports `linkType` discriminator (`app` | `url`) and dispatches click accordingly
- Suppresses click handling while the dashboard is in edit mode (consistent with REQ-WDG-014)
- Has `defaultContent: {title:'', icon:'', iconType:'class', backgroundColor:'#3b82f6', textColor:'#ffffff', linkType:'app', linkValue:''}`

The tile widget type MUST be selectable from the unified "Add custom widget" picker (REQ-WDG-010) and surfaced alongside `label`, `text`, `image`, `link`, and `nc-widget`.

#### Scenario: Tile widget appears in the picker

- **GIVEN** the unified Add Custom Widget modal is open
- **WHEN** the user opens the type picker
- **THEN** the picker MUST list `tile` as a selectable type
- **AND** picking it MUST mount the `TileForm` sub-form

#### Scenario: Tile renders with content from placement

- **GIVEN** a widget placement with `type: 'tile'` and `content: {title: 'Files', icon: 'icon-folder', iconType: 'class', linkType: 'app', linkValue: '/apps/files', backgroundColor: '#3b82f6', textColor: '#ffffff'}`
- **WHEN** the placement renders
- **THEN** a card with the icon + "Files" label + blue background + white text MUST appear
- **AND** clicking the card (in view mode) MUST navigate to `/apps/files`

#### Scenario: Tile renderer supports legacy and new content shapes

- **GIVEN** a placement created via the deprecated `oc_mydash_tiles` flow with `placement.tileTitle: 'Old Tile'` and `placement.tileIcon: '📁'` (legacy shape)
- **WHEN** the placement renders via `TileWidget`
- **THEN** the title and icon MUST display correctly
- **AND** no console errors MUST occur from the missing `placement.content` field
