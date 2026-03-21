# Custom Tiles Specification

## Problem
Custom tiles are user-created shortcut cards that provide quick access to Nextcloud apps or external URLs. Unlike widgets (which render dynamic content from Nextcloud apps), tiles are simple, static cards with an icon, label, and link. Tiles are first created as reusable entities in the `oc_mydash_tiles` table, then placed onto dashboards via a special tile placement mechanism that stores tile data inline on the placement. This inline-copy model means tile placements are independent snapshots -- changes to the tile definition do NOT propagate to existing placements.

## Proposed Solution
Implement Custom Tiles Specification following the detailed specification. Key requirements include:
- See full spec for detailed requirements

## Scope
This change covers all requirements defined in the tiles specification.

## Success Criteria
- Create a tile linking to a Nextcloud app
- Create a tile linking to an external URL
- Create a tile with an emoji icon
- Create a tile with SVG path icon
- Create a tile with missing required fields
