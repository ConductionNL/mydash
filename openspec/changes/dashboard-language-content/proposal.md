# Dashboard Language Content

Per-language content variants for MyDash dashboards. A single dashboard can carry multiple localised widget trees, names, and descriptions. The viewer's Nextcloud locale (or an explicit `?lang=` query parameter) is matched against the available variants; when no match is found the system falls back to the dashboard's primary variant. Each dashboard MUST have exactly one row marked `isPrimary = 1`. New dashboards auto-seed a primary translation in the owner's current Nextcloud locale.

## Affected code units

- `lib/Migration/Version001017Date20260502130000.php` — schema migration creating `oc_mydash_dash_translations` table
- `lib/Db/DashboardTranslation.php` — entity for translation rows
- `lib/Db/DashboardTranslationMapper.php` — database mapper with normalisation and resolution logic
- `lib/Service/DashboardTranslationService.php` (new) — business logic for CRUD and variant promotion
- `lib/Controller/DashboardTranslationApiController.php` (new) — REST endpoints for variant operations
- `lib/Service/DashboardService.php` — backfill and cascade-delete integration
- `lib/Migration/DashboardTranslationTableBuilder.php` — schema builder helper

## Why this change

Multi-language dashboards are a load-bearing feature in environments serving users across language boundaries (corporate networks, government portals, education systems). Today, dashboard names and widget configurations are single-language. This change adds first-class support for per-language variants without disrupting existing single-language dashboards.

## Approach

- Store language variants in a dedicated `oc_mydash_dash_translations` table independent from the dashboard record
- Normalise all locale codes to 2-character ISO 639-1 base codes (`nl`, `en`, `de`, `fr`) before storage
- Implement a three-tier resolution strategy: exact match → language-part match → primary fallback
- Enforce the invariant "exactly one primary per dashboard" in the service layer (not via constraints)
- Backfill existing dashboards with a primary translation seeded from their owner's Nextcloud locale
- Provide REST endpoints for create/read/update/delete/promote operations with full access control

## Standards & References

- Nextcloud Controller patterns: `OCP\AppFramework\Controller`, `#[NoAdminRequired]` attribute
- Locale storage convention: ISO 639-1 base code (`nl`, `en`, `de`, `fr`); input BCP-47 codes are normalised before storage
- REQ-DASH-038 through REQ-DASH-044 define all acceptance criteria
