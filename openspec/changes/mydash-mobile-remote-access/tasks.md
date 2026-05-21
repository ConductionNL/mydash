---
status: todo
---

# Tasks: Mobile and Remote Dashboard Access

## Session Management

- [ ] **task-1:** Create `SessionTimeoutService` in `lib/Service/SessionTimeoutService.php`
  - Implement `getSessionTimeoutSeconds(): int` — reads from `IAppConfig['mydash']['sessionTimeoutSeconds']`
  - Implement `validateSessionActive(ISession $session): bool` — checks `time() - lastActivity <= timeout`
  - Implement `invalidateSession(ISession $session): void` — clears session and logs audit event
  - Add unit tests in `tests/Unit/Service/SessionTimeoutServiceTest.php`
  - **Spec ref:** REQ-REMOTE-002, REQ-AUTH-001

- [ ] **task-2:** Create session timeout middleware in `lib/Middleware/SessionTimeoutMiddleware.php`
  - Inject `SessionTimeoutService`, `ISession`, `ILogger`
  - Check session validity on every request before controller dispatch
  - Return HTTP 401 with redirect to login if session expired
  - Log timeout events to audit trail via `AuditTrailService`
  - Register middleware in `lib/AppInfo/Application.php` (DI binding)
  - **Spec ref:** REQ-REMOTE-002, REQ-AUTH-001

- [ ] **task-3:** Create admin settings for session timeout configuration
  - Create `lib/Settings/DashboardAdmin.php` (implements `\OCP\Settings\ISettings`)
  - Template: `templates/admin/dashboard.html.tpl` with form:
    - Session Timeout (range: 300–86400 seconds)
    - Remote Access Enabled (toggle)
    - MFA Required for Remote (toggle, deferred to future)
  - Controller method `POST /api/admin/settings` to save via `IAppConfig`
  - Add validation: timeout >= 300 and <= 86400
  - Register settings section in `Application::register()`
  - **Spec ref:** REQ-REMOTE-002

- [ ] **task-4:** Implement session extend endpoint
  - Create `SessionController::extend()` method
  - Route: `POST /api/session/extend`
  - Action: Touch the current session (set last activity = now)
  - Return: HTTP 200 with remaining session time (in seconds)
  - Annotation: `#[NoAdminRequired]` (authenticated users only)
  - **Spec ref:** REQ-REMOTE-002

## Mobile UI Responsive Layout

- [ ] **task-5:** Create responsive base layout component `src/components/Dashboard.vue`
  - Use `NcContent` → `NcAppContent` → `router-view` pattern (ADR-004)
  - Include session timeout indicator in top-right (status badge + time remaining)
  - Responsive grid layout:
    - 320px: single-column, stacked blocks
    - 768px: two-column with collapsible sidebar
    - 1024px+: full multi-column layout
  - Use CSS Grid with NL Design System tokens (ADR-010)
  - No hardcoded colors — use `var(--color-*)` tokens
  - **Spec ref:** REQ-MOBILE-001

- [ ] **task-6:** Create idle activity timer in `src/composables/useIdleTimer.ts`
  - Composable that monitors: mousemove, keypress, click, scroll, touch events
  - Debounced (1-second granularity) to avoid excessive re-renders
  - Export: `{ isIdle, secondsRemaining, extendSession }`
  - Call backend `POST /api/session/extend` on manual extend
  - Emit warning when `secondsRemaining <= 120` (2-minute threshold)
  - Auto-logout when `secondsRemaining <= 0`
  - **Spec ref:** REQ-INTEGRATE-001

- [ ] **task-7:** Create session timeout warning dialog in `src/components/SessionTimeoutDialog.vue`
  - Displays when `secondsRemaining <= 120`
  - Modal (not dismissible by clicking outside)
  - Shows: remaining time, "Extend Session" button (primary), "Log Out" button (secondary)
  - "Extend Session" calls backend to refresh session
  - "Log Out" clears token and redirects to login
  - Auto-dismisses if user extends
  - Announce to screen readers via `aria-live="polite"`
  - **Spec ref:** REQ-INTEGRATE-001, REQ-A11Y-001

- [ ] **task-8:** Ensure touch target sizes are >= 44×44px
  - Audit all interactive elements (buttons, links, form inputs)
  - Apply CSS: `min-height: 44px; min-width: 44px; padding: 8px 12px;`
  - Use `@media (max-width: 768px)` for mobile-specific sizes
  - Test on real mobile devices and Chrome DevTools mobile emulator
  - **Spec ref:** REQ-MOBILE-001, REQ-MOBILE-002

- [ ] **task-9:** Implement pull-to-refresh for news feed
  - Use library: `@nextcloud/vue` or custom implementation via `touchmove` listener
  - Trigger refresh when user swipes down from top of list
  - Show spinner during fetch
  - Append new items to the top of the list
  - **Spec ref:** REQ-MOBILE-002

## Application Visibility System

- [ ] **task-10:** Create `ApplicationVisibilityService` in `lib/Service/ApplicationVisibilityService.php`
  - Inject `ObjectService`, `IGroupManager`, `IUserSession`
  - Implement `getVisibleApplications(IUser $user): array`
    - Query `mydash_applications` register via `ObjectService`
    - Filter by user's roles (groups) using `visibleToRoles` field
    - Sort by `sortOrder` ascending
    - Return array of application records with visibility resolved
  - Implement `testVisibility(string $userId, string $groupId = null): array`
    - Admin-only method to test what a user/group would see
    - Return apps visible to that user/group
  - Add unit tests covering multi-role scenarios
  - **Spec ref:** REQ-APPS-001

- [ ] **task-11:** Create OpenRegister schema and register template for applications
  - Schema: `ApplicationVisibilityRule` in `lib/Settings/mydash_register.json`
    - Properties: name, applicationId, applicationName, applicationIcon, applicationUrl, visibleToRoles, visibleToUsers, description, sortOrder, isActive, createdAt, updatedAt
    - Required: name, applicationId, applicationName, visibleToRoles
    - Types: string, array, integer, boolean, datetime
  - Register: `mydash_applications` (x-openregister.type: "application")
  - Include 5 seed objects (procest, pipelinq, decidesk, openregister, files) with Dutch descriptions
  - Seed data import via `ConfigurationService::importFromApp()` on repair step
  - **Spec ref:** REQ-APPS-001, ADR-001

- [ ] **task-12:** Create applications API endpoint
  - Route: `GET /api/applications`
  - Action: Return paginated list of visible applications for authenticated user
  - Response shape: `{ "data": [...], "total": N, "page": 1, "pages": M }`
  - Supports query params: `_page`, `_limit`
  - Annotation: `#[NoAdminRequired]` (any authenticated user)
  - Filtering delegated to `ApplicationVisibilityService::getVisibleApplications()`
  - **Spec ref:** REQ-APPS-001

- [ ] **task-13:** Create applications admin management endpoints
  - `POST /api/applications` — Create new visibility rule
    - Request body: `{ name, applicationId, visibleToRoles, ... }`
    - Validation: applicationId must be unique
    - Calls `ObjectService::saveObject()` to store in register
    - Returns created object
  - `PUT /api/applications/{id}` — Update visibility rule
  - `DELETE /api/applications/{id}` — Soft-delete (set isActive: false)
  - `GET /api/applications/{id}` — Retrieve single rule
  - All admin endpoints: `#[AuthorizedAdminSetting(Application::APP_ID)]`
  - All mutations logged to audit trail
  - **Spec ref:** REQ-ADMIN-001

- [ ] **task-14:** Create Pinia store for applications in `src/store/modules/applicationsStore.ts`
  - Use `createObjectStore('applications', 'mydash_applications', 'ApplicationVisibilityRule')`
  - Plugins: `auditTrailsPlugin`, `relationsPlugin`
  - Computed: `visibleApps` (filtered for current user's roles)
  - Action: `loadApplications()`
  - Action: `addApplication(data)`
  - Action: `updateApplication(id, data)`
  - Action: `deleteApplication(id)`
  - Export store via `useApplicationStore()`
  - **Spec ref:** REQ-APPS-001

- [ ] **task-15:** Create applications list page `src/pages/ApplicationsList.vue`
  - Use `CnIndexPage` with `useListView` composable
  - Display applications in a responsive grid (icon + name + description)
  - Show "No applications" empty state when list is empty
  - Clicking an app opens a detail view or navigates to app URL
  - Search/filter bar (via `CnFilterBar`) to find apps by name
  - **Spec ref:** REQ-APPS-001, REQ-MOBILE-001

## News Management System

- [ ] **task-16:** Create `NewsVisibilityService` in `lib/Service/NewsVisibilityService.php`
  - Inject `ObjectService`, `IGroupManager`, `IUserSession`
  - Implement `getVisibleNews(IUser $user, int $limit = 10, int $offset = 0): array`
    - Query `mydash_news` register
    - Filter by `visibleToRoles` (user's groups) and `visibleToUsers` (individual inclusion)
    - Exclude items with `expiryDate` in the past
    - Exclude items where `isActive = false`
    - Sort by `priority` DESC, then `publishedDate` DESC
    - Return paginated results
  - Implement `testVisibility(string $userId, string $groupId = null): array`
    - Admin-only; test what a user/group would see
  - Add unit tests
  - **Spec ref:** REQ-NEWS-001

- [ ] **task-17:** Create OpenRegister schema and register template for news
  - Schema: `NewsItem` in `lib/Settings/mydash_register.json`
    - Properties: title, summary, content, category, visibleToRoles, visibleToUsers, priority, publishedDate, expiryDate, sourceUrl, authorUserId, isActive, createdAt, updatedAt
    - Required: title, summary, category, visibleToRoles, authorUserId
    - Types: string, array, integer, datetime, boolean, html
  - Register: `mydash_news` (x-openregister.type: "application")
  - Include 4 seed objects with realistic Dutch content (security, it, hr, executive categories)
  - **Spec ref:** REQ-NEWS-001, ADR-001

- [ ] **task-18:** Create news API endpoints
  - `GET /api/news` — List visible news (paginated, filtered by role/user)
    - Query params: `_page`, `_limit`, `category` (filter by category)
    - Returns: `{ "data": [...], "total": N, "page": P, "pages": M }`
  - `GET /api/news/{id}` — Retrieve single news item (with full content)
  - Annotation: `#[NoAdminRequired]` (any authenticated user)
  - Filtering delegated to `NewsVisibilityService`
  - **Spec ref:** REQ-NEWS-001

- [ ] **task-19:** Create news admin management endpoints
  - `POST /api/news` — Create new news item
    - Request body: `{ title, summary, content, category, visibleToRoles, publishedDate, expiryDate, ... }`
    - If `publishedDate` is future, schedule via background job (task-21)
    - Calls `ObjectService::saveObject()` to store
    - Audit log the creation
  - `PUT /api/news/{id}` — Update existing news
  - `DELETE /api/news/{id}` — Soft-delete (set isActive: false)
  - All admin endpoints: `#[AuthorizedAdminSetting]`
  - **Spec ref:** REQ-ADMIN-002

- [ ] **task-20:** Create Pinia store for news in `src/store/modules/newsStore.ts`
  - Use `createObjectStore('news', 'mydash_news', 'NewsItem')`
  - Plugins: `auditTrailsPlugin`, `relationsPlugin`
  - Computed: `visibleNews` (filtered by user's roles)
  - Action: `loadNews(page, limit, category)`
  - Action: `addNewsItem(data)`
  - Action: `updateNewsItem(id, data)`
  - Action: `deleteNewsItem(id)`
  - Mark-as-read via localStorage (frontend only, not persisted)
  - **Spec ref:** REQ-NEWS-001

- [ ] **task-21:** Create background job for scheduled news publishing
  - Create `lib/Cron/PublishScheduledNews.php` (extends `OCP\BackgroundJob\TimedJob`)
  - Run every 5 minutes
  - Find all news items where `publishedDate <= now()` AND `isActive = false`
  - Set `isActive = true` for those items
  - Log publish event to audit trail
  - **Spec ref:** REQ-ADMIN-002

- [ ] **task-22:** Create news list page `src/pages/NewsList.vue`
  - Use `CnDataTable` with pagination
  - Display: Title, Category badge, Published date, Expires date, Visibility (roles)
  - Search by title, filter by category
  - Click row → navigate to detail view
  - Admin can edit/delete from action column
  - **Spec ref:** REQ-NEWS-001

- [ ] **task-23:** Create news detail page `src/pages/NewsDetail.vue`
  - Use `CnDetailPage` layout
  - Display full HTML content of news item
  - Show metadata: author, published date, category, "Source" link (if available)
  - "Back to feed" button
  - Admin can edit/delete via header actions
  - **Spec ref:** REQ-NEWS-001

## Admin Settings Pages

- [ ] **task-24:** Create admin page for applications in `templates/admin/applications.html.tpl`
  - Display list of application visibility rules via AJAX (populated by frontend)
  - Table columns: App Name, Icon, URL, Visible to Roles, Sort Order, Active (toggle), Actions (edit/delete)
  - "Add Application" button → opens `CnFormDialog` (schema-driven)
  - Inline edit/delete actions
  - "Test Visibility" tool: select user/group → show what they would see
  - JavaScript entrypoint: `src/admin-applications.js`
  - **Spec ref:** REQ-ADMIN-001

- [ ] **task-25:** Create admin page for news in `templates/admin/news.html.tpl`
  - Display list of news items
  - Table columns: Title, Category, Visible to Roles, Published, Expires, Status, Actions
  - "New News Item" button → opens form dialog
  - Inline edit/delete actions with confirmation
  - JavaScript entrypoint: `src/admin-news.js`
  - **Spec ref:** REQ-ADMIN-002

- [ ] **task-26:** Create admin page for general settings in `templates/admin/settings.html.tpl`
  - Form fields:
    - Session Timeout (range slider: 300–86400 seconds, step 60)
    - Remote Access Enabled (toggle switch)
    - News Refresh Interval (select or slider: 300–7200 seconds)
  - "Save Settings" button → POST to backend
  - Success/error messages via `OCP\Notification` or in-page toast
  - JavaScript entrypoint: `src/admin-settings.js`
  - **Spec ref:** REQ-REMOTE-002

## Testing

- [ ] **task-27:** Create unit tests for session management
  - `tests/Unit/Service/SessionTimeoutServiceTest.php`
    - Test `getSessionTimeoutSeconds()` reads from config
    - Test `validateSessionActive()` correctly calculates timeout
    - Test `invalidateSession()` clears session and logs
  - `tests/Unit/Middleware/SessionTimeoutMiddlewareTest.php`
    - Test middleware passes through valid sessions
    - Test middleware returns 401 for expired sessions
  - Run: `composer test -- tests/Unit/` ✓ all pass

- [ ] **task-28:** Create unit tests for application visibility
  - `tests/Unit/Service/ApplicationVisibilityServiceTest.php`
    - Test `getVisibleApplications()` filters by user roles
    - Test multi-role union (user with 2 roles sees apps from both)
    - Test admin sees all applications
    - Test deduplication when same app appears in multiple roles
    - Test `testVisibility()` for admin diagnostics
  - Mock `ObjectService`, `IGroupManager`
  - Run: `composer test -- tests/Unit/` ✓ all pass

- [ ] **task-29:** Create unit tests for news visibility
  - `tests/Unit/Service/NewsVisibilityServiceTest.php`
    - Test `getVisibleNews()` filters by role
    - Test expiry date filtering (don't show expired)
    - Test `isActive: false` filtering
    - Test sort order (priority DESC, then date DESC)
    - Test pagination
  - Mock `ObjectService`, `IGroupManager`
  - Run: `composer test -- tests/Unit/` ✓ all pass

- [ ] **task-30:** Create integration tests for APIs
  - `tests/integration/controller/SessionControllerTest.php`
    - Test `GET /api/session` returns current session info
    - Test `POST /api/session/extend` refreshes session
    - Test unauthenticated requests return 401
  - `tests/integration/controller/ApplicationsControllerTest.php`
    - Test `GET /api/applications` returns visible apps for user
    - Test POST/PUT/DELETE require admin
    - Test visibility filtering works
  - `tests/integration/controller/NewsControllerTest.php`
    - Test `GET /api/news` returns visible items
    - Test admin CRUD endpoints
    - Test expiry filtering
  - Use Newman/Postman collection or PHP test client
  - Run: `composer test:integration` ✓ all pass

- [ ] **task-31:** Create frontend component tests
  - `tests/unit/Dashboard.spec.js` — Test responsive layout
  - `tests/unit/SessionTimeoutDialog.spec.js` — Test warning/extend/logout flow
  - `tests/unit/useIdleTimer.spec.js` — Test idle detection and callbacks
  - Run: `npm run test:unit` ✓ all pass

- [ ] **task-32:** Create browser/E2E tests (Playwright)
  - Scenario: Remote login from mobile device
    - Navigate to login URL from mobile emulator
    - Enter credentials and log in
    - Verify dashboard loads
    - Verify app list and news feed visible
  - Scenario: Session timeout warning
    - Log in
    - Wait for idle timeout warning (mock timer or real wait)
    - Click "Extend Session"
    - Verify dialog closes and session continues
  - Scenario: Session timeout logout
    - Log in
    - Do not interact
    - Wait for timeout
    - Verify redirected to login page
  - Run: `npm run test:e2e` ✓ all pass

## Documentation & Translations

- [ ] **task-33:** Create user documentation in `docs/remote-access.md`
  - How to log in from outside the office
  - Session timeout explanation and how to extend
  - Mobile app usage tips (touch targets, responsive layout)
  - FAQ: "I got logged out, why?" → idle timeout
  - Screenshots from running app
  - Sections in English and Dutch

- [ ] **task-34:** Create admin documentation in `docs/admin-guide.md`
  - How to configure session timeout
  - How to manage application visibility rules
  - How to publish and schedule news items
  - How to test visibility for users/groups
  - Best practices (e.g., don't set timeout too short)
  - Screenshots

- [ ] **task-35:** Add all user-facing strings to translation files
  - `l10n/en.json` — Create with all strings in English (keys == values)
  - `l10n/nl.json` — Create with Dutch translations
  - Strings include:
    - Button labels: "Extend Session", "Log Out", "Add Application", "Publish News"
    - Messages: "Your session is expiring soon", "Your session has expired"
    - Form labels: "Session Timeout", "Visible to Roles", "Publish Date"
    - Dialog titles and placeholders
  - Verify: `npm run lint:i18n` (if checker exists)
  - All user-visible strings via `t('mydash', 'text')` in Vue
  - Backend strings via `$this->l10n->t('text')`
  - **Spec ref:** REQ-I18N-001

## Quality & Deployment

- [ ] **task-36:** Run pre-commit quality checks
  - SPDX headers: `grep -rL 'SPDX-License-Identifier' lib/ src/ --include='*.php' --include='*.vue' --include='*.js'`
    - Add missing headers to all files
  - PHPCodeSniffer + PHPStan: `composer check:strict` ✓ no errors
  - Vue linting: `npm run lint` ✓ no errors
  - Type checking: `npm run typescript` ✓ if applicable
  - Hydra gates (if available): `./scripts/run-hydra-gates.sh --scope-to-diff` ✓ all green
  - **Spec ref:** ADR-014, ADR-015

- [ ] **task-37:** Verify database migrations
  - If new tables required: create migration in `lib/Migration/` (though most data lives in OpenRegister)
  - Register migration with `VersionFactory` in `Application::register()`
  - Test on fresh install: `./occ upgrade` succeeds
  - Test on upgrade: `./occ upgrade` from previous version succeeds
  - Verify seed data loads: Check `mydash_applications` and `mydash_news` registers have 5+4 objects

- [ ] **task-38:** Smoke test the running app
  - Start Nextcloud: `npm run serve` or `docker-compose up`
  - Log in as non-admin user
  - Verify dashboard loads
  - Verify see applications matching role
  - Verify see news items matching role
  - Log in as admin
  - Verify see all applications
  - Visit admin settings → configure session timeout
  - Verify session timeout warning appears
  - Verify "Extend Session" resets timer
  - Verify session logout works
  - On mobile device or browser mobile emulator:
    - Verify touch interactions work
    - Verify layout is responsive
    - Verify no horizontal scroll at 320px

- [ ] **task-39:** Create PR with all changes
  - All artifacts committed: proposal.md, design.md, specs.md, tasks.md (this file)
  - Code changes committed with SPDX headers and `@spec` tags linking to tasks
  - Commit message: `feat: Add OpenSpec change mydash-mobile-remote-access`
  - PR description includes: summary, testing done, screenshots (if UI changes)
  - No uncommitted files or debug code
  - CI/CD pipeline passes (tests, linting, gates)

## Deferred (Future Spec)

The following are OUT of scope for this change and deferred:

- [ ] Advanced mobile app (native iOS/Android) — Future: `openspec/changes/mydash-native-apps`
- [ ] Machine learning recommendation engine for apps/news — Future: ML integration spec
- [ ] Multi-factor authentication (MFA) for remote sessions — Future: `openspec/changes/mydash-mfa`
- [ ] Deep linking to external platforms — Future: OpenConnector integration
- [ ] Offline support and service workers — Future: PWA enhancement spec
- [ ] Calendar event integration on dashboard — Future: ADR-019 integration registry

These are documented in proposal.md "Deferred" section.
