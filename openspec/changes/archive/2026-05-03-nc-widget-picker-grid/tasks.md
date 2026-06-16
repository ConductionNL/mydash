# Tasks — nc-widget-picker-grid

## 1. Grid component

- [x] 1.1 Create `src/components/Widgets/Forms/NcWidgetGridPicker.vue` (or inline in `NcDashboardForm.vue` if compact)
- [x] 1.2 Layout: CSS grid with `grid-template-columns: repeat(auto-fill, minmax(140px, 1fr))`, `gap: 12px`
- [x] 1.3 Each card: icon (40px square) + display name (single line, ellipsis on overflow) + selected-state border + check-icon overlay
- [x] 1.4 Card has `role="radio"`, container has `role="radiogroup"` with appropriate `aria-label`
- [x] 1.5 Keyboard: ArrowLeft/Right/Up/Down moves focus across the grid (track current index with `tabindex` rotation), Enter selects, Space selects, Tab moves focus out
- [x] 1.6 Selected state synced with `v-model` (existing `widgetId` shape unchanged)

## 2. Wire into NcDashboardForm

- [x] 2.1 Replace the `<NcSelect>` element in `NcDashboardForm.vue` with `<NcWidgetGridPicker v-model="content.widgetId" :widgets="availableWidgets" />`
- [x] 2.2 Verify the form's `validate()` still catches missing `widgetId` selection
- [x] 2.3 Verify `update:content` event still fires with the same payload shape on selection

## 3. Tests

- [x] 3.1 Vitest: grid renders one card per available widget; icon present; display name present
- [x] 3.2 Vitest: clicking a card sets `v-model` correctly
- [x] 3.3 Vitest: ArrowRight from card 0 focuses card 1; Enter on focused card selects
- [x] 3.4 Vitest: selected card has the selected-state class
- [x] 3.5 Vitest: empty `widgets` list renders an empty-state message ("No Nextcloud widgets are installed")

## 4. i18n

- [x] 4.1 Add `Pick a widget`, `No Nextcloud widgets are installed`, `Selected` (aria-label) to `l10n/{en,nl}.{js,json}`

## 5. Quality gates

- [x] 5.1 ESLint clean (no new violations from this change)
- [x] 5.2 Stylelint clean
- [x] 5.3 `npm test` clean (825 tests passed)
- [x] 5.4 `npm run build` clean
