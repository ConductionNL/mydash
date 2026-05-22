---
capability: public-dashboard-publication
status: draft
---

# Public Dashboard Publication — Specifications

## DEFINED Requirements

### Requirement: REQ-PDP-001 Publish a dashboard

When a dashboard owner initiates publication, the system MUST validate the dashboard is eligible, require selection of mode, slug, and branding, and MUST refuse publication if any dashboard query is not executable by the publication service account.

#### Scenario: Valid publication creation with public mode

- **GIVEN** a dashboard owner with admin privileges and a dashboard containing queries querying `registers/schemas` the publication service account is allowed to access
- **WHEN** the owner submits a publication form with mode `public`, slug `verkeer-live`, and default branding
- **THEN** the system MUST create a DashboardPublication object with status `draft`
- **AND** MUST create a PublicationBranding object with defaults (organisation logo from nldesign palette, primaryColour from active theme, footerHtml with gemeente contact)
- **AND** MUST create a PublicationAccess object with cacheControlMaxAge `0`, robotsPolicy `index_follow`
- **AND** MUST reject if the slug is already in use (non-unique within tenant)
- **AND** MUST reject if any query references a register/schema not in the service account's ACL

#### Scenario: Refuse publication if service account lacks permission

- **GIVEN** a dashboard with a query against the `basisregister-adressen` schema
- **AND** the publication service account is only allowed to access `gemeentelijke-gegevensbronnen`
- **WHEN** the owner attempts to publish the dashboard
- **THEN** the system MUST return a 403 error with a message listing the disallowed schema(s)
- **AND** MUST NOT create a publication object

### Requirement: REQ-PDP-002 Anonymous access to published dashboards

When an unauthenticated client requests a published dashboard via `GET /apps/mydash/publication/{slug}`, the system MUST serve the dashboard without requiring authentication, MUST NOT set any session cookie that identifies the viewer, and MUST execute all dashboard queries as the publication service account.

#### Scenario: Anonymous viewer accesses a public-mode publication

- **GIVEN** a published DashboardPublication with status `published`, mode `public`, and slug `wonen-zeist`
- **AND** a viewer with no authentication cookie
- **WHEN** the viewer requests `GET /apps/mydash/publication/wonen-zeist`
- **THEN** the response MUST be HTTP 200
- **AND** MUST include the dashboard definition and computed widget data
- **AND** MUST NOT set a `Set-Cookie: nc_session_id` or other authenticating cookie
- **AND** MUST execute all underlying queries as the publication service account
- **AND** MUST increment the PublicationViewLog hourly bucket for this publication

#### Scenario: Authenticated viewer accessing public publication

- **GIVEN** a published public-mode publication
- **AND** a viewer with valid Nextcloud session
- **WHEN** the viewer accesses the publication
- **THEN** the viewer MUST be treated as anonymous
- **AND** the underlying queries MUST still execute as the publication service account (NOT the viewer's account)
- **AND** the viewer's permissions MUST NOT elevate the query scope

### Requirement: REQ-PDP-003 Signed-URL publication access

When an owner configures a `signed`-mode publication and issues a signed URL grant, the system MUST generate a tamper-evident signature, MUST reject requests with invalid or expired signatures, MUST log usage, and MUST allow immediate revocation.

#### Scenario: Generate and validate a signed-URL grant

- **GIVEN** a published DashboardPublication with status `published`, mode `signed`
- **WHEN** the owner issues a SignedUrlGrant with expiresAt 7 days from now
- **THEN** the system MUST generate a signature as HMAC-SHA256(`{publicationId}:{grantId}:{expiresAt}`, tenantSecret)
- **AND** MUST construct the shareable URL as `/apps/mydash/publication/{slug}?grant={grantId}&expires={expiresAt}&sig={signature}`
- **AND** MUST store the grant with initialusageCount `0`

#### Scenario: Access via valid signed URL

- **GIVEN** a valid signed URL with grant ID, expiry timestamp, and signature
- **WHEN** an unauthenticated client requests the URL
- **THEN** the system MUST verify the signature (HMAC-SHA256 matches)
- **AND** MUST verify expiresAt is in the future
- **AND** MUST verify the grant is not revoked
- **AND** MUST increment the grant's usageCount
- **AND** MUST update lastUsedAt to now
- **AND** MUST serve the dashboard (same as public-mode access)

#### Scenario: Reject tampered or expired signature

- **GIVEN** a signed URL with an invalid signature (bit flip) or expiresAt in the past
- **WHEN** the client requests the URL
- **THEN** the system MUST return HTTP 403 Forbidden
- **AND** MUST NOT increment any usage counter
- **AND** MUST log the failed access attempt for auditing

#### Scenario: Immediate revocation

- **GIVEN** an active SignedUrlGrant
- **WHEN** the owner marks the grant as `revoked: true`
- **THEN** subsequent requests using that grant's signature MUST be rejected with HTTP 403
- **AND** the change MUST take effect immediately (no caching)

### Requirement: REQ-PDP-004 Password-gated publication access

When a publication is in `password` mode, the viewer MUST submit a password; the system MUST compare using constant-time hashing and enforce lockout after N failed attempts.

#### Scenario: Initial password submission

- **GIVEN** a published DashboardPublication with status `published`, mode `password`
- **WHEN** an unauthenticated viewer requests the publication URL
- **THEN** the system MUST return an HTTP 200 response with a login form (no dashboard data yet)
- **AND** MUST set a temporary session cookie scoped to this publication (no cross-publication leakage)

#### Scenario: Correct password submission

- **GIVEN** the viewer submits a form with the correct password
- **WHEN** the system compares the submitted password against the stored bcrypt hash
- **THEN** the comparison MUST use bcrypt's constant-time comparison (builtin to password_verify)
- **AND** MUST set the temporary session cookie to `authenticated=true` (scoped to publicationId)
- **AND** MUST serve the dashboard with all widget data
- **AND** MUST increment the PublicationViewLog counter
- **AND** MUST reset the failed-attempt counter for this viewer's session

#### Scenario: Incorrect password and lockout

- **GIVEN** a PasswordGate with attemptsLockoutThreshold `5`
- **AND** a viewer who has submitted 4 incorrect passwords in this session
- **WHEN** the viewer submits a 5th incorrect password
- **THEN** the system MUST reject the password
- **AND** MUST set a lockout flag on the session for lockoutDurationSeconds (e.g., 300 seconds)
- **AND** MUST display the optional hint text
- **AND** MUST reject all subsequent password attempts for 300 seconds with a "Too many attempts" message
- **AND** MUST log the lockout event for auditing

#### Scenario: Password never logged

- **GIVEN** any password submission (correct or incorrect)
- **WHEN** the system processes the request
- **THEN** the plaintext password MUST NOT appear in any log (app logs, audit trail, error messages)
- **AND** MUST NOT be stored in any form other than the bcrypt hash

### Requirement: REQ-PDP-005 Publication branding

When a published dashboard renders on the public view, the page MUST display the configured branding (logo, colours, footer) and MUST NOT display the internal MyDash navigation chrome.

#### Scenario: Branding elements appear on public view

- **GIVEN** a published DashboardPublication with a PublicationBranding profile specifying organisationLogo, primaryColour `#FF6600`, footerHtml `<p>Privacyverklaring</p>`, language `nl`
- **WHEN** an unauthenticated viewer accesses the publication
- **THEN** the HTML response MUST include:
  - A logo image sourced from the file reference in organisationLogo
  - CSS variables or inline styles applying primaryColour to heading backgrounds, button colors, and borders
  - The footerHtml rendered in the page footer
  - A language attribute on the `<html>` tag set to the publication's language
  - No MyDash top navbar, no user account menu, no "Add widget" affordances

#### Scenario: Meta tags and document title

- **GIVEN** a published dashboard with branding including a dashboard title "Wonen en Huisvesting 2024"
- **WHEN** the public view is rendered
- **THEN** the `<title>` tag MUST be set to the dashboard title
- **AND** the `<meta name="description">` MUST contain a short summary (from dashboard metadata or publication settings)
- **AND** the `<link rel="canonical">` MUST point to the publication's stable URL
- **AND** the `<link rel="icon">` MUST point to the favicon from PublicationBranding

#### Scenario: No MyDash chrome

- **GIVEN** any published dashboard view
- **WHEN** the page renders
- **THEN** the response MUST NOT include the internal sidebar, workspace switcher, user menu, admin links, or "Add widget" toolbar
- **AND** MUST only display the dashboard widgets, footer, and publication branding

### Requirement: REQ-PDP-006 Robots and indexing control

When a published dashboard is served, the system MUST emit X-Robots-Tag HTTP header and meta-robots tag matching the configured robotsPolicy, honoring `index_follow`, `noindex_nofollow`, or `noindex_follow` directives.

#### Scenario: Indexable publication

- **GIVEN** a published DashboardPublication with robotsPolicy `index_follow`
- **WHEN** a search-engine bot requests the publication URL
- **THEN** the response MUST include:
  - `X-Robots-Tag: index, follow` HTTP header
  - `<meta name="robots" content="index, follow">` in the HTML head
  - `<link rel="canonical">` pointing to the stable publication URL

#### Scenario: Noindex with allow-follow

- **GIVEN** a published DashboardPublication with robotsPolicy `noindex_follow`
- **WHEN** a bot requests the publication
- **THEN** the response MUST include:
  - `X-Robots-Tag: noindex, follow` HTTP header
  - `<meta name="robots" content="noindex, follow">` HTML tag
  - Search engines MUST NOT index the page; they MAY follow links in it

#### Scenario: Fully noindex

- **GIVEN** a published DashboardPublication with robotsPolicy `noindex_nofollow`
- **WHEN** any client requests the publication
- **THEN** the response MUST include:
  - `X-Robots-Tag: noindex, nofollow` HTTP header
  - `<meta name="robots" content="noindex, nofollow">`
  - Search engines MUST NOT index and MUST NOT follow links

### Requirement: REQ-PDP-007 Caching and CDN compatibility

When a published dashboard is served, the response MUST carry Cache-Control headers with the configured max-age and stale-while-revalidate, MUST emit a stable ETag computed from dashboard version and data-refresh timestamp, and MUST honor conditional GET (If-None-Match) returning 304.

#### Scenario: Cache-Control headers on response

- **GIVEN** a published DashboardPublication with PublicationAccess cacheControlMaxAge `3600` (1 hour) and staleWhileRevalidate `604800` (7 days)
- **WHEN** an unauthenticated client requests the publication
- **THEN** the response MUST include:
  - `Cache-Control: public, max-age=3600, stale-while-revalidate=604800` HTTP header
  - A CDN MAY cache the response for up to 3600 seconds
  - After 3600 seconds, the CDN MAY serve stale content for up to 7 additional days while revalidating in the background

#### Scenario: ETag computation and validation

- **GIVEN** a published dashboard with version hash `abc123` and data-refresh timestamp `2024-05-10T14:30:00Z`
- **WHEN** the dashboard is first requested
- **THEN** the response MUST include an `ETag: "{version}-{refreshTimestamp}"` header
- **AND** the ETag value MUST be stable (same for identical dashboard state)
- **AND** MUST change if the dashboard definition is modified or data is refreshed

#### Scenario: Conditional GET with If-None-Match

- **GIVEN** a client with a cached response including `ETag: "abc123-2024-05-10T14:30:00Z"`
- **WHEN** the client sends `GET /apps/mydash/publication/wonen-zeist` with header `If-None-Match: "abc123-2024-05-10T14:30:00Z"`
- **THEN** the system MUST compare the ETag
- **AND** if the ETag matches, MUST return `HTTP 304 Not Modified` (no response body)
- **AND** if the ETag differs (dashboard updated), MUST return `HTTP 200` with the full response

#### Scenario: Cache-Control max-age 0 (no-cache)

- **GIVEN** a publication with cacheControlMaxAge `0`
- **WHEN** the response is served
- **THEN** the response MUST include `Cache-Control: public, max-age=0, must-revalidate`
- **AND** CDN and client MUST revalidate on every access (If-None-Match check)

### Requirement: REQ-PDP-008 Aggregate analytics without PII

When viewers access a published dashboard, the system MUST record only aggregate view counts per hour bucket, MUST derive uniqueness from salted session-cookie hashes (not backreferable to identity), MUST NOT record IP addresses in clear, and MUST allow the owner to disable analytics.

#### Scenario: View-log aggregation

- **GIVEN** 150 viewers accessing a publication `wonen-zeist` on 2024-05-10 between 14:00 and 14:59 UTC
- **WHEN** the system records analytics
- **THEN** it MUST create or update a PublicationViewLog entry with:
  - publicationId: (the publication ID)
  - day: `2024-05-10`
  - hourBucket: `14`
  - viewCount: `150`
  - uniqueSessionCount: (count of unique salted cookie hashes; may be ≤ 150 if repeat visitors)
  - topCountry: (ISO code, derived from GeoIP of request metadata, not stored in clear)
  - topReferrer: (most common HTTP Referrer header, or "direct")

#### Scenario: Session uniqueness via salted hash

- **GIVEN** an anonymous viewer with a session cookie `sessionId=xyz123`
- **WHEN** the viewer accesses the publication
- **THEN** the system MUST:
  - Salt the sessionId with a random tenant-scoped salt (e.g., HMAC-SHA256(`xyz123`, tenantSalt))
  - Hash the result (e.g., SHA256(salted value))
  - Count this hash as one unique session
  - MUST NOT store the original sessionId, IP, or user agent
  - The hash MUST NOT be backreferable to the viewer's identity

#### Scenario: No IP or PII logged

- **GIVEN** any viewer access to a publication
- **WHEN** the view is recorded in PublicationViewLog
- **THEN** the entry MUST NOT include:
  - IP address (in clear or otherwise)
  - User-Agent string
  - Browser fingerprinting data
  - Personal identifiers
- **AND** MUST only include:
  - Aggregate count
  - GeoIP country (derived from IP but not stored in clear)
  - HTTP Referrer domain

#### Scenario: Owner views analytics

- **GIVEN** a dashboard owner viewing their publication's analytics in the MyDash admin dashboard
- **WHEN** the owner navigates to the publication analytics page
- **THEN** the UI MUST display:
  - Daily/hourly view counts
  - Unique session counts
  - Top countries
  - Top referrers
  - Trend charts (views over time)
- **AND** MUST NOT display individual viewer profiles or IP addresses
- **AND** MUST NOT offer any export that includes PII

#### Scenario: Owner can disable analytics

- **GIVEN** a published DashboardPublication with an analytics-enabled flag
- **WHEN** the owner toggles analytics off
- **THEN** the system MUST:
  - Stop recording new PublicationViewLog entries for this publication
  - Optionally purge existing logs (depends on implementation; design section is open)
  - Return a 204 No Content response on publication access (no analytics processing)
