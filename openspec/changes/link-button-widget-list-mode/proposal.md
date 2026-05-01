# Link-Button Widget List Mode

## Why

The existing link-button widget renders a single clickable button per placement, suitable for "one primary action" UI patterns. However, many dashboards need to present multiple related links grouped together in a single widget cell — e.g., "Quick Links" showing Navigation, Help, Settings; or a "Downloads" widget with links to Report 1, Report 2, Report 3. Currently, users must either (a) place multiple single-button widgets side by side (consuming grid space inefficiently) or (b) leave links unmapped in other widget types.

This change extends the link-button widget with an optional "list" display mode, allowing administrators to configure a single widget placement that renders a vertical or horizontal list of multiple links. The change is purely additive — existing single-button placements remain unchanged and function identically.

## What Changes

- Add `displayMode ENUM('button','list')` field to widget-placement config (default: `'button'` for backward compatibility)
- Add `links JSON` field to widget-placement, typed as an array of link objects (empty or null for button mode; non-empty array for list mode)
- Each link object reuses the three action-type schema from the parent link-button capability (external URL, internal action_id, or createFile)
- Add `listOrientation ENUM('vertical','horizontal')` (default: `'vertical'`) to control list layout direction
- Add `listItemGap ENUM('compact','normal','spacious')` (default: `'normal'`) to control inter-item spacing
- Extend the edit form with a display-mode toggle and a list editor (reusing the existing single-link form for each entry)
- Preserve full backward compatibility: placements without `displayMode` or `links` fields remain valid and render using legacy single-link fields

## Capabilities

### New Capabilities

NEW capability: `link-button-widget-list-mode` (NOT a modification of the parent `link-button-widget` capability, because the parent is not yet promoted to `openspec/specs/`; once the parent is promoted, this change will be merged into it via a follow-up delta change)

### Modified Capabilities

- (none — this is a new capability)

## Impact

**Affected code:**

- `lib/Db/WidgetPlacement.php` — add `displayMode` and `links` fields with getters/setters
- `lib/Db/WidgetPlacementMapper.php` — if list-mode metadata requires indexing, add indexes (initially, no new indexes needed; `links` is fully contained in the placement record)
- `lib/Service/PlacementService.php` — validation for list-mode invariants (non-empty links array when displayMode='list')
- `src/components/widgets/LinkButtonWidget.vue` — extend renderer to detect `displayMode` and conditionally render list or single-button layout
- `src/components/widgets/LinkButtonEditForm.vue` — add display-mode toggle, list editor UI, and drag-to-reorder handler
- `src/stores/placements.js` — if a dedicated placement store exists, ensure it serialises the new fields correctly

**Affected APIs:**

- No new routes — all changes are to the widget placement's config structure (existing `PUT /api/widgets/{placementId}` handles the new fields)
- Existing `GET /api/widgets` and `GET /api/dashboard/{id}/widgets` continue to work unchanged; responses now MAY include `displayMode` and `links` fields on placements that have them

**Dependencies:**

- No new Composer or npm dependencies
- Reuses existing `IconRenderer`, modal components, drag-drop libraries already in the codebase

**Migration:**

- Zero-impact: existing placements without the new fields remain valid (implicit `displayMode = 'button'`)
- No database schema migration required (JSON fields can be empty/null)
- No data backfill needed
- Frontend form auto-detects legacy vs. new format and presents the appropriate UI

## Timeline & Ownership

**Scope:** Widget placement configuration extension + frontend rendering + edit form UI

**Complexity:** Medium — three requirements per link (action type, icon resolution, styling) replicate existing single-button logic; list rendering is straightforward flexbox + role attributes

**Risk:** Low — fully backward compatible; no data migration; no schema changes
