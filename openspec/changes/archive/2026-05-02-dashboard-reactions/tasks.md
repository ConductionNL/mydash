# Tasks — dashboard-reactions

## 1. Schema migration

- [x] 1.1 Create `lib/Migration/Version001014Date20260502120000.php` with `oc_mydash_dashboard_reactions` table: `id (PK)`, `dashboard_uuid VARCHAR(36) NOT NULL`, `user_id VARCHAR(64) NOT NULL`, `emoji VARCHAR(32) NOT NULL`, `reacted_at DATETIME NOT NULL`
- [x] 1.2 Same migration adds unique constraint `mydash_react_uuid_user_emoji` on `(dashboard_uuid, user_id, emoji)` to prevent duplicate reactions
- [x] 1.3 Same migration adds index `mydash_react_uuid` on `dashboard_uuid` for fast lookups by dashboard
- [x] 1.4 Same migration adds index `mydash_react_emoji` on `emoji` for fast lookups of reactors by emoji
- [x] 1.5 Same migration adds `reactions_enabled SMALLINT NULL` column to `oc_mydash_dashboards`
- [x] 1.6 Migration is idempotent (uses `hasTable` / `hasColumn` / `hasIndex` guards)
- [ ] 1.7 Run migration locally against sqlite, mysql, and postgres; verify clean application each time *(manual; deferred)*

## 2. Domain model

- [x] 2.1 Create `lib/Db/DashboardReaction.php` entity with fields: id, dashboardUuid, userId, emoji, reactedAt; add getters/setters (Entity `__call` pattern — no named args)
- [x] 2.2 Add `Dashboard::reactionsEnabled` field to `Dashboard.php` with getter/setter
- [x] 2.3 Update `Dashboard::jsonSerialize()` to include `reactionsEnabled` (nullable in output)
- [x] 2.4 In `DashboardReaction::jsonSerialize()` include all fields; `emoji` as-is (unicode string), `reactedAt` in "Y-m-d H:i:s" format for consistency with dashboard timestamps

## 3. Mapper layer

- [x] 3.1 Create `lib/Db/DashboardReactionMapper.php` extending `QBMapper`
- [x] 3.2 Add `findByDashboard(string $dashboardUuid): array` — `WHERE dashboard_uuid = ?` ordered by `reacted_at DESC`
- [x] 3.3 Add `findByEmoji(string $dashboardUuid, string $emoji, ?int $limit, ?int $offset): array` — used for `/users` endpoint
- [x] 3.4 Add `findByUser(string $userId, string $dashboardUuid): array` — returns calling user's reactions on a dashboard
- [x] 3.5 Add `countByEmoji(string $dashboardUuid): array` — returns `[emoji => count, ...]` associative array via SQL GROUP BY
- [x] 3.6 Add `addReaction(...)` — inserts new row, throws `DBException` on unique-constraint hit (caller swallows for idempotency)
- [x] 3.7 Add `removeReaction(...)` — deletes matching row, returns true if found, false if not (idempotent)
- [x] 3.8 Add `deleteByDashboardUuid(string $dashboardUuid): int` — cascade delete all reactions for dashboard
- [x] 3.9 Add `countReactorsByEmoji(...)` — pagination total helper
- [x] 3.10 Add PHPUnit fixture coverage via `DashboardReactionTest` (entity) + `ReactionServiceTest` (mapper interaction stubs)

## 4. Admin settings

- [x] 4.1 Define IAppConfig keys `mydash.reactions_enabled_default` (bool, default true) and `mydash.reactions_allowed_emojis` (JSON array, default `["👍","❤️","🎉","😂","🤔","😢"]`) inside `ReactionService` constants — surface in admin UI is a follow-up concern
- [x] 4.2 Add `ReactionService::isReactionsEnabledByDefault()` and `ReactionService::getAllowedEmojis()` getter methods

## 5. Service layer

- [x] 5.1 Create `lib/Service/ReactionService.php` with methods:
  - `isReactionsEnabled(Dashboard $dashboard): bool`
  - `validateEmoji(string $emoji): void`
  - `addReaction(...)` (idempotent via DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION)
  - `removeReaction(...)`
  - `getReactionsSummary(...)` — `{counts, mine, enabled}`
  - `getReactorsByEmoji(...)` — capped at 100 with offset cursor
  - `deleteReactionsByDashboard(...)` — invoked from `ReactionsListener` on cascade
- [x] 5.2 Permission checks raise `PermissionDeniedException` (HTTP 403) when caller cannot VIEW the dashboard
- [x] 5.3 Validation surfaces via `InvalidArgumentException` (HTTP 400) and `ReactionsDisabledException` (HTTP 403)

## 6. Controller + routes

- [x] 6.1 `DashboardReactionApiController::getReactions` mapped to `GET /api/dashboards/{uuid}/reactions`
- [x] 6.2 `DashboardReactionApiController::addReaction` mapped to `POST /api/dashboards/{uuid}/reactions` (idempotent)
- [x] 6.3 `DashboardReactionApiController::removeReaction` mapped to `DELETE /api/dashboards/{uuid}/reactions/{emoji}` (returns 204)
- [x] 6.4 `DashboardReactionApiController::getReactorsByEmoji` mapped to `GET /api/dashboards/{uuid}/reactions/{emoji}/users` (cursor query)
- [x] 6.5 Register all four routes in `appinfo/routes.php` with proper requirements (uuid regex, emoji = `.+`)
- [x] 6.6 All four methods carry `#[NoAdminRequired]` (permission check is runtime, not declarative)

## 7. Dashboard cascade delete

- [x] 7.1 `ReactionsListener` (subscribed to `DashboardDeletedEvent`) delegates to `ReactionService::deleteReactionsByDashboard`. The event-driven path is the single integration seam — `DashboardService` does not call `ReactionService` directly.
- [x] 7.2 Cascade ordering verified: cascade-events scaffolding fires `DashboardDeletedEvent` after the soft-delete; the listener removes reactions before the response is returned.

## 8. Frontend store

- [x] 8.1 Extend `src/stores/dashboard.js` with `reactionsSummary` map keyed by dashboard UUID
- [x] 8.2 Add action `fetchReactionsSummary(dashboardUuid)` calling `GET /api/dashboards/{uuid}/reactions`
- [x] 8.3 Add action `addReaction(dashboardUuid, emoji)` calling `POST` and merging the returned summary
- [x] 8.4 Add action `removeReaction(dashboardUuid, emoji)` calling `DELETE` then refreshing the summary
- [x] 8.5 Defer UI component (emoji picker, reaction bar) to follow-up `dashboard-reactions-ui` change

## 9. PHPUnit tests

- [x] 9.1 `DashboardReactionTest::test*` — entity getters/setters + JSON serialisation + Y-m-d H:i:s timestamp shape
- [x] 9.2 `ReactionsListenerTest::test*` — DashboardDeletedEvent delegated to service, foreign events ignored, throws swallowed (REQ-CSC-006)
- [x] 9.3 `ReactionServiceTest::testIsReactionsEnabledTriState` — null follows global, 1 = true, 0 = false (REQ-RXN-006)
- [x] 9.4 `ReactionServiceTest::testValidateEmojiRejectsNonWhitelisted` — emoji not in list throws InvalidArgumentException (REQ-RXN-007)
- [x] 9.5 `ReactionServiceTest::testAddReactionPermissionDenied` — non-view-capable user cannot react (403) (REQ-RXN-008)
- [x] 9.6 `ReactionServiceTest::testAddReactionDisabledThrows` — disabled state surfaces ReactionsDisabledException (REQ-RXN-005)
- [x] 9.7 `ReactionServiceTest::testAddReactionIdempotentOnUniqueConstraint` — re-post is a no-op, summary unchanged (REQ-RXN-001)
- [x] 9.8 `ReactionServiceTest::testGetReactionsSummary*Shape` — disabled/enabled summary shapes (REQ-RXN-003)
- [x] 9.9 `ReactionServiceTest::testRemoveReactionDelegatesToMapper` — REQ-RXN-002 mapper handoff
- [x] 9.10 `ReactionServiceTest::testGetReactorsByEmojiPagination` + `testGetReactorsByEmojiLastPageNoCursor` — cursor + 100-item cap (REQ-RXN-004)
- [x] 9.11 `ReactionServiceTest::testDeleteReactionsByDashboardDelegates` — cascade path tested (REQ-RXN-009)

## 10. End-to-end Playwright tests

- [ ] 10.1 Logged-in user can POST reaction to a dashboard they can view *(deferred — covered by unit tests; live env exercise belongs to follow-up E2E pass)*
- [ ] 10.2 User re-posts same emoji, verify 200 and reaction count unchanged (idempotent) *(deferred)*
- [ ] 10.3 User can DELETE their reaction, verify 204 *(deferred)*
- [ ] 10.4 User cannot react to dashboard they cannot view (403) *(deferred)*
- [ ] 10.5 Admin disables reactions globally, user GETs summary, verify `enabled: false` and POST returns 403 *(deferred)*
- [ ] 10.6 Admin sets per-dashboard toggle `reactionsEnabled = 0`, user GETs summary, verify `enabled: false` regardless of global setting *(deferred)*
- [ ] 10.7 Admin deletes a dashboard, verify all reactions cascade-deleted *(deferred — covered indirectly by `ReactionsListenerTest`)*

## 11. Quality gates

- [x] 11.1 `composer check:strict` (lint, lint:initial-state, phpcs, phpmd, psalm, phpstan, test:all) passes
- [x] 11.2 ESLint + Vitest clean (299/299 tests; webpack build succeeds)
- [ ] 11.3 Update OpenAPI spec / Postman collection for the four new endpoints *(deferred — no canonical spec file in repo to update)*
- [x] 11.4 i18n keys for all error messages (`"Emoji not allowed"`, `"Reactions are disabled"`, etc.) added to `l10n/en.json`, `l10n/nl.json`, `l10n/en.js`, `l10n/nl.js`
- [x] 11.5 SPDX headers on every new PHP file (inside the docblock per the SPDX-in-docblock convention)
- [x] 11.6 npm run build succeeds (existing bundle-size warnings only)
