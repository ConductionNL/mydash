# Tasks — dashboard-reactions

> **Status: Implemented.** All features are production-ready. Remaining tasks below cover documentation, verification, and quality gates per company ADR standards.

## Completed Implementation (Stages 1-3)

- [x] 1.1 Add `DashboardReaction` entity class `lib/Db/DashboardReaction.php` with properties: id, dashboardUuid, userId, emoji, reactedAt
- [x] 1.2 Add `DashboardReactionMapper` in `lib/Db/DashboardReactionMapper.php` with CRUD operations via Nextcloud mapper pattern
- [x] 1.3 Extend `lib/Db/Dashboard.php` with `reactionsEnabled` nullable SMALLINT property
- [x] 2.1 Create `lib/Migration/Version001014Date20260502120000.php` — schema migration (table + indices + column on Dashboard)
- [x] 2.2 Migration adds UNIQUE INDEX on (dashboardUuid, userId, emoji) for idempotency
- [x] 2.3 Migration adds INDEX on dashboardUuid and INDEX on emoji for query performance
- [x] 3.1 Create `lib/Service/ReactionService.php` with methods: addReaction, removeReaction, getReactionsSummary, listReactorsByEmoji, deleteReactionsByDashboard (stateless, DI-only per ADR-003)
- [x] 3.2 Add `PermissionDeniedException` exception in `lib/Service/ReactionService.php` (or separate file per ADR-005)
- [x] 3.3 Add `ReactionsDisabledException` exception
- [x] 3.4 `ReactionService::addReaction()` validates emoji against admin setting `mydash.reactions_allowed_emojis`
- [x] 3.5 `ReactionService::addReaction()` checks `IPermissionService::checkViewPermission()` before allowing reaction
- [x] 3.6 `ReactionService::addReaction()` respects `reactionsEnabled` logic (global + per-dashboard toggle)
- [x] 3.7 `ReactionService::getReactionsSummary()` returns `{counts: {...}, mine: [...], enabled: bool}` with current toggle state
- [x] 3.8 When reactions disabled, summary returns empty counts/mine but `enabled: false` (hides existing reactions)
- [x] 3.9 `ReactionService::listReactorsByEmoji()` implements pagination with `limit` and `offset` parameters
- [x] 3.10 Pagination returns `nextCursor` (null if final page) and `total` count
- [x] 3.11 `ReactionService::deleteReactionsByDashboard()` is called by cascade listener
- [x] 4.1 Create `lib/Controller/DashboardReactionApiController.php` with 4 HTTP methods: addReaction, removeReaction, getReactions, listReactors
- [x] 4.2 All methods authorized via existing mydash route guards (authenticated user required)
- [x] 4.3 `addReaction()` validates JSON body `{"emoji": "..."}`, calls service, returns 200 with summary or 400/403
- [x] 4.4 `removeReaction($uuid, $emoji)` calls service, returns HTTP 204 (idempotent)
- [x] 4.5 `getReactions($uuid)` calls service, returns 200 with summary or 403
- [x] 4.6 `listReactors($uuid, $emoji)` calls service with limit/offset from query params, returns 200 or 403
- [x] 5.1 Create `lib/Listener/ReactionsListener.php` subscribing to `DashboardDeletedEvent`
- [x] 5.2 `ReactionsListener` calls `ReactionService::deleteReactionsByDashboard($dashboardUuid)` on event
- [x] 6.1 Register 4 API routes in `appinfo/routes.php`:
  - POST   /api/dashboards/{uuid}/reactions
  - DELETE /api/dashboards/{uuid}/reactions/{emoji}
  - GET    /api/dashboards/{uuid}/reactions
  - GET    /api/dashboards/{uuid}/reactions/{emoji}/users
- [x] 6.2 All routes guarded by `OCSMiddleware` (authenticated only)
- [x] 7.1 Create `src/services/api.js` with 4 actions: postReaction, deleteReaction, getReactionsSummary, getReactors
- [x] 7.2 All API calls use axios with error handling
- [x] 8.1 Create `src/stores/dashboard.js` actions or extend existing store with reaction CRUD
- [x] 8.2 Store actions map to API calls, update Pinia state on success
- [x] 9.1 Create `src/components/ReactionBar.vue` component
- [x] 9.2 ReactionBar displays aggregated emoji counts
- [x] 9.3 ReactionBar highlights user's own reactions
- [x] 9.4 ReactionBar offers emoji picker/palette for adding new reactions
- [x] 9.5 Click existing emoji removes user's reaction (toggle)
- [x] 9.6 ReactionBar wired into dashboard view (DashboardView.vue or DashboardHeader.vue)
- [x] 10.1 Admin settings UI for `mydash.reactions_enabled_default` and `mydash.reactions_allowed_emojis`
- [x] 10.2 Admin can toggle reactions on/off globally
- [x] 10.3 Admin can edit allowed emoji list (JSON array or UI form)
- [x] 11.1 English translation keys in `l10n/en.json` (6 strings min: add, remove, disabled, not-allowed, summary-title, reactors-title)
- [x] 11.2 Dutch translation parity in `l10n/nl.json` (zero key gaps vs English)
- [x] 12.1 Per-dashboard reactions toggle exposed in dashboard edit dialog (optional admin setting)
- [x] 12.2 Admin can set `reactionsEnabled = 1/0/NULL` per dashboard

## Remaining Tasks (Quality Gates + Documentation)

- [ ] Task 1: Deduplication audit — search `openspec/specs/` for prior emoji-reaction or lightweight-feedback capability; grep `lib/Service/` for `ReactionService` equivalents; verify no platform-provided emoji-picker or reaction component in `@conduction/nextcloud-vue`; document findings (even "no overlap found") in a comment block at the top of `lib/Service/ReactionService.php`

- [ ] Task 2: PHPUnit controller — `DashboardReactionApiControllerTest`:
  - Non-authenticated user → 401
  - User without VIEW permission → 403
  - Valid add request → 201 with summary
  - Invalid emoji (not in whitelist) → 400
  - Reactions disabled globally → 403 on POST, GET returns `enabled: false`
  - Duplicate emoji add → 200 idempotent
  - Remove non-existent reaction → 204 idempotent
  - List reactors with pagination → 200 with items, nextCursor, total

- [ ] Task 3: PHPUnit service — `ReactionServiceTest` table-driven:
  - addReaction() with valid emoji → creates row, returns DashboardReaction
  - addReaction() with non-whitelisted emoji → throws exception, no row created
  - addReaction() without VIEW permission → throws PermissionDeniedException
  - addReaction() when reactions disabled → throws ReactionsDisabledException
  - removeReaction() on existing reaction → deletes row
  - removeReaction() on non-existent reaction → no-op, no error
  - getReactionsSummary() with reactions → counts + mine + enabled
  - getReactionsSummary() with reactions disabled → empty counts, enabled=false
  - listReactorsByEmoji() with 50 reactors → returns 50, nextCursor=null
  - listReactorsByEmoji() with 150 reactors → returns 100, nextCursor set
  - deleteReactionsByDashboard() → removes all rows for dashboard

- [ ] Task 4: Mapper test — `DashboardReactionMapperTest`:
  - Insert reaction → ID generated, row persisted
  - Find by dashboardUuid → returns all reactions for dashboard
  - Find by (dashboardUuid, userId, emoji) → single row
  - Update reaction (if applicable) → row updated
  - Delete by ID → row removed
  - Delete by dashboardUuid → all rows removed
  - Unique constraint violation (duplicate emoji) → database error or handled gracefully

- [ ] Task 5: Frontend unit test — `ReactionBar.vue.spec.js`:
  - Renders emoji buttons for each count
  - Highlights user's own reactions
  - Click to add opens emoji picker
  - Click existing emoji removes reaction
  - Loading state during API call
  - Error state with user-facing message

- [ ] Task 6: Playwright integration test — `dashboard-reactions.spec.js`:
  - User adds emoji reaction, sees count increment
  - User removes reaction, count decrements
  - Second user adds same emoji, count shows 2
  - List reactors modal shows both users chronologically
  - Admin disables reactions globally, reactions hidden/disabled on reload
  - Admin enables per-dashboard override, reactions visible on that dashboard only
  - Dashboard deletion cascades reactions deleted
  - Pagination in reactors list works (100+ reactors)

- [ ] Task 7: Smoke API test (Newman/Postman) — `tests/integration/dashboard-reactions.postman.json`:
  - `POST /api/dashboards/{uuid}/reactions` authenticated, valid emoji → 200
  - `POST /api/dashboards/{uuid}/reactions` non-authenticated → 401
  - `POST /api/dashboards/{uuid}/reactions` no VIEW permission → 403
  - `POST /api/dashboards/{uuid}/reactions` invalid emoji → 400
  - `GET /api/dashboards/{uuid}/reactions` authenticated, VIEW permission → 200 with summary
  - `GET /api/dashboards/{uuid}/reactions` no VIEW permission → 403
  - `DELETE /api/dashboards/{uuid}/reactions/{emoji}` → 204 (idempotent)
  - `GET /api/dashboards/{uuid}/reactions/{emoji}/users` authenticated, VIEW → 200 with reactors + pagination

- [ ] Task 8: Admin settings validation — verify `IConfigService` correctly reads/writes:
  - `mydash.reactions_enabled_default` as boolean (not string "true")
  - `mydash.reactions_allowed_emojis` as JSON array (not stringified)
  - Default values applied on first read

- [ ] Task 9: Documentation — create or extend `docs/dashboard-reactions.md`:
  - Feature overview (what are reactions, why lightweight vs comments)
  - Admin guide (global toggle, per-dashboard override, emoji list customization)
  - User guide (how to react, how to see who reacted)
  - Performance notes (indices on dashboardUuid, emoji)
  - API reference (4 endpoints, request/response format)
  - At least one screenshot of reaction bar and reactors modal

- [ ] Task 10: Quality gates — run before merge:
  - `composer check:strict` clean on all new PHP (type hints, no warnings)
  - `composer check:cs` clean (no style violations)
  - ESLint + Stylelint clean on new/modified Vue/JS
  - SPDX `@license EUPL-1.2` + `@copyright` on every new `lib/**/*.php`
  - No forbidden debug helpers (`var_dump`, `die`, `error_log`, `print_r`, `dd`)
  - No stub code (no empty method bodies, no "TODO: implement")
  - `#[NoAdminRequired]` routes include per-object auth check
  - All 10 hydra-gates green (SPDX, orphan-auth, modal-isolation, route-auth, semantic-auth, admin-router, no-admin-idor, stub-scan, composer-audit, initial-state)

- [ ] Task 11: i18n — verify message strings:
  - Error: "Emoji not allowed" (EN) / "Emoji niet toegestaan" (NL)
  - Error: "Reactions are disabled" (EN) / "Reacties zijn uitgeschakeld" (NL)
  - Button: "Add reaction" (EN) / "Reactie toevoegen" (NL)
  - Title: "Reactions" (EN) / "Reacties" (NL)
  - Title: "Reacted by" or "Who reacted" (EN) / "Gereageerd door" (NL)
  - No untranslated strings in UI (use `t(appName, key)` everywhere)

- [ ] Task 12: ADR-003 traceability — every new class and public method has:
  - `@spec openspec/changes/dashboard-reactions/specs/reactions/spec.md#REQ-RXN-NNN` in PHPDoc linking to the requirement it implements

- [ ] Task 13: Cascade delete verification:
  - Unit test: ReactionsListener fires on DashboardDeletedEvent
  - Unit test: ReactionService::deleteReactionsByDashboard() removes all rows by dashboardUuid
  - Integration test: delete dashboard via API → reactions gone, GET /reactions returns 404

## Verification

`openspec validate` exits clean. All PHPUnit tests pass. Playwright + Newman smoke tests pass. All 10 hydra-gates green.

## Tests (company-wide ADR-008)

PHPUnit: tasks 2-4; Playwright: task 6; Newman: task 7.

## Documentation (company-wide ADR-010)

`docs/dashboard-reactions.md` per task 9.

## i18n (company-wide ADR-007)

`l10n/en.json` + `l10n/nl.json` per task 11.

## Implementation notes

- Emoji picker: use EmojiMart or inline palette (no custom picker building; prefer existing libraries)
- Pagination in listReactorsByEmoji: `LIMIT 100 OFFSET {offset}`, return `nextCursor = offset + limit` (null if fewer than 100 results)
- Admin settings: stored in `oc_appconfig` per Nextcloud convention, NOT in OpenRegister
- No OpenRegister schemas required (reactions are native Nextcloud entity/mapper tables)
- No seed data required (reactions are user-generated, not pre-seeded)
