---
status: draft
---
# Embedded Analytics

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Reports / (root)

**Rationale:** Output artefact  
_Source: /tmp/ia-mydash-openregister.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

mydash today is a Nextcloud app: to see a widget you have to log into Nextcloud, navigate to mydash, and have permission on the dashboard. That is correct for internal organisational consumption but it forecloses three classes of legitimate use that customers keep asking for. First, public-facing transparency — a gemeente that wants to put a live "openstaande WOO-verzoeken" widget on the page that explains their WOO process to citizens. Second, partner-facing operational dashboards — a shared-service organisation that runs mydash for a consortium of municipalities and wants each member gemeente to embed their own slice into the gemeente's internal intranet without giving everyone a Nextcloud account. Third, in-product analytics — a SaaS vendor whose product is built on Conduction's stack who wants to surface "your usage this month" widgets inside their own customer portal without iframing a full Nextcloud login flow. Embedded Analytics gives mydash a first-class answer to all three.

The capability is built around six ideas. First, signed JWT tokens are the only auth mechanism — no session cookies, no IP allow-lists, no anonymous embeds — so every embedded view has a verifiable identity that can be revoked, audited, and scoped. Second, two embedding form-factors are offered: an iframe (works in any host page, no JS integration needed) and a JS-SDK (gives the host page more control over layout, sizing, and event interception). Third, per-tenant theming through CSS variables lets each embed look native to its host — the gemeente's brand on the gemeente's intranet, the SaaS vendor's brand inside the SaaS product — without forking widget code. Fourth, CSP-compliance is non-negotiable: every embed declares its host origin upfront and the embed responds with the right `Content-Security-Policy: frame-ancestors` header so the host's CSP doesn't block the iframe and the iframe doesn't widen the host's attack surface. Fifth, read-only mode is a property of the token, not a UI toggle — a read-only token cannot be coerced into write operations even by a tampered front-end, because the API tier rejects writes when the token's `scope` lacks them. Sixth, every embed view writes a usage row so an org can answer "did this embed get any traffic?", revoke unused tokens, and prove an embed was reachable at the timestamp a downstream incident report references.

The feature layers on top of the existing widget and dashboard infrastructure without changing the widget data contract — a widget renders the same way whether it is loaded in the Nextcloud app shell or in an embedded iframe, just with different chrome around it. Auth is the only major addition: a new token-issuer flow, a new public-facing render route that accepts JWTs instead of session cookies, and a new admin UI for managing embed tokens. WCAG AA compliance is intentionally explicit because public-facing embeds frequently land in contexts (municipal websites, public information portals) that have legal accessibility obligations under the Web Toegankelijkheidsverordening / EN 301 549; an embed that fails AA in a public context is a regulatory issue, not just a UX issue.

## Data Model

An `embed_token` object holds the issued credential and its scope. Fields: `id` (UUID), `name` (string, human label shown in the admin list — e.g. "Gemeente Zeist intranet — WOO widget"), `description` (optional markdown), `tokenHash` (sha256 of the JWT — the raw token is shown ONCE on creation and never retrievable; admins re-issue if lost), `subject` (object describing the embed target: `type=widget|dashboard`, `id=UUID`, optional `filterContext` pinning), `scope` (object: `mode=read|read-with-interactions`, `allowedFilters` array of dimensions the embed may publish, `allowedActions` array — `interact`, `export`, `drillDown`), `hostOrigins` (array of allowed `Origin` values for CORS and `frame-ancestors`), `tenantThemeId` (optional UUID into theme registry), `tokenExpiresAt` (timestamp, nullable for non-expiring tokens), `revokedAt` (nullable, set on revocation), `revocationReason` (optional string), `rateLimitPolicy` (object: `requestsPerMinute`, `burstSize`), `createdBy`, `createdAt`, `updatedAt`.

A `tenant_theme` object describes a brand bundle. Fields: `id`, `name`, `cssVariables` (object: NL Design tokens overridden per-embed, e.g. `--c-primary`, `--c-surface`, `--font-family`), `logoUrl` (optional, displayed in embed header if `showHeader=true`), `customCss` (optional, sandboxed — only `:root`-scoped rules accepted, no arbitrary selectors), `createdBy`, `createdAt`. Themes are reusable across tokens so a multi-tenant SaaS vendor maintains one theme per tenant rather than per embed.

An `embed_usage_event` object is written for each embed page-view and for each interaction. Fields: `id`, `tokenId`, `eventType` (enum: `pageView`, `widgetRendered`, `filterApplied`, `drillDown`, `export`), `hostOrigin` (from `Origin` header), `userAgent` (truncated and hashed for privacy), `viewportSize` (small/medium/large bucket, not exact pixels), `timestamp`, `responseStatusCode`, `responseLatencyMs`, `correlationId` (UUID echoed in response headers for downstream tracing).

A `signing_key` object holds the JWT signing material. Fields: `id`, `algorithm` (enum: `RS256`, `ES256` — symmetric algorithms not supported because they cannot be safely shared with verifying parties), `publicKeyJwk`, `privateKeyEncrypted` (only decryptable by the signer service), `status` (enum: `active`, `retiring`, `revoked`), `createdAt`, `retiredAt`. Multiple active keys are permitted to support rotation; tokens carry `kid` to select the verifying key.

A `csp_declaration` object on the embed token controls the response CSP. Fields (within `embed_token.cspPolicy`): `frameAncestors` (computed from `hostOrigins`, never user-overridable), `defaultSrc`, `connectSrc`, `imgSrc`, `styleSrc`, `scriptSrc`, `fontSrc`. Defaults are conservative (`'self'` only) and the admin UI shows the resulting CSP header verbatim so an admin can paste it into a host-page audit.

The render route is `GET /apps/mydash/embed/{subject-type}/{subject-id}?token=<jwt>`. JWTs are bearer-style passed in the `Authorization: Bearer <jwt>` header for SDK consumers, and as a `?token=` query parameter for iframe consumers (because iframes cannot inject arbitrary request headers). The query-parameter path enforces stricter rate limits and a stricter CSP, because the query token is more easily leaked into referer-headers and access-logs.

## Requirements

### REQ-EMB-001 — Token issuance returns the JWT exactly once

The system SHALL generate a JWT signed with the active signing key when a new `embed_token` is created, return it once in the create response, persist only the sha256 hash, and reject any subsequent request to re-retrieve the raw token.

- GIVEN an admin with token-management permission
  WHEN they POST a valid token-create payload
  THEN the response SHALL include the raw JWT exactly once with a `keepThisSafe` warning and the persisted `embed_token` row SHALL store only the sha256 hash.
- GIVEN an existing `embed_token` row
  WHEN any GET request to the token endpoint is made
  THEN the response SHALL include all metadata except the raw JWT, and SHALL include a "Re-issue" action that revokes the old token and produces a new one.
- GIVEN an admin attempts to create a token with a `hostOrigins` array that includes a malformed origin (e.g. missing scheme)
  WHEN the create request is processed
  THEN the API SHALL respond 400 with a field-level error and SHALL NOT persist the token.

### REQ-EMB-002 — Public render route validates JWT and enforces scope

The render route SHALL verify the JWT signature against the `kid`-selected `signing_key`, reject expired or revoked tokens, enforce `subject` match (the URL's subject-id MUST equal the token's subject id), and respond with 403 on any mismatch without leaking the existence of the subject.

- GIVEN a valid, non-expired, non-revoked JWT for widget W
  WHEN the route is called with `/embed/widget/W?token=<jwt>`
  THEN the widget SHALL render with the token's scope applied (filter context pinned, allowed actions enabled).
- GIVEN a valid JWT for widget W but the URL specifies widget V
  WHEN the route is called
  THEN the response SHALL be 403 with body `{error: "scope mismatch"}` and SHALL NOT indicate whether V exists.
- GIVEN a JWT whose `tokenExpiresAt` is in the past
  WHEN the route is called
  THEN the response SHALL be 401 with body `{error: "token expired"}` and a `WWW-Authenticate: Bearer error="invalid_token", error_description="expired"` header.
- GIVEN a JWT whose `embed_token` row has `revokedAt` set
  WHEN the route is called
  THEN the response SHALL be 401 with body `{error: "token revoked"}` and the response latency SHALL be constant-time-comparable to a non-revoked rejection (no timing oracle).

### REQ-EMB-003 — CSP frame-ancestors enforced from token hostOrigins

The render route SHALL emit `Content-Security-Policy: frame-ancestors <hostOrigins>` derived from the token's `hostOrigins`, and the embed SHALL refuse to render when the iframe's actual top-level origin is not in `hostOrigins`.

- GIVEN a token with `hostOrigins=["https://www.zeist.nl"]`
  WHEN the embed is loaded inside an iframe on `https://www.zeist.nl/woo/`
  THEN the response SHALL include `Content-Security-Policy: frame-ancestors https://www.zeist.nl` and the iframe SHALL render normally.
- GIVEN the same token loaded from `https://evil.example.com/`
  WHEN the iframe attempts to render
  THEN the browser SHALL refuse to display the iframe (per CSP frame-ancestors enforcement) AND the server-side render route SHALL additionally check the `Sec-Fetch-Site` and `Referer` headers and respond 403 if they indicate a non-allowed origin (defence in depth for browsers/proxies that strip CSP).
- GIVEN a token with `hostOrigins=["*"]`
  WHEN the create request is processed
  THEN the API SHALL respond 400 ("wildcard origin not permitted") — the system enforces an explicit-allowlist policy.

### REQ-EMB-004 — Read-only enforcement at the API tier

The system SHALL reject any write operation (POST/PUT/PATCH/DELETE) issued under an `embed_token` whose `scope.mode=read`, even when the operation would otherwise be permitted by the subject's ACL.

- GIVEN a `read`-mode token for dashboard D
  WHEN the SDK attempts a POST to add a comment on D
  THEN the API SHALL respond 403 with body `{error: "read-only token"}` and NO state SHALL be mutated.
- GIVEN a `read-with-interactions`-mode token whose `scope.allowedActions=["interact", "drillDown"]` (but not `export`)
  WHEN the SDK attempts to invoke the export endpoint
  THEN the API SHALL respond 403 with body `{error: "action 'export' not in scope"}`.
- GIVEN a `read-with-interactions` token
  WHEN the SDK applies an in-bus filter clause (drill-down)
  THEN the operation SHALL succeed and SHALL be captured in an `embed_usage_event` with `eventType=filterApplied`.

### REQ-EMB-005 — Per-tenant theming via CSS variables

The embed SHALL apply the token's `tenantThemeId` (if set) by injecting `cssVariables` into the embed's `:root` and SHALL refuse `customCss` rules that target selectors outside `:root`.

- GIVEN a tenant theme with `cssVariables={"--c-primary": "#21468B", "--font-family": "Inter, sans-serif"}` and a token referencing it
  WHEN the embed renders
  THEN the embed's `:root` SHALL include those variable values and the widget SHALL paint with the gemeente's brand colour and font.
- GIVEN a tenant theme whose `customCss` contains `.someClass { color: red }`
  WHEN the theme is saved
  THEN the API SHALL respond 400 with a CSS validation error ("only :root-scoped rules permitted") because non-:root selectors could be used to disable required UI affordances (e.g. hide accessibility focus rings).
- GIVEN a token without `tenantThemeId`
  WHEN the embed renders
  THEN the embed SHALL use the platform default NL Design theme.

### REQ-EMB-006 — JS-SDK exposes render, event, and resize APIs

The system SHALL publish a JS-SDK (`@mydash/embed-sdk`, ESM and UMD builds) that exposes `MyDashEmbed.render(container, {token, subjectType, subjectId})`, `.on(eventType, handler)` for filter/drill/export events, and `.resize()` for host-driven layout changes.

- GIVEN a host page that imports the SDK and calls `render` with a valid token
  WHEN the SDK initialises
  THEN it SHALL create an iframe pointing at the embed render route, inject the JWT via `postMessage` handshake (so the token does not appear in the iframe `src`), and resolve a promise when the first render completes.
- GIVEN a host page subscribes to `filterApplied` via `.on('filterApplied', handler)`
  WHEN the user clicks a bar inside the embedded widget
  THEN the handler SHALL be invoked with `{dimension, value, source}` and the host page MAY synchronise its own UI off this event.
- GIVEN a host page invokes `.resize()` after a layout change
  WHEN the SDK processes the call
  THEN the iframe SHALL receive a `postMessage` instructing it to re-measure and the embed SHALL emit `'resized'` back when complete.

### REQ-EMB-007 — Rate limiting and request budgeting per token

The system SHALL apply the token's `rateLimitPolicy` to all requests, return 429 with a `Retry-After` header when the bucket is exhausted, and write a `embed_usage_event` of type `pageView` even on rate-limited responses for budgeting analysis.

- GIVEN a token with `rateLimitPolicy={requestsPerMinute: 60, burstSize: 10}`
  WHEN 70 requests arrive within ten seconds
  THEN the first 70 SHALL be processed up to the burst budget and the remainder SHALL receive 429 with `Retry-After: <seconds>`.
- GIVEN a token with no `rateLimitPolicy`
  WHEN any request arrives
  THEN the org-default policy SHALL apply (default 600 rpm, burst 60) — there is no "unlimited" mode.
- GIVEN a token that hits its rate limit
  WHEN the 429 is returned
  THEN an `embed_usage_event` row SHALL be written with `responseStatusCode=429` so analytics surface the throttling.

### REQ-EMB-008 — Usage telemetry without leaking PII

The system SHALL persist `embed_usage_event` rows for every page-view and interaction, SHALL capture `hostOrigin`, `userAgent` (truncated to family + major version + hashed), and `viewportSize` (bucketed), and SHALL NOT capture full IP addresses, full user-agents, or any identifier of the host-page's logged-in user.

- GIVEN an embed page-view from `https://www.zeist.nl/woo/`
  WHEN the event is written
  THEN it SHALL contain `hostOrigin="https://www.zeist.nl"`, `userAgent` family+major (e.g. "Firefox 128") with a sha256 of the full string, and a viewport bucket (e.g. "medium"), and SHALL NOT contain the client IP or full UA string.
- GIVEN the admin opens the usage report for a token
  WHEN the report renders
  THEN it SHALL show pageviews per day, host-origin distribution (if the token allows multiple origins), and unique-viewport buckets, AND SHALL NOT show any individual-user identifiers.
- GIVEN org-level telemetry is disabled for embed tokens
  WHEN events occur
  THEN no `embed_usage_event` rows SHALL be written (the kill-switch is auditable and admin-managed).

### REQ-EMB-009 — Token revocation takes effect within 60 seconds

The system SHALL revoke a token by setting `revokedAt`, propagate the revocation to all render-route workers within 60 seconds via a short-TTL cache, and reject subsequent render requests with 401.

- GIVEN a valid token in active use
  WHEN an admin invokes the revoke action
  THEN within 60 seconds (the cache TTL bound) ALL render-route workers SHALL reject the token with 401, even if they had recently cached a valid-status decision.
- GIVEN a revocation request
  WHEN the API processes it
  THEN the revoke row SHALL include `revokedAt` and `revocationReason` and a notification SHALL be sent to the token's `createdBy` user.
- GIVEN a revoked token used after revocation
  WHEN the render route is called
  THEN an `embed_usage_event` SHALL be written with `responseStatusCode=401` so a forensic review can reconstruct attempted post-revocation use.

### REQ-EMB-010 — WCAG 2.1 AA compliance enforced in embed rendering

The embed render route SHALL produce HTML output that meets WCAG 2.1 AA success criteria for the widget being embedded, SHALL pass automated axe-core checks at build time on a representative sample of widget shapes, and SHALL surface accessibility violations in the embed-token admin UI as a non-fatal warning.

- GIVEN an embed-render of a chart widget
  WHEN the HTML is inspected
  THEN every interactive element SHALL have an accessible name (`aria-label` or visible text), colour contrast SHALL meet AA (≥4.5:1 for text, ≥3:1 for graphical objects), and the embed SHALL be navigable by keyboard alone (tab order, focus indicators, no keyboard traps).
- GIVEN a tenant theme whose `cssVariables` set `--c-primary` to a value producing <4.5:1 contrast against `--c-surface`
  WHEN the theme is saved
  THEN the API SHALL respond 400 with a contrast-failure error including the computed contrast ratio.
- GIVEN a widget whose embed render fails the axe-core build-time check
  WHEN an admin attempts to create a token for it
  THEN the create flow SHALL show a blocking warning ("this widget has accessibility violations: <list>") and SHALL require an explicit "acknowledge and proceed" confirmation before creating.

## Standards & Sources

JWT issuance follows RFC 7519 (JSON Web Token) with RFC 7515 (JWS) for signing. Only asymmetric algorithms (RS256, ES256) are permitted because symmetric HS256 requires sharing the signing key with verifying parties, which is operationally unsafe when verifiers are third-party SaaS products or municipal intranet teams. Key rotation follows RFC 7517 (JWK) — multiple active keys with `kid` selection — and the rotation procedure (retire old key, mark active key for retirement, issue all new tokens against the new key, revoke the retired key only after all tokens signed by it have expired) is the standard pattern documented by Auth0, Okta, and the OIDC discovery spec. RS256 keys are 2048-bit RSA minimum; ES256 keys use the NIST P-256 curve (the same curve mandated by WebAuthn).

CSP enforcement (`frame-ancestors`) follows the W3C Content Security Policy Level 3 specification and is the only sanctioned mechanism for restricting iframe embedding (the deprecated `X-Frame-Options` is emitted as a fallback for legacy browsers but no protective claim is made about its sufficiency on modern browsers). The defence-in-depth check on `Sec-Fetch-Site` and `Referer` follows the Fetch Metadata Request Headers spec and provides protection on the small population of browsers/proxies that strip CSP — relevant in enterprise networks running deep packet inspection that rewrites or strips response headers. Wildcard origins (`*`) are explicitly rejected because they negate the entire framing protection; the explicit-allowlist policy matches the OWASP CSP cheat-sheet's strongest recommendation.

The JS-SDK postMessage handshake for token injection follows the OAuth2 implicit-flow critique of token-in-URL: query-parameter tokens leak into referer headers, access logs, and browser histories, so the SDK uses an iframe loaded without the token in the URL and then posts the token into the iframe via `postMessage` after origin verification. The handshake protocol matches what Stripe Elements, Plaid Link, and Google Identity Services use — both endpoints verify each other's `event.origin` against an allowlist before any message is honoured.

WCAG 2.1 AA compliance is mandated by the Web Toegankelijkheidsverordening (the Dutch implementation of EU Directive 2016/2102) and by EN 301 549 v3.2.1 for public-sector procurements; embeds that land on a municipal website without AA compliance are a regulatory issue, not just a UX bug. The axe-core build-time check (Deque's open-source rules engine) is the industry-standard automated check and catches the ~30% of WCAG violations that are mechanically detectable; the manual review that the remaining 70% requires is the responsibility of the widget author and is documented per-widget. The 4.5:1 / 3:1 contrast requirements are WCAG 1.4.3 (text) and 1.4.11 (non-text), and the theme-save contrast validator uses the WCAG luminance formula directly (not a perceptual approximation) so the validator's verdict is reproducible by any independent WCAG audit tool.

The minimal-PII telemetry shape follows GDPR/AVG data minimisation (Article 5(1)(c)) and the Nederlandse Datapreventie norm: capturing IP addresses for embed views is a processing activity that requires a legal basis (legitimate interest, with a DPIA), while capturing host-origin and viewport-bucket is functional analytics that does not produce a data-subject record. The user-agent hashing (truncated family+major, sha256 of full UA) supports fingerprint diversity analysis (e.g. "are 60% of users on Chrome 128?") without retaining the full UA string that itself approximates a fingerprint.

Token rate limiting follows the token-bucket algorithm with `requestsPerMinute` as the refill rate and `burstSize` as the bucket capacity, matching how AWS API Gateway, GitHub's REST API, and most CDN edge rate-limiters implement throttling. The 429 with `Retry-After` is RFC 6585 compliant and the 60-rpm default for unspecified tokens is conservative enough that even a busy municipal website is unlikely to hit it from organic traffic; tokens that need higher budgets are an admin decision so the cost (in compute, in audit-log volume) is visible.

Revocation propagation within 60 seconds is a deliberate engineering trade-off against the alternative (zero-cache, every render call hits the DB to check revocation status). The 60-second TTL is short enough to satisfy most incident-response timelines, long enough that the render route can sustain high throughput without DB pressure. For higher-assurance use cases (e.g. a compromised token of a critical embed) the admin UI exposes a "purge cache" action that drops the TTL to zero for that token id — manual escalation rather than a system-wide latency tax.

## Cross-app integration

- **openregister**: `embed_token`, `tenant_theme`, `embed_usage_event`, and `signing_key` rows are stored as register objects in the existing mydash register, sharing lifecycle, ACL, soft-delete, and audit-log infrastructure. Token revocation is implemented as a soft-delete with `revokedAt`; the audit log shows the revocation actor and reason.
- **openconnector + auth-system token scopes (ADR-XXX)**: the JWT scope vocabulary (`scope.mode`, `scope.allowedFilters`, `scope.allowedActions`) is aligned with the platform-wide token-scopes ADR so an organisation's existing scope concepts (e.g. `read:dashboards`, `interact:dashboards`) extend naturally to embed tokens. OpenConnector is the recommended location for tokens that need to be issued by an external IdP and exchanged for mydash embed tokens (token-exchange flow, RFC 8693), keeping the IdP integration in OC's source-credential vault.
- **mydash drill-down-cross-widget-filter**: an embed token with `read-with-interactions` mode and `allowedActions=["drillDown"]` participates in the bus exactly as an in-app session would, with the restriction that the URL bookmarking is scoped to the embed's iframe URL (the host page may choose to mirror the bus state into its own URL via the SDK's filter events).
- **mydash scheduled-exports**: a scheduled export's recipient channel MAY be an embed (delivering a static snapshot rendered with the embed's theme to a Files-area destination accessible to the embed's host) — this is a v2 follow-up but the embed-token schema reserves the field.
- **opencatalogi**: public-facing catalog embeds (e.g. softwareproducten on a partner-program website) reuse the embed-token mechanism without needing a separate opencatalogi-specific public flow.
- **docudesk**: document-preview widgets MAY be embedded for partner-facing document portals; the document's underlying ACL is enforced by docudesk regardless of the embed token, so an embed cannot widen access to a document beyond what the token's owning user has.
- **AI Chat Companion (ADR-034)**: the chat companion is intentionally NOT exposed via embed tokens in v1; embeds are read/interact only. A future embed mode for the companion would require a separate scope-vocabulary addition and is out of scope here.

## Target users

- **Org administrators / platform operators** issue, scope, and revoke embed tokens. They manage signing-key rotation, tenant themes, rate-limit policies, and the org-level telemetry kill-switch. They are the audience for the usage-analytics report and the access-revocation UI; they answer security questions ("which embeds exist? when was each last used? are any tokens compromised?").
- **Front-end developers on the host site** (municipal webteams, SaaS-product engineers, partner-portal teams) consume the iframe URL or the JS-SDK. They are the audience for the embed-integration documentation, the CSP-header preview, and the SDK's TypeScript types. They never see the underlying widget code; the embed is a black box that exposes a documented contract.
- **End-users on the host site** (citizens reading a gemeente's WOO page, customers reading their usage in a SaaS product) consume the embedded widget without authentication. They never log into mydash and may not know it exists; the embed must therefore be self-explanatory and accessible to the same standard the host page meets.
- **Dashboard authors / data analysts** create the widgets and dashboards that are then embedded — they need to be aware that their widget will be rendered in unknown brand contexts and on unknown viewport sizes, so the widget design has to be robust to theme overrides and responsive layout.
- **Compliance / DPO** reviews the embed-usage telemetry to confirm no PII is leaving the mydash boundary, audits the WCAG-AA report for public-facing embeds (required evidence for the annual toegankelijkheidsverklaring), and approves new embed deployments to citizen-facing contexts. They are the audience for the "what data does an embed collect?" page in the admin docs.
