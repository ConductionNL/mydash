# Nextcloud Unified Search Integration

MyDash dashboards, widgets, and metadata are currently hidden from Nextcloud's built-in unified search (Ctrl+K / Cmd+K). Users must open the MyDash app first to discover dashboards. This change registers a search provider implementing `OCP\Search\IProvider` so that pressing Ctrl+K anywhere in Nextcloud automatically includes MyDash dashboards, widgets, and metadata in the unified search results. Users can discover and navigate directly to dashboards by name, description, widget content, or metadata field values without entering the app first.

## Affected code units

- `lib/Search/MyDashSearchProvider.php` — new class implementing `OCP\Search\IProvider`
- `lib/AppInfo/Application.php` — register the search provider in the app bootstrap
- `appinfo/info.xml` — declare `<types><search/></types>` capability
- Existing app services: `DashboardService::getVisibleToUser()` (permission boundary), `WidgetPlacementMapper`, `MetadataService`

## Why this change

Nextcloud's unified search is the primary discovery mechanism across the platform. By exposing MyDash content, users can:
- Find dashboards by name or description without manual browsing
- Locate specific widget content across all their dashboards
- Search custom metadata field values to navigate to relevant dashboards
- Enjoy a unified navigation experience without switching to the MyDash app context

The search provider integrates seamlessly with Nextcloud's existing `OCP\Search` interfaces and respects the app's permission model (only returning results the user can view).

## Approach

- **Search provider class** — implement `OCP\Search\IProvider` with methods `getId()`, `getName()`, `getOrder()`, and `search(IUser, ISearchQuery): SearchResult`
- **Multi-bucket results** — return up to 10 results each for dashboards (name/description match), widgets (content match), and metadata fields (value match)
- **Permission filtering** — all results go through `DashboardService::getVisibleToUser()` so the user only sees what they can access
- **Graceful degradation** — metadata search (REQ-SRCH-004) is optional; if the `dashboard-metadata-fields` capability is unavailable, the provider skips metadata and returns dashboards + widgets only
- **Localization** — all user-facing strings (`Dashboards`, `Widget content on %s`, `Metadata: %1$s = %2$s`) are translated via `IL10N`

## Capabilities

**New Capabilities:**

- `search-integration` (search provider registration, multi-bucket result matching, permission filtering, localization)

## Notes

- The search provider is always available when MyDash is enabled (no feature flags).
- Results are ordered: dashboards first, then widgets, then metadata, with a 10-result cap per type for performance.
- The provider returns an empty `SearchResult` gracefully when no matches exist (no error thrown).
- Widget deep-links include a `widget=<placementId>` hint so the frontend can scroll to the specific widget when present.
