status: draft

# Public Dashboard Publication

## Placement & Information Architecture

**Placement type:** `DETAIL_TAB` — Tab on the detail view of an existing object. NOT a standalone page — appears inside the parent record's detail surface (e.g. an extra tab on the existing detail header).

**Lives at:** Beheer / Tab: Sharing & Publication

**Rationale:** Publication policy  
_Source: /tmp/ia-mydash-openregister.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Provide an anonymous-read publication channel for mydash dashboards so that gemeenten, provincies, waterschappen, and other public bodies can publish open-data dashboards to citizens, journalists, and other partners without forcing each viewer through an authentication step. Today mydash dashboards are identity-bound: every viewer is a known user, every view is logged against that user, and access is governed by per-dashboard ACLs. That is correct for internal BI and for the existing `dashboard-sharing` capability which lets one user share a dashboard with another identified user. It is wrong for open-overheid usage where the entire point is that any citizen can land on a URL like `dashboards.gemeentezeist.nl/wonen` and see the live numbers without a login.

This spec introduces a publication channel that is distinct from sharing. A dashboard owner can mark a dashboard as publishable, choose between three publication modes (fully public, signed-URL with expiry, password-gated), apply a publication-specific brand chrome (gemeente logo, kleurpalet, footer with privacy and accessibility links), configure robots and noindex behaviour, and set cache-control headers so the dashboard can be fronted by a CDN at high request volumes. Underlying data queries run as a designated "publication service account" with its own ACL so that public dashboards can never accidentally inherit a viewer's elevated permissions. View-counts and traffic metrics are recorded in aggregate (no per-citizen profiling) and surface in the owner's mydash analytics. The spec deliberately scopes out interactive filtering, comment threads, and authenticated personalisation; those belong to a future `interactive-public-dashboard` spec.

## Data Model

**DashboardPublication**: publicationId, dashboardId, slug (URL-safe, unique within tenant), mode (public, signed, password), publishedAt, publishedBy, status (draft, published, paused, retracted), retractionReason, lastModifiedAt.

**PublicationBranding**: publicationId, organisationLogo (file ref), favicon (file ref), primaryColour, secondaryColour, fontFamily, footerHtml, accessibilityStatementUrl, privacyStatementUrl, contactEmail, language (nl, en, fr, de).

**PublicationAccess**: publicationId, robotsPolicy (index_follow, noindex_nofollow, noindex_follow), cacheControlMaxAge, staleWhileRevalidate, allowedReferrers[] (optional CORS allowlist), allowedCountries[] (optional geofence).

**SignedUrlGrant**: grantId, publicationId, issuedTo (free-text label), issuedAt, expiresAt, signature, usageCount, lastUsedAt, revoked.

**PasswordGate**: publicationId, passwordHash, hint, attemptsLockoutThreshold, lockoutDurationSeconds.

**PublicationServiceAccount**: tenantId, accountId, allowedRegisters[], allowedSchemas[], rowLevelFilter, createdAt, rotatedAt.

**PublicationViewLog** (aggregate, no PII): publicationId, day, hourBucket, viewCount, uniqueSessionCount (cookie-hash-based), topCountry, topReferrer.

## Requirements

### REQ-PDP-001: Publish a dashboard
GIVEN a dashboard owner, WHEN they choose "publish to public", THEN the system MUST require selection of mode, slug, and branding profile, MUST validate that the dashboard's underlying queries are executable by the publication service account, and MUST refuse to publish if any query would expose data the service account is not entitled to.

### REQ-PDP-002: Anonymous access path
GIVEN a published dashboard in public mode, WHEN an unauthenticated client requests its URL, THEN the system MUST serve the dashboard without redirecting to login, MUST not set any session cookie that identifies the viewer, and MUST run all dashboard queries as the publication service account.

### REQ-PDP-003: Signed-URL mode
GIVEN a publication in signed mode, WHEN the owner issues a signed URL with an expiry, THEN the system MUST generate a tamper-evident signature, MUST reject requests with an invalid or expired signature, MUST log usage against the grant, and MUST allow the owner to revoke any grant at any time with immediate effect.

### REQ-PDP-004: Password-gated mode
GIVEN a publication in password mode, WHEN a viewer arrives, THEN the system MUST present a minimal password form, MUST hash and compare against the stored passwordHash using a constant-time comparison, MUST lock further attempts after the configured threshold, and MUST never log the submitted password.

### REQ-PDP-005: Brand chrome
GIVEN a publication with a branding profile, WHEN the dashboard renders, THEN the page chrome MUST display the configured logo, colours, footer, and language; the mydash internal navigation chrome MUST NOT appear; and the document title and meta-description MUST be derived from the publication settings.

### REQ-PDP-006: Robots and indexing control
GIVEN a publication with a robotsPolicy, WHEN search-engine bots request the page or the site's robots.txt, THEN the system MUST emit the configured directive both as a meta-robots tag and via X-Robots-Tag header so that even if the bot ignores HTML, the header signal is honoured.

### REQ-PDP-007: Caching and CDN compatibility
GIVEN a publication with cacheControlMaxAge configured, WHEN the page is served, THEN the response MUST carry Cache-Control with the configured max-age and stale-while-revalidate, MUST emit a stable ETag computed from the dashboard definition version and the data-refresh timestamp, and MUST honour conditional GET (If-None-Match) returning 304 when appropriate.

### REQ-PDP-008: Aggregate analytics without PII
GIVEN viewer traffic on a publication, WHEN the system records metrics, THEN it MUST only persist aggregate counts per hour bucket, MUST derive uniqueness from a salted session cookie hash that is not back-referable to an identity, MUST NOT record IP addresses in clear, and MUST allow the owner to disable analytics entirely.

## Standards

- WCAG 2.2 AA — required for all gemeente public-facing pages.
- Wet Digitale Overheid — accessibility statement link is mandatory.
- AVG/GDPR — aggregate-only analytics fit the no-consent path (no tracking cookies).
- Forum Standaardisatie "pas toe of leg uit" — DCAT-AP for any open-data sets surfaced.
- HTTP caching (RFC 9111) — Cache-Control and conditional GET semantics.

## Cross-app

- **mydash dashboard-sharing**: distinct surface; sharing is identity-bound, publication is anonymous.
- **opencatalogi**: published dashboards can be registered as open-data resources in the gemeente catalogue.
- **openregister**: publication service account permissions enforced at the register/schema layer.
- **openconnector**: optional CDN front (Cloudflare, Fastly, KPN CDN) provisioned via connector.
- **nldesign**: branding profile defaults derived from the active nldesign palette.

## Target users

Gemeente communicatie-afdelingen, open-data coördinatoren, woordvoerders, raadsgriffies, journalisten en burgers als eindgebruiker, MKB klanten die KPI-pagina's publiek delen met partners.
