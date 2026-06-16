# Tasks — divider-widget

## 1. Widget Registration

- [ ] 1.1 Create `lib/Dashboard/DividerWidgetProvider.php` implementing `OCP\Dashboard\IWidgetV2` or `IWidget`
- [ ] 1.2 Implement `getId()` returning `"mydash_divider"`, `getTitle()` returning localized "Divider", `getOrder()` returning appropriate position in widget list
- [ ] 1.3 Implement `getIconUrl()` returning a divider-themed SVG icon URL
- [ ] 1.4 Register the widget provider in `appinfo/app.php` or a service provider by injecting `IManager` and calling `registerWidget(new DividerWidgetProvider())`
- [ ] 1.5 Confirm the divider widget appears in the widget picker when adding widgets to a dashboard

## 2. Frontend Component — DividerWidget.vue

- [ ] 2.1 Create `src/components/widgets/DividerWidget.vue` as a Vue 3 SFC
- [ ] 2.2 Accept `placement` object as props with structure: `{ widgetContent: { style: string, lineColor: ?string, lineThickness: number, lineStyle: string, whitespaceSize: string, headingText: ?string } }`
- [ ] 2.3 Implement three conditional render branches by `placement.widgetContent.style`:
  - [ ] 2.3a **line**: render a horizontal line using `<div style="border-bottom: ${thickness}px ${lineStyle} ${color}; width: 100%; role="separator"" />`
  - [ ] 2.3b **whitespace**: render a transparent `<div style="height: ${mapSize(whitespaceSize)}px; role="separator"" />`
  - [ ] 2.3c **heading-break**: render `<div style="display: flex; align-items: center; gap: 1rem;"><hr style="flex: 1; border-color: ${color}"/><h3>${headingText}</h3><hr style="flex: 1; border-color: ${color}"/></div>` with `aria-label` on the wrapper
- [ ] 2.4 For default lineColor (null), use CSS custom property `--color-border` from the theme
- [ ] 2.5 Add print-safe CSS: no `display: none` in `@media print` — dividers MUST remain visible
- [ ] 2.6 Test render on multiple viewport sizes and confirm dividers adapt to widget width

## 3. Frontend Config Component — DividerWidgetConfig.vue

- [ ] 3.1 Create `src/components/widgets/config/DividerWidgetConfig.vue` as a Vue 3 SFC
- [ ] 3.2 Accept `placement` object as props with `widgetContent` structure
- [ ] 3.3 Render a minimal edit form with ONLY:
  - [ ] 3.3a Style selector (dropdown: line / whitespace / heading-break)
  - [ ] 3.3b Conditional fields based on selected style:
    - [ ] Line style: lineColor (color picker or text input, label "Line Color"), lineThickness (number input 1–8, label "Thickness (px)"), lineStyle (dropdown: solid / dashed / dotted, label "Line Style")
    - [ ] Whitespace style: whitespaceSize (dropdown: small / medium / large / xlarge, label "Spacing Size")
    - [ ] Heading-break style: headingText (required text input, label "Heading Text"), lineColor (optional color picker), lineStyle (optional dropdown)
- [ ] 3.4 Emit `update:modelValue` with updated `widgetContent` JSON when any field changes
- [ ] 3.5 No name, icon, click-target, or other standard widget fields — keep the form minimal
- [ ] 3.6 Provide sensible UI hints (e.g., "Select a divider style to break up dashboard sections")

## 4. Widget Add Modal Defaults

- [ ] 4.1 When user selects the divider widget in the "Add Widget" modal, pre-populate placement defaults:
  - [ ] 4.1a `gridHeight = 1`
  - [ ] 4.1b `gridWidth = max dashboard width` (fetch from dashboard's gridColumns or use dashboard's default)
  - [ ] 4.1c `widgetContent = { style: "line", lineColor: null, lineThickness: 1, lineStyle: "solid" }`
- [ ] 4.2 Allow user to override these defaults in the placement editor before adding to dashboard

## 5. Theme and CSS

- [ ] 5.1 Ensure `--color-border` custom property is available in the divider render context (inherited from NC theme)
- [ ] 5.2 Add optional scoped CSS in `DividerWidget.vue` for print media: `@media print { /* divider visibility rules */ }`
- [ ] 5.3 Confirm dividers render correctly in both light and dark NC themes
- [ ] 5.4 Test color contrast for dividers: line dividers MUST meet WCAG AA contrast requirements (4.5:1 minimum if used as text separators; visual separators have lower requirements)

## 6. Accessibility

- [ ] 6.1 Line dividers: add `role="separator"` and omit `aria-label` (decorative)
- [ ] 6.2 Whitespace dividers: add `role="separator"` and omit `aria-label` (decorative)
- [ ] 6.3 Heading-break dividers: render heading as semantic `<h3>`, add `aria-label="heading_text divider"` on the wrapper, ensure heading inherits correct hierarchy
- [ ] 6.4 Test with screen reader (e.g., NVDA, JAWS) to confirm dividers are announced correctly or skipped as decorative
- [ ] 6.5 Ensure font sizes scale appropriately on print for headings and text readability

## 7. Frontend Store Integration

- [ ] 7.1 No special state management required — dividers are fully client-side and use only the `widgetContent` JSON from the placement record
- [ ] 7.2 Confirm `src/stores/dashboards.js` already serializes placements with `widgetContent` correctly; no changes needed unless divider-specific state is added later

## 8. Routing and Navigation

- [ ] 8.1 No custom routes required — dividers are rendered by standard dashboard page logic
- [ ] 8.2 Confirm `appinfo/routes.php` does NOT need modification (divider widget uses only widget discovery, not custom endpoints)

## 9. Backend Service Layer

- [ ] 9.1 No service classes required — all logic is client-side
- [ ] 9.2 If backend state tracking is needed in the future (e.g., divider analytics), deferred to a follow-up change

## 10. i18n / Translations

- [ ] 10.1 Add translation keys for the divider widget:
  - [ ] `mydash.widget.divider.title` — "Divider"
  - [ ] `mydash.widget.divider.description` — "A visual section break or spacer for organizing dashboard layouts"
  - [ ] `mydash.config.divider.style` — "Style"
  - [ ] `mydash.config.divider.style.line` — "Horizontal Line"
  - [ ] `mydash.config.divider.style.whitespace` — "Whitespace"
  - [ ] `mydash.config.divider.style.heading-break` — "Heading with Lines"
  - [ ] `mydash.config.divider.lineColor` — "Line Color"
  - [ ] `mydash.config.divider.lineThickness` — "Thickness (pixels)"
  - [ ] `mydash.config.divider.lineStyle` — "Line Style"
  - [ ] `mydash.config.divider.lineStyle.solid` — "Solid"
  - [ ] `mydash.config.divider.lineStyle.dashed` — "Dashed"
  - [ ] `mydash.config.divider.lineStyle.dotted` — "Dotted"
  - [ ] `mydash.config.divider.whitespaceSize` — "Spacing Size"
  - [ ] `mydash.config.divider.whitespaceSize.small` — "Small (16px)"
  - [ ] `mydash.config.divider.whitespaceSize.medium` — "Medium (32px)"
  - [ ] `mydash.config.divider.whitespaceSize.large` — "Large (64px)"
  - [ ] `mydash.config.divider.whitespaceSize.xlarge` — "Extra Large (128px)"
  - [ ] `mydash.config.divider.headingText` — "Heading Text"
- [ ] 10.2 Add translations for both `nl` and `en` in `l10n/nl.json` and `l10n/en.json`
- [ ] 10.3 Reference these keys in Vue components via `$t()` helper

## 11. Quality Gates

- [ ] 11.1 ESLint + Stylelint clean on `src/components/widgets/DividerWidget.vue` and `src/components/widgets/config/DividerWidgetConfig.vue`
- [ ] 11.2 No unused imports or variables
- [ ] 11.3 Test divider rendering in Playwright: add a test that places a divider on a dashboard and confirms all three styles render correctly
- [ ] 11.4 Confirm print layout with Playwright's `page.goto(url, { waitUntil: 'networkidle' }); await page.pdf({ path: 'divider.pdf' })` — dividers MUST be visible in the PDF
- [ ] 11.5 Confirm no console errors or warnings when rendering dividers (Vue dev tools)
- [ ] 11.6 SPDX headers on every new PHP file (inside the docblock per the SPDX-in-docblock convention) — gate-spdx must pass

## 12. Documentation and Testing

- [ ] 12.1 Update `.github/docs/widgets.md` (or create if missing) with divider widget usage examples
- [ ] 12.2 Add Playwright test fixture: create a dashboard with one of each divider style and verify all render correctly
- [ ] 12.3 Test responsive behaviour: confirm dividers adapt to narrow viewports (mobile) without breaking layout
- [ ] 12.4 Test dark mode: confirm dividers are visible in both light and dark themes with appropriate color contrast

## 13. Optional Follow-Up

- [ ] 13.1 Consider adding a divider-specific icon/SVG design that matches Nextcloud's icon style
- [ ] 13.2 Consider adding divider "templates" (preset configs) in a follow-up change for common patterns (e.g., "Section Header", "Subtle Spacer", "Bold Separator")
- [ ] 13.3 Consider collecting divider usage analytics in a future iteration to inform dashboard layout patterns
