# Dashboard Reactions

## Why

Today MyDash dashboards are one-directional: users view content but cannot provide lightweight social feedback. Teams using shared dashboards want emoji reactions (👍, ❤️, 🎉, etc.) to mark useful or appreciated dashboards without full-featured comments. This is a low-friction engagement signal that aligns with modern collaborative tools.

## What Changes

- Add a new `oc_mydash_dashboard_reactions` table with schema: `id` (PK), `dashboardUuid VARCHAR(36)`, `userId VARCHAR(64)`, `emoji VARCHAR(32)`, `reactedAt TIMESTAMP`, unique constraint on `(dashboardUuid, userId, emoji)`. Users can react with multiple distinct emojis but cannot duplicate the same emoji on the same dashboard.
- Add a per-dashboard `reactionsEnabled SMALLINT(0/1) NULL` column to `oc_mydash_dashboards`. NULL = follow global setting; 1 = force on; 0 = force off.
- Add admin settings: `mydash.reactions_enabled_default` (boolean, default true) for global toggle, and `mydash.reactions_allowed_emojis` (JSON array, default `["👍","❤️","🎉","😂","🤔","😢"]`) for allowed emoji whitelist.
- Expose `GET /api/dashboards/{uuid}/reactions` returning `{counts: {emoji: number, ...}, mine: [emoji, ...], enabled: boolean}`.
- Expose `POST /api/dashboards/{uuid}/reactions` body `{emoji}` to add a reaction (idempotent, 200 not 409).
- Expose `DELETE /api/dashboards/{uuid}/reactions/{emoji}` to remove calling user's reaction (idempotent 204).
- Expose `GET /api/dashboards/{uuid}/reactions/{emoji}/users` returning `[{userId, displayName, reactedAt}]` capped at 100, with cursor-based pagination.
- Permission: only users who can VIEW the dashboard can react.
- Cascade delete: deleting a dashboard removes all its reactions.

## Capabilities

### New Capabilities

- `dashboard-reactions`: lightweight emoji reactions on dashboards with admin-configurable emoji list and per-dashboard toggle.

### Modified Capabilities

- `dashboards`: adds cascade-delete requirement for reactions.

## Impact

**Affected code:**

- `lib/Db/DashboardReaction.php` — new entity (id, dashboardUuid, userId, emoji, reactedAt)
- `lib/Db/DashboardReactionMapper.php` — new mapper with methods: `findByDashboard`, `findByEmoji`, `addReaction`, `removeReaction`, `countByDashboard`, `deleteByDashboardUuid`
- `lib/Db/Dashboard.php` — add nullable `reactionsEnabled` field with getter/setter
- `lib/Service/ReactionService.php` — new service: validation, permission checks, emoji whitelist enforcement, idempotent add/remove logic
- `lib/Controller/DashboardController.php` — four new endpoints: GET reactions, POST add, DELETE remove, GET users-by-emoji
- `appinfo/routes.php` — register four new routes
- `appinfo/info.xml` — add admin settings declarations
- `lib/Migration/VersionXXXXDate2026...php` — schema migration
- `src/stores/dashboards.js` — track reactions per dashboard, wire `GET /api/dashboards/{uuid}/reactions`
- `lib/Service/DashboardService.php` — pass cascade-delete signal to `ReactionService` on dashboard deletion

**Affected APIs:**

- 4 new read/write routes (no existing routes changed)
- Existing `GET /api/dashboards`, `GET /api/dashboard` unchanged

**Dependencies:**

- No new composer or npm dependencies
- Uses existing `IAppConfig` for admin settings
- Uses existing `IGroupManager` for permission checks (VIEW permission inherited from `dashboards` capability)

**Migration:**

- Zero-impact: new table and nullable column only. Existing dashboards default to `reactionsEnabled = NULL` (follows global setting), so all existing dashboards respect the global toggle.
- No data backfill required.
