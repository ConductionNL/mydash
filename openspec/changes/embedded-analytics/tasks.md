# Tasks — embedded-analytics

## Infrastructure & Data Layer

- [ ] Task 1: Create OpenRegister schemas in `lib/Settings/mydash_register.json` for four new register types:
  - `embed_token` (fields: id, name, description, tokenHash, subject, scope, hostOrigins, tenantThemeId, tokenExpiresAt, revokedAt, revocationReason, rateLimitPolicy, createdBy, createdAt, updatedAt)
  - `tenant_theme` (fields: id, name, cssVariables, logoUrl, customCss, createdBy, createdAt)
  - `embed_usage_event` (fields: id, tokenId, eventType, hostOrigin, userAgent, userAgentHash, viewportSize, timestamp, responseStatusCode, responseLatencyMs, correlationId)
  - `signing_key` (fields: id, algorithm, publicKeyJwk, privateKeyEncrypted, status, createdAt, retiredAt)
  Include seed data per design.md (3–5 objects per schema with Dutch values)

- [ ] Task 2: Create entity mapping classes under `lib/Entity/`:
  - `EmbedToken.php` (OpenRegister mapper)
  - `TenantTheme.php` (OpenRegister mapper)
  - `EmbedUsageEvent.php` (OpenRegister mapper)
  - `SigningKey.php` (OpenRegister mapper)
  Plus exception classes: `EmbedTokenException`, `InvalidTokenException`, `TokenExpiredException`, `TokenRevokedException`

- [ ] Task 3: Create `lib/Service/JwtSigningService.php` for JWT lifecycle:
  - Generate RS256 and ES256 key pairs (minimum 2048-bit RSA, P-256 for ECDSA)
  - Sign JWTs with the active key (includes `kid` header)
  - Verify JWT signatures against the `kid`-selected public key
  - Rotate keys: mark old key `status: retiring`, new tokens sign with new key, old tokens remain valid until expiry
  - Unit tests: signature verification, key rotation, algorithm selection

- [ ] Task 4: Create `lib/Service/EmbedTokenService.php` for token persistence:
  - `createToken(name, description, subject, scope, hostOrigins, tenantThemeId, tokenExpiresAt, rateLimitPolicy)`: Generate JWT + persist token record + return token (shown once)
  - `getToken(id)`: Return token metadata (no JWT)
  - `validateToken(jwt)`: Verify signature + expiry + revocation status
  - `revokeToken(id, reason, notes)`: Set revokedAt, send notification
  - `unrevokeToken(id)`: Clear revokedAt (soft revocation)
  - Unit tests: token creation, validation, revocation, expiry

- [ ] Task 5: Create `lib/Service/RateLimitService.php` (token-bucket algorithm):
  - `checkRateLimit(tokenId, timestamp)`: Query token's `rateLimitPolicy`, check bucket state, return remaining quota or 429 error
  - `refillBucket(tokenId)`: Update bucket based on elapsed time (token-bucket refill)
  - Store bucket state in Redis or in-memory cache with TTL
  - Unit tests: burst capacity, refill rate, 429 response generation

## JWT Validation & Scope Enforcement

- [ ] Task 6: Create `lib/Middleware/EmbedTokenMiddleware.php`:
  - Extract JWT from `?token=` query parameter OR `Authorization: Bearer` header
  - Validate JWT signature + expiry + revocation (REQ-EMB-002)
  - Extract token subject and scope
  - Store token context in the request for downstream use (tokenId, scope, hostOrigins, etc.)

- [ ] Task 7: Create `lib/Service/ScopeEnforcementService.php` (REQ-EMB-004):
  - `enforceReadOnly(request)`: If token's `scope.mode=read` and request is POST/PUT/PATCH/DELETE, respond 403
  - `enforceActionScope(request, action)`: If action not in `scope.allowedActions`, respond 403
  - `applyFilterContext(request, filterContext)`: Pin filters from token's `subject.filterContext`
  - Integrate into API middleware so ALL non-GET/HEAD requests are checked

- [ ] Task 8: Create `lib/Service/CspService.php` for CSP header generation (REQ-EMB-003):
  - `generateCspHeader(hostOrigins)`: Return `Content-Security-Policy: frame-ancestors <origins>`
  - `validateOrigins(origins)`: Reject wildcard, require scheme + domain
  - `validateOriginFormat(origin)`: Validate RFC 3986 format
  - Unit tests: CSP header syntax, origin validation, wildcard rejection

## Public Render Route

- [ ] Task 9: Create `lib/Controller/EmbedController.php`:
  - `GET /apps/mydash/embed/{subjectType}/{subjectId}`: Public render route
    1. Extract + validate JWT (Middleware handles this)
    2. Verify subject match (URL's subjectId == token's subject.id)
    3. Render the widget/dashboard HTML with:
       - Token scope applied (filter context pinned, allowed actions visible)
       - Tenant theme injected into :root CSS variables
       - CSP headers emitted
    4. Write `embed_usage_event` (REQ-EMB-008)
    5. Respond 200 or appropriate error (401, 403, 429)

- [ ] Task 10: Create `lib/Service/EmbedRenderService.php`:
  - `renderWidget(widgetId, tokenScope, tenantTheme)`: Render single widget with scope + theme applied
  - `renderDashboard(dashboardId, tokenScope, filterContext, tenantTheme)`: Render dashboard with scope + theme applied
  - Reuse existing widget/dashboard rendering code (no fork)
  - Wrap output with CSP headers, no navigation chrome

## Theming & Accessibility

- [ ] Task 11: Create `lib/Service/TenantThemeService.php`:
  - `createTheme(name, cssVariables, logoUrl, customCss)`: Validate CSS, persist theme
  - `validateCss(customCss)`: Reject non-:root selectors (CSSTidy or simple regex)
  - `validateContrastRatio(primaryColor, surfaceColor)`: Use WCAG luminance formula, enforce 4.5:1
  - Unit tests: CSS validation, contrast computation, color parsing

- [ ] Task 12: Add axe-core integration to build pipeline:
  - In `npm run build`, add a linting step that:
    1. Renders representative widget shapes (chart, table, text, image)
    2. Runs axe-core accessibility checks against the rendered HTML
    3. Generates a report (axe-results.json)
    4. Fails build if high/critical violations are found
    5. Warns on medium/low violations
  - Create `src/axe-core-check.js` script
  - Output report saved to `docs/accessibility/axe-results.json` for review

- [ ] Task 13: Modify dashboard/widget rendering to accept token context:
  - Add optional `embedContext` parameter to widget/dashboard render functions
  - If `embedContext` present, apply theme CSS variables, scope restrictions, filter pinning
  - Maintain backward compatibility (no context = normal in-app rendering)

- [ ] Task 14: Create `src/composables/useEmbedContext.js`:
  - Detect if the current render is in embed mode (check for token in parent postMessage or in render context)
  - Provide theme CSS variables to Vue components
  - Apply scope restrictions (hide disallowed actions, disable exports, etc.)
  - Relay events back to parent via postMessage (REQ-EMB-006)

## Admin UI — Token Management

- [ ] Task 15: Create `src/components/EmbedTokenManager.vue`:
  - List view: Table of all tokens with name, status, subject, created, last-used, revoked date, actions
  - Create flow: Form for name, description, subject (widget/dashboard picker), scope (mode + filters + actions), hostOrigins, tenantThemeId, tokenExpiresAt, rateLimitPolicy
  - Display: Show raw JWT once, with "keep this safe" warning, "copy" button
  - Detail view: Full token metadata, re-issue/revoke buttons, usage stats link
  - Edit: Update name, description, rateLimitPolicy (scope + subject immutable)
  - Revoke dialog: Dropdown for reason, text field for notes, confirm
  - Actions: Re-issue, revoke/un-revoke, view usage

- [ ] Task 16: Create `src/components/TenantThemeManager.vue`:
  - List view: Table of themes with name, primary colour preview, logo, created date
  - Create flow: Form for name, CSS variables (editable text fields with labels), logoUrl uploader, customCss (textarea with :root validation)
  - Live preview: Show selected colours applied to sample widget
  - Edit: Update name, variables, logo, customCss
  - Delete: Warn if theme is in-use by tokens
  - Copy button: Export JSON for documentation

- [ ] Task 17: Create `src/components/EmbedUsageReport.vue`:
  - Summary card: Total pageviews (7d), status, last activity
  - Pageviews chart: Line chart by date
  - Host origin distribution: Bar chart or pie
  - Viewport size breakdown: Stacked bar (small/medium/large)
  - Browser distribution: Bar chart (top 10 user-agents)
  - Interaction breakdown: Counters for filterApplied, drillDown, export
  - Rate limit breaches table (if any): Date, count, percentage of quota
  - Export button: Download CSV of underlying events (unless org telemetry disabled)

- [ ] Task 18: Create `src/components/EmbedTokenForm.vue` (sub-component):
  - Reusable form for create + edit flows
  - Name/description fields
  - Subject picker (dropdown of widgets + dashboards)
  - Filter context picker (if subject is dashboard with filterable dimensions)
  - Scope editor:
    - Radio buttons: Read vs Read-with-Interactions
    - Checkboxes for allowedActions (interact, export, drillDown)
    - Multi-select for allowedFilters
  - HostOrigins multi-input (add/remove origins with validation)
  - TenantThemeId picker dropdown
  - TokenExpiresAt date picker (optional)
  - RateLimitPolicy fields (requestsPerMinute, burstSize)
  - Help text + CSP preview button (shows resulting CSP header)
  - Submit + cancel buttons

## JS-SDK

- [ ] Task 19: Create `@mydash/embed-sdk` package structure:
  - `src/MyDashEmbed.js` — main entry point (render, on, off, resize methods)
  - `src/postMessage.js` — Origin verification + handshake helpers
  - `src/types.ts` — TypeScript definitions (EmbedConfig, EmbedInstance, event types)
  - `dist/embed-sdk.esm.js` (ESM build)
  - `dist/embed-sdk.umd.js` (UMD build)
  - `package.json` with exports, version matching app version

- [ ] Task 20: Implement `MyDashEmbed.render(container, config)` (REQ-EMB-006):
  - Accept: `{token, subjectType, subjectId, tenantThemeId (optional)}`
  - Create iframe with `src="/apps/mydash/embed/{subjectType}/{subjectId}"`
  - Insert iframe into container
  - Wait for `load` event
  - Perform postMessage handshake: POST token to iframe (origin verification on both sides)
  - Return promise that resolves when 'embed-ready' message received
  - Reject promise if errors occur

- [ ] Task 21: Implement event relay in SDK (REQ-EMB-006):
  - `.on(eventType, handler)` registers listener for filterApplied, drillDown, export, error, etc.
  - Listen for postMessage from iframe
  - Invoke registered handlers with event payload
  - `.off(eventType, handler)` unregisters listener
  - Support multiple listeners per event

- [ ] Task 22: Implement `.resize()` method (REQ-EMB-006):
  - Read iframe's container dimensions
  - Post resize message to iframe with new width/height
  - Wait for 'embed-resized' message
  - Return promise that resolves when resize complete

- [ ] Task 23: Create iframe-side entry point for SDK (`src/embed-entry.js`):
  - Listen for postMessage from parent (token injection)
  - Verify parent origin against allowed hostOrigins (decoded from token)
  - Store token in secure context
  - Render widget/dashboard using EmbedRenderService
  - Relay interaction events back to parent via postMessage
  - Listen for resize messages and re-measure

- [ ] Task 24: Build and publish SDK:
  - Create `.github/workflows/publish-sdk.yml` to build + publish @mydash/embed-sdk to npm on release
  - Package.json versions match app version (synchronized releases)
  - README with examples (vanilla JS, ESM, TypeScript)

## Rate Limiting & Revocation

- [ ] Task 25: Implement revocation cache + propagation:
  - Store revocation status in Redis (or in-memory cache with 60s TTL)
  - On token revocation, immediately set cache entry `revoked:<tokenId> = true`
  - Render route checks cache first; if miss, queries database
  - Implement purge-cache action: Broadcast cache-clear message to all workers (Redis pub/sub)
  - Unit tests: cache hits, TTL expiry, database fallback

- [ ] Task 26: Integrate RateLimitService into render route:
  - In EmbedController, call `RateLimitService.checkRateLimit(tokenId, now)`
  - If 429, write usage event with 429 status
  - Return 429 response with Retry-After header
  - Include RateLimit-Limit, RateLimit-Remaining, RateLimit-Reset headers

- [ ] Task 27: Create revocation UI flow in EmbedTokenManager:
  - Revoke button → confirmation dialog with reason dropdown + notes textarea
  - POST `/api/embed-tokens/{id}/revoke` with reason + notes
  - Show success message
  - Update token list (status changes to REVOKED)
  - Un-revoke button (clear revokedAt, show confirmation)

## Usage Telemetry

- [ ] Task 28: Implement usage event capture (REQ-EMB-008):
  - In EmbedController render route, after rendering widget:
    1. Extract host origin from Origin or Referer header
    2. Parse user-agent: extract browser family + major version
    3. Compute user-agent hash: sha256(full UA string)
    4. Compute viewport bucket: small/medium/large from request context (if available from SDK)
    5. Measure response latency
    6. Create `embed_usage_event` row with all fields
  - Unit tests: PII minimisation (no IP, no full UA, no end-user identifier)

- [ ] Task 29: Create usage report API:
  - `GET /api/embed-tokens/{id}/usage` — Summary stats + aggregated events (by date, by host origin, by viewport, by browser)
  - Respond with JSON suitable for charts (date series, bar data, pie data)
  - No individual-user rows (aggregation only)

- [ ] Task 30: Implement telemetry kill-switch:
  - Add org setting: `embed_telemetry_enabled` (boolean, default true)
  - In render route, check setting before writing `embed_usage_event`
  - If disabled, skip event capture (no row written)
  - Audit log when toggle is changed
  - Admin UI: Settings page checkbox for "Collect embed usage telemetry"

## Testing & Documentation

- [ ] Task 31: PHPUnit tests for JWT signing:
  - Sign a JWT with RS256, verify signature
  - Verify JWT with wrong key fails
  - Verify expired JWT rejected
  - Verify revoked token rejected
  - Key rotation: old key still verifies old tokens, new key signs new tokens

- [ ] Task 32: PHPUnit tests for token lifecycle:
  - Create token → retrieve JWT (once)
  - Re-retrieve → no JWT
  - Update token (name, rateLimitPolicy)
  - Revoke token → subsequent requests rejected
  - Un-revoke token → requests accepted again

- [ ] Task 33: PHPUnit tests for scope enforcement:
  - Read-only token rejects POST/PUT/PATCH/DELETE
  - Read-with-interactions token allows pinned filters
  - Action scope: export allowed only if in allowedActions
  - Filter scope: filter dimensions restricted to allowedFilters

- [ ] Task 34: Vitest tests for JS-SDK:
  - Render method creates iframe, performs postMessage handshake
  - Event listeners registered + invoked on iframe messages
  - Resize method posts message + resolves promise
  - Origin verification prevents cross-origin leaks
  - Multiple embeds on same page are independent

- [ ] Task 35: Playwright integration tests:
  - Create embed token via admin UI
  - Render widget in iframe with token (public render route)
  - Verify CSP headers present
  - Verify filter interaction works (drill-down)
  - Verify read-only token blocks write operations
  - Verify theme CSS variables applied
  - Verify rate limiting (429 after quota exceeded)
  - Verify revocation takes effect within 60s

- [ ] Task 36: Create documentation:
  - `docs/embed/overview.md` — Purpose + use cases + security model
  - `docs/embed/admin-guide.md` — Token creation, revocation, theming, usage analytics
  - `docs/embed/integration-guide.md` — How to integrate SDK (iframe + JS-SDK form factors)
  - `docs/embed/sdk-reference.md` — JS-SDK API (render, on, resize, event types)
  - `docs/embed/troubleshooting.md` — Common issues (CSP errors, CORS, origin mismatch)

- [ ] Task 37: i18n for new UI strings:
  - Token manager: create, revoke, re-issue, usage, accessibility warning
  - Theme manager: create, edit, delete, preview
  - Admin notification: token revoked, policy updated
  - All strings in both NL (Dutch) + EN (English)
  - Add to `src/translations/nl.json` + `src/translations/en.json`

## Quality & Integration

- [ ] Task 38: ESLint clean on new files:
  - Run `npm run lint` on all new/modified Vue + JS files
  - Fix any warnings or errors before merging
  - Ensure axe-core check output is clean (no accessibility violations)

- [ ] Task 39: Security audit checklist:
  - JWT signature verification is unconditional (no bypass paths)
  - Scope enforcement at API tier (not UI)
  - CSP frame-ancestors + Fetch-Metadata headers present
  - No IP addresses captured in telemetry
  - Rate limiting prevents abuse
  - Revocation propagates within 60s
  - Read-only tokens cannot mutate state
  - Wildcard origins rejected at creation time

- [ ] Task 40: Integration with existing MyDash features:
  - Dashboards display "Share" → includes "Create embed token" action alongside existing share modes
  - Widgets display "Embed" button → quick token creation for single widget
  - Admin Settings → "Embed Tokens" section with manager + themes
  - Audit log records token lifecycle (create, revoke, re-issue)

## Verification

`openspec validate` exits clean. Embed tokens are creatable via admin UI, render publicly with CSS variables + scope enforcement, propagate revocation within 60s, and write auditable telemetry.

## Tests (company-wide ADR-009)

- PHPUnit: JWT signing, token lifecycle, scope enforcement, rate limiting, CSP validation
- Vitest: JS-SDK render, event relay, origin verification
- Playwright: End-to-end from token creation to rendered widget, filter interaction, revocation propagation

## Documentation (company-wide ADR-010)

- User guide: Token creation, revocation, theme management
- Developer guide: SDK integration (iframe + JS-SDK), event handling, CSP configuration
- Architecture: JWT signing, scope model, telemetry PII minimisation

## i18n (company-wide ADR-005)

Dutch (NL) + English (EN) parity for all new UI strings and user-facing messages.
