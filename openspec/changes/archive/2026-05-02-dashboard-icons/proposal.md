# Dashboard icons — registry capability

Introduce a new `dashboard-icons` capability that owns the catalogue of icons available for dashboards (in the switcher sidebar, admin list, etc.). Provides a stable named registry plus a discriminator function that lets the same icon field hold either a registry name OR an uploaded URL.

## Affected code units

- `src/constants/dashboardIcons.js` — new module exporting `DASHBOARD_ICONS`, `DEFAULT_ICON`, `getIconComponent(name)`, `isCustomIconUrl(name)`
- `src/components/Dashboard/IconRenderer.vue` — small component that renders `<component :is>` for built-in icons or `<img>` for URLs
- `lib/Db/Dashboard.php` — annotate `icon` field convention (NULL or registry name or URL)
- No DB schema change (the column already exists on `oc_mydash_dashboards`)

## Why a new capability

The icon system is a small, self-contained surface that is consumed by the sidebar, admin UI, and (separately) the link-button widget icon picker. Pulling it out as its own capability gives a single place to grow the icon set, swap rendering libraries, or add icon search later — without bloating the `dashboards` capability.

## Approach

- 15 named icons drawn from `vue-material-design-icons` to start (a deliberate small palette: `ViewDashboard`, `Home`, `ChartBar`, `Cog`, `AccountGroup`, `Calendar`, `FileDocument`, `Bell`, `Star`, `Heart`, `BookOpenVariant`, `Lightbulb`, `RocketLaunch`, `Earth`, `Briefcase`).
- `DEFAULT_ICON = 'ViewDashboard'`.
- Frontend-only; no backend involvement (icon names are persisted as opaque strings on the `dashboards.icon` column).
- Custom uploaded icons (the `custom-icon-upload-pattern` change) extend this with URL semantics.

## Notes

- We deliberately keep the palette small for a curated UX. Future change can introduce a full MDI search picker if needed.
- Icon component imports are tree-shake-friendly (only referenced ones land in the bundle).
