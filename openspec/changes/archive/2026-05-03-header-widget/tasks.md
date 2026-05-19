# Tasks — header-widget

## 1. Widget registration and service layer

- [ ] 1.1 Create `lib/Service/HeaderWidgetService.php` with core methods:
  - `getWidgetInfo(): array` — returns widget metadata (id, title, icon_url, v2 API support)
  - `validateImageSource(string $url, string $fileId): bool` — checks allow-list for external URLs, file ACL for file IDs
  - `checkAllowList(string $url): bool` — checks hostname against allow-list setting
  - `canReadFile(int $fileId, string $userId): bool` — verifies file read ACL via `IRootFolder`
- [ ] 1.2 Register the widget in a boot/lifecycle hook or service provider:
  - Hook into Nextcloud's dashboard widget registration (e.g., in `AppInfo/Bootstrap.php` or a listener on `IManager`)
  - Call `IManager::registerWidget()` with widget metadata
  - Widget id: `mydash_header`, title: translatable `app.mydash.header_widget_title`, icon: header icon URL
- [ ] 1.3 Create fixture-based PHPUnit tests for `HeaderWidgetService`:
  - `testCheckAllowListMatching` — hostname match, mismatch, case-insensitivity
  - `testCanReadFileSuccess` — user can read file, returns true
  - `testCanReadFileAccessDenied` — user cannot read file, returns false

## 2. Widget configuration UI component

- [ ] 2.1 Create `src/components/widgets/headerpicker/HeaderWidgetConfig.vue`:
  - Used by `WidgetAddEditModal.vue` when configuring a header widget placement
  - Form sections:
    - **Title** (required): text input
    - **Subtitle** (optional): text input
    - **Background Image**: choice of external URL OR NC file picker (via `FilePicker`)
    - **Overlay Mode**: radio buttons (none, tint, gradient-bottom)
    - **Overlay Color & Opacity**: color picker + slider (default 0.4)
    - **Text Color**: color picker (with auto-contrast option)
    - **Text Alignment**: radio buttons (left, center, right; default center)
    - **Vertical Alignment**: radio buttons (top, middle, bottom; default middle)
    - **Height**: dropdown (small/medium/large/xlarge; default medium)
    - **CTA Button** (optional): label + URL + style (primary/secondary/ghost)
  - Validation: URLs must be HTTP/HTTPS; colors must be valid CSS; overlay opacity 0..1
  - On save: emit `update:widgetContent` with serialized config object
- [ ] 2.2 Integrate into `WidgetAddEditModal.vue`:
  - When user selects `mydash_header` widget from picker
  - Display `HeaderWidgetConfig.vue` instead of default generic config panel

## 3. Placement configuration and schema migration

- [ ] 3.1 Create `lib/Migration/VersionXXXXDate2026...AddHeaderWidgetSettings.php`:
  - Add app config table entry for `mydash.header_widget_allowed_image_domains` (default `null` or `[]`)
  - NOTE: All per-placement config stored in `oc_mydash_widget_placements.widgetContent` JSON
- [ ] 3.2 Add getter method in `WidgetPlacementService` or factory to safely parse `widgetContent` JSON:
  - `extractHeaderConfig(WidgetPlacement $placement): array` — returns parsed config with defaults (title required; subtitle, backgroundImageUrl, backgroundImageFileId, backgroundColor, overlayMode, overlayOpacity, textColor, textAlign, verticalAlign, height, cta all optional with sensible defaults)
  - Validate and sanitize config (URLs must be HTTP/HTTPS, colors valid CSS, opacity 0..1, heights in [small, medium, large, xlarge], alignments in [left, center, right], verticalAlignments in [top, middle, bottom], overlayModes in [none, tint, gradient-bottom])
- [ ] 3.3 Create PHPUnit test for placement config parsing:
  - `testExtractHeaderConfigWithDefaults` — verify default values are applied
  - `testExtractHeaderConfigValidation` — invalid URLs/colors rejected, valid ones accepted

## 4. Image source validation and allow-list

- [ ] 4.1 Implement in `HeaderWidgetService::validateImageSource()`:
  - Input: `backgroundImageUrl` (string, nullable) and `backgroundImageFileId` (int, nullable)
  - If `backgroundImageFileId` is set:
    - Call `canReadFile($fileId, $userId)` to verify ACL
    - Return true if file is readable, false otherwise
    - No error UI — frontend falls back to backgroundColor
  - If `backgroundImageUrl` is set and not same-origin:
    - Call `checkAllowList($url)` to verify hostname
    - Return true if allowed, false otherwise
    - Return true if same-origin (always allowed)
  - Return true if both are null (valid state)
- [ ] 4.2 Implement `HeaderWidgetService::checkAllowList()`:
  - Read `mydash.header_widget_allowed_image_domains` from `IAppConfig::getValueString()`
  - If empty or null, return true (all allowed by default)
  - Otherwise, parse URL, extract hostname (case-insensitive)
  - Check for exact match in allow-list (no wildcard subdomain expansion)
  - Return boolean
- [ ] 4.3 Implement `HeaderWidgetService::canReadFile()`:
  - Use `IRootFolder::getUserFolder($userId)` to get user's file root
  - Try to fetch file by ID via file search or direct path resolution
  - Return true if file exists and is readable, false otherwise
  - On exception (file deleted, permission denied), return false silently
- [ ] 4.4 Create PHPUnit tests:
  - `testValidateImageSourceWithFileId` — file readable, returns true
  - `testValidateImageSourceWithFileIdAccessDenied` — file not readable, returns false
  - `testValidateImageSourceWithUrlAllowListAllowed` — URL in allow-list, returns true
  - `testValidateImageSourceWithUrlAllowListDisallowed` — URL not in allow-list, returns false
  - `testValidateImageSourceWithUrlSameOrigin` — same-origin URL, returns true (no allow-list check)

## 5. Frontend widget component (Vue 3 SFC)

- [ ] 5.1 Create `src/components/widgets/HeaderWidget.vue`:
  - Props: `placement: object` (with widgetContent config)
  - Data: `imageLoaded: false`, `imageError: null`
  - Computed properties:
    - `headerConfig`: extract + validate placement config with defaults
    - `headerStyles`: generate inline CSS for wrapper (height, background, etc.)
    - `overlayStyles`: generate CSS for overlay div (color, opacity, gradient)
    - `textStyles`: generate CSS for title/subtitle (color, alignment, vertical-align)
    - `buttonClasses`: generate button classes based on CTA style
  - Methods:
    - `onImageLoad()`: set imageLoaded = true
    - `onImageError()`: set imageLoaded = false, maintain backgroundColor fallback
    - `handleCtaClick()`: navigate to cta.url (target=_blank for external, same tab for internal)
  - Template:
    - Root div with headerStyles
    - Conditional `<img>` or `background-image` CSS for image
    - Overlay div (if overlayMode !== 'none') with overlayStyles
    - Absolute-positioned content area with title (`<h2>`), subtitle (`<p>`), and CTA button
    - CTA button with `rel="noopener noreferrer"` if target=_blank
    - ARIA labels on button combining text + destination
  - Lifecycle: onMounted → validate config, set up image loading
  - No data fetching (fully client-side, images load via standard NC preview routes)
- [ ] 5.2 Handle image-load failure gracefully:
  - Listen for `@error` on image element
  - If image fails, clear `background-image` and display backgroundColor instead
  - No error icon or message in header (falls back silently)
  - Subtitle and CTA still render normally
- [ ] 5.3 Handle file ID image source:
  - Frontend constructs preview URL: `linkToRoute('files.api.v1.resources', {fileid: fileId, preview: true})`
  - OR use standard NC file preview URL pattern: `/index.php/apps/files/api/v1/files/{fileId}?preview=true`
  - Pass URL to image element same as external URLs
  - ACL is handled server-side (invalid file IDs/no ACL → 404 from file preview route)
- [ ] 5.4 Create Playwright E2E tests:
  - `testHeaderWidgetRendersTitleAndSubtitle` — widget displays title and subtitle correctly
  - `testHeaderWidgetWithBackgroundImage` — widget renders image (mocked via preview route)
  - `testHeaderWidgetWithOverlay` — widget applies tint/gradient overlay
  - `testHeaderWidgetWithCTA` — CTA button renders and navigates on click
  - `testHeaderWidgetImageFailureWithColorFallback` — image 404 → backgroundColor only
  - `testHeaderWidgetHeightPresetsResponsive` — height presets render correctly

## 6. Accessibility

- [ ] 6.1 Ensure semantic HTML:
  - Title rendered as `<h2>` (proper heading hierarchy)
  - Subtitle rendered as `<p>` (semantic paragraph)
  - CTA rendered as `<a href>` or `<button>` with proper labels
- [ ] 6.2 Add ARIA labels:
  - CTA button with `aria-label` combining button text and link destination if button text is icon-only
  - Title and subtitle with proper role/lang attributes if multilingual
- [ ] 6.3 Keyboard navigation:
  - CTA button focusable and clickable via Enter/Space
  - Header widget navigable via Tab in dashboard grid
- [ ] 6.4 Color contrast:
  - Auto-contrast logic: detect background brightness and pick white or black text (default if no textColor set)
  - Manual textColor override supported

## 7. Internationalization (i18n)

- [ ] 7.1 Add Dutch (nl) and English (en) translation keys:
  - `app.mydash.header_widget_title` — "Kopbanner" / "Header Banner"
  - `app.mydash.header_config_title` — "Titel" / "Title"
  - `app.mydash.header_config_subtitle` — "Ondertitel (optioneel)" / "Subtitle (optional)"
  - `app.mydash.header_config_image` — "Achtergrondafbeelding" / "Background Image"
  - `app.mydash.header_config_image_url` — "Afbeeldings-URL" / "Image URL"
  - `app.mydash.header_config_image_file` — "Bestand uit Nextcloud" / "File from Nextcloud"
  - `app.mydash.header_config_overlay_mode` — "Overlay-modus" / "Overlay Mode"
  - `app.mydash.header_config_overlay_color` — "Overlay-kleur" / "Overlay Color"
  - `app.mydash.header_config_overlay_opacity` — "Doorzichtigheid (0-1)" / "Opacity (0-1)"
  - `app.mydash.header_config_text_color` — "Tekstkleur" / "Text Color"
  - `app.mydash.header_config_text_align` — "Tekstuitlijning" / "Text Alignment"
  - `app.mydash.header_config_vertical_align` — "Verticale uitlijning" / "Vertical Alignment"
  - `app.mydash.header_config_height` — "Hoogte" / "Height"
  - `app.mydash.header_config_cta` — "Call-to-Action knop (optioneel)" / "Call-to-Action Button (optional)"
  - `app.mydash.header_config_cta_label` — "Knoptekst" / "Button Text"
  - `app.mydash.header_config_cta_url` — "Doel-URL" / "Target URL"
  - `app.mydash.header_config_cta_style` — "Knopstijl" / "Button Style"
  - `app.mydash.header_config_overlay_none` — "Geen" / "None"
  - `app.mydash.header_config_overlay_tint` — "Gekleurde overlay" / "Tinted Overlay"
  - `app.mydash.header_config_overlay_gradient` — "Gradient aan onderkant" / "Gradient Bottom"
  - `app.mydash.header_config_height_small` — "Klein (120px)" / "Small (120px)"
  - `app.mydash.header_config_height_medium` — "Gemiddeld (200px)" / "Medium (200px)"
  - `app.mydash.header_config_height_large` — "Groot (320px)" / "Large (320px)"
  - `app.mydash.header_config_height_xlarge` — "Extra groot (480px)" / "Extra Large (480px)"
  - `app.mydash.header_config_text_align_left` — "Links" / "Left"
  - `app.mydash.header_config_text_align_center` — "Gecentreerd" / "Center"
  - `app.mydash.header_config_text_align_right` — "Rechts" / "Right"
  - `app.mydash.header_config_vertical_align_top` — "Boven" / "Top"
  - `app.mydash.header_config_vertical_align_middle` — "Midden" / "Middle"
  - `app.mydash.header_config_vertical_align_bottom` — "Onder" / "Bottom"
  - `app.mydash.header_config_cta_style_primary` — "Primair" / "Primary"
  - `app.mydash.header_config_cta_style_secondary` — "Secundair" / "Secondary"
  - `app.mydash.header_config_cta_style_ghost` — "Transparant" / "Ghost"
- [ ] 7.2 Add translation files:
  - `l10n/nl.json` — Dutch translations
  - `l10n/en.json` — English translations (fallback)

## 8. Quality gates and testing

- [ ] 8.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan):
  - Fix any pre-existing issues in touched files
  - New PHP code must pass all checks
- [ ] 8.2 Run ESLint on all Vue/JS files:
  - `npm run lint` on `src/components/widgets/`
  - Fix any warnings or errors
- [ ] 8.3 Run Stylelint on component stylesheets
- [ ] 8.4 Confirm all hydra-gates pass locally before opening PR
- [ ] 8.5 Add SPDX-License-Identifier and SPDX-FileCopyrightText headers to every new PHP file (inside docblock)
- [ ] 8.6 PHPUnit test coverage:
  - Aim for 80%+ line coverage on `HeaderWidgetService`
  - All public methods tested
  - Edge cases (null fields, invalid URLs, file not found) covered
- [ ] 8.7 Playwright E2E test coverage:
  - Header widget renders in dashboard
  - All height presets render correctly
  - Image loading (external URL and file ID) works
  - All overlay modes render correctly
  - CTA button navigates correctly
  - Image load failure gracefully falls back to backgroundColor
  - Accessibility: title navigable via keyboard, ARIA labels present
- [ ] 8.8 Manual testing on local Nextcloud instance:
  - Create a dashboard with header widget
  - Configure title, subtitle, background image (external + NC file)
  - Test each overlay mode and height preset
  - Test CTA button navigation (internal and external)
  - Test image load failure (404)
  - Test file ACL (try with file user cannot read)

## 9. Documentation and changelog

- [ ] 9.1 Update `CHANGELOG.md` with:
  - New feature: "Add header widget for full-width banners with images, overlays, and CTA"
  - List features: overlay modes, height presets, image allow-list, file ACL
- [ ] 9.2 Update `README.md` (if applicable) with widget description
- [ ] 9.3 Add code comments to `HeaderWidgetService` explaining allow-list logic and file ACL validation
