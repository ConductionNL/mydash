# Tasks — dashboard-rss-feeds

## 1. Schema migration

- [ ] 1.1 Create `lib/Migration/VersionXXXXDate2026...AddFeedTokensTable.php` adding new `oc_mydash_feed_tokens` table with columns: `id` (INT, auto-increment, PK), `userId` (VARCHAR(64)), `token` (VARCHAR(64)), `createdAt` (DATETIME), `lastUsedAt` (DATETIME NULL), `revokedAt` (DATETIME NULL)
- [ ] 1.2 Add UNIQUE index on `(userId)` to enforce one token per user; add index on `token` for fast public feed lookup
- [ ] 1.3 Confirm migration is reversible (drop table in `postSchemaChange` rollback path)
- [ ] 1.4 Run migration locally against sqlite, mysql, and postgres; verify schema applied cleanly each time

## 2. Domain model

- [ ] 2.1 Create `lib/Db/FeedToken.php` entity with fields: `id`, `userId`, `token`, `createdAt`, `lastUsedAt`, `revokedAt` (use Entity `__call` pattern, no named args in setters)
- [ ] 2.2 Add getters/setters for each field with proper type hints and Entity conventions
- [ ] 2.3 Add helper method `isRevoked()` returning `revokedAt !== null`
- [ ] 2.4 Add helper method `isValid()` returning `revokedAt === null` (token is active)

## 3. Mapper layer

- [ ] 3.1 Create `lib/Db/FeedTokenMapper.php` extending `QBMapper`
- [ ] 3.2 Add `FeedTokenMapper::findByToken(string $token): FeedToken` — returns token record; throws `DoesNotExistException` if not found or if revoked
- [ ] 3.3 Add `FeedTokenMapper::findByUserId(string $userId): ?FeedToken` — returns active token for user or null if none exists
- [ ] 3.4 Add `FeedTokenMapper::updateLastUsed(FeedToken $token): void` — sets `lastUsedAt` to now and persists
- [ ] 3.5 Add fixture-based PHPUnit tests covering: token lookup, revoked token (not found), user with no token, update last-used

## 4. Service layer — Token Management

- [ ] 4.1 Create `lib/Service/FeedTokenService.php` with dependency injection: `FeedTokenMapper`, `ITimeFactory` (or \DateTime), `ILogger`
- [ ] 4.2 Add `FeedTokenService::getOrCreateToken(string $userId): FeedToken` — atomically fetch existing token or create a new one; returns the token record
- [ ] 4.3 Add `FeedTokenService::regenerateToken(string $userId): FeedToken` — set `revokedAt` on existing token, generate new token (32 random bytes, base64-url encoded), insert new record, return new token
- [ ] 4.4 Add `FeedTokenService::revokeToken(string $userId): void` — set `revokedAt` on the user's active token; idempotent (succeeds even if no token exists)
- [ ] 4.5 Add `FeedTokenService::resolveToken(string $token): ?FeedToken` — lookup token, return null if not found or revoked, else return token record; call `mapper.updateLastUsed()` before returning
- [ ] 4.6 Token format: `bin2hex(random_bytes(32))` or equivalent base64-url encoding; ensure URL-safe output (no `+`, `/`, `=` if using base64-url)

## 5. Service layer — Feed Rendering

- [ ] 5.1 Create `lib/Service/FeedService.php` with dependencies: `DashboardMapper`, `PermissionService`, `IUserManager`, `IConfig`, `ILogger`
- [ ] 5.2 Add `FeedService::renderFeed(FeedToken $token): string` — main entry point
  - [ ] 5.2a Query all dashboards accessible to the token's user via `DashboardMapper::findByUserId(token.userId)`
  - [ ] 5.2b Filter by the user's actual ACLs (call `PermissionService` or equivalent to check each dashboard)
  - [ ] 5.2c Sort by `updatedAt` descending (most recent first)
  - [ ] 5.2d Cap at `IConfig::getAppValue('mydash', 'mydash.feed_item_cap', '50')` items
  - [ ] 5.2e Build RSS 2.0 or Atom feed XML; return as string
- [ ] 5.3 Add `FeedService::buildRssFeed(array $dashboards, string $userId, string $feedUrl): string` — construct RSS 2.0 XML
  - [ ] 5.3a `<rss version="2.0">` root, `<channel>` with `<title>`, `<link>` (Nextcloud root), `<description>`
  - [ ] 5.3b One `<item>` per dashboard with: `<title>` (name), `<link>` (deep-link to dashboard), `<description>` (escaped), `<pubDate>` (RFC 2822, updatedAt), `<guid isPermaLink="false">` (UUID), `<author>` (owner display name)
- [ ] 5.4 Add `FeedService::getOwnerDisplayName(string $userId): string` — resolve user's display name via `IUserManager::get(userId).getDisplayName()`; fallback to userId if user not found
- [ ] 5.5 Add helper `escapeDashboardDescription(string $desc): string` — XML-escape `&`, `<`, `>`, `"` in descriptions to prevent injection

## 6. Controller + routes

- [ ] 6.1 Create `lib/Controller/FeedController.php` extending `ApiController`
- [ ] 6.2 Add `FeedController::getToken()` mapped to `GET /api/feed/token` (`#[NoAdminRequired]`, logged-in user required)
  - [ ] 6.2a Call `FeedTokenService::getOrCreateToken($this->userId)`
  - [ ] 6.2b Return HTTP 200 with `{"token": "...", "url": "https://.../feed/<token>.xml"}`
  - [ ] 6.2c Construct absolute feed URL using `IUrlGenerator` or similar (app root + route name + token)
- [ ] 6.3 Add `FeedController::regenerateToken()` mapped to `POST /api/feed/token/regenerate` (`#[NoAdminRequired]`, logged-in)
  - [ ] 6.3a Call `FeedTokenService::regenerateToken($this->userId)`
  - [ ] 6.3b Return HTTP 200 with new token and URL
- [ ] 6.4 Add `FeedController::revokeToken()` mapped to `DELETE /api/feed/token` (`#[NoAdminRequired]`, logged-in)
  - [ ] 6.4a Call `FeedTokenService::revokeToken($this->userId)`
  - [ ] 6.4b Return HTTP 204 No Content (idempotent)
- [ ] 6.5 Add `FeedController::publicFeed(string $token)` mapped to `GET /feed/{token}.xml` (public, no auth required)
  - [ ] 6.5a Call `FeedTokenService::resolveToken($token)` to fetch and validate token (returns null if revoked or not found)
  - [ ] 6.5b If null, return HTTP 404 (do NOT differentiate between revoked and non-existent to avoid leaking status)
  - [ ] 6.5c Call `FeedService::renderFeed($token)` to generate feed XML
  - [ ] 6.5d Return HTTP 200 with Content-Type `application/rss+xml; charset=utf-8` and the feed body
- [ ] 6.6 Register all four routes in `appinfo/routes.php` with correct requirements and methods
- [ ] 6.7 Confirm methods carry correct auth attributes (`#[NoAdminRequired]` for authed endpoints, no attribute for public)

## 7. Database sensitive-parameter marking

- [ ] 7.1 If `config/sensitive-parameters.php` exists, add `oc_mydash_feed_tokens.token` to the sensitive list (prevents plaintext logging)
- [ ] 7.2 If file does not exist, document the requirement in a code comment or TODO

## 8. PHPUnit tests

- [ ] 8.1 `FeedTokenMapperTest::findByToken` — valid token, revoked token, non-existent token
- [ ] 8.2 `FeedTokenMapperTest::findByUserId` — user with active token, user with no token, user with only revoked tokens
- [ ] 8.3 `FeedTokenMapperTest::updateLastUsed` — verify lastUsedAt is updated on persist
- [ ] 8.4 `FeedTokenServiceTest::getOrCreateToken` — first call creates, second call returns same, isolation between users
- [ ] 8.5 `FeedTokenServiceTest::regenerateToken` — old token revoked, new token unique, old no longer resolves
- [ ] 8.6 `FeedTokenServiceTest::revokeToken` — idempotent on non-existent token
- [ ] 8.7 `FeedTokenServiceTest::resolveToken` — valid token updates lastUsedAt, revoked token returns null, non-existent returns null
- [ ] 8.8 `FeedServiceTest::renderFeed` — multi-dashboard fixture, correct sort order (newest first), ACL filtering (user sees only accessible), item cap enforced
- [ ] 8.9 `FeedServiceTest::buildRssFeed` — valid RSS 2.0 XML structure, all fields present, special characters escaped
- [ ] 8.10 `FeedControllerTest::publicFeed` — valid token (200, correct feed), revoked token (404), non-existent token (404)

## 9. Integration tests (E2E)

- [ ] 9.1 Create a dashboard fixture for integration testing; grant permission to specific user
- [ ] 9.2 Test full flow: user A requests token, gets feed URL, public client fetches feed, verifies dashboard appears
- [ ] 9.3 Test ACL: user B (different user) cannot access user A's private dashboard via user A's feed
- [ ] 9.4 Test regenerate: token A → revoke+regenerate → token B, old feed returns 404, new feed works
- [ ] 9.5 Test soft-revoke: token active → DELETE → 404, later GET /api/feed/token issues new token

## 10. Documentation

- [ ] 10.1 Add to `CHANGELOG.md`: "New capability `dashboard-rss-feeds`: per-user RSS feed token exposing accessible dashboards; opt-in via GET /api/feed/token"
- [ ] 10.2 Document token format and security considerations in code comments and/or app README
- [ ] 10.3 Document the four endpoints in app API docs (if such docs exist) or in a separate FEED_API.md file

