# Tasks — group-routing

## 1. Backend resolver

- [ ] 1.1 Add `AdminTemplateService::resolvePrimaryGroup(string $userId): string` (read-only, pure) per REQ-TMPL-012
- [ ] 1.2 Add internal helper `AdminTemplateService::pickFirstMatch(array $orderedGroups, array $userGroups): ?string` for clarity and direct unit testing
- [ ] 1.3 Add `AdminSettingsService::getGroupOrder(): array` returning the JSON `string[]` from `admin_settings.group_order` (default `[]`)
- [ ] 1.4 Tolerate stale group IDs in `group_order` — never throw when an entry no longer exists in Nextcloud (REQ-TMPL-012 final scenario)
- [ ] 1.5 Add `Dashboard::DEFAULT_GROUP_ID = 'default'` constant so the sentinel string is named in exactly one place

## 2. Single source of truth wiring

- [ ] 2.1 Update `WorkspaceController::index` to call `resolvePrimaryGroup` and pass the result into dashboard resolution
- [ ] 2.2 Refactor REQ-DASH-013 implementation (visible-to-user) to consume `resolvePrimaryGroup` instead of inlining the algorithm
- [ ] 2.3 Refactor REQ-DASH-018 implementation to consume `resolvePrimaryGroup` instead of inlining the algorithm
- [ ] 2.4 Verify (by code review and the grep test in 4.2) that no caller invokes `IGroupManager::getUserGroupIds` outside the resolver

## 3. Frontend

- [ ] 3.1 Surface the resolved primary group as `primaryGroup` initial state (already plumbed by the `runtime-shell` change)
- [ ] 3.2 Surface its display name via `IGroupManager::get($id)?->getDisplayName()` (or the literal `'Default'` for the `'default'` sentinel) as `primaryGroupDisplayName`
- [ ] 3.3 Render the display name in the workspace header so users can see which group's dashboards they are viewing

## 4. PHPUnit tests

- [ ] 4.1 Table-driven `AdminTemplateServiceTest::testResolvePrimaryGroup` covering every REQ-TMPL-012 scenario: priority order wins, no match returns `'default'`, empty `group_order` returns `'default'`, configured-but-not-member is skipped, deleted-group is harmless
- [ ] 4.2 Single-source-of-truth grep guard test: `grep -r 'getUserGroupIds' lib/` returns only the resolver — fail otherwise (REQ-TMPL-013)
- [ ] 4.3 `AdminTemplateServiceTest::testPickFirstMatch` direct unit tests for the helper (empty inputs, no overlap, multiple overlaps)

## 5. Quality gates

- [ ] 5.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes — fix any pre-existing issues encountered along the way
- [ ] 5.2 ESLint + Stylelint clean on touched Vue/JS files
- [ ] 5.3 SPDX headers on every new PHP file (inside the docblock per the SPDX-in-docblock convention) — `gate-spdx` must pass
- [ ] 5.4 Run all 10 `hydra-gates` locally before opening PR
- [ ] 5.5 i18n keys for the `'Default'` display-name fallback in both `nl` and `en`
