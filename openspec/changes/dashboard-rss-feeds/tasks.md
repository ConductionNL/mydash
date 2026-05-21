# Dashboard RSS Feeds - Tasks

## Database Layer

- [ ] **T01**: Define `FeedToken` entity with fields (id, userId, token, createdAt, lastUsedAt, revokedAt), constants for column names, and `jsonSerialize()` method — `mydash/lib/Db/FeedToken.php`
- [ ] **T02**: Implement `FeedTokenTableBuilder` with full DDL: BIGINT PK, VARCHAR user_id (UNIQUE), VARCHAR token (UNIQUE), DATETIME created_at, DATETIME NULL last_used_at, DATETIME NULL revoked_at — `mydash/lib/Migration/FeedTokenTableBuilder.php`
- [ ] **T03**: Add indexes to `oc_mydash_feed_tokens`: PRIMARY KEY on id, UNIQUE on user_id, UNIQUE on token, INDEX on revoked_at — `mydash/lib/Migration/FeedTokenTableBuilder.php`
- [ ] **T04**: Create database migration `Version001001Date20260521000000` that calls `FeedTokenTableBuilder::create()` to add the feed_tokens table — `mydash/lib/Migration/Version001001Date20260521000000.php`

## Service Layer

- [ ] **T05**: Implement `FeedTokenFactory::create(userId)` to generate a new `FeedToken` entity with 32-byte cryptographically random token (base64-url encoded), current `createdAt` timestamp, `lastUsedAt=NULL`, `revokedAt=NULL` — `mydash/lib/Service/FeedTokenFactory.php`
- [ ] **T06**: Implement `FeedTokenMapper` extending `QBMapper<FeedToken>`: `find(id)`, `findByUserId(userId)`, `findByToken(token)`, `findActive(token)` (token exists AND revokedAt IS NULL) — `mydash/lib/Db/FeedTokenMapper.php`
- [ ] **T07**: Implement `FeedTokenService::getOrCreateToken(userId)` — queries `findByUserId`, returns existing or creates new via `FeedTokenFactory` + insert, returns token object — `mydash/lib/Service/FeedTokenService.php`
- [ ] **T08**: Implement `FeedTokenService::regenerateToken(userId)` — atomically: mark old token `revokedAt = now()` (if exists), generate new token via factory, insert new record, return new token — `mydash/lib/Service/FeedTokenService.php`
- [ ] **T09**: Implement `FeedTokenService::revokeToken(userId)` — idempotent: find token by userId (if exists), set `revokedAt = now()`, save — `mydash/lib/Service/FeedTokenService.php`
- [ ] **T10**: Implement `FeedTokenService::updateLastUsed(token)` — set `lastUsedAt = now()` on token record — `mydash/lib/Service/FeedTokenService.php`
- [ ] **T11**: Implement `FeedRenderer::renderRss(dashboards, userId, instanceName)` — generates valid RSS 2.0 XML with: channel title ("userId's MyDash Dashboards"), link (app home), description, and one item per dashboard with title, link, description, pubDate (RFC 2822), guid, author — `mydash/lib/Service/FeedRenderer.php`
- [ ] **T12**: Implement `FeedRenderer::renderAtom(dashboards, userId, instanceName)` — generates valid Atom 1.0 XML with: feed id, title, updated, link rel="alternate", author, and one entry per dashboard with id, title, link, summary, updated, author — `mydash/lib/Service/FeedRenderer.php`
- [ ] **T13**: Implement token-to-feed URL generation: static method `FeedTokenService::getFeedUrl(token, serverUrl)` — returns absolute URL like `https://instance/index.php/apps/mydash/feed/{token}.xml` — `mydash/lib/Service/FeedTokenService.php`

## HTTP Controller Layer

- [ ] **T14**: Register feed API routes in `routes.php`: `GET /api/feed/token`, `POST /api/feed/token/regenerate`, `DELETE /api/feed/token` (all require `#[NoAdminRequired]` + session auth), `GET /feed/{token}.xml` (public, `#[PublicPage]` + `#[NoCSRFRequired]`) — `mydash/appinfo/routes.php`
- [ ] **T15**: Implement `FeedApiController::getToken()` — extract userId from session, `FeedTokenService::getOrCreateToken(userId)`, construct feed URL, return 200 `{ token: "...", url: "..." }` — `mydash/lib/Controller/FeedApiController.php`
- [ ] **T16**: Implement `FeedApiController::regenerateToken()` — extract userId from session, `FeedTokenService::regenerateToken(userId)`, construct feed URL, return 200 `{ token: "...", url: "..." }` — `mydash/lib/Controller/FeedApiController.php`
- [ ] **T17**: Implement `FeedApiController::revokeToken()` — extract userId from session, `FeedTokenService::revokeToken(userId)` (idempotent), return 204 No Content — `mydash/lib/Controller/FeedApiController.php`
- [ ] **T18**: Implement `FeedPublicController::renderFeed(token, format='rss')` — `FeedTokenService::findActiveToken(token)` (validate not revoked), return 404 if token invalid/revoked, update `lastUsedAt`, resolve userId, fetch accessible dashboards via `DashboardService::getUserDashboards(userId)`, filter via permissions (only accessible dashboards), sort by `updatedAt` DESC, apply item cap from config `mydash.feed_item_cap` (default 50), render via `FeedRenderer::renderRss()` or `renderAtom()`, return 200 with appropriate Content-Type — `mydash/lib/Controller/FeedPublicController.php`
- [ ] **T19**: Add config initialization in repair step: `OCP\IConfig::setAppValue('mydash', 'mydash.feed_item_cap', '50')` if not already set — `mydash/lib/Settings/Admin.php` or repair step

## Frontend — API Client

- [ ] **T20**: Implement `api.js` methods: `getFeedToken()` (GET /api/feed/token), `regenerateFeedToken()` (POST /api/feed/token/regenerate), `revokeFeedToken()` (DELETE /api/feed/token) — `mydash/src/services/api.js`

## Frontend — Components

- [ ] **T21**: Implement feed settings panel in `SettingsView.vue` or `FeedSettingsPanel.vue`: display current token (masked), buttons for "Regenerate Token", "Copy Token URL", "Revoke Token" — `mydash/src/components/FeedSettingsPanel.vue`
- [ ] **T22**: Integrate feed settings into main settings/preferences page — add link or section to FeedSettingsPanel — `mydash/src/views/Settings.vue`
- [ ] **T23**: Add success/error notifications for token operations (regenerate, revoke) using NcNotification — `mydash/src/components/FeedSettingsPanel.vue`

## Security & Testing

- [ ] **T24**: Verify token generation uses `random_bytes(32)` with proper entropy and base64-url encoding (no `+`, `/`, `=`) — unit test `FeedTokenFactoryTest.php`
- [ ] **T25**: Verify `#[PublicPage]` + `#[NoCSRFRequired]` on public feed endpoint — security gate check
- [ ] **T26**: Verify ACL filtering: mock DashboardService to return multiple dashboards, verify only accessible ones appear in feed — unit test `FeedRendererTest.php`
- [ ] **T27**: Verify token revocation: create token, revoke it, attempt public feed access → HTTP 404 — integration test
- [ ] **T28**: Verify per-user opt-in: unregistered token → HTTP 404; after GET /api/feed/token → feed accessible — integration test
- [ ] **T29**: Verify XML escaping in feed items: dashboard description with `&`, `<`, `>` → properly escaped in RSS/Atom — unit test `FeedRendererTest.php`
- [ ] **T30**: Verify RFC 2822 date format for pubDate in RSS feeds — unit test
- [ ] **T31**: Verify feed item cap: create 100 dashboards, fetch feed with cap=10 → exactly 10 items, sorted by updatedAt DESC — integration test
- [ ] **T32**: Write unit tests for `FeedTokenFactory`, `FeedTokenMapper`, `FeedTokenService` (ADR-008) — `mydash/tests/Unit/Service/FeedTokenServiceTest.php` etc.
- [ ] **T33**: Write feature documentation for RSS feeds including setup, token management, feed URL format — `mydash/docs/features/rss-feeds.md`
- [ ] **T34**: Verify i18n support for feed settings UI strings (labels, buttons, notifications) — `mydash/translationfiles/en.json` — ADR-007

## Deduplication Check

- [ ] **T35**: Verify no overlap with existing Dashboard queries — confirm `DashboardService::getUserDashboards()` and ACL filtering reuse existing capabilities rather than duplicating — document findings in task comment
