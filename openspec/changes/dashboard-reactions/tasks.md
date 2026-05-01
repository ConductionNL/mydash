# Tasks — dashboard-reactions

## 1. Schema migration

- [ ] 1.1 Create `lib/Migration/VersionXXXXDate2026...AddDashboardReactionsTable.php` with `oc_mydash_dashboard_reactions` table: `id (PK)`, `dashboardUuid VARCHAR(36) NOT NULL`, `userId VARCHAR(64) NOT NULL`, `emoji VARCHAR(32) NOT NULL`, `reactedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP`
- [ ] 1.2 Same migration adds unique constraint `idx_mydash_react_uuid_user_emoji` on `(dashboardUuid, userId, emoji)` to prevent duplicate reactions
- [ ] 1.3 Same migration adds index `idx_mydash_react_uuid` on `dashboardUuid` for fast lookups by dashboard
- [ ] 1.4 Same migration adds index `idx_mydash_react_emoji` on `emoji` for fast lookups of reactors by emoji
- [ ] 1.5 Same migration adds `reactionsEnabled SMALLINT(0/1) NULL` column to `oc_mydash_dashboards`
- [ ] 1.6 Migration is reversible (drop table, drop columns, drop indexes in postSchemaChange rollback)
- [ ] 1.7 Run migration locally against sqlite, mysql, and postgres; verify clean application each time

## 2. Domain model

- [ ] 2.1 Create `lib/Db/DashboardReaction.php` entity with fields: id, dashboardUuid, userId, emoji, reactedAt; add getters/setters (Entity `__call` pattern — no named args)
- [ ] 2.2 Add `Dashboard::reactionsEnabled` field to `Dashboard.php` with getter/setter
- [ ] 2.3 Update `Dashboard::jsonSerialize()` to include `reactionsEnabled` (nullable in output)
- [ ] 2.4 In `DashboardReaction::jsonSerialize()` include all fields; `emoji` as-is (unicode string), `reactedAt` in "Y-m-d H:i:s" format for consistency with dashboard timestamps

## 3. Mapper layer

- [ ] 3.1 Create `lib/Db/DashboardReactionMapper.php` extending `QBMapper`
- [ ] 3.2 Add `findByDashboard(string $dashboardUuid): array` — `WHERE dashboardUuid = ?` ordered by `reactedAt DESC`
- [ ] 3.3 Add `findByEmoji(string $dashboardUuid, string $emoji): array` — `WHERE dashboardUuid = ? AND emoji = ?` ordered by `reactedAt DESC`, used for `/users` endpoint
- [ ] 3.4 Add `findByUser(string $userId, string $dashboardUuid): array` — `WHERE dashboardUuid = ? AND userId = ?`, returns calling user's reactions on a dashboard
- [ ] 3.5 Add `countByEmoji(string $dashboardUuid): array` — returns `[emoji => count, ...]` associative array via SQL GROUP BY
- [ ] 3.6 Add `addReaction(string $dashboardUuid, string $userId, string $emoji): DashboardReaction` — insert new row, throw `IntegrityConstraintViolationException` if duplicate already exists (caller handles idempotency)
- [ ] 3.7 Add `removeReaction(string $dashboardUuid, string $userId, string $emoji): bool` — delete matching row, return true if found, false if not (idempotent)
- [ ] 3.8 Add `deleteByDashboardUuid(string $dashboardUuid): int` — cascade delete all reactions for dashboard, return count deleted
- [ ] 3.9 Add PHPUnit test fixtures: add reactions, count by emoji, find by user, delete by dashboard UUID

## 4. Admin settings

- [ ] 4.1 Update `appinfo/info.xml` to declare two admin settings: `mydash.reactions_enabled_default` (type bool, default true) and `mydash.reactions_allowed_emojis` (type string/JSON, default `["👍","❤️","🎉","😂","🤔","😢"]`)
- [ ] 4.2 Add getter methods in a new config service or extend existing `ConfigService`: `isReactionsEnabledByDefault()` and `getAllowedEmojis(): array`

## 5. Service layer

- [ ] 5.1 Create `lib/Service/ReactionService.php` with methods:
  - `isReactionsEnabled(Dashboard $dashboard): bool` — checks `reactionsEnabled` field (null = follow global, 1 = true, 0 = false)
  - `validateEmoji(string $emoji): void` — throw `\InvalidArgumentException` if emoji not in whitelist
  - `addReaction(string $dashboardUuid, string $userId, string $emoji): DashboardReaction` — permission check + validation + call mapper, swallow IntegrityConstraintViolation (idempotent)
  - `removeReaction(string $dashboardUuid, string $userId, string $emoji): bool` — permission check + call mapper
  - `getReactionsSummary(string $dashboardUuid): array` — returns `{counts: {emoji: number, ...}, mine: [emoji, ...], enabled: boolean}`
  - `getReactorsByEmoji(string $dashboardUuid, string $emoji, ?string $cursor): array` — returns `[{userId, displayName, reactedAt}]` capped at 100 with pagination
  - `deleteReactionsByDashboard(string $dashboardUuid): void` — called from DashboardService on cascade delete
- [ ] 5.2 Permission checks: throw `\OCP\AppFramework\OCS\OCSException` (403) if user cannot VIEW the dashboard (inherit permission model from `dashboards` capability)
- [ ] 5.3 All validation errors throw `\OCP\AppFramework\OCS\OCSException` with appropriate HTTP status (400 for bad emoji, 403 for permission, 500 for DB)

## 6. Controller + routes

- [ ] 6.1 Add `DashboardController::getReactions(string $uuid)` mapped to `GET /api/dashboards/{uuid}/reactions` (logged-in) — calls `ReactionService::getReactionsSummary()`, returns JSON with `{counts, mine, enabled}`
- [ ] 6.2 Add `DashboardController::addReaction(string $uuid)` mapped to `POST /api/dashboards/{uuid}/reactions` (logged-in) — body `{emoji}`, calls `ReactionService::addReaction()`, returns 200 with updated summary (idempotent)
- [ ] 6.3 Add `DashboardController::removeReaction(string $uuid, string $emoji)` mapped to `DELETE /api/dashboards/{uuid}/reactions/{emoji}` (logged-in) — calls `ReactionService::removeReaction()`, returns 204 always (idempotent)
- [ ] 6.4 Add `DashboardController::getReactorsByEmoji(string $uuid, string $emoji, ?string $cursor)` mapped to `GET /api/dashboards/{uuid}/reactions/{emoji}/users` (logged-in) — query param `cursor`, calls `ReactionService::getReactorsByEmoji()`, returns `[{userId, displayName, reactedAt}]`
- [ ] 6.5 Register all four routes in `appinfo/routes.php` with proper format requirements (uuid = UUID regex, emoji = any non-empty string)
- [ ] 6.6 All four methods carry `#[NoAdminRequired]` (permission check is runtime, not declarative)

## 7. Dashboard cascade delete

- [ ] 7.1 Update `DashboardService::deleteDashboard(string $uuid)` to call `ReactionService::deleteReactionsByDashboard()` before deleting the dashboard record
- [ ] 7.2 Verify cascade order: reactions deleted first, then dashboard

## 8. Frontend store

- [ ] 8.1 Extend `src/stores/dashboards.js` with `reactionsSummary` object keyed by dashboard UUID: `{counts: {emoji: number, ...}, mine: [emoji, ...], enabled: boolean}`
- [ ] 8.2 Add action `fetchReactionsSummary(dashboardUuid)` that calls `GET /api/dashboards/{uuid}/reactions` and caches the result
- [ ] 8.3 Add action `addReaction(dashboardUuid, emoji)` that calls `POST /api/dashboards/{uuid}/reactions`, updates local store (idempotent)
- [ ] 8.4 Add action `removeReaction(dashboardUuid, emoji)` that calls `DELETE /api/dashboards/{uuid}/reactions/{emoji}`, updates local store (idempotent)
- [ ] 8.5 Defer UI component (emoji picker, reaction bar) to follow-up `dashboard-reactions-ui` change

## 9. PHPUnit tests

- [ ] 9.1 `DashboardReactionMapperTest::testAddReaction` — insert and retrieve, verify unique constraint on duplicate
- [ ] 9.2 `DashboardReactionMapperTest::testRemoveReaction` — delete by UUID+user+emoji, verify idempotent (not-found returns false)
- [ ] 9.3 `DashboardReactionMapperTest::testCountByEmoji` — multiple reactions per emoji, verify counts
- [ ] 9.4 `DashboardReactionMapperTest::testDeleteByDashboardUuid` — cascade delete all reactions for a dashboard
- [ ] 9.5 `ReactionServiceTest::testAddReactionIdempotent` — re-post same emoji returns 200, summary unchanged
- [ ] 9.6 `ReactionServiceTest::testValidateEmojiRejectsNonWhitelisted` — emoji not in list throws InvalidArgumentException
- [ ] 9.7 `ReactionServiceTest::testPermissionCheckViewOnly` — non-view-capable user cannot react (403)
- [ ] 9.8 `ReactionServiceTest::testIsReactionsEnabled` — null follows global, 1 = true, 0 = false
- [ ] 9.9 `DashboardServiceTest::testDeleteDashboardCascadesReactions` — deleting dashboard removes all its reactions
- [ ] 9.10 `ReactionServiceTest::testGetReactorsByEmojiPagination` — cursor-based pagination, 100-item cap

## 10. End-to-end Playwright tests

- [ ] 10.1 Logged-in user can POST reaction to a dashboard they can view (API call from fixture), verify 200
- [ ] 10.2 User re-posts same emoji, verify 200 and reaction count unchanged (idempotent)
- [ ] 10.3 User can DELETE their reaction, verify 204
- [ ] 10.4 User cannot react to dashboard they cannot view (403)
- [ ] 10.5 Admin disables reactions globally, user GETs summary, verify `enabled: false` and POST returns 403
- [ ] 10.6 Admin sets per-dashboard toggle `reactionsEnabled = 0`, user GETs summary, verify `enabled: false` regardless of global setting
- [ ] 10.7 Admin deletes a dashboard, verify all reactions cascade-deleted

## 11. Quality gates

- [ ] 11.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes — fix any pre-existing issues encountered
- [ ] 11.2 ESLint + Stylelint clean on all touched Vue/JS files
- [ ] 11.3 Update OpenAPI spec / Postman collection for the four new endpoints
- [ ] 11.4 i18n keys for all error messages (`"Emoji not allowed"`, `"Reactions disabled"`, etc.) in both `nl` and `en` per the i18n requirement
- [ ] 11.5 SPDX headers on every new PHP file (inside the docblock per the SPDX-in-docblock convention) — gate-spdx must pass
- [ ] 11.6 Run all 10 `hydra-gates` locally before opening PR
