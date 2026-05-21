# Design — Dashboard Reactions

## Architecture

### Backend

```
DashboardReactionApiController   (thin: routing + validation + response)
        │
        ▼
ReactionService                  (all business logic, stateless)
        │
        ├── DashboardReactionMapper  (Nextcloud entity persistence)
        ├── IPermissionService       (VIEW permission check)
        ├── IConfigService           (admin settings: enabled, emoji list)
        └── UserSession              (authenticated user ID)

ReactionsListener                (DashboardDeletedEvent → cascade delete)
        │
        ▼
ReactionService::deleteReactionsByDashboard()
```

**Service responsibilities:**
- `addReaction(string $dashboardUuid, string $userId, string $emoji): DashboardReaction` — validate emoji against whitelist, check reactions enabled, enforce VIEW permission, create or ignore duplicate via unique constraint, return the reaction row.
- `removeReaction(string $dashboardUuid, string $userId, string $emoji): void` — delete the calling user's reaction (idempotent; no error if not found).
- `getReactionsSummary(string $dashboardUuid, string $userId): array` — return `{counts: {...}, mine: [...], enabled: bool}` reflecting current global and per-dashboard toggle state.
- `listReactorsByEmoji(string $dashboardUuid, string $emoji, int $limit = 100, int $offset = 0): array` — paginated list of reactors with display names and reaction timestamps, ordered chronologically.
- `deleteReactionsByDashboard(string $dashboardUuid): void` — cascade delete all reactions for a dashboard.

**Exceptions:**
- `PermissionDeniedException` — user lacks VIEW permission on dashboard.
- `ReactionsDisabledException` — reactions are disabled globally or on this dashboard.

**Controller pattern (ADR-003):**
- `DashboardReactionApiController` handles all four public endpoints.
- Methods: `addReaction($uuid)`, `removeReaction($uuid, $emoji)`, `getSummary($uuid)`, `listReactors($uuid, $emoji)`.
- All methods authorized via existing mydash route guards (authenticated user required).
- Thin routing + input validation; all business logic delegated to `ReactionService`.

### Frontend

```
DashboardView.vue / DashboardHeader.vue
  └── ReactionBar.vue              (new component — display counts + user's reactions)
        ├── Shows aggregated emoji counts
        ├── Highlights user's own reactions
        ├── "Add reaction" button opens emoji picker on click
        └── One-click removal on user's reaction

src/stores/dashboard.js             (Pinia store)
  ├── actions.addReaction(uuid, emoji)      — calls API, updates local state
  ├── actions.removeReaction(uuid, emoji)   — calls API, updates local state
  ├── actions.getReactions(uuid)            — fetches summary, caches
  └── actions.listReactors(uuid, emoji)     — paginated fetch for modal

src/services/api.js                 (REST calls)
  ├── postReaction(uuid, emoji)
  ├── deleteReaction(uuid, emoji)
  ├── getReactionsSummary(uuid)
  └── getReactors(uuid, emoji, limit, offset)
```

`ReactionBar.vue` displays emoji with counts, highlights user's own reactions, and offers a dropdown picker (emoji palette or search) to add reactions. Click existing emoji to remove (toggle off).

---

## Data Model

### Schema: DashboardReaction

**Database table:** `oc_mydash_dashboard_reactions`

| Column | Type | Nullable | Constraints | Description |
|---|---|---|---|---|
| `id` | INTEGER | NO | PRIMARY KEY, AUTO_INCREMENT | Surrogate key |
| `dashboardUuid` | VARCHAR(36) | NO | FK → `oc_mydash_dashboards.uuid`, INDEX | Which dashboard |
| `userId` | VARCHAR(64) | NO | INDEX | Nextcloud user ID of the reactor |
| `emoji` | VARCHAR(32) | NO | INDEX | Unicode emoji string (e.g., `👍`, `❤️`) |
| `reactedAt` | DATETIME | NO | DEFAULT CURRENT_TIMESTAMP | Moment of reaction |

**Indices:**
- **UNIQUE** `(dashboardUuid, userId, emoji)` — one user can react with multiple emojis to a dashboard, but cannot duplicate the same emoji. Enforces idempotency: INSERT with existing (uuid, user, emoji) is silently ignored.
- **INDEX** `dashboardUuid` — fast `GET /api/dashboards/{uuid}/reactions`
- **INDEX** `emoji` — fast `GET /api/dashboards/{uuid}/reactions/{emoji}/users`

### Column: Dashboard.reactionsEnabled

**Database table:** `oc_mydash_dashboards`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `reactionsEnabled` | SMALLINT(0/1) | YES | NULL | NULL = follow global setting; 1 = force enabled; 0 = force disabled |

### Admin Settings

**Setting:** `mydash.reactions_enabled_default`
- Type: boolean
- Default: `true`
- Purpose: Global enable/disable of reactions feature. Applies to all dashboards with `reactionsEnabled = NULL`.

**Setting:** `mydash.reactions_allowed_emojis`
- Type: JSON array of strings
- Default: `["👍","❤️","🎉","😂","🤔","😢"]`
- Purpose: Whitelist of allowed emoji. Admin can customize per organization (e.g., remove 😂 in formal org; add 🚀 in startup).

---

## Seed Data

> No OpenRegister schemas required. Reactions are stored in native Nextcloud entity (Mapper) tables, not OpenRegister. No seed data applicable.

---

## Reuse Analysis

| Concern | Platform service / component used | Custom code needed? |
|---|---|---|
| Dashboard permission checks | `IPermissionService::checkViewPermission()` (existing mydash) | No — read-only call |
| Nextcloud user ID | `UserSession::getUser()` → `getUID()` (existing Nextcloud) | No — read-only call |
| Admin settings (global toggle, emoji list) | `IConfigService` (existing Nextcloud) | No — read-only call |
| Database entity persistence | `Mapper` pattern (existing Nextcloud) | Custom entity + mapper |
| Event-driven cascade delete | `EventDispatcher` (existing Nextcloud) | `ReactionsListener` thin subscriber |
| Frontend API calls | `axios` (existing Nextcloud) | Thin wrapper in `api.js` |
| Pinia store for reaction state | `Pinia` + `dashboard.js` store (existing mydash) | Add four actions to store |
| Emoji picker UI | `EmojiMart` or inline palette | `ReactionBar.vue` component |
| i18n for UI strings | `t(appName, key)` (existing Nextcloud) | Add 6 strings to `l10n/*.json` |

**Deduplication check — existing capabilities NOT duplicated:**
- `IPermissionService` (existing mydash) controls per-dashboard VIEW/EDIT/etc. permissions. This change adds a read-only check before reactions; no overlap.
- Comments system (if exists) provides threaded discussion. Reactions are lightweight metadata, orthogonal to comments.
- Audit trail (OpenRegister) is automatic for all data changes; reactions logged via same mechanism.
- No overlap with `ObjectService`, `RegisterService`, or any platform-provided component found.

---

## Reactions Enabled Logic

**At request time:**
1. Read `mydash.reactions_enabled_default` (admin setting; default: true).
2. Read `dashboard.reactionsEnabled` for the target dashboard:
   - **NULL** → follow global setting (use step 1)
   - **1** → force enabled
   - **0** → force disabled
3. Result: boolean `isEnabled`.

**POST /api/dashboards/{uuid}/reactions:**
- If `isEnabled` is false, return HTTP 403 with `{"message": "Reactions are disabled"}`.
- If emoji not in `mydash.reactions_allowed_emojis`, return HTTP 400 with `{"message": "Emoji not allowed"}`.
- Else, attempt INSERT; return HTTP 200 with updated summary (same whether row inserted or duplicate).

**GET /api/dashboards/{uuid}/reactions:**
- Always return the summary object with `enabled: isEnabled` (reflects current toggle state).
- If `isEnabled` is false, return `{counts: {}, mine: [], enabled: false}` (hide existing reactions).
- Else, return real aggregated counts and user's reactions.

**DELETE /api/dashboards/{uuid}/reactions/{emoji}:**
- Idempotent: always return HTTP 204, whether row existed or not.

---

## Idempotency

**POST add (duplicate emoji):**
- Unique constraint `(dashboardUuid, userId, emoji)` prevents duplicate row.
- INSERT with existing tuple is silently ignored (no error).
- Response is HTTP 200 with updated summary (looks identical to first add).

**DELETE remove (non-existent emoji):**
- DELETE WHERE (uuid, userId, emoji) = (...) affects zero rows if not found.
- No error raised; HTTP 204 returned regardless.
- Idempotent: sending DELETE twice has no side effect on second call.
