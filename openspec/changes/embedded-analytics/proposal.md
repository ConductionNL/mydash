# Embedded Analytics

MyDash widgets today require a logged-in Nextcloud session to view. This forecloses three legitimate use cases: public-facing transparency dashboards (e.g., a gemeente's live "openstaande WOO-verzoeken" on a public webpage), partner-facing operational dashboards (shared-service organisations embedding their widget slice into each member gemeente's intranet), and in-product analytics (SaaS vendors surfacing usage widgets inside their customer portal). Embedded Analytics adds a first-class answer via signed JWT tokens, iframe and JS-SDK rendering paths, per-tenant theming, WCAG AA compliance, and auditable usage telemetry.

## Affected code units

- **NEW** `lib/Controller/EmbedController.php` — token issuance, revocation, usage analytics
- **NEW** `lib/Service/EmbedTokenService.php` — token generation, validation, persistence
- **NEW** `lib/Service/JwtSigningService.php` — JWT lifecycle (RS256/ES256), key rotation
- **NEW** `lib/Service/RateLimitService.php` — token-bucket rate limiting per token
- **NEW** `lib/Entity/EmbedToken.php` — OpenRegister mapping for embed_token objects
- **NEW** `lib/Entity/TenantTheme.php` — OpenRegister mapping for tenant_theme objects
- **NEW** `lib/Entity/EmbedUsageEvent.php` — OpenRegister mapping for embed_usage_event objects
- **NEW** `lib/Entity/SigningKey.php` — OpenRegister mapping for signing_key objects
- **NEW** `src/components/EmbedTokenManager.vue` — admin UI for token creation, revocation, usage report
- **NEW** `@mydash/embed-sdk` (ESM + UMD) — JS client for iframe rendering with postMessage handshake
- **NEW** `GET /apps/mydash/embed/{subjectType}/{subjectId}?token=<jwt>` — public render route (CSP-hardened)
- **MODIFY** `src/composables/useEmbedContext.js` — detect embed mode, apply token scope and theming
- **MODIFY** Dashboard and widget rendering — enforce read-only on REQ-EMB-004 scope, apply theming on REQ-EMB-005

## Capabilities

### New Capabilities

- `embed-tokens`: Admin-only JWT token lifecycle (issuance, revocation, rotation). Tokens are the only auth mechanism for embedded views.
- `embed-render`: Public-facing HTML rendering of widgets with CSP frame-ancestors, scope enforcement, and usage telemetry.
- `tenant-themes`: Per-tenant brand bundles (CSS variables, custom CSS, logo) injected into embed contexts.
- `embed-sdk`: JavaScript client (@mydash/embed-sdk) for host-page integration (iframe wrapper + postMessage token injection + event relay).
- `embed-usage-analytics`: Per-token usage telemetry (pageviews, interactions, host origin, viewport size) with PII minimisation.

### Modified Capabilities

- `widget-rendering`: Widgets now accept a token scope that restricts available actions and applies filter pinning.
- `dashboard-sharing`: Embed tokens join the existing sharing surface in the dashboard detail view (alongside "share to group").

## Why a new change

The six sub-capabilities (tokens, render, theming, SDK, telemetry, WCAG) are tightly coupled and release together. Token issuance without a render path is useless; a public render without WCAG compliance is a regulatory issue on municipal websites. Splitting into six micro-changes would spawn six git branches, six PRs, and six merge conflicts while adding no architectural clarity — the coupling is real, not artificial.

## Cross-app integration

- **openregister**: `embed_token`, `tenant_theme`, `embed_usage_event`, and `signing_key` stored as register objects with lifecycle, ACL, soft-delete, and audit-log infrastructure.
- **openconnector + auth-system token scopes**: JWT scope vocabulary aligns with platform-wide ADRs; external IdPs can exchange credentials for mydash tokens via RFC 8693 token-exchange flow.
- **mydash drill-down**: Embed tokens with `read-with-interactions` mode participate in the bus exactly like in-app sessions, scoped to the embed's iframe URL.
- **opencatalogi**: Public-facing catalog embeds reuse the embed-token mechanism.
- **docudesk**: Document-preview widgets may be embedded with the document's underlying ACL enforced by docudesk (embed cannot widen access).
- **AI Chat Companion (ADR-034)**: Intentionally NOT exposed via embed tokens in v1; embeds are read/interact only.

## Notes

- Read-only enforcement at the API tier (REQ-EMB-004) ensures even a tampered frontend cannot coerce writes; the check is unconditional on the server side.
- CSP `frame-ancestors` is the only sanctioned framing protection (W3C CSP Level 3); `X-Frame-Options` is emitted as a fallback for legacy browsers but carries no protective claim on modern browsers.
- Rate limiting follows the token-bucket algorithm with a 60-second propagation window for revocations — a deliberate trade-off between incident-response speed and render-route throughput.
- WCAG 2.1 AA compliance is mandated by the Web Toegankelijkheidsverordening for public-sector procurements; municipal-website embeds without AA are a regulatory issue.
- Usage telemetry captures host origin, viewport bucket, and user-agent family+major (hashed) — NO IP addresses, NO full user-agents, NO end-user identifiers (GDPR/AVG Article 5(1)(c) data minimisation).
