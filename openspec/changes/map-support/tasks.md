# Tasks: Map support widget

## Frontend

- [ ] Add `vue2-leaflet` + `leaflet` + `proj4` to `package.json`; register `<l-map>` / `<l-tile-layer>` / `<l-geo-json>` globals in the dashboard view bootstrap.
- [ ] Create `src/components/widgets/MapWidget.vue` with: tile layer (PDOK BRT default), GeoJSON renderer, click-to-detail handler, basic legend, loading / empty / error states.
- [ ] Create `src/components/widgets/MapWidgetConfig.vue` for the dashboard-author UI: register/schema picker, geo-property selector, spatial-filter mode (none / bbox / polygon), clustering threshold.
- [ ] Register `map` in `src/constants/widgetTypes.js` with a label, icon (MDI `Map`), and config-component reference.
- [ ] Wire `widgetData.js` (or equivalent store module) to call OpenRegister's spatial-query API when the widget type is `map`. Pass through bbox / polygon / nearest-N filters from the widget config.
- [ ] Click-to-detail: clicking a feature opens the underlying object's deep-link in a new tab using the deep-link-registry.

## Backend

- [ ] Document the `map` widget type in the canonical mydash widget catalogue.
- [ ] Validate `map`-typed widget configurations on save: required `register`, `schema`, `geoProperty` keys; optional `spatialFilter`, `clusterThreshold`.

## Testing

- [ ] Unit test for `MapWidgetConfig.vue` validation logic.
- [ ] Unit test for the spatial-filter to API-query translator in the store.
- [ ] Playwright smoke: drop a `map` widget on a fresh dashboard, configure it against a register/schema with geo data, confirm features render and click-to-detail navigates correctly.

## Documentation

- [ ] Add a "Map widget" section to the dashboard-authoring docs.
- [ ] Cross-reference OpenRegister's geo-metadata-kaart change so authors know which OR features ship the underlying data.

## Out of scope (separate follow-up changes)

- BAG / BGT base-registration overlays — `map-overlays-bag-bgt`.
- INSPIRE metadata compliance — publication-side concern, not widget-side.
- Map drawing / geometry editing — `map-drawing-tools`.
