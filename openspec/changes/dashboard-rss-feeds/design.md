# Dashboard RSS Feeds — Design Document

## Architecture

### Backend

| Class | Namespace | Role |
|---|---|---|
| `FeedToken` | `OCA\MyDash\Db` | ORM entity; maps to `oc_mydash_feed_tokens` |
| `FeedTokenMapper` | `OCA\MyDash\Db` | QBMapper subclass; all DB queries for feed tokens |
| `FeedTokenFactory` | `OCA\MyDash\Service` | Creates new `FeedToken` entities with cryptographic token generation |
| `FeedTokenService` | `OCA\MyDash\Service` | Orchestrates CRUD for tokens, handles regeneration and revocation |
| `FeedRenderer` | `OCA\MyDash\Service` | Renders RSS 2.0 or Atom feeds from dashboard list |
| `FeedApiController` | `OCA\MyDash\Controller` | HTTP layer for `/api/feed/token` endpoints |
| `FeedPublicController` | `OCA\MyDash\Controller` | HTTP layer for public `/feed/{token}.xml` endpoint |
| `FeedTokenTableBuilder` | `OCA\MyDash\Migration` | Encapsulates the DDL for `oc_mydash_feed_tokens` |

### Data Model

Feed tokens are stored in the `oc_mydash_feed_tokens` table with the following fields:
- **id**: Auto-increment integer primary key
- **userId**: Nextcloud user ID (VARCHAR(64)), for whom this token was issued
- **token**: URL-safe cryptographically random token string (VARCHAR(64))
- **createdAt**: Timestamp (DATETIME) when the token was first generated
- **lastUsedAt**: Nullable timestamp (DATETIME NULL) of the last successful feed request
- **revokedAt**: Nullable timestamp (DATETIME NULL) indicating soft revocation

Constraints:
- UNIQUE index on `(userId)` — only one active token per user at any time
- Token format: 32 random bytes encoded as base64-url string using `-` and `_` instead of `+` and `/`

### Data Flow

#### GET /api/feed/token (request/retrieve token)

```
Browser (logged in)
  └─ api.getFeedToken()
       └─ GET /apps/mydash/api/feed/token
            └─ FeedApiController::getToken()
                 ├─ (auth guard: require user session)
                 ├─ FeedTokenService::getOrCreateToken(userId)
                 │    ├─ FeedTokenMapper::findByUserId(userId)
                 │    └─ (if null) FeedTokenFactory::create(userId) → FeedTokenMapper::insert()
                 └─ return 200 { token: "...", url: "https://instance/apps/mydash/feed/..." }
```

#### POST /api/feed/token/regenerate (rotate token)

```
Browser (logged in)
  └─ api.regenerateToken()
       └─ POST /apps/mydash/api/feed/token/regenerate
            └─ FeedApiController::regenerateToken()
                 ├─ (auth guard: require user session)
                 ├─ FeedTokenService::regenerateToken(userId)
                 │    ├─ FeedTokenMapper::findByUserId(userId) [if exists]
                 │    │    └─ update existing record: set revokedAt = now
                 │    ├─ FeedTokenFactory::create(userId) → new token
                 │    └─ FeedTokenMapper::insert(newToken)
                 └─ return 200 { token: "new_...", url: "..." }
```

#### DELETE /api/feed/token (soft-revoke)

```
Browser (logged in)
  └─ api.deleteToken()
       └─ DELETE /apps/mydash/api/feed/token
            └─ FeedApiController::revokeToken()
                 ├─ (auth guard: require user session)
                 ├─ FeedTokenService::revokeToken(userId)
                 │    └─ FeedTokenMapper::findByUserId(userId)
                 │         └─ (if exists) update: set revokedAt = now
                 └─ return 204 No Content
```

#### GET /feed/{token}.xml (public feed)

```
Browser (public, no auth)
  └─ GET /apps/mydash/feed/{token}.xml
       └─ FeedPublicController::renderFeed(token)
            ├─ FeedTokenMapper::findByToken(token)
            │    └─ verify revokedAt is NULL
            │    └─ (if revoked or not found) return 404
            ├─ update token record: set lastUsedAt = now
            ├─ resolve token.userId
            ├─ DashboardService::getUserDashboards(userId) [uses existing DashboardService]
            ├─ PermissionService::filterAccessibleDashboards(dashboards, userId) [via existing ACL capability]
            ├─ sort dashboards by updatedAt DESC
            ├─ limit to config('mydash.feed_item_cap', 50)
            ├─ FeedRenderer::renderRss(dashboards, userId) [or renderAtom()]
            └─ return 200 { Content-Type: application/rss+xml, body: RSS 2.0 }
```

### Data Flow Summary

1. **Token Issuance**: User requests token → check if exists → create if not → return token + URL
2. **Token Rotation**: User regenerates → mark old as revoked → generate and store new → return new token
3. **Token Revocation**: User deletes → mark as revoked (idempotent, no error if doesn't exist)
4. **Feed Rendering**: Public client uses token → validate (not revoked, exists) → fetch accessible dashboards → apply ACL filter → render RSS/Atom

### Key Design Decisions

- **Single token per user** (UNIQUE on userId) — simplifies rotation and revocation, matches common RSS reader patterns
- **Soft revocation** (revokedAt column) — enables audit trail and re-issuance without losing history
- **Per-user opt-in** (feeds disabled until token requested) — default-deny security posture
- **ACL filtering** — feeds respect dashboard permissions via existing `PermissionService` or equivalent capability
- **Admin-configurable item cap** — prevents unbounded response sizes via `OCP\IConfig::getAppValue('mydash', 'mydash.feed_item_cap', '50')`
- **Cryptographic randomness** — 32 bytes random via `random_bytes(32)`, base64-url encoded to ~43 chars
- **Public endpoint** (`#[PublicPage]` + `#[NoCSRFRequired]`) — feeds must be accessible without Nextcloud session

### Seed Data

No seed data required for this change — feed tokens are user-generated on demand, not pre-populated.

### Reuse Analysis

- **Token generation**: Custom implementation (similar to password reset tokens but simpler)
- **Dashboard retrieval**: Leverage existing `DashboardService::getUserDashboards()`
- **ACL filtering**: Leverage existing permissions capability (field-level or object-level RBAC)
- **Dashboard entity**: Use existing Dashboard entity (no schema changes)
- **Config**: Use `OCP\IConfig::getAppValue()` for admin-configurable feed item cap
