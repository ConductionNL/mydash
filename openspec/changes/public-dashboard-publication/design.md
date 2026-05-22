# Design — Public Dashboard Publication

## Overview

MyDash today requires every dashboard viewer to be authenticated. This design formalises a publication channel for gemeenten to share read-only open-data dashboards with citizens, journalists, and partners without forcing a login. The owner (typically a gemeente communicatie or open-data coordinator) marks a dashboard as publishable, selects a publication mode (public, signed URL, or password-gated), applies custom branding and SEO directives, and the dashboard becomes viewable at a public URL with all queries executing under a designated publication service account.

## Goals

- Enable unauthenticated (anonymous) read-only access to live dashboards
- Prevent viewers from inheriting elevated permissions — all queries run as a locked-down service account
- Support three publication modes: fully public, signed-URL with expiry, and password-gated
- Apply gemeente branding (logo, colours, footer, language) to the public view
- Control search-engine indexing via robots policy and X-Robots-Tag headers
- Enable CDN fronting via Cache-Control headers and conditional GET support
- Record view metrics in aggregate form (no per-citizen profiling)
- Maintain separation from the existing `dashboard-sharing` capability (which is identity-bound)

## Non-goals

- Interactive filtering on published dashboards — read-only only
- Comment threads or user-contributed content — out of scope
- Authenticated personalisation on public dashboards — belongs to future `interactive-public-dashboard`
- Per-resource access control (v1) — all authenticated readers can access any resource by name
- Garbage collection (v1) — orphaned signed grants accumulate

## Data Model

Seven entities stored in OpenRegister, all scoped to a single tenant (a gemeente):

### DashboardPublication

Represents the publication contract linking a dashboard to a public slug and mode.

**Properties:**
- `publicationId` — unique identifier (UUID)
- `dashboardId` — reference to mydash dashboard being published (register+schema+objectId)
- `slug` — URL-safe identifier (unique within tenant), e.g. `wonen`, `verkeer-live`
- `mode` — publication mode: `public`, `signed`, `password`
- `publishedAt` — timestamp when first published
- `publishedBy` — objectId of user who published
- `status` — current state: `draft`, `published`, `paused`, `retracted`
- `retractionReason` — optional; only set when `status: retracted`
- `lastModifiedAt` — timestamp of most recent config change

### PublicationBranding

Branding and presentation customisation for a published dashboard.

**Properties:**
- `publicationId` — links to DashboardPublication
- `organisationLogo` — file reference (UUID) to logo asset
- `favicon` — file reference to favicon asset
- `primaryColour` — hex colour code
- `secondaryColour` — hex colour code
- `fontFamily` — CSS font family (e.g. `'Helvetica Neue', sans-serif`)
- `footerHtml` — HTML footer content for the public view
- `accessibilityStatementUrl` — URL to gemeente accessibility statement
- `privacyStatementUrl` — URL to gemeente privacy statement
- `contactEmail` — support contact email displayed in footer
- `language` — `nl`, `en`, `fr`, `de` — the UI language for this publication

### PublicationAccess

Search-engine and caching directives for a publication.

**Properties:**
- `publicationId` — links to DashboardPublication
- `robotsPolicy` — `index_follow`, `noindex_nofollow`, or `noindex_follow`
- `cacheControlMaxAge` — seconds (e.g. 3600 for 1 hour, 0 for no-cache)
- `staleWhileRevalidate` — seconds (e.g. 604800 for 7 days)
- `allowedReferrers` — optional array of CORS allowlist referrer domains
- `allowedCountries` — optional array of ISO 3166-1 alpha-2 codes (geofence)

### SignedUrlGrant

Represents a time-limited, usage-limited grant for accessing a `signed`-mode publication.

**Properties:**
- `grantId` — unique identifier (UUID)
- `publicationId` — links to DashboardPublication (must have `mode: signed`)
- `issuedTo` — free-text label (e.g. "Press release distribution 2024-05-10")
- `issuedAt` — timestamp of creation
- `expiresAt` — timestamp when this grant expires
- `signature` — HMAC-SHA256 signature of `{publicationId}:{grantId}:{expiresAt}` using tenant secret
- `usageCount` — number of times this grant has been used to access the publication
- `lastUsedAt` — timestamp of most recent use
- `revoked` — boolean; when true, all access via this grant is denied

### PasswordGate

Configuration for a `password`-mode publication access gate.

**Properties:**
- `publicationId` — links to DashboardPublication (must have `mode: password`)
- `passwordHash` — bcrypt hash of the password (minimum cost 12)
- `hint` — optional hint text shown after N failed attempts
- `attemptsLockoutThreshold` — number of failed attempts before temporary lockout (e.g. 5)
- `lockoutDurationSeconds` — duration of temporary lockout (e.g. 300 for 5 minutes)

### PublicationServiceAccount

Represents a service account used to execute queries for all publications in a tenant.

**Properties:**
- `tenantId` — which tenant this account belongs to
- `accountId` — identifier for this service account (e.g. `publication-reader-prod`)
- `allowedRegisters` — array of register names this account can query
- `allowedSchemas` — array of schema names this account can query
- `rowLevelFilter` — optional JSON predicate limiting rows visible to this account (e.g. `{status: "published"}`)
- `createdAt` — timestamp
- `rotatedAt` — timestamp of most recent credential rotation

### PublicationViewLog

Aggregate (no PII) log of viewers accessing a publication. Recorded once per hour bucket.

**Properties:**
- `publicationId` — links to DashboardPublication
- `day` — ISO date string (YYYY-MM-DD)
- `hourBucket` — 0–23, UTC hour
- `viewCount` — number of requests in this hour bucket
- `uniqueSessionCount` — count of unique sessions derived from salted cookie hashes (not backreferable to identity)
- `topCountry` — ISO 3166-1 alpha-2 code of most requests (derived from GeoIP on request metadata, not stored in clear)
- `topReferrer` — domain or `direct` — most common HTTP Referrer header

## Decisions

### D1: Separate `publication` from `dashboard-sharing` as distinct capabilities

**Decision**: Create a new `public-dashboard-publication` capability separate from the existing `dashboard-sharing` capability.

**Alternatives considered:**
- Extend `dashboard-sharing` with a "public" recipient mode. Rejected — the access model is fundamentally different (anon vs. authenticated), the query execution context differs (service account vs. user), and the configuration surface (robots, caching, branding) would bloat the sharing UI.
- Reuse OpenRegister permission ACLs for publication. Rejected — OpenRegister ACLs are row-level and identity-bound; publication needs a service account with its own fixed permissions.

**Rationale:** Two separate capabilities make the use cases clear, reduce feature creep in either direction, and allow independent evolution. A gemeente publishing open data (publication) is a different stakeholder journey from one user sharing a dashboard with a colleague (sharing).

### D2: Publication modes are discrete (public, signed, password) not combinable

**Decision**: `mode` is a single enum value (`public`, `signed`, `password`), not a set of flags.

**Alternatives considered:**
- Bitmask combining password + signature. Rejected — most publications need only one gate, combining gates adds complexity to validation logic and to the UI, and the "password + signature together" case is rare.

**Rationale:** Discrete modes keep the validation logic simple and the owner's mental model clear: "pick one gate type."

### D3: Service account permissions are coarse-grained (register/schema level, not row-level)

**Decision**: PublicationServiceAccount ACLs are lists of allowed register and schema names. Row-level filtering is only via the optional `rowLevelFilter` JSON predicate.

**Alternatives considered:**
- Use OpenRegister's fine-grained object-level ACLs. Rejected — ACLs are per-user, not per-service-account; adapting them to a service-account context would require OpenRegister changes.
- Per-object explicit grant. Rejected — too heavyweight for a v1 feature; a gemeente publishing a dashboard knows which registers it queries and can list them upfront.

**Rationale:** Coarse-grained ACLs are simple to configure and audit. The gemeente declares "publication reader can access registers X, Y, Z" and MyDash enforces it for all publications under that service account.

### D4: Analytics are aggregate and non-backreferable

**Decision**: PublicationViewLog records only aggregate counts per hour, with uniqueness derived from salted hashes of session cookies (not raw IPs or user agents).

**Alternatives considered:**
- Record per-IP + per-user-agent. Rejected — GDPR treats that as PII (or quasi-identifier); aggregate-only is the safer path.
- Record in a separate analytics system (Plausible, Fathom). Rejected — introduces a dependency; in-app aggregate logging is simpler for v1.

**Rationale:** Aggregate-only data fits the AVG/GDPR "no consent required" path, and the gemeente gets the insights it needs (traffic volume, peak hours, top countries) without building a tracking infrastructure.

### D5: Branding profile is a separate entity, not embedded in DashboardPublication

**Decision**: PublicationBranding is a separate schema, 1:1 with DashboardPublication.

**Alternatives considered:**
- Embed branding fields directly into DashboardPublication. Rejected — makes the publication entity sprawling; if branding becomes configurable per-widget in future, separation simplifies that change.

**Rationale:** Separation keeps DashboardPublication lean and lets branding evolve independently.

### D6: Signed-URL signature is HMAC, not asymmetric key

**Decision**: Signature is HMAC-SHA256 of `{publicationId}:{grantId}:{expiresAt}` using a tenant-scoped secret.

**Alternatives considered:**
- RSA or ECDSA signing. Rejected — HMAC is simpler, tenant-scoped secrets are sufficient for the trust model (only the tenant admin issues grants), and key rotation is straightforward.
- Opaque token (no structure). Rejected — making the payload visible lets auditors see the expiry without a lookup query.

**Rationale:** HMAC is fast, deterministic, and fits the threat model (tamper-evident links, not global-trust PKI).

### D7: Cache-Control and ETag enable CDN fronting but are optional

**Decision**: PublicationAccess.cacheControlMaxAge defaults to 0 (no-cache) and is configurable up to (e.g.) 86400 (24 hours). ETag is always computed from dashboard version + data-refresh timestamp.

**Alternatives considered:**
- Mandatory long-lived cache. Rejected — stale data is a liability for some gemeenten; defaults should be safe.
- No ETag support. Rejected — conditional GET (If-None-Match) is cheap and reduces bandwidth for cacheable content.

**Rationale:** Defaults are conservative; a gemeente can opt into aggressive caching if their data is refreshed infrequently.

## Reuse Analysis

- **OpenRegister**: All seven entities are stored as OpenRegister objects; no custom Entity/Mapper classes
- **AuthorizationService**: Publication service account permissions enforced at register/schema layer (no per-object ACL)
- **QueryService** (existing MyDash querying): Dashboard queries reuse existing query-execution code, only the execution context (principal) changes to the service account
- **FileService**: Logo and favicon are stored via FileService; PublicationBranding holds file references (not embedded data)
- **NotificationService** (optional): On publication state change, notify the owner via Nextcloud notifications
- **IInitialState**: Public view may need minimal initial state (publication config, branding, available data)

## Risks and Mitigations

| Risk | Mitigation |
|---|---|
| A published dashboard inherits the viewer's elevated permissions (elevation-of-privilege) | All dashboard queries execute as publication service account, not the viewer. Query engine validates that the service account is allowed to query the registers/schemas. |
| Password is transmitted in clear over HTTP if TLS is not enforced | Gemeente deployment enforces HTTPS. Documentation emphasizes TLS requirement. Password is never logged. |
| Signed-URL is intercepted or leaked | Owner can revoke any grant immediately. Signature is tamper-evident; modified URLs are rejected. Expiry limits exposure window. |
| View counts are correlated across time to identify individuals | No user agent, IP, or fingerprinting data is stored. Cookie-hash-based uniqueness is per-session; daily bucket aggregation further dilutes. |
| Service account leaks due to misconfiguration | Service account credentials (if any; depends on register transport) are stored in app-data, not in code. Register/schema ACL enforcement is the primary gate. |
| Dashboard data changes between CDN cache and next refresh | ETag + If-None-Match ensures the cache is invalidated when dashboard version changes; data-refresh timestamp is part of ETag. Stale-While-Revalidate window is configurable and defaults to short. |

## Migration Plan

1. **Phase 1: Backend services** — Implement PublicationService, PublicationAuthService, PublicationQueryService, PublicationAnalyticsService in separate PRs for testability.
2. **Phase 2: OpenRegister schemas** — Define the seven schemas in `lib/Settings/mydash_register.json` with seed data (3-5 example publications).
3. **Phase 3: Controller layer** — PublicationController (read) and PublicationSettingsController (admin config).
4. **Phase 4: Frontend UI** — Publication settings form (owner config), public-view route (anon accessible), analytics dashboard (owner view).
5. **Phase 5: Testing & CI** — Integration tests (end-to-end publication lifecycle), performance tests (CDN caching), security tests (service account isolation).
6. **Phase 6: Documentation** — API docs, owner guide (how to set up a publication), security guide (robots.txt, geofencing).

## Seed Data

Three example publications in `lib/Settings/mydash_register.json`:

**Example 1: Public gemeente housing dashboard**
- slug: `wonen-gemeente-zeist`
- mode: `public`
- status: `published`
- language: `nl`
- robotsPolicy: `index_follow` (search engines welcome)
- cacheControlMaxAge: 3600 (1 hour)

**Example 2: Press-briefing dashboard (signed URLs)**
- slug: `persconferentie-mei-2024`
- mode: `signed`
- status: `published`
- language: `nl`
- robotsPolicy: `noindex_nofollow` (embargoed until press event)
- grants: 2 example SignedUrlGrant objects with expiresAt 1 day ahead

**Example 3: Community consultation results (password-gated)**
- slug: `inspraak-ergebnis-2024`
- mode: `password`
- status: `published`
- language: `nl`
- passwordHash: `$2y$12$...` (bcrypt of `demo`)
- robotsPolicy: `noindex_follow` (visible to humans, not indexable)

## Test strategy

- **PHPUnit**: PublicationService CRUD, PublicationAuthService validation (public/signed/password modes), PublicationQueryService executes as service account, PublicationAnalyticsService aggregates without PII
- **Integration tests**: End-to-end publication flow (create, publish, access via each mode, retract)
- **Security tests**: Service account isolation (viewer cannot elevate to other registers), password constant-time comparison, signed-URL tamper-detection
- **Performance tests**: CDN caching (ETag + If-None-Match), view-log aggregation under sustained traffic
- **Playwright**: Public view renders correctly with branding, anon access does not require login, password gate enforces lockout after N attempts, signed-URL expiry blocks expired links

## Open Questions

1. Should the owner be able to rotate the publication service account credentials, or is tenant-scoped rotation sufficient?
2. Should geofencing (allowedCountries) block at the HTTP layer or log-but-allow (for transparency)?
3. For stale-while-revalidate, should the response include a `Warning` header signalling staleness to the CDN?
4. Should view logs be queryable in the owner's analytics UI in real time, or with an aggregation delay (e.g., 5-minute bucket)?
