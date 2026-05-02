# Map support — render geospatial dashboard widgets

Render geospatial properties from OpenRegister objects on a map widget inside mydash dashboards. Adds a new `map` widget type alongside the existing chart / KPI / table widgets, consuming the GeoJSON storage + spatial-query API that OpenRegister's `geo-metadata-kaart` change is shipping.

## Affected code units

- `src/components/widgets/MapWidget.vue` — new Leaflet-based widget that renders GeoJSON returned by OpenRegister's spatial-query API; supports point / line / polygon / multi-geometry layers, click-to-detail navigation back to the underlying object, optional clustering for dense point sets.
- `src/constants/widgetTypes.js` — register `map` alongside the existing widget types so dashboard authors can pick it from the widget palette.
- `src/store/modules/widgetData.js` — extend the data-fetch path to call OpenRegister's spatial-query endpoint with bbox / within-polygon / nearest-N filters when the widget is configured as a map.
- `lib/Db/Widget.php` — annotate `type` field convention (existing column; new accepted value `map`).
- No DB schema change.

## Why a new change

Map rendering is a contained UI surface that depends on geospatial data shipped by OpenRegister, but the rendering decisions (Leaflet vs MapLibre, tile-layer choice, layer control UX, clustering strategy) are mydash-specific. Splitting this change keeps the OpenRegister geo spec focused on storage + API contracts and lets mydash iterate the widget independently.

## Approach

- Leaflet 1.9 as the map library — lightweight, well-maintained, plays well with PDOK tile layers and Vue 2 via `vue2-leaflet`.
- Default base layer: PDOK BRT achtergrondkaart (`https://service.pdok.nl/brt/achtergrondkaart/wmts/v2_0`).
- Coordinate transformation (WGS84 ↔ EPSG:28992 RD) via `proj4` when widgets request RD-projected output.
- Widget configuration UI: register/schema picker (reuse existing dashboard-widget config), spatial filter (none / bbox / drawn polygon), clustering threshold.
- Click-to-detail: clicking a feature opens the underlying OpenRegister object's deep-link in a new tab via the existing deep-link-registry.

## Hand-off context

Filed as a sibling to:
- OpenRegister `geo-metadata-kaart` change (decision 2026-05-02): scope-tightened to GeoJSON storage + spatial-query API; UI deferred to consuming apps. https://github.com/ConductionNL/openregister/blob/feat/openspec-batch-impl-2026-05-02/openspec/changes/geo-metadata-kaart/tasks.md
- WOO Tilburg sibling: https://github.com/ConductionNL/tilburg-woo-ui/issues/436

## Notes

- Out of scope for this change: BAG/BGT base-registration overlays (deferred to a follow-up `map-overlays-bag-bgt` change once the base widget is in production).
- Out of scope: INSPIRE metadata compliance (a publication-side concern, not a widget-side concern).
- Out of scope: WFS/GeoJSON export from the widget — the OpenRegister API already exposes GeoJSON; widget consumers can call the API directly.
