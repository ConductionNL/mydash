# Public Dashboard Publication

MyDash dashboards are today identity-bound — every viewer is an authenticated user and every view is recorded against that user. This is correct for internal BI and for the existing `dashboard-sharing` capability which lets one user share with another identified user. It is wrong for open-overheid use cases where any citizen should be able to view a live dashboard without a login.

This change introduces a publication channel — distinct from sharing — that lets a dashboard owner mark a dashboard as publishable and choose from three publication modes (fully public, signed-URL with expiry, password-gated). The owner can apply a publication-specific brand chrome (gemeente logo, colours, footer), configure search-engine indexing and caching directives, and surface traffic metrics in aggregate form (no per-citizen tracking). All data queries on a published dashboard run as a designated "publication service account" so the viewer never inherits elevated permissions.

This change scopes deliberately to read-only viewing. Interactive filtering, comment threads, and authenticated personalisation belong to a future `interactive-public-dashboard` change.

## Affected code units

- `lib/Service/PublicationService.php` — manage DashboardPublication, PublicationBranding, PublicationAccess configurations
- `lib/Service/PublicationAuthService.php` — validate access against mode (public, signed, password)
- `lib/Service/PublicationQueryService.php` — execute dashboard queries as publication service account
- `lib/Service/PublicationAnalyticsService.php` — aggregate view counts per hour, no PII
- `lib/Controller/PublicationController.php` — `/api/publication/{slug}` read endpoint (anon-accessible)
- `lib/Controller/PublicationSettingsController.php` — owner config endpoints (auth-required)
- OpenRegister schemas: `DashboardPublication`, `PublicationBranding`, `PublicationAccess`, `SignedUrlGrant`, `PasswordGate`, `PublicationServiceAccount`, `PublicationViewLog`

## Why a new capability

Publication is a distinct use case with its own data model, access-control rules, query-execution context, and analytics. Folding it into `dashboard-sharing` would conflate two incompatible flows: identity-bound sharing with per-user ACLs, and anonymous publication with service-account queries.

## Approach

- **Configuration**: Owner marks a dashboard as publishable and configures mode, slug, branding, robots policy, cache headers via a publication-settings form.
- **Access validation**: Signed mode validates tamper-evident signature + expiry. Password mode validates hash against configured password. Public mode allows all.
- **Query execution**: All dashboard queries execute as a pre-configured publication service account with its own register/schema permissions.
- **Branding**: Publication-specific logo, colours, footer, language, and meta tags replace the internal MyDash chrome on the public-view route.
- **Indexing**: X-Robots-Tag and meta-robots directives honour the configured robotsPolicy so search engines follow the owner's intent.
- **Caching**: Cache-Control headers and ETag support enable CDN fronting at scale.
- **Analytics**: View counts recorded per hour bucket derived from salted session-cookie hashes — no backreferable identity tracking.

## Capabilities

**New Capabilities:**
- `public-dashboard-publication`: Owner-configured publication of read-only dashboards to anonymous viewers without authentication, with mode selection (public, signed, password), custom branding, SEO/cache control, and aggregate analytics.

## Standards

- WCAG 2.2 AA for gemeente public-facing pages
- Wet Digitale Overheid accessibility statement requirement
- AVG/GDPR aggregate-only analytics (no tracking cookies)
- Forum Standaardisatie DCAT-AP for open-data resource registration
- HTTP caching (RFC 9111) Cache-Control and conditional GET semantics

## Cross-app dependencies

- **mydash dashboard-sharing**: distinct surface; sharing is identity-bound, publication is anonymous
- **opencatalogi**: published dashboards can be registered as open-data resources
- **openregister**: publication service account permissions enforced at register/schema layer
- **openconnector**: optional CDN front provisioned via connector
- **nldesign**: branding defaults derived from active nldesign palette

## Target users

Gemeente communicatie-afdelingen, open-data coördinatoren, journalisten, raadsgriffies, MKB klanten sharing KPI-pages with partners, and citizens viewing published open-data dashboards.
