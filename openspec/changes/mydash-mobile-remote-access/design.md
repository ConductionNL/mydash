---
status: draft
---

# Design: Mobile and Remote Dashboard Access

## Overview

This design extends MyDash to support remote and mobile access by:

1. **Authentication & Session Management** — Leverage Nextcloud's built-in session handling with configurable timeout for security
2. **Mobile-Responsive UI** — NL Design System-based responsive layout for 320px–1920px viewports
3. **Role-Based Application Filtering** — Admin-configurable mappings between user roles and visible applications
4. **News & Content Curation** — Role-based visibility for news items and organizational information

All configuration lives in OpenRegister with policy enforcement in the frontend and backend.

## Data Model

### 1. Dashboard Configuration (Extends MyDash)

**Entity: DashboardConfig**
- **Type:** AppConfiguration (stored in `IAppConfig`, not OpenRegister)
- **Purpose:** App-wide settings for mobile/remote access behavior

```yaml
DashboardConfig:
  properties:
    sessionTimeoutSeconds:
      type: integer
      minimum: 300
      maximum: 86400
      default: 900
      description: "Session idle timeout in seconds (5 min – 24 hrs; default 15 min)"
    
    mobileUiEnabled:
      type: boolean
      default: true
      description: "Enable responsive mobile UI (always true; kept for forward compatibility)"
    
    remoteAccessEnabled:
      type: boolean
      default: true
      description: "Allow authentication from non-corporate networks"
    
    requireMfaForRemote:
      type: boolean
      default: false
      description: "Require multi-factor authentication for remote sessions"
    
    newsRefreshIntervalSeconds:
      type: integer
      default: 3600
      description: "How often to refresh news feed (default 1 hour)"

  example:
    sessionTimeoutSeconds: 900
    mobileUiEnabled: true
    remoteAccessEnabled: true
    requireMfaForRemote: false
    newsRefreshIntervalSeconds: 3600
```

### 2. Application Visibility Rules (OpenRegister)

**Register:** `mydash_applications` | **Schema:** `ApplicationVisibilityRule`

```json
{
  "@self": {
    "register": "mydash_applications",
    "schema": "ApplicationVisibilityRule",
    "slug": "visibility-rule-001"
  },
  "name": "string — human-readable name of the rule",
  "applicationId": "string — reference to external app identifier (e.g., 'procest', 'pipelinq')",
  "applicationName": "string — display name of the application",
  "applicationIcon": "string — URL or MDI icon identifier",
  "applicationUrl": "string — deep link to the application",
  "visibleToRoles": ["array of string — Nextcloud group names"]",
  "visibleToUsers": ["array of string — Nextcloud user IDs (optional, for individual overrides)"],
  "description": "string — why this app matters for this role",
  "sortOrder": "integer — display order (default 0)",
  "isActive": "boolean — whether this rule is currently enforced",
  "createdAt": "datetime — ISO 8601 timestamp",
  "updatedAt": "datetime — ISO 8601 timestamp"
}
```

### 3. News & Content Feed (OpenRegister)

**Register:** `mydash_news` | **Schema:** `NewsItem`

```json
{
  "@self": {
    "register": "mydash_news",
    "schema": "NewsItem",
    "slug": "news-item-001"
  },
  "title": "string — headline (max 100 chars)",
  "summary": "string — brief description (max 500 chars)",
  "content": "string — full HTML content",
  "category": "string — organization category ('executive', 'it', 'hr', 'security')",
  "visibleToRoles": ["array of string — Nextcloud group names"],
  "visibleToUsers": ["array of string (optional) — individual user IDs"],
  "priority": "integer — display order (higher = more prominent, default 0)",
  "publishedDate": "datetime — when published",
  "expiryDate": "datetime nullable — when to stop showing",
  "sourceUrl": "string nullable — original source URL",
  "authorUserId": "string — Nextcloud user ID of content creator",
  "isActive": "boolean — whether this item is visible",
  "createdAt": "datetime",
  "updatedAt": "datetime"
}
```

### 4. Session Lifecycle (Backend-Only Configuration)

Sessions are managed entirely by Nextcloud's core with timeout via:

- `IAppConfig['mydash']['sessionTimeoutSeconds']` — idle timeout enforced in middleware
- JavaScript listener for idle detection (mouse/keyboard activity)
- Automatic redirect to login after timeout
- Audit trail entry per session lifecycle event

## Reuse Analysis

| Capability | OpenRegister Service | Usage |
|---|---|---|
| Application/news storage and versioning | `ObjectService` + `RegisterService` | Store and query application visibility rules and news items |
| Role-based content filtering | `AuthorizationService` (RBAC via `relations.group`) | Filter applications and news by `visibleToRoles` at query time |
| Audit trail for policy changes | `AuditTrailService` | Immutable log of admin changes to visibility rules and news |
| Admin configuration UI | `CnRegisterMapping` + `CnIndexPage` | No custom admin UI — use OR's register management pages |
| Object lifecycle (publish/unpublish news) | `LifecycleService` + status transitions | News items can transition between `draft`, `published`, `archived` states |
| Frontend data binding | `createObjectStore` (Pinia store) | Sync application/news data with reactive store updates |

**No duplication:** All capabilities leverage OR's existing abstractions. No app-local RBAC, no custom audit logging, no bespoke object storage.

## Mobile-Responsive UI

All components follow NL Design System tokens and responsive patterns:

### Viewport Coverage
- **320px** (mobile portrait): Touch-optimized, single-column layout
- **768px** (tablet): Two-column dashboard with sidebar collapsible
- **1024px+** (desktop): Full multi-column layout with optional sidebar

### Key Components

#### App Launcher (Mobile)
- Icon grid with application cards
- Touch-sized tap targets (min 44×44px per WCAG)
- Filtered by user's role-based visibility rules
- Quick-access favorites (user preference, stored in profile)

#### News Feed (Mobile)
- Vertical scrolling list with image thumbnails
- Prioritized by role and published date
- "Read more" inline expansion (no modal, per ADR-010)
- Mark-as-read feature (frontend-only, no backend state)

#### Session Indicator (Mobile + Desktop)
- Top-right corner: displays remaining session time
- Warning badge at 2-minute mark before timeout
- "Extend session" button to prevent forced logout
- Auto-refresh on user activity

### Component Reuse from @conduction/nextcloud-vue

- `CnIndexPage` — List applications/news items
- `CnDetailPage` — View single application/news item details
- `CnDataTable` + `CnFacetSidebar` — Filter news by category/role
- `CnEmptyState` — No applications/news visible to this user
- `CnCard` — News item cards with responsive layout
- `CnStatusBadge` — Session status indicator

No custom modal or form components — all forms use `CnFormDialog` (schema-driven).

## Seed Data

### ApplicationVisibilityRule Seed Data

```json
[
  {
    "@self": {
      "register": "mydash_applications",
      "schema": "ApplicationVisibilityRule",
      "slug": "procest-for-case-workers"
    },
    "name": "Procest for Case Workers",
    "applicationId": "procest",
    "applicationName": "Procest",
    "applicationIcon": "mdi-folder-open",
    "applicationUrl": "https://nextcloud.example.nl/apps/procest/",
    "visibleToRoles": ["case-workers", "supervisors", "admin"],
    "description": "Case management and workflow tracking",
    "sortOrder": 1,
    "isActive": true,
    "createdAt": "2026-05-21T10:00:00Z",
    "updatedAt": "2026-05-21T10:00:00Z"
  },
  {
    "@self": {
      "register": "mydash_applications",
      "schema": "ApplicationVisibilityRule",
      "slug": "pipelinq-for-managers"
    },
    "name": "Pipelinq for Managers",
    "applicationId": "pipelinq",
    "applicationName": "Pipelinq",
    "applicationIcon": "mdi-chart-line",
    "applicationUrl": "https://nextcloud.example.nl/apps/pipelinq/",
    "visibleToRoles": ["managers", "admin"],
    "description": "Pipeline and project tracking for management oversight",
    "sortOrder": 2,
    "isActive": true,
    "createdAt": "2026-05-21T10:00:00Z",
    "updatedAt": "2026-05-21T10:00:00Z"
  },
  {
    "@self": {
      "register": "mydash_applications",
      "schema": "ApplicationVisibilityRule",
      "slug": "decidesk-for-governance"
    },
    "name": "Decidesk for Governance",
    "applicationId": "decidesk",
    "applicationName": "Decidesk",
    "applicationIcon": "mdi-gavel",
    "applicationUrl": "https://nextcloud.example.nl/apps/decidesk/",
    "visibleToRoles": ["board-members", "chairs", "admin"],
    "description": "Governance, decisions, and meeting minutes",
    "sortOrder": 3,
    "isActive": true,
    "createdAt": "2026-05-21T10:00:00Z",
    "updatedAt": "2026-05-21T10:00:00Z"
  },
  {
    "@self": {
      "register": "mydash_applications",
      "schema": "ApplicationVisibilityRule",
      "slug": "openregister-for-all"
    },
    "name": "OpenRegister for All Users",
    "applicationId": "openregister",
    "applicationName": "OpenRegister",
    "applicationIcon": "mdi-database",
    "applicationUrl": "https://nextcloud.example.nl/apps/openregister/",
    "visibleToRoles": ["admin"],
    "description": "Data management and register administration",
    "sortOrder": 4,
    "isActive": true,
    "createdAt": "2026-05-21T10:00:00Z",
    "updatedAt": "2026-05-21T10:00:00Z"
  },
  {
    "@self": {
      "register": "mydash_applications",
      "schema": "ApplicationVisibilityRule",
      "slug": "files-for-document-workers"
    },
    "name": "Files for Document Management",
    "applicationId": "files",
    "applicationName": "Files",
    "applicationIcon": "mdi-folder",
    "applicationUrl": "https://nextcloud.example.nl/apps/files/",
    "visibleToRoles": ["case-workers", "managers", "admin"],
    "description": "Central file repository for case documents and attachments",
    "sortOrder": 5,
    "isActive": true,
    "createdAt": "2026-05-21T10:00:00Z",
    "updatedAt": "2026-05-21T10:00:00Z"
  }
]
```

### NewsItem Seed Data

```json
[
  {
    "@self": {
      "register": "mydash_news",
      "schema": "NewsItem",
      "slug": "security-update-may2026"
    },
    "title": "Security update: Enable two-factor authentication",
    "summary": "Organization-wide security notification",
    "content": "<p>All users should enable two-factor authentication in their Nextcloud account. Visit Settings → Security to configure your authenticator app.</p>",
    "category": "security",
    "visibleToRoles": ["admin"],
    "priority": 10,
    "publishedDate": "2026-05-21T09:00:00Z",
    "expiryDate": "2026-06-04T23:59:59Z",
    "authorUserId": "admin",
    "isActive": true,
    "createdAt": "2026-05-21T09:00:00Z",
    "updatedAt": "2026-05-21T09:00:00Z"
  },
  {
    "@self": {
      "register": "mydash_news",
      "schema": "NewsItem",
      "slug": "system-maintenance-may28"
    },
    "title": "Planned maintenance: May 28, 02:00–04:00 CEST",
    "summary": "Nextcloud system will be offline during this window",
    "content": "<p>We will perform scheduled maintenance on May 28 from 02:00 to 04:00 CEST. All services will be temporarily unavailable. No data will be lost.</p>",
    "category": "it",
    "visibleToRoles": ["case-workers", "managers", "admin"],
    "priority": 8,
    "publishedDate": "2026-05-21T08:00:00Z",
    "expiryDate": "2026-05-28T23:59:59Z",
    "authorUserId": "admin",
    "isActive": true,
    "createdAt": "2026-05-21T08:00:00Z",
    "updatedAt": "2026-05-21T08:00:00Z"
  },
  {
    "@self": {
      "register": "mydash_news",
      "schema": "NewsItem",
      "slug": "hr-benefits-enrollment"
    },
    "title": "Benefits enrollment open through June 15",
    "summary": "Human Resources announces annual benefits enrollment period",
    "content": "<p>Open enrollment for health insurance, retirement plans, and wellness programs is now open. All employees must complete enrollment by June 15. Questions? Contact HR@gemeente.nl.</p>",
    "category": "hr",
    "visibleToRoles": ["case-workers", "managers", "board-members", "admin"],
    "priority": 5,
    "publishedDate": "2026-05-20T10:00:00Z",
    "expiryDate": "2026-06-15T23:59:59Z",
    "authorUserId": "admin",
    "isActive": true,
    "createdAt": "2026-05-20T10:00:00Z",
    "updatedAt": "2026-05-20T10:00:00Z"
  },
  {
    "@self": {
      "register": "mydash_news",
      "schema": "NewsItem",
      "slug": "new-procest-feature"
    },
    "title": "Procest: Bulk case assignment now available",
    "summary": "Procest releases new bulk operations feature",
    "content": "<p>Procest now supports assigning multiple cases to team members in one action. This speeds up case distribution by up to 80%. See the Procest documentation for usage examples.</p>",
    "category": "executive",
    "visibleToRoles": ["case-workers", "managers", "admin"],
    "priority": 3,
    "publishedDate": "2026-05-19T14:30:00Z",
    "expiryDate": null,
    "sourceUrl": "https://procest.example.nl/blog/bulk-assignment",
    "authorUserId": "admin",
    "isActive": true,
    "createdAt": "2026-05-19T14:30:00Z",
    "updatedAt": "2026-05-19T14:30:00Z"
  }
]
```

## Implementation Architecture

### Backend (PHP)

**Services (minimal — leverages OpenRegister):**
- `SessionTimeoutService` — Enforces idle timeout per ADR-003
- `ApplicationVisibilityService` — Queries `mydash_applications` register filtered by user roles
- `NewsVisibilityService` — Queries `mydash_news` register filtered by user roles
- Both delegate to `ObjectService` for storage and RBAC enforcement

**No custom models** — all data lives in OpenRegister registers. Controllers and services are thin adapters calling `ObjectService`.

### Frontend (Vue 2 + Pinia)

**Store:**
- `useApplicationStore` — Pinia store wrapping `createObjectStore('applications', 'mydash_applications', 'ApplicationVisibilityRule')`
- `useNewsStore` — Pinia store wrapping `createObjectStore('news', 'mydash_news', 'NewsItem')`
- `useSessionStore` — Session state: `{ isActive, secondsRemaining, lastActivityTime }`

**Pages:**
- `Dashboard.vue` — Main dashboard (router: `/`)
  - `CnIndexPage` showing applications grid
  - News feed sidebar via `CnDataTable`
  - Session indicator in top-right
  - Idle detection via mousemove/keypress listeners

- `ApplicationDetail.vue` — Single application view (router: `/applications/:id`)
  - `CnDetailPage` with application metadata
  - Deep link to external application via button

- `NewsDetail.vue` — Single news item view (router: `/news/:id`)
  - Full article render
  - "Back to feed" navigation

**Admin Pages:**
- `ApplicationAdmin.vue` — Manage application visibility rules
  - `CnIndexPage` + `CnFormDialog` (schema-driven)
  - Create/edit/delete visibility rules
  - Test visibility for specific users/roles

- `NewsAdmin.vue` — Manage news items
  - `CnIndexPage` + `CnFormDialog`
  - Publish/unpublish/schedule news
  - Preview visibility

### Session Timeout Implementation

**Backend:**
- Middleware checks `Nextcloud\OCP\ISession::getLastActivity()`
- If `time() - lastActivity > sessionTimeoutSeconds`, invalidate session
- Return 401 with redirect to login

**Frontend:**
- JavaScript IdleTimer (debounced, 1-second granularity)
- On idle timeout approaching (2 min remaining), show `NcDialog` with "Extend session?" button
- "Extend session" button POSTs to `POST /api/session/extend` (no-op — just refreshes last activity)
- Auto-logout on final timeout, redirect to login page

## Compliance & Security

- **Authentication:** Nextcloud built-in only (ADR-005)
- **Authorization:** Per-role visibility filtering (ADR-023 — action RBAC not applicable here; all viewing)
- **Audit:** OpenRegister's `AuditTrailService` tracks all policy changes
- **GDPR:** News items respect `visibleToUsers` for individual opt-in/opt-out
- **i18n:** All UI strings via `t()` in Vue, backend via `$this->l10n->t()` (ADR-007)
- **NL Design System:** All colors/spacing via CSS custom properties (ADR-010)
- **Responsive:** 320px–1920px coverage, critical features work at 768px (ADR-010)

## Deferred (Out of Scope)

- Machine learning-based news recommendations (future: ML service integration)
- Calendar synchronization with MyDash events (future: integrations registry)
- Deep personalization of app order per user (future: user preference persistence)
- Offline support / service worker (future: PWA enhancement)
