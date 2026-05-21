# Dashboard Reactions

## Why

MyDash dashboards are shared resources in many organizations—team leads track operational metrics, departments publish survey results, HR publishes policy updates. Currently, viewers have no lightweight way to acknowledge or appreciate a dashboard without sending a comment or direct message. Dashboard creators receive no feedback signal: they don't know if a dashboard is useful, if it's being read, or if it resonates with viewers.

Reactions provide a low-friction, non-intrusive social feedback mechanism. A user can quickly mark a dashboard as 👍 useful, ❤️ appreciated, 🎉 exciting, or 🤔 interesting without jumping into a comment thread. Aggregated reaction counts let creators gauge impact and engagement at a glance.

An administrator can enable/disable reactions globally, configure which emoji are allowed, and toggle reactions per-dashboard to enforce governance (e.g., disallow reactions on compliance dashboards while keeping them on team morale boards).

## What Changes

- Introduce `oc_mydash_dashboard_reactions` table to store reactions: one row per (dashboardUuid, userId, emoji) tuple, with `reactedAt` timestamp.
- Add `reactionsEnabled` column to `oc_mydash_dashboards` for per-dashboard toggle (NULL = follow global setting; 1/0 = force on/off).
- Add two admin settings:
  - `mydash.reactions_enabled_default` (boolean, default: true) — global reactions toggle
  - `mydash.reactions_allowed_emojis` (JSON array, default: `["👍","❤️","🎉","😂","🤔","😢"]`) — emoji whitelist
- Four new API endpoints:
  - `POST /api/dashboards/{uuid}/reactions` — add a reaction
  - `DELETE /api/dashboards/{uuid}/reactions/{emoji}` — remove calling user's reaction
  - `GET /api/dashboards/{uuid}/reactions` — retrieve counts and user's reactions
  - `GET /api/dashboards/{uuid}/reactions/{emoji}/users` — paginated list of reactors for an emoji
- Implement cascade delete: when a dashboard is deleted, all its reactions are removed via `ReactionsListener` subscribing to `DashboardDeletedEvent`.
- Support permission enforcement: only users who can VIEW a dashboard may react, retrieve reactions, or list reactors.
- Support idempotency: adding the same emoji twice is a no-op; deleting a non-existent reaction returns 204 (not an error).
- Localize all error messages into English and Dutch.

## Capabilities

### New Capabilities

- **dashboard-reactions**: Lightweight social feedback via emoji on MyDash dashboards. Covers:
  - Authenticated users adding/removing emoji reactions to dashboards they can view
  - Aggregated reaction counts visible to all viewers, with tracking of the user's own reactions
  - Paginated list of reactors for each emoji
  - Global enable/disable toggle and per-dashboard override
  - Configurable emoji whitelist
  - Permission enforcement (view permission required) and cascade deletion (dashboard deletion removes reactions)
  - Idempotent add and remove operations
  - Full i18n support (English, Dutch)

## Impact

**Code affected:**
- `lib/Migration/Version001014Date20260502120000.php` — database schema (table, indices, column)
- `lib/Db/DashboardReaction.php` + `lib/Db/DashboardReactionMapper.php` — entity and mapper
- `lib/Db/Dashboard.php` — extend with `reactionsEnabled` property
- `lib/Service/ReactionService.php` — business logic (add, remove, list, summary)
- `lib/Service/ReactionService.php` — exceptions (`PermissionDeniedException`, `ReactionsDisabledException`)
- `lib/Controller/DashboardReactionApiController.php` — HTTP controller for four endpoints
- `lib/Listener/ReactionsListener.php` — event listener for cascade delete
- `appinfo/routes.php` — four new API routes
- `src/services/api.js` — four client-side API actions
- `src/stores/dashboard.js` — Pinia store actions (add, remove, get, listReactors)
- `l10n/en.json` + `l10n/nl.json` — six new i18n strings

**APIs:**
- `POST   /api/dashboards/{uuid}/reactions`
- `DELETE /api/dashboards/{uuid}/reactions/{emoji}`
- `GET    /api/dashboards/{uuid}/reactions`
- `GET    /api/dashboards/{uuid}/reactions/{emoji}/users`

**Data:**
- One new database table `oc_mydash_dashboard_reactions` with indices on (dashboardUuid, userId, emoji), dashboardUuid, and emoji.
- One new nullable SMALLINT column on `oc_mydash_dashboards.reactionsEnabled`.
- Two new admin settings under `mydash.*`.

**Dependencies:**
- `IPermissionService` (existing mydash) — permission checks on dashboard view
- `IConfigService` (existing Nextcloud) — admin settings (global toggle, emoji list)
- `UserSession` (existing Nextcloud) — authenticated user ID
- `EventDispatcher` (existing Nextcloud) — subscribe to `DashboardDeletedEvent`

**Migration:**
- One database migration adding the schema and column.
- No breaking changes to existing APIs or admin settings.
- Unconfigured installations default to reactions enabled with the default emoji list.
