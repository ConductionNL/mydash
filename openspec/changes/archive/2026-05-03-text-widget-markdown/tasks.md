# Tasks — text-widget-markdown

## 1. Admin configuration

- [ ] 1.1 Add `mydash.text_widget_default_mode` to app config store (e.g., `lib/AppConfig.php` or equivalent Nextcloud IAppConfig usage)
- [ ] 1.2 Default the setting to `'markdown'` (primary mode) if not explicitly set
- [ ] 1.3 Expose the setting to the Nextcloud admin panel (Settings UI is deferred to follow-up `admin-text-widget-settings` change; only the config backend is required here)
- [ ] 1.4 Add validation that only `'html'` and `'markdown'` are accepted values; reject or coerce invalid values

## 2. Domain and widget schema updates

- [ ] 2.1 Update text-widget data model to add `contentMode` field to `styleConfig.content` schema (no database migration required; JSON-on-read parsing)
- [ ] 2.2 Add getter/helper in TextWidget entity or service to retrieve current `contentMode` (defaulting to `'html'` for backward compat)
- [ ] 2.3 Update widget creation to populate `contentMode` from the admin setting at add time

## 3. Markdown parser integration

- [ ] 3.1 Add `league/commonmark` to `composer.json` (or reuse if already vendored in nextcloud-vue; check dependencies first)
- [ ] 3.2 Create `lib/Service/MarkdownParserService.php` with public method `parse(string $markdown): string` that returns sanitised HTML
- [ ] 3.3 Wire the parser to use the same sanitiser as REQ-TXT-001 (inspect existing HTML sanitiser; add `<table>`, `<thead>`, `<tbody>`, `<tr>`, `<th>`, `<td>` to the allow-list)
- [ ] 3.4 Add unit tests for parser: headings (H1–H6), emphasis (bold, italic), code (inline and blocks), links, lists (bullet and ordered), block quotes, tables

## 4. Text-widget renderer update

- [ ] 4.1 Update the text-widget renderer (Vue component or server-side template) to check `contentMode` field
- [ ] 4.2 When `contentMode = 'markdown'`, call `MarkdownParserService::parse()` before rendering
- [ ] 4.3 When `contentMode = 'html'` or unset, use the existing HTML sanitiser from REQ-TXT-001 (no change)
- [ ] 4.4 Ensure both paths go through the same XSS allow-list (both branches sanitise)

## 5. Text-widget edit form UI

- [ ] 5.1 Add Mode toggle/radio to `src/components/TextWidgetForm.vue`: `[HTML] [Markdown]` with options clearly labeled
- [ ] 5.2 Bind the toggle to `content.contentMode` in the form's data object
- [ ] 5.3 Set the toggle's default based on: existing `contentMode` if present, else admin setting, else `'markdown'`
- [ ] 5.4 Ensure toggling modes preserves the text content (no clearing or transformation)
- [ ] 5.5 Include the updated `contentMode` in the form payload when saving

## 6. AddWidgetModal update

- [ ] 6.1 When the text-widget sub-form is mounted for a NEW widget, pre-populate the Mode toggle with the admin setting `mydash.text_widget_default_mode`
- [ ] 6.2 Allow the user to explicitly select a different mode before adding the widget
- [ ] 6.3 Pass the selected `contentMode` to the widget creation payload

## 7. Sanitiser enhancements

- [ ] 7.1 Review the existing HTML sanitiser (from REQ-TXT-001) and verify it allows `<h1>` through `<h6>` tags
- [ ] 7.2 Extend the allow-list to include table-related tags: `<table>`, `<thead>`, `<tbody>`, `<tfoot>`, `<tr>`, `<th>`, `<td>`, `<caption>`
- [ ] 7.3 Ensure all table attributes (colspan, rowspan, etc.) are appropriately whitelisted or stripped per security policy
- [ ] 7.4 Verify that link sanitisation adds `rel="noopener noreferrer"` to any `target="_blank"` anchors (from markdown or HTML)
- [ ] 7.5 Add unit test for sanitiser round-trip: XSS attempts (scripts, event handlers, `javascript:` URLs) are stripped; safe tags and attributes pass through

## 8. Frontend store / API integration

- [ ] 8.1 Ensure `src/stores/dashboards.js` or widget store passes through the `contentMode` field from API responses without loss
- [ ] 8.2 Confirm the widget serialise/deserialise cycle preserves `contentMode` (JSON round-trip)

## 9. PHPUnit tests

- [ ] 9.1 `MarkdownParserServiceTest::testParseHeadings` — verify H1–H6 syntax
- [ ] 9.2 `MarkdownParserServiceTest::testParseEmphasis` — bold, italic, combined
- [ ] 9.3 `MarkdownParserServiceTest::testParseCode` — inline and blocks
- [ ] 9.4 `MarkdownParserServiceTest::testParseLinks` — href and text
- [ ] 9.5 `MarkdownParserServiceTest::testParseLists` — bullets and ordered, nested
- [ ] 9.6 `MarkdownParserServiceTest::testParseBlockQuotes`
- [ ] 9.7 `MarkdownParserServiceTest::testParseTables`
- [ ] 9.8 `MarkdownParserServiceTest::testSanitisesScriptTags` — XSS stripping
- [ ] 9.9 `MarkdownParserServiceTest::testSanitisesEventHandlers` — onclick, onload, etc. removed
- [ ] 9.10 `MarkdownParserServiceTest::testSanitisesJavascriptUrls` — javascript: protocol stripped from links
- [ ] 9.11 `TextWidgetServiceTest::testRendersHtmlModeUnchanged` — existing behavior preserved when `contentMode = 'html'` or unset
- [ ] 9.12 `TextWidgetServiceTest::testRendersMarkdownMode` — markdown is parsed when `contentMode = 'markdown'`
- [ ] 9.13 `WidgetControllerTest::testNewWidgetReceivesAdminDefaultMode` — newly added widgets get the correct default contentMode

## 10. End-to-end Playwright tests

- [ ] 10.1 User creates a new text-widget, sees Mode toggle defaulting to `Markdown` (or admin default), toggles to `HTML`, adds widget
- [ ] 10.2 User edits an existing widget, changes Mode from `HTML` to `Markdown`, content (with markdown syntax) is parsed and rendered correctly
- [ ] 10.3 User enters markdown syntax (`# Heading`, `**bold**`, `[link](url)`) in a markdown-mode widget, saves, and views the rendered HTML output
- [ ] 10.4 User creates a markdown-mode widget with table syntax, sees the table rendered correctly on the dashboard
- [ ] 10.5 Admin changes `mydash.text_widget_default_mode` setting to `'html'`, creates a new text-widget, and it defaults to HTML mode (existing widgets unaffected)
- [ ] 10.6 Existing widget with `contentMode = 'html'` and HTML content continues to render unchanged after feature is deployed

## 11. Quality gates

- [ ] 11.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes — fix any pre-existing issues encountered
- [ ] 11.2 ESLint + Stylelint clean on all touched Vue/JS files
- [ ] 11.3 `i18n` keys for Mode toggle labels (`Mode`, `HTML`, `Markdown`) in both `nl` and `en` per the i18n requirement
- [ ] 11.4 SPDX headers on every new PHP file (inside the docblock per the SPDX-in-docblock convention) — gate-spdx must pass
- [ ] 11.5 Verify markdown parser dependencies (composer.json and npm versions) are stable and security-audited
- [ ] 11.6 Run all relevant `hydra-gates` locally before opening PR
