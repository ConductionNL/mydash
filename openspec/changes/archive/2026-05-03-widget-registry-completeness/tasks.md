# Tasks — widget-registry-completeness

## 1. Completeness test

- [x] 1.1 Create `src/constants/__tests__/widgetRegistry.completeness.spec.js`
- [x] 1.2 Define `const EXPECTED_TYPES = ['label', 'text', 'image', 'link', 'nc-widget', 'tile', 'video']` (sort alphabetically)
- [x] 1.3 Test 1: `widgetRegistry` keys MUST equal `EXPECTED_TYPES` exactly (order-independent set equality, with helpful diff message on failure)
- [x] 1.4 Test 2: `listWidgetTypes(t)` MUST return entries for every member of `EXPECTED_TYPES` (each having non-null `form` and `renderer`)
- [x] 1.5 Test 3: Every entry has `displayName`, `defaultContent`, and `icon` set

## 2. Documentation

- [x] 2.1 Add a section to `DEVELOPMENT.md` titled "Adding a widget type" — bullets:
  - Update `widgetRegistry.js` with the new entry
  - Update `EXPECTED_TYPES` in `widgetRegistry.completeness.spec.js`
  - Implement renderer + form following the pattern of an existing widget
  - Add i18n keys to all 4 `l10n/` files
  - Add canonical capability spec under `openspec/specs/<widget-name>/spec.md`

## 3. Quality gates

- [x] 3.1 `npm test` clean — completeness test passes against current registry
- [x] 3.2 ESLint clean
