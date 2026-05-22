# Tasks — public-dashboard-publication

## Core Service Layer

- [ ] Task 1: Create `lib/Service/PublicationService.php` with methods: `createPublication(dashboardId, mode, slug, brandingDefaults)`, `updatePublication(publicationId, updates)`, `publishPublication(publicationId)`, `pausePublication(publicationId)`, `retractPublication(publicationId, reason)`. Include validation that slug is unique within tenant and matches `/^[a-z0-9\-]{3,50}$/`. Store all changes via OpenRegister ObjectService.

- [ ] Task 2: Create `lib/Service/PublicationAuthService.php` with methods: `validatePublicAccess(publicationId, request)` → `{allowed: bool, mode: string}`, `validateSignedUrlGrant(publicationId, grantId, expiresAt, signature) → {valid: bool, usageCount: int}`, `validatePasswordGate(publicationId, passwordSubmission) → {authenticated: bool, hint?: string, lockoutRemaining?: int}`. Implement constant-time password comparison using `password_verify()`. Enforce lockout logic with session-scoped attempt counters.

- [ ] Task 3: Create `lib/Service/PublicationQueryService.php` with method: `executeQueriesAsServiceAccount(publicationId, queries) → {results}`. For each query, fetch the PublicationServiceAccount ACL for the tenant, verify the query targets only allowed registers/schemas, execute the query via the existing query engine with the service account principal (not the request context principal). Reject queries targeting disallowed registers with a 403 error.

- [ ] Task 4: Create `lib/Service/PublicationAnalyticsService.php` with methods: `recordView(publicationId, request)` → aggregates into PublicationViewLog hourly bucket, `aggregateViews(publicationId, dayStart, dayEnd)` → returns daily summary, `deriveSessionHash(sessionCookie, tenantSalt) → hash` using HMAC-SHA256, `getCountryFromGeoIP(request) → iso3166code` via optional GeoIP library (e.g., MaxMind, or skip if unavailable). Ensure no PII is logged (no IP, no User-Agent, no session ID in plaintext).

- [ ] Task 5: Create `lib/Service/BrandingService.php` with methods: `createBrandingProfile(publicationId, branding)`, `updateBrandingProfile(publicationId, updates)`, `getBrandingDefaults()` returning nldesign palette defaults. Store logo and favicon via FileService, keep references in PublicationBranding. Validate footerHtml is safe (no script tags; use an HTML purifier like `Html2Text` or `Bleach`).

## OpenRegister Schemas

- [ ] Task 6: Define OpenRegister schema `DashboardPublication` with properties: publicationId (UUID), dashboardId (ref to mydash dashboards register), slug (string, pattern, unique index), mode (enum: public/signed/password), publishedAt (datetime), publishedBy (ref to user), status (enum: draft/published/paused/retracted), retractionReason (string nullable), lastModifiedAt (datetime). Add to `lib/Settings/mydash_register.json` with `x-openregister.type: "application"`.

- [ ] Task 7: Define OpenRegister schema `PublicationBranding` with properties: publicationId (ref), organisationLogo (UUID ref to file), favicon (UUID ref to file), primaryColour (hex string), secondaryColour (hex string), fontFamily (string), footerHtml (string), accessibilityStatementUrl (url), privacyStatementUrl (url), contactEmail (email), language (enum: nl/en/fr/de). Add to `lib/Settings/mydash_register.json`.

- [ ] Task 8: Define OpenRegister schema `PublicationAccess` with properties: publicationId (ref), robotsPolicy (enum: index_follow/noindex_nofollow/noindex_follow), cacheControlMaxAge (integer, seconds, default 0), staleWhileRevalidate (integer, seconds, default 0), allowedReferrers (array of strings, optional), allowedCountries (array of ISO 3166-1 alpha-2 codes, optional). Add to `lib/Settings/mydash_register.json`.

- [ ] Task 9: Define OpenRegister schema `SignedUrlGrant` with properties: grantId (UUID), publicationId (ref), issuedTo (string, label), issuedAt (datetime), expiresAt (datetime), signature (string, HMAC-SHA256), usageCount (integer, default 0), lastUsedAt (datetime nullable), revoked (boolean, default false). Add to `lib/Settings/mydash_register.json`.

- [ ] Task 10: Define OpenRegister schema `PasswordGate` with properties: publicationId (ref), passwordHash (string, bcrypt), hint (string nullable), attemptsLockoutThreshold (integer, default 5), lockoutDurationSeconds (integer, default 300). Add to `lib/Settings/mydash_register.json`.

- [ ] Task 11: Define OpenRegister schema `PublicationServiceAccount` with properties: tenantId (ref), accountId (string, identifier), allowedRegisters (array of register names), allowedSchemas (array of schema names), rowLevelFilter (JSON nullable), createdAt (datetime), rotatedAt (datetime). Add to `lib/Settings/mydash_register.json`.

- [ ] Task 12: Define OpenRegister schema `PublicationViewLog` with properties: publicationId (ref), day (date, ISO format), hourBucket (integer 0-23, UTC), viewCount (integer), uniqueSessionCount (integer), topCountry (ISO 3166-1 alpha-2 nullable), topReferrer (string nullable). Add to `lib/Settings/mydash_register.json` with `@self` envelope. Include composite index `(publicationId, day, hourBucket)` for efficient queries.

## Seed Data

- [ ] Task 13: Add 3 seed publications to `lib/Settings/mydash_register.json` under `components.objects[]` with `@self` envelope:
  1. Public gemeente dashboard (slug: `wonen-zeist`, mode: public, robotsPolicy: index_follow, cacheControlMaxAge: 3600)
  2. Signed-URL press briefing (slug: `press-may-2024`, mode: signed, robotsPolicy: noindex_nofollow, grants: array with 1 example SignedUrlGrant expiring 7 days ahead)
  3. Password-gated consultation results (slug: `inspraak-2024`, mode: password, passwordHash: bcrypt(`demo`), robotsPolicy: noindex_follow)

## Controllers

- [ ] Task 14: Create `lib/Controller/PublicationController.php` with endpoint `GET /api/publication/{slug}` (anon-accessible, no auth required). Parse `?grant=&expires=&sig=` query params. Call PublicationAuthService to validate access. If allowed, call PublicationQueryService to execute dashboard queries. Call PublicationAnalyticsService to record view. Return JSON response with dashboard definition, branding config, and widget data. Return 403 if access denied, 404 if publication not found.

- [ ] Task 15: Create `lib/Controller/PublicationSettingsController.php` with endpoints:
  - `GET /api/publication` (auth-required, admin) → list all publications for this tenant
  - `POST /api/publication` (auth-required, admin) → create new publication
  - `GET /api/publication/{publicationId}` (auth-required, admin) → fetch publication config
  - `PUT /api/publication/{publicationId}` (auth-required, admin) → update publication (branding, access config)
  - `POST /api/publication/{publicationId}/publish` (auth-required, admin) → transition to published status
  - `POST /api/publication/{publicationId}/pause` (auth-required, admin) → transition to paused status
  - `POST /api/publication/{publicationId}/retract` (auth-required, admin) → transition to retracted status
  - `GET /api/publication/{publicationId}/analytics` (auth-required, admin) → fetch PublicationViewLog entries for date range
  - Signed mode only:
    - `POST /api/publication/{publicationId}/grants` (auth-required, admin) → issue a new SignedUrlGrant; return the full signed URL
    - `POST /api/publication/{publicationId}/grants/{grantId}/revoke` (auth-required, admin) → revoke a grant
    - `GET /api/publication/{publicationId}/grants` (auth-required, admin) → list all grants for this publication
  - Password mode only:
    - `POST /api/publication/{publicationId}/password` (auth-required, admin) → set/update password
    - `GET /api/publication/{publicationId}/password/hint` (anon-accessible, after N failed attempts) → reveal hint text

## Frontend Routes

- [ ] Task 16: Add Vue route `/publication/{slug}` in `src/router/index.js`. Create component `src/views/PublicationView.vue` rendering the public dashboard view (no auth required). Fetch publication metadata and widget data from `GET /api/publication/{slug}`. Display PublicationBranding (logo, colours, footerHtml, language). If publication is password-gated, show login form before dashboard. If signed-mode, check query params for grant/expires/sig and validate. Apply robotsPolicy meta tags, Cache-Control headers (via response headers from server).

- [ ] Task 17: Add route `/admin/publication` in `src/router/index.js`. Create component `src/views/PublicationSettingsPage.vue` (auth-required, admin only). Include sub-routes:
  - List view: CnDataTable listing all publications with columns (slug, mode, status, published-at, actions)
  - Detail view: form to edit publication settings (mode, slug, branding config, access config)
  - Analytics view: charts showing viewCount, uniqueSessionCount, topCountries, topReferrers by day/hour
  - Grants view (signed-mode only): list active/revoked grants, issue new, revoke existing
  - Password view (password-mode only): update password hash, set hint, configure lockout

## Frontend Services

- [ ] Task 18: Create `src/services/publicationService.js` with functions:
  - `fetchPublication(slug, grant?, expiresAt?, sig?)` → call GET /api/publication/{slug} with optional grant params
  - `listPublications()` → call GET /api/publication (admin)
  - `createPublication(dashboardId, mode, slug, branding)` → call POST /api/publication
  - `updatePublication(publicationId, updates)` → call PUT /api/publication/{publicationId}
  - `publishPublication(publicationId)` → call POST /api/publication/{publicationId}/publish
  - `retractPublication(publicationId, reason)` → call POST /api/publication/{publicationId}/retract
  - `fetchAnalytics(publicationId, dateStart, dateEnd)` → call GET /api/publication/{publicationId}/analytics
  - `issueSignedGrant(publicationId, issuedTo, expiresAt)` → call POST /api/publication/{publicationId}/grants
  - `revokeSignedGrant(publicationId, grantId)` → call POST /api/publication/{publicationId}/grants/{grantId}/revoke
  - `submitPassword(publicationId, password)` → call POST /api/publication/{publicationId}/password-check

## Frontend Components

- [ ] Task 19: Create `src/components/PublicationPasswordForm.vue` component. Renders a minimal HTML form (no MyDash chrome) with a password input, submit button, and error messages. Calls publicationService.submitPassword(). On success, sets a session cookie and reloads the dashboard. On failure, increments attempt counter and shows hint after N attempts. Displays lockout message if lockoutRemaining > 0.

- [ ] Task 20: Create `src/components/PublicationBrandingForm.vue` component (admin form). Includes fields:
  - File picker for organisationLogo (calls FileService upload)
  - File picker for favicon
  - Colour pickers for primaryColour, secondaryColour
  - Font-family dropdown
  - Textarea for footerHtml (with HTML sanitisation preview)
  - URL inputs for accessibilityStatementUrl, privacyStatementUrl
  - Email input for contactEmail
  - Language select (nl/en/fr/de)
  - Submit button calls publicationService.updatePublication()

## Routes and Redirects

- [ ] Task 21: Register routes in `appinfo/routes.php`:
  - `GET /api/publication` → PublicationSettingsController::list
  - `POST /api/publication` → PublicationSettingsController::create
  - `GET /api/publication/{publicationId}` → PublicationSettingsController::show
  - `PUT /api/publication/{publicationId}` → PublicationSettingsController::update
  - `POST /api/publication/{publicationId}/publish` → PublicationSettingsController::publish
  - `POST /api/publication/{publicationId}/pause` → PublicationSettingsController::pause
  - `POST /api/publication/{publicationId}/retract` → PublicationSettingsController::retract
  - `GET /api/publication/{publicationId}/analytics` → PublicationSettingsController::analytics
  - `POST /api/publication/{publicationId}/grants` → PublicationSettingsController::issueGrant
  - `POST /api/publication/{publicationId}/grants/{grantId}/revoke` → PublicationSettingsController::revokeGrant
  - `GET /api/publication/{publicationId}/grants` → PublicationSettingsController::listGrants
  - `POST /api/publication/{publicationId}/password` → PublicationSettingsController::setPassword
  - `GET /api/publication/{publicationId}/password/hint` → PublicationSettingsController::passwordHint (rate-limited)
  - `GET /publication/{slug}` → PublicationController::view (anon-accessible, no auth)

## Testing

- [ ] Task 22: PHPUnit tests for PublicationService (`tests/Unit/Service/PublicationServiceTest.php`): create publication, validate slug uniqueness, update branding, list publications, transition status (draft → published → paused → retracted). Verify OpenRegister objects are created/updated correctly.

- [ ] Task 23: PHPUnit tests for PublicationAuthService (`tests/Unit/Service/PublicationAuthServiceTest.php`): public-mode access always allowed, signed-mode validates signature (rejects tampered/expired), password-mode validates hash (constant-time), lockout enforces threshold + duration, revoked grants are denied.

- [ ] Task 24: PHPUnit tests for PublicationQueryService (`tests/Unit/Service/PublicationQueryServiceTest.php`): executes queries as service account, rejects queries targeting disallowed registers, returns correct result set.

- [ ] Task 25: PHPUnit tests for PublicationAnalyticsService (`tests/Unit/Service/PublicationAnalyticsServiceTest.php`): records view to PublicationViewLog hourly bucket, derives session hash (deterministic, not backreferable), aggregates counts correctly, does not log PII (no IP, no User-Agent).

- [ ] Task 26: Playwright E2E test: Create a public-mode publication, access as anon user, verify dashboard renders with branding (logo, colours, footer), verify no MyDash chrome, verify Cache-Control header is present, verify view-log is incremented.

- [ ] Task 27: Playwright E2E test: Create signed-mode publication, issue a grant, construct signed URL, access as anon user, verify dashboard renders, verify grant usageCount incremented, revoke grant, verify subsequent access is 403.

- [ ] Task 28: Playwright E2E test: Create password-mode publication with password `test123`, attempt incorrect passwords 5 times, verify lockout message appears, wait 300 seconds (or mock time), verify next attempt is allowed. Verify hint text appears after N attempts.

- [ ] Task 29: Playwright E2E test: Owner publishes a dashboard, navigates to analytics page, views hourly/daily counts, top countries, top referrers. Verify no individual viewer data leaks.

- [ ] Task 30: Security test: verify service account isolation (viewer accessing a published dashboard cannot elevate to query protected registers outside the service account ACL).

## Quality Checks

- [ ] Task 31: ESLint clean on all touched JS/Vue files (no linter errors or warnings). Run `npm run lint`.

- [ ] Task 32: PHPStan/Psalm static analysis clean. Run `composer check:strict` and `vendor/bin/psalm` with level 5. Ensure PublicationService, PublicationAuthService, PublicationQueryService, PublicationAnalyticsService are fully typed.

- [ ] Task 33: OpenAPI documentation: add `/api/publication` and `/api/publication/{publicationId}` endpoints to `openapi.yaml` with request/response schemas, status codes, authentication requirements.

- [ ] Task 34: i18n — add translation keys for publication UI strings:
  - `publication.title`, `publication.slug`, `publication.mode`, `publication.status`
  - `publication.mode.public`, `publication.mode.signed`, `publication.mode.password`
  - `publication.status.draft`, `publication.status.published`, `publication.status.paused`, `publication.status.retracted`
  - `publication.branding.logo`, `publication.branding.colors`, `publication.branding.footer`
  - `publication.access.robotsPolicy`, `publication.access.cacheControl`
  - `publication.analytics.views`, `publication.analytics.uniqueSessions`, `publication.analytics.topCountries`
  - `publication.password.submit`, `publication.password.hint`, `publication.password.lockout`
  - Add Dutch (`nl`) and English (`en`) translations to `src/translations.json` (or equivalent i18n file).

- [ ] Task 35: Deduplication check — search codebase for existing publication-related code or patterns:
  - Search `lib/Service/` for any existing PublicationService
  - Search `src/` for any existing publication routes or components
  - Confirm no overlap with `dashboard-sharing` capability (separate ACL model, service account isolation)
  - Document findings in a comment in PublicationService.php linking to any related patterns (e.g., use of existing QueryService for service account execution).

## Verification

- [ ] Task 36: Run `openspec validate` to verify all requirements in spec.md are testable and all test tasks (22–29) produce passing results. Confirm seed data loads correctly via `composer run repair`.

## Documentation

- [ ] Task 37: Add CHANGELOG.md entry: "feat: Add public dashboard publication capability for anonymous access with mode selection (public, signed, password), custom branding, SEO directives, and PII-free analytics."

- [ ] Task 38: Developer docs: create `docs/publication.md` documenting:
  - Architecture overview (PublicationService, PublicationAuthService, PublicationQueryService, PublicationAnalyticsService)
  - Data model (seven OpenRegister schemas)
  - Configuration (how to set up a publication service account, configure allowed registers/schemas)
  - API reference (all endpoints with curl examples)
  - Owner guide (UI walkthrough: publish, configure branding, view analytics, manage grants)
  - Security guide (robots.txt control, password best practices, why service account isolation matters)
  - Troubleshooting (common errors, debug logs)

## Migration / Deployment

- [ ] Task 39: Create database repair step (if needed): verify OpenRegister schemas exist, create publication service account for tenant if not present, idempotently load seed publications.

- [ ] Task 40: Add feature flag (optional) for gradual rollout: `MYDASH_PUBLIC_PUBLICATIONS_ENABLED` (default: true). If false, hide publication settings UI and return 404 for publication routes. Allows safe rollback if issues arise post-deploy.
