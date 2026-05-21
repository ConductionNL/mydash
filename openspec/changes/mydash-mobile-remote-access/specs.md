---
status: draft
---

# Specification: Mobile and Remote Dashboard Access

## REQ-REMOTE-001: Remote Authentication from Any Network

**Category:** Security | **Priority:** P0 | **Feature:** Remote Work and Mobile Access

### Scenario: Authenticate from external network
- GIVEN a remote employee with valid Nextcloud credentials
- WHEN they visit the MyDash URL from a non-corporate network (mobile hotspot, home wifi, coffee shop)
- THEN they can complete Nextcloud login via the standard authentication flow
- AND they are not blocked by network geolocation checks
- AND their session is established with standard Nextcloud tokens

### Scenario: Authenticate from blocked third-party device
- GIVEN device management policies block authentication from unregistered devices
- WHEN an employee logs in from an unknown device
- THEN they are prompted to register or authorize the device (if required by org policy)
- AND once authorized, access is granted
- AND the session is tracked for audit purposes

### Scenario: Nextcloud admin can validate remote access is enabled
- GIVEN a Nextcloud admin in Settings → MyDash Configuration
- WHEN they view the Remote Access settings
- THEN they see a toggle: "Allow remote access from external networks" (default: enabled)
- AND they can disable remote access to lock dashboard access to corporate networks only

## REQ-REMOTE-002: Session Idle Timeout

**Category:** Security | **Priority:** P0 | **Feature:** Remote Work and Mobile Access

### Scenario: Session automatically expires after idle period
- GIVEN a remote employee authenticated in MyDash
- WHEN they do not interact with the dashboard for 15 minutes (default timeout)
- THEN their session is automatically invalidated
- AND the next request returns HTTP 401 Unauthorized
- AND the user is redirected to the login page with a message: "Your session has expired. Please log in again."

### Scenario: Activity resets the idle timer
- GIVEN a session with 5 minutes remaining before timeout
- WHEN the user clicks, types, or moves the mouse
- THEN the idle timer resets to the full timeout duration (15 minutes)
- AND they are not logged out

### Scenario: Extend session before timeout
- GIVEN a session with 2 minutes remaining (warning threshold)
- WHEN the user sees the "Session expiring soon" dialog
- AND clicks "Extend Session"
- THEN the timer is reset to the full duration
- AND they can continue working without interruption

### Scenario: Idle timeout is configurable by admin
- GIVEN a Nextcloud admin in Settings → MyDash Configuration
- WHEN they view Session Timeout settings
- THEN they can set the timeout between 5 minutes (300 seconds) and 24 hours (86400 seconds)
- AND changes apply to all new sessions immediately
- AND existing sessions continue with their original timeout value

### Scenario: Session timeout enforced on both frontend and backend
- GIVEN a session that has exceeded the timeout window
- WHEN the frontend is not running (browser closed)
- THEN the backend session cache invalidates the token
- AND the next login attempt shows: "Session expired"
- AND this is logged in the audit trail as "session_timeout"

## REQ-MOBILE-001: Mobile-Responsive User Interface

**Category:** Design | **Priority:** P0 | **Feature:** Remote Work and Mobile Access

### Scenario: Dashboard renders on mobile viewport (320px)
- GIVEN a user accessing MyDash on a mobile phone (320px width, portrait orientation)
- WHEN the dashboard loads
- THEN all content is readable and usable without horizontal scrolling
- AND touch targets (buttons, links) are at least 44×44 pixels
- AND the layout is single-column with stacked content blocks

### Scenario: Dashboard responsive at tablet size (768px)
- GIVEN a user accessing MyDash on a tablet (768px width)
- WHEN the dashboard loads
- THEN the layout includes a sidebar navigation (collapsible on tap)
- AND main content area expands to fill available space
- AND all functionality is accessible via touch

### Scenario: Dashboard optimized for desktop (1024px+)
- GIVEN a user accessing MyDash on a desktop browser (1024px+ width)
- WHEN the dashboard loads
- THEN the full multi-column layout is displayed
- AND sidebar is always visible
- AND mouse and keyboard interactions are fully supported

### Scenario: Touch-optimized application launcher
- GIVEN a mobile user viewing the application list
- WHEN they see the app icons/cards
- THEN each app card is at least 44×44 pixels in tap size
- AND the layout uses icon grids that reflow for screen width
- AND tapping an app card opens the application details or navigates to the app

### Scenario: News feed scrolls smoothly on mobile
- GIVEN a mobile user viewing the news feed
- WHEN they scroll through news items
- THEN scrolling is smooth (60 fps) without jank
- AND images load with lazy-loading to reduce bandwidth
- AND tap interactions are responsive (< 100ms latency perceived)

## REQ-APPS-001: Display Role-Based Application List

**Category:** Feature | **Priority:** P1 | **Feature:** Access to Relevant Applications and News

### Scenario: Employee sees only applications their role can access
- GIVEN an authenticated employee with role "case-worker"
- WHEN they view the MyDash dashboard
- THEN they see application cards for: Procest, Files, Pipelinq (if supervisors see it)
- AND they do NOT see: Decidesk, OpenRegister, or other admin-only apps
- AND the applications are sorted by the admin-configured `sortOrder`

### Scenario: Admin sees all applications
- GIVEN a Nextcloud admin
- WHEN they view the MyDash dashboard
- THEN they see all applications registered in the system
- AND each application shows a badge: "Visible to: admin, managers, case-workers"

### Scenario: User with multiple roles sees union of all accessible apps
- GIVEN a user with roles: ["case-workers", "supervisors"]
- WHEN they view the application list
- THEN they see apps visible to case-workers AND apps visible to supervisors
- AND apps are deduplicated (each app appears once)

### Scenario: Application card includes metadata
- GIVEN an application in the MyDash list
- WHEN the user views the card
- THEN the card displays:
  - Application icon (MDI icon or image URL)
  - Application name
  - Short description
  - "Open" button linking to the app's deep link

### Scenario: Applications update when admin changes visibility
- GIVEN an admin editing application visibility rules in Settings
- WHEN they add "case-workers" role to a Procest visibility rule
- THEN the next time a case-worker views their dashboard
- AND their browser refreshes the page or waits for the next sync
- AND Procest appears in their application list

### Scenario: Empty state when no applications visible
- GIVEN a user with a role that has no applications configured
- WHEN they view the application section
- THEN they see an empty state: "No applications available for your role. Contact your administrator."

## REQ-NEWS-001: Display Role-Based News Feed

**Category:** Feature | **Priority:** P1 | **Feature:** Access to Relevant Applications and News

### Scenario: Employee sees only news relevant to their role
- GIVEN an authenticated employee with role "case-worker"
- WHEN they view the news feed on their dashboard
- THEN they see news items where `visibleToRoles` includes "case-worker"
- AND news items are sorted by `priority` (highest first), then by `publishedDate` (newest first)
- AND expired news items (past `expiryDate`) are not shown

### Scenario: News item includes full metadata
- GIVEN a news item in the feed
- WHEN the user views it
- THEN the item displays:
  - Title
  - Summary / first 200 characters with "Read More" link
  - Published date and author
  - Category badge (e.g., "Security", "IT", "HR")

### Scenario: Read more expands news item inline
- GIVEN a summarized news item in the list
- WHEN the user clicks "Read More"
- THEN the item expands to show the full HTML content
- AND the content is rendered with styling matching NL Design System
- AND external links open in a new tab

### Scenario: Admin publishes news to specific roles
- GIVEN a Nextcloud admin in Settings → MyDash News
- WHEN they create a new news item with:
  - title: "Security update: MFA required"
  - visibleToRoles: ["admin"]
  - expiryDate: "2026-06-04"
- THEN only admins see the news
- AND the news disappears from view after June 4
- AND a log entry records the creation and visibility settings

### Scenario: News items marked as read (frontend only)
- GIVEN a user viewing a news item
- WHEN they click "Mark as read" or just view the full content
- THEN a visual indicator (e.g., faded text) shows the item has been read
- AND this preference is stored in the browser's local storage (no backend persistence)
- AND the preference is per-browser (not synced across devices)

## REQ-ADMIN-001: Admin Settings for Application Access

**Category:** Feature | **Priority:** P1 | **Feature:** Access to Relevant Applications and News

### Scenario: Admin views application visibility matrix
- GIVEN a Nextcloud admin accessing Settings → MyDash Configuration → Applications
- WHEN the page loads
- THEN they see a table with:
  - Columns: Application Name | Icon | URL | Visible to Roles | Sort Order | Active (toggle)
  - Rows: One per application configured in the `mydash_applications` register
- AND the table supports pagination (20 items per page)
- AND they can filter by role or application name

### Scenario: Admin creates new application visibility rule
- GIVEN an admin on the application settings page
- WHEN they click "Add Application"
- THEN a form dialog opens with fields:
  - Application ID (select from discovered Nextcloud apps)
  - Application Name
  - Application Icon
  - Application URL (deep link)
  - Visible to Roles (multi-select of Nextcloud groups)
  - Description (why this app matters)
  - Sort Order (integer)
  - Active (toggle)
- AND clicking "Save" creates a record in the `mydash_applications` register
- AND validation ensures Application ID is unique

### Scenario: Admin edits existing visibility rule
- GIVEN an admin viewing an application rule
- WHEN they click "Edit"
- THEN the form pre-fills with current values
- AND they can modify any field
- AND clicking "Save" updates the register record
- AND the audit trail logs the change with before/after values

### Scenario: Admin deletes visibility rule
- GIVEN an admin viewing an application rule
- WHEN they click "Delete"
- THEN a confirmation dialog appears: "Remove [App Name] from user dashboards?"
- AND clicking "Confirm" soft-deletes the rule (or sets `isActive: false`)
- AND users no longer see the application
- AND a log entry records the deletion

### Scenario: Test visibility for a specific user/role
- GIVEN an admin on the application settings page
- WHEN they use the "Test Visibility" tool with:
  - User/Group selector: "alice" or "case-workers"
  - Filter: "Show only apps visible to this user"
- THEN they see exactly which applications that user/group would see
- AND this is non-destructive (read-only)

## REQ-ADMIN-002: Admin Settings for News Management

**Category:** Feature | **Priority:** P1 | **Feature:** Access to Relevant Applications and News

### Scenario: Admin views all news items
- GIVEN a Nextcloud admin accessing Settings → MyDash Configuration → News
- WHEN the page loads
- THEN they see a table with all news items from the `mydash_news` register
- AND columns: Title | Category | Visible to Roles | Published | Expires | Status (draft/published/expired)
- AND they can sort by title, published date, or expiry date

### Scenario: Admin publishes new news item
- GIVEN an admin on the news settings page
- WHEN they click "New News Item"
- THEN a form dialog opens with:
  - Title (text input, max 100 chars)
  - Summary (textarea, max 500 chars)
  - Content (HTML editor or rich text)
  - Category (select: executive, it, hr, security)
  - Visible to Roles (multi-select of groups)
  - Visible to Users (optional, array of user IDs)
  - Priority (integer, affects sort order)
  - Published Date (datetime picker, default: now)
  - Expiry Date (datetime picker, optional)
  - Source URL (optional link to original content)
- AND clicking "Publish" creates a record in `mydash_news` register with `isActive: true`

### Scenario: Admin schedules future news item
- GIVEN an admin creating a news item
- WHEN they set Published Date to a future time (e.g., May 25 at 09:00)
- THEN the item is saved with `isActive: false`
- AND a background job runs at the scheduled time to set `isActive: true`
- AND the news immediately becomes visible to the appropriate roles

### Scenario: Admin edits and re-publishes news
- GIVEN an admin viewing a published news item
- WHEN they click "Edit"
- THEN the form pre-fills with current values
- AND they can modify title, content, visibility, expiry date
- AND clicking "Update" persists changes
- AND the audit trail records the edit

### Scenario: Admin unpublishes or expires news
- GIVEN an admin viewing an active news item
- WHEN they click "Unpublish" or set Expiry Date to now
- THEN the item becomes invisible to all users
- AND existing news items with past expiry dates are auto-filtered in queries

## REQ-AUTH-001: Session Management Security

**Category:** Security | **Priority:** P0 | **Feature:** Remote Work and Mobile Access

### Scenario: Session tokens are Nextcloud-managed
- GIVEN a user logging into MyDash
- WHEN they authenticate
- THEN MyDash relies entirely on Nextcloud's session/token infrastructure (PHPSESSID or Bearer token)
- AND no custom session storage or cookie handling is implemented in MyDash
- AND tokens are validated on every backend request

### Scenario: Session invalidation on logout
- GIVEN an authenticated user
- WHEN they click "Log Out" or their session expires
- THEN Nextcloud's session handler invalidates the token
- AND all subsequent API calls return HTTP 401
- AND the user is redirected to the login page

### Scenario: Audit trail logs session events
- GIVEN session lifecycle events (login, logout, timeout, extend)
- WHEN these events occur
- THEN entries are recorded in the OpenRegister audit trail with:
  - Event type: "session_login", "session_logout", "session_timeout", "session_extend"
  - User ID (from `IUserSession`)
  - Timestamp
  - IP address (if available)
- AND audit records are immutable and retention-compliant

## REQ-MOBILE-002: Mobile Touch Interactions

**Category:** Design | **Priority:** P1 | **Feature:** Remote Work and Mobile Access

### Scenario: Touch gestures work on mobile
- GIVEN a mobile user on the MyDash dashboard
- WHEN they perform common touch gestures:
  - Tap to select/navigate
  - Swipe to scroll lists
  - Long-press to open context menu (if applicable)
- THEN the gestures are recognized and respond within 100ms
- AND visual feedback (highlight, ripple effect) is provided

### Scenario: App launcher is touch-friendly
- GIVEN a mobile user viewing applications
- WHEN they tap an app card
- THEN the app opens (navigates to the URL or shows detail page)
- AND there is no accidental activation due to scrolling
- AND the tap target is clearly visible and at least 44×44px

### Scenario: News feed supports pull-to-refresh
- GIVEN a mobile user viewing the news feed
- WHEN they pull down (swipe down from top)
- THEN the feed refreshes to load the latest news items
- AND a spinner or loading indicator appears during refresh
- AND new items appear in the list (if any have been published since the last load)

## REQ-INTEGRATE-001: Session Timeout Integration with Frontend

**Category:** Design | **Priority:** P1 | **Feature:** Remote Work and Mobile Access

### Scenario: Idle detection monitors user activity
- GIVEN the MyDash frontend loaded in a browser tab
- WHEN the user interacts with the page (mouse, keyboard, touch)
- THEN the idle timer is reset
- AND these events count: mousemove, keypress, click, scroll, touch

### Scenario: Session timeout warning appears
- GIVEN a session with 2 minutes remaining
- WHEN the idle timer reaches the warning threshold
- THEN an `NcDialog` modal appears with:
  - Title: "Your session is expiring soon"
  - Message: "You will be logged out in 2 minutes due to inactivity."
  - Buttons: "Extend Session" (primary), "Log Out" (secondary)
  - Auto-dismiss: No (user must take action)
- AND the dialog is not dismissible by clicking outside (modal)

### Scenario: Extend session cancels logout
- GIVEN the session timeout warning is visible
- WHEN the user clicks "Extend Session"
- THEN a POST request is sent to `/api/session/extend`
- AND the backend refreshes the session last-activity timestamp
- AND the dialog closes
- AND the idle timer resets to full duration
- AND the session continues normally

### Scenario: Force logout after timeout
- GIVEN a session at timeout
- WHEN the timeout is reached
- THEN the frontend clears the session token from memory/storage
- AND all API calls are rejected by the backend with HTTP 401
- AND the user is redirected to the login page with: "Your session has expired. Please log in again."
- AND a message explains they were logged out due to inactivity

## REQ-PERF-001: Performance on Mobile Networks

**Category:** Design | **Priority:** P2 | **Feature:** Remote Work and Mobile Access

### Scenario: Dashboard loads in under 2 seconds on 4G
- GIVEN a mobile user on a 4G network
- WHEN they load the MyDash dashboard
- THEN the initial page load completes in < 2 seconds (measured by First Contentful Paint)
- AND application list and news feed are visible and interactive
- AND images are lazy-loaded and optimized (WEBP or optimized JPEG)

### Scenario: Application list loads without blocking render
- GIVEN the dashboard page is loading
- WHEN the application list is being fetched from the API
- THEN the page shows a skeleton loader or placeholder
- AND the rest of the dashboard is interactive while the list loads
- AND data loads asynchronously without freezing the UI

### Scenario: News feed pagination reduces bandwidth
- GIVEN a mobile user with limited bandwidth
- WHEN they view the news feed
- THEN items are paginated (default 10 items per page)
- AND "Load More" button allows fetching additional items
- AND images are lazy-loaded (loaded only when scrolled into view)

## REQ-COMPAT-001: Browser and Device Compatibility

**Category:** Design | **Priority:** P1 | **Feature:** Remote Work and Mobile Access

### Scenario: Dashboard works on modern mobile browsers
- GIVEN MyDash running on mobile browsers:
  - Safari (iOS 14+)
  - Chrome (Android 10+)
  - Edge (Android, iOS)
- WHEN the user navigates the dashboard
- THEN all features work correctly
- AND no console errors are logged
- AND CSS Grid / Flexbox layout is correct

### Scenario: Session timeout works across browser types
- GIVEN a session in Safari, Chrome, or Edge
- WHEN the idle timeout is reached
- THEN the logout mechanism works consistently across all browsers
- AND the backend session is invalidated
- AND the user sees the logout message

## REQ-A11Y-001: Accessibility for Mobile & Remote Users

**Category:** Design | **Priority:** P1 | **Feature:** Remote Work and Mobile Access

### Scenario: WCAG AA compliance on mobile viewport
- GIVEN the MyDash dashboard at 320px width
- WHEN tested with automated accessibility tools (axe, WAVE)
- THEN no WCAG AA violations are reported
- AND color contrast is at least 4.5:1 for text
- AND buttons have visible focus indicators

### Scenario: Keyboard navigation works on desktop
- GIVEN a user accessing MyDash on desktop with keyboard only
- WHEN they navigate using Tab, Enter, and arrow keys
- THEN all interactive elements (buttons, links, form inputs) are reachable
- AND focus order is logical (top-to-bottom, left-to-right)
- AND modal dialogs trap focus within the dialog

### Scenario: Screen reader announces session timeout
- GIVEN a user with a screen reader (NVDA, JAWS, VoiceOver)
- WHEN their session timeout warning appears
- THEN the modal is announced as a live region
- AND the message is read aloud
- AND button options are clearly announced

## REQ-I18N-001: Multilingual Interface

**Category:** Design | **Priority:** P1 | **Feature:** Remote Work and Mobile Access

### Scenario: All UI strings are translated
- GIVEN MyDash deployed in a Dutch-speaking Nextcloud instance
- WHEN the user's language is set to Dutch (nl)
- THEN all user-facing text is displayed in Dutch:
  - Button labels: "Toepassing openen", "Sessie verlengen"
  - Messages: "Uw sessie is verlopen. Meld u alstublieft opnieuw aan."
  - Form labels: "Zichtbaar voor rollen"
- AND all strings are sourced from `l10n/nl.json` (backend) and `l10n/nl.json` (frontend)

### Scenario: Admin settings are translated
- GIVEN an admin in Settings → MyDash Configuration
- WHEN their language is set to Dutch
- THEN admin UI strings are in Dutch:
  - "Toepassingen", "Nieuwsberichten", "Instellingen voor sessie-timeout"
- AND form placeholders and help text are translated
