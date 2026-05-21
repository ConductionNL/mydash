# Tasks — NC Dashboard Widget Proxy Picker UX

## Implementation Tasks

- [ ] **TASK-NCDP-001**: Create `src/components/Widgets/Pickers/WidgetPicker.vue` — grid container with `role="radiogroup"`, handles keyboard navigation (arrow keys, Enter, Space, Tab), manages focus via `tabindex`, emits `@select` event with selected widget id
- [ ] **TASK-NCDP-002**: Create `src/components/Widgets/Pickers/WidgetPickerCard.vue` — individual card button with `role="radio"`, icon (40×40), title (single-line with ellipsis), selected state visual (border + check-mark overlay), `aria-label`, `aria-checked`
- [ ] **TASK-NCDP-003**: Update `src/components/Widgets/Forms/NcDashboardForm.vue` — integrate `WidgetPicker` component, bind to `content.widgetId` v-model, handle pre-fill from existing `editingWidget.content`, implement empty-state (no widgets available)
- [ ] **TASK-NCDP-004**: Icon fallback — add `onerror` handler to card images to replace broken URLs with generic `<NcIcon name="apps" />` fallback; test with missing and 404 URLs
- [ ] **TASK-NCDP-005**: Form validation — add `validate()` method to `NcDashboardForm` that checks `widgetId` is not empty; show error message `t('mydash', 'Please select a widget')` on validation failure
- [ ] **TASK-NCDP-006**: Responsive CSS — ensure grid uses `grid-template-columns: repeat(auto-fill, minmax(140px, 1fr))` with `gap: 12px`; verify layout on 320px, 768px, 1100px, 1400px viewports
- [ ] **TASK-NCDP-007**: Internationalization — add translation keys for Dutch (nl_NL) and English (en_US):
  - `'NC Widget Picker'` / `'Nextcloud Widget Picker'`
  - `'Select a widget'` / `'Selecteer een widget'`
  - `'No Nextcloud widgets are installed'` / `'Geen Nextcloud-widgets geïnstalleerd'`
  - `'Please select a widget'` / `'Selecteer alstublieft een widget'`
  - `'Select {widget} widget'` (for aria-label, parametrized)

## Test Tasks

- [ ] **TASK-NCDP-008**: Vitest — test keyboard navigation: arrow keys move focus correctly within grid; Tab exits grid; Enter/Space select a card; focus management via `tabindex` works correctly
- [ ] **TASK-NCDP-009**: Vitest — test picker logic: selected card gets `aria-checked="true"`; v-model updates on selection; empty state renders when `widgets` array is empty
- [ ] **TASK-NCDP-010**: Vitest — test icon fallback: missing `iconUrl` shows generic icon; 404 image triggers fallback; card layout remains consistent
- [ ] **TASK-NCDP-011**: Playwright — test UI rendering: 8-widget picker shows 8 cards in responsive grid; clicking a card selects it (visual + v-model update); empty state message appears when widgets array is empty
- [ ] **TASK-NCDP-012**: Playwright — test form submission: selecting widget and clicking Add creates placement with correct `widgetId`; submitting without selection shows validation error
- [ ] **TASK-NCDP-013**: Playwright — test responsive layout: desktop viewport shows multi-column grid; tablet shows fewer columns; mobile shows 2-3 columns; cards remain clickable at all sizes

## Documentation & Quality

- [ ] **TASK-NCDP-014**: ESLint — ensure all new Vue components pass ESLint rules (no unused variables, proper imports, accessibility rules)
- [ ] **TASK-NCDP-015**: TypeScript (if applicable) — ensure proper typing for component props/emits
- [ ] **TASK-NCDP-016**: Documentation — add Changelog entry describing the new widget picker UX, keyboard navigation, and responsive layout

## Verification Checklist

- [ ] `npm run lint` exits clean
- [ ] `npm run test` (Vitest) passes all picker-related tests
- [ ] `npm run test:e2e` (Playwright) passes all picker scenarios
- [ ] Manual testing: picker renders correctly with 5+ widgets; keyboard nav works; empty state works; responsive layout works on mobile/tablet/desktop
- [ ] i18n: Dutch and English strings appear correctly in all locales
- [ ] Icon fallback: missing/broken icons show generic widget icon, card layout is consistent
- [ ] Form validation: form blocks submission when widget not selected; error message displays correctly
