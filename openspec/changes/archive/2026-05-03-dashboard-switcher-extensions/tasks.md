# Tasks — dashboard-switcher-extensions

## 1. Sidebar footer

- [ ] 1.1 Create `src/components/Workspace/SidebarFooter.vue` — pinned-to-bottom block with brand row + Documentation link
- [ ] 1.2 Add brand assets (Sendent + Conduction logos) — either import from `@conduction/nextcloud-vue` if available, or add SVGs under `img/`
- [ ] 1.3 Sendent + Conduction logos open their respective sites via `<a target="_blank" rel="noopener noreferrer">`
- [ ] 1.4 Documentation link icon + label, target same URL the gear menu currently uses
- [ ] 1.5 Mount `SidebarFooter` inside `DashboardSwitcherSidebar.vue` with `position: sticky; bottom: 0` and a divider above

## 2. "Add Dashboard" card button

- [ ] 2.1 Remove the inline `+ New Dashboard` row from inside the personal section's list
- [ ] 2.2 Add a dedicated `NcButton type="outline"` card below the personal list (still inside the sidebar's scroll container)
- [ ] 2.3 Card shows `+` icon + `t('mydash', 'Add dashboard')` label
- [ ] 2.4 Card visible only when `allowUserDashboards === true` (same gate as the old inline row)
- [ ] 2.5 Click emits `update:open(false)` then `create-dashboard()` — same contract as the removed inline row

## 3. Tests

- [ ] 3.1 Vitest: `SidebarFooter` renders Sendent + Conduction logos, both with `target="_blank"`
- [ ] 3.2 Vitest: Documentation link present with correct href
- [ ] 3.3 Vitest: `DashboardSwitcherSidebar` renders the Add-Dashboard card when `allowUserDashboards: true`
- [ ] 3.4 Vitest: Add-Dashboard card hidden when `allowUserDashboards: false`
- [ ] 3.5 Vitest: clicking the Add-Dashboard card emits `update:open(false)` then `create-dashboard()` in order
- [ ] 3.6 Vitest: footer stays at the bottom even with a long dashboards list (assert `position: sticky` style)

## 4. i18n

- [ ] 4.1 Add `Add dashboard`, `Documentation`, `Powered by` to `l10n/{en,nl}.{js,json}` (4 files)

## 5. Quality gates

- [ ] 5.1 ESLint clean
- [ ] 5.2 Stylelint clean
- [ ] 5.3 Build clean
