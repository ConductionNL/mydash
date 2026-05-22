# Design — Embedded Analytics

## Overview

MyDash widgets are today rendered only for authenticated Nextcloud users with dashboard ACL permission. Three customer classes cannot adopt MyDash today:

1. **Public transparency dashboards**: Municipalities that want to publish live "openstaande WOO-verzoeken" on their public website without exposing Nextcloud.
2. **Partner-facing operational dashboards**: Shared-service organisations running MyDash for a consortium of municipalities, embedding each member gemeente's widget slice into the gemeente's internal intranet (avoiding mass Nextcloud account creation).
3. **In-product analytics**: SaaS vendors whose products are built on Conduction's stack, surfacing "your usage this month" inside their customer portal without iframing a full Nextcloud login flow.

Today's auth boundary (Nextcloud session) is correct for internal organisational consumption but too narrow for these use cases. The change introduces **embed tokens** — signed JWTs that are the sole auth mechanism for embedded views, scoped to a specific widget or dashboard, rate-limited, revocable within 60 seconds, and subject to auditable telemetry.

## Goals

- **Flexible form factors**: Support both iframe (drop into any HTML, no JS integration) and JS-SDK (host page controls layout/sizing/events).
- **Security-first**: Signed JWTs, asymmetric crypto (RS256/ES256), no session cookies, no IP allow-lists, no anonymous embeds. Every embed has a verifiable identity that can be revoked and audited.
- **Scope enforcement**: Read-only tokens cannot mutate state even if the frontend is tampered. Filter context can be pinned. Allowed actions are explicitly listed (interact, export, drillDown).
- **Per-tenant theming**: CSS variables let the embed blend into the host page's brand without forking widget code — gemeente's colours on gemeente's intranet, SaaS vendor's colours inside their product.
- **CSP compliance**: Embeds declare host origins upfront; the render route emits `Content-Security-Policy: frame-ancestors` so the host's CSP doesn't block the iframe.
- **Usage telemetry without PII**: Every embed write captures host origin, viewport size, and user-agent family (hashed) for "did this embed get traffic?" answers. NO IP addresses, NO full UAs, NO end-user identifiers.
- **WCAG 2.1 AA compliance**: Public-facing embeds land on municipal websites with legal accessibility obligations (Web Toegankelijkheidsverordening). Build-time axe-core checks catch ~30% of violations; admin UI warns on creation.
- **Incident response**: Token revocation takes effect within 60 seconds across all render workers; a "purge cache" action available to admins gives zero-cache escalation for critical incidents.

## Non-Goals

- Per-resource ACL on individual assets (files, tables, records embedded by reference). Embed tokens grant access to the named widget/dashboard; ACLs on downstream entities are enforced by those entities.
- Seamless JWT refresh flows. Tokens are long-lived or non-expiring by default; refresh-token machinery is out of scope v1.
- Custom widget shapes per embed (e.g. "render this widget in 2-column layout"). Theme variables only; layout is the host page's responsibility.
- Simultaneous draft + published versions of a dashboard. Embeds point to the latest published version.
- Semantic drift from the platform-wide token-scope ADR. The JWT scope vocabulary (mode, allowedFilters, allowedActions) mirrors existing OpenConnector patterns.

## Context

### User stories from the field

1. **Public WOO dashboard**: "Our gemeente wants to publish a live widget on our public website that shows outstanding WOO requests without exposing Nextcloud. Citizens should see the list, but not be able to download it."
2. **Partner intranet**: "We run MyDash for 8 gemeentes. Each gemeente wants their own dashboard in their internal intranet, but they don't have Nextcloud accounts. We need to embed a widget per gemeente into their own page."
3. **SaaS usage dashboard**: "Our customer portal shows 'your usage this month'. It's built in React and lives on a different domain. We want to embed a MyDash widget to replace a custom in-house chart."

### JWT as the auth boundary

Nextcloud sessions are user-centric and require a login flow. Embed tokens are widget-centric and are issued by an admin, then handed to a third party (municipal webmaster, partner org, SaaS portal team) for integration. The token is an identity that outlives any user and can be revoked independently of user lifecycle.

**Why JWTs and not OAuth2 client credentials or other grant flows?**

- Tokens are issued once and distributed offline (the admin hands the token to the municipal webmaster via email). OAuth would require the webmaster to stand up a server to hold credentials, which is operationally heavy.
- Tokens are read/cached/cached by stateless edge servers. JWTs carry their validity in the token itself; a stateless proxy can verify the signature without a DB call. An opaque token would require a central token-info endpoint.
- Public standards: RFC 7519 (JWT), RFC 7515 (JWS), RFC 7517 (JWK) — widely understood, widely implemented, widely audited.

### Key rotation

Multiple active keys are permitted so an org can rotate without breaking in-flight tokens. The key selection uses the `kid` header (RFC 7517). The rotation procedure is:

1. Create a new signing key, mark it `status: active`. 
2. Mark the old key `status: retiring` so new tokens are signed with the new key.
3. Tokens signed by the old key remain valid (the JWK's public key is available) until their expiration.
4. Once all tokens signed by the old key have expired, retire the old key to `status: revoked` (optional archival, not deletion).

This is the pattern documented by Auth0, Okta, and the OIDC discovery spec.

### CSP as the framing boundary

The W3C Content Security Policy Level 3 spec mandates that a frame-ancestor must declare which origins may embed it. We emit `Content-Security-Policy: frame-ancestors <hostOrigins>` (computed from the token's `hostOrigins` list). This prevents a malicious website from tricking a user's browser into displaying our widget while claiming it's from their domain.

Defence in depth: we also check `Sec-Fetch-Site` and `Referer` headers (Fetch Metadata Request Headers spec) so a browser/proxy that strips CSP headers still cannot spoof the origin.

**Why explicit-allowlist instead of wildcard?**

An admin configuring `hostOrigins: ["*"]` negates all framing protection. We reject `*` and require explicit origins per token. This matches the OWASP CSP cheat-sheet's strongest recommendation.

## Decisions

### D1: JWT-based tokens, not session cookies or IP allow-lists

**Decision**: The only auth mechanism is a signed JWT token. No session cookies are issued. No IP allow-lists are supported (they don't work across CDNs and mobile networks anyway).

**Rationale**: JWTs can be shared offline between organisations and remain valid forever (or until explicit revocation). Session cookies require a login flow. IP allow-lists are fragile and don't work for mobile users.

**Alternatives considered**:
- OAuth2 client credentials grant. Too heavy for the use case (webmaster should not need to run a secure credential-storage server).
- Anonymous public dashboards (no token). Rejected — every embed must have a verifiable identity for audit and revocation.
- Nextcloud session + embed link. Rejected — user has to log into Nextcloud first; defeats the purpose.

### D2: Read-only enforcement at the API tier, not the UI

**Decision**: A token's `scope.mode` (read vs read-with-interactions) is enforced on the API tier. The backend rejects any POST/PUT/PATCH/DELETE when the token is read-only, even if the frontend is tampered.

**Rationale**: The frontend cannot be trusted to enforce a policy that an attacker can modify. If a municipal website embeds a read-only widget on their public page, they need to know that even if someone patches the JS in DevTools, they cannot coerce a write.

**Alternatives considered**:
- UI-only enforcement (hide buttons). Rejected — not secure against determined attackers.
- Separate endpoints for read vs write. Rejected — overcomplicates routing; the API already dispatches based on HTTP verb.

### D3: PostMessage handshake for token injection (JS-SDK), query-parameter fallback (iframe)

**Decision**: The JS-SDK loads an iframe without a token in the `src` URL, then posts the token to the iframe via `postMessage` after origin verification. The iframe-only form-factor accepts the token as `?token=<jwt>` in the query string for users who can't integrate a JS library.

**Rationale**: Query-parameter tokens leak into referer headers, browser history, and proxy logs. The postMessage handshake (already used by Stripe Elements, Plaid Link, and Google Identity Services) avoids the leak while keeping the iframe-only path simple for basic usage.

**Alternatives considered**:
- Always require postMessage. Rejected — too heavy for a one-off embed; some users just want to paste a URL into an `<iframe src>` tag.
- Bearer header injection via Service Worker. Rejected — adds a build step and browser compatibility issues.

### D4: Rate limiting with 60-second revocation propagation window

**Decision**: Each token has a `rateLimitPolicy` (requestsPerMinute, burstSize). Revocations are propagated to all render workers within 60 seconds via a short-TTL cache. For higher-assurance incidents, admins can invoke a "purge cache" action to drop the TTL to zero.

**Rationale**: 60 seconds is short enough to satisfy incident-response timelines (revoke → within 1 minute, no more requests). Caching avoids DB pressure on the render route; a zero-cache option exists for critical incidents.

**Alternatives considered**:
- Zero-cache revocation (every request hits DB). Rejected — render route throughput would suffer under high traffic.
- Eventually-consistent revocation (background sync, no max TTL). Rejected — too long for incident response.

### D5: Telemetry captures host origin, viewport bucket, user-agent family — NO IP, NO full UA, NO end-user ID

**Decision**: Every `embed_usage_event` writes: `hostOrigin` (from Origin header), `viewportSize` (bucketed: small/medium/large, not exact pixels), `userAgent` (browser family + major version + sha256 of full string for fingerprint analysis), `responseStatusCode`, `responseLatencyMs`. NO client IP, NO full user-agent string, NO user identifier.

**Rationale**: Administrators can answer "did this embed get traffic?" and "is it slow?" without processing personal data. Capturing IP addresses or full UAs would require a legal basis (legitimate interest + DPIA under GDPR/AVG Article 5(1)(c)).

**Alternatives considered**:
- Full telemetry (IP, full UA, user ID). Rejected — requires legal basis; not proportionate to the business question ("did this embed get used?").
- No telemetry. Rejected — admins have no way to detect unused embeds or revoke tokens that are not helping.

### D6: WCAG 2.1 AA compliance with axe-core build-time checks

**Decision**: The embed render route produces HTML that meets WCAG 2.1 AA success criteria. The build pipeline runs axe-core automated checks on representative widget shapes. The admin UI warns (non-blocking) when creating a token for a widget with known violations.

**Rationale**: Public-facing embeds on municipal websites have legal accessibility obligations under the Web Toegankelijkheidsverordening and EN 301 549. An embed that fails AA is a regulatory issue, not a UX bug. Automated checks catch ~30% of violations (high false-positive rate, but better than nothing). Manual review is the widget author's responsibility and is documented per-widget.

**Alternatives considered**:
- No accessibility checks. Rejected — regulatory risk on public-sector websites.
- Hard block on embedding inaccessible widgets. Rejected — too strict; admins may choose to embed anyway and document the workaround.

## Risks and Mitigations

| Risk | Mitigation |
|---|---|
| Token leaked into referer header or access log (iframe form-factor) | Query-parameter tokens enforce stricter rate limits and stricter CSP. Admins are warned during token creation that iframe-form tokens are less secure. |
| Host website compromised, embed tokens exfiltrated | Tokens are scoped to a specific widget (subject-id pinning). Attacker cannot widen access to other widgets/dashboards without getting more tokens. Audit log shows which embeds exist. |
| Compromised signing key (private key leaked) | Multiple active keys permitted; old key can be retired immediately. All tokens signed by the old key are revoked. Rotation procedure is automated. |
| Public WOO widget embedded on a website, but municipality accidentally marks it read-with-interactions | Admin creates the token; responsibility is on the admin to choose the right scope. Token metadata includes scope, so audit review can catch mistakes. |
| End-user fingerprinting via user-agent telemetry | User-agent is hashed and only family+major is retained. Not enough to identify an individual. Org-level telemetry kill-switch lets admins disable all event capture. |
| Performance regression: render route adds latency | Render route adds two checks (JWT signature + rate-limit lookup) and writes one event. Both are O(1). JWT verification is fast (standard library, not custom crypto). Rate-limit and revocation lookups hit a local cache (60s TTL) before DB. Expected overhead <10ms. |

## Seed Data

### EmbedToken seed objects

Three realistic scenarios for Netherlands municipalities:

1. **Public WOO widget** — Gemeente Zeist wants to embed a live "openstaande WOO-verzoeken" widget on their public website (https://www.zeist.nl).
   - name: "Gemeente Zeist — WOO intranet"
   - description: "Live WOO requests for internal intranet"
   - subject: {type: "widget", id: "<woo-widget-uuid>"}
   - scope: {mode: "read", allowedFilters: ["status"], allowedActions: []}
   - hostOrigins: ["https://intranet.zeist.nl"]
   - tokenExpiresAt: null (non-expiring)
   - rateLimitPolicy: {requestsPerMinute: 600, burstSize: 60}

2. **Partner consortium dashboard** — NL Design consortium embed for Gemeente Amsterdam.
   - name: "Consortium — Amsterdam operations board"
   - description: "Shared-service ops dashboard for AMS gemeente"
   - subject: {type: "dashboard", id: "<dashboard-uuid>", filterContext: {gemeente: "Amsterdam"}}
   - scope: {mode: "read-with-interactions", allowedFilters: ["periode", "afdeling"], allowedActions: ["interact", "drillDown"]}
   - hostOrigins: ["https://intranet.amsterdam.nl"]
   - tenantThemeId: "<amsterdam-theme-uuid>"
   - tokenExpiresAt: null
   - rateLimitPolicy: {requestsPerMinute: 300, burstSize: 30}

3. **SaaS usage widget** — Conduction customer portal embedding a monthly-usage widget.
   - name: "SaaS Portal — Usage widget"
   - description: "Customer usage this month (SaaS portal integration)"
   - subject: {type: "widget", id: "<usage-widget-uuid>"}
   - scope: {mode: "read", allowedFilters: ["month"], allowedActions: []}
   - hostOrigins: ["https://portal.example.com", "https://staging-portal.example.com"]
   - tenantThemeId: "<saas-vendor-theme-uuid>"
   - tokenExpiresAt: "2026-12-31T23:59:59Z" (expires end of year)
   - rateLimitPolicy: {requestsPerMinute: 1200, burstSize: 120}

### TenantTheme seed objects

1. **Gemeente Zeist brand** (slug: `theme-zeist`)
   - name: "Gemeente Zeist"
   - cssVariables: {
       "--c-primary": "#21468B",
       "--c-surface": "#FFFFFF",
       "--c-text": "#1a1a1a",
       "--font-family": "Inter, sans-serif",
       "--c-border": "#e0e0e0"
     }
   - logoUrl: "https://www.zeist.nl/logo.svg"
   - customCss: ":root { --spacing: 8px; }" (only :root rules allowed)

2. **Gemeente Amsterdam brand** (slug: `theme-amsterdam`)
   - name: "Gemeente Amsterdam"
   - cssVariables: {
       "--c-primary": "#EC0000",
       "--c-surface": "#FFFFFF",
       "--c-text": "#333333",
       "--font-family": "Roboto, sans-serif",
       "--c-border": "#cccccc"
     }
   - logoUrl: "https://www.amsterdam.nl/logo.svg"

3. **SaaS vendor brand** (slug: `theme-saas-vendor`)
   - name: "Example Corp"
   - cssVariables: {
       "--c-primary": "#6366F1",
       "--c-surface": "#FFFFFF",
       "--c-text": "#1F2937",
       "--font-family": "\"Helvetica Neue\", Helvetica, Arial, sans-serif",
       "--c-border": "#D1D5DB"
     }
   - logoUrl: "https://example.com/branding/logo.svg"

### SigningKey seed objects

1. **Active RS256 key** (slug: `key-rsa-active-1`)
   - algorithm: "RS256"
   - status: "active"
   - publicKeyJwk: { kty: "RSA", use: "sig", ..., kid: "rsa-1" }
   - privateKeyEncrypted: (encrypted with KMS)

2. **Retiring ES256 key** (slug: `key-ecdsa-retiring`)
   - algorithm: "ES256"
   - status: "retiring"
   - publicKeyJwk: { kty: "EC", use: "sig", crv: "P-256", ..., kid: "ec-old" }
   - privateKeyEncrypted: (encrypted with KMS)

### EmbedUsageEvent seed objects

1. Sample page-view event from Gemeente Zeist public website.
   - tokenId: "<zeist-woo-token-id>"
   - eventType: "pageView"
   - hostOrigin: "https://intranet.zeist.nl"
   - userAgent: "Chrome 124 / sha256(Mozilla/5.0...)"
   - viewportSize: "medium"
   - responseStatusCode: 200
   - responseLatencyMs: 87
   - timestamp: "2026-05-22T14:30:00Z"

2. Sample widget-rendered event from SaaS portal.
   - tokenId: "<saas-usage-token-id>"
   - eventType: "widgetRendered"
   - hostOrigin: "https://portal.example.com"
   - userAgent: "Safari 17 / sha256(Mozilla/5.0...)"
   - viewportSize: "small"
   - responseStatusCode: 200
   - responseLatencyMs: 156
   - timestamp: "2026-05-22T15:45:00Z"

3. Sample filter-applied event from consortium dashboard.
   - tokenId: "<amsterdam-ops-token-id>"
   - eventType: "filterApplied"
   - hostOrigin: "https://intranet.amsterdam.nl"
   - userAgent: "Firefox 125 / sha256(Mozilla/5.0...)"
   - viewportSize: "large"
   - responseStatusCode: 200
   - responseLatencyMs: 45
   - timestamp: "2026-05-22T16:20:00Z"

## Test Strategy

- **PHPUnit**: Token issuance (jwt generation + hash persistence), JWT validation (signature + expiry + revocation status), rate limiting (bucket state, 429 responses), CSP header emission, read-only enforcement.
- **Vitest (JS-SDK)**: PostMessage handshake (token injection after origin verification), iframe load, filter event relay, resize message handling.
- **Playwright**: Full end-to-end from token creation to embedded widget rendering, filter interaction from host page, theme application (CSS variable override), rate-limit 429 behaviour.
- **Admin UI**: Token create/revoke flows, usage report rendering, accessibility axe-core results display.

## Migration Plan

1. **Phase 1 — Backend services**: JWT signing service (key generation + rotation), EmbedTokenService (CRUD + validation), RateLimitService. Test coverage, no user-facing endpoints yet.
2. **Phase 2 — Public render route**: GET /apps/mydash/embed/{subjectType}/{subjectId}?token=<jwt>, CSP headers, read-only enforcement, usage telemetry.
3. **Phase 3 — Admin UI**: EmbedTokenManager component (create, revoke, view usage), tenant theme manager, signing key rotation UI.
4. **Phase 4 — JS-SDK**: @mydash/embed-sdk package (ESM + UMD), postMessage handshake, event relay.
5. **Phase 5 — Integration**: Modify dashboard/widget rendering to accept token scope context, apply theming, wire up accessibility checks.

## Open Questions

1. **Should token expiry default to a fixed duration (e.g., 1 year) or be non-expiring?**
   - Current decision: Non-expiring by default, with optional `tokenExpiresAt` that admins can set per token. Rationale: issued-once tokens should remain valid until explicitly revoked; forcing re-issuance on every anniversary is operationally heavy.

2. **Should the `EmbedUsageEvent` aggregate data (e.g., "pageviews per day") or write raw events?**
   - Current decision: Write raw events (every page-view is one row). Aggregation happens at query time via analytics views. Rationale: raw events support arbitrary slicing (by origin, by viewport, by time); pre-aggregation would lock us into specific dimensions.

3. **Should the JS-SDK support Pinia/Vuex integration for two-way state sync, or is event-only (host subscribes to filterApplied) sufficient?**
   - Current decision: Event-only in v1. Host page subscribes to filterApplied/drillDown/export events via `.on(eventType, handler)`. Two-way state sync is out of scope. Rationale: keeps the SDK lightweight and language-agnostic; not all host pages use Pinia.

## Reuse Analysis

- **OpenRegister integration**: Embeds store all persistent objects (tokens, themes, keys, events) as register objects, reusing ObjectService, AuditTrailService, ACL infrastructure.
- **Existing widget/dashboard rendering**: Embeds leverage the same widget/dashboard rendering code paths as in-app views. No widget-specific fork. Theme injection happens at the Vue root level (CSS variable injection into `:root`).
- **Nextcloud CSP + Security headers**: The embed route respects Nextcloud's CSP policy and emits appropriate headers (CSP, X-Content-Type-Options, X-Frame-Options fallback).

## Deduplication Check

- **Token issuance**: No overlap with Nextcloud's session/cookie issuance. Nextcloud tokens (app password, remember token) are user-centric; embed tokens are widget-centric.
- **Rate limiting**: OpenRegister has no built-in rate limiting. RateLimitService is new and specific to embed tokens.
- **Usage telemetry**: Not reusing any MyDash existing logging. ActivityService is user-action centric; EmbedUsageEvent is traffic-observation centric.
- **Theme injection**: Theme service is new. Widgets already support CSS variable overrides (REQ-WDG-007 or similar), but tenant-theme is a new schema for bundling multiple variables + logo + custom CSS.
- **JWT signing**: Not reusing Nextcloud's OAuth2 token logic (which is stateful and session-based). JWT is stateless and self-contained.

**Conclusion**: No overlap found. All components are new.
