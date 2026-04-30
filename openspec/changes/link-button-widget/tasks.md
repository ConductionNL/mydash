# Tasks — link-button-widget

## 1. Backend (file creation endpoint)

- [ ] 1.1 Create `lib/Service/FileService.php::createFile(string $userId, string $filename, string $dir, string $content): array`
- [ ] 1.2 Filename validation: regex `^[a-zA-Z0-9_\-. ]+$`, ≤255 chars, no `..` or `/` or `\` or null byte
- [ ] 1.3 Dir validation: no `..`, no null byte
- [ ] 1.4 Extension validation against admin-configured allow-list (default `txt, md, docx, xlsx, csv, odt`)
- [ ] 1.5 Resolve via `IRootFolder::getUserFolder`; create subdirectory if missing
- [ ] 1.6 Overwrite if file exists; return `{status, fileId, url}` with `URLGenerator::linkToRouteAbsolute('files.view.index', ['openfile' => fileId])`
- [ ] 1.7 Add `lib/Controller/FileController.php::createFile` mapped to `POST /api/files/create` in `appinfo/routes.php`
- [ ] 1.8 Wrap exceptions; do not leak raw exception messages to the response

## 2. Renderer

- [ ] 2.1 Create `src/components/Widgets/Renderers/LinkButtonWidget.vue`
- [ ] 2.2 Implement three click branches by `actionType` (external / internal / createFile)
- [ ] 2.3 Suppress all click handlers in admin/edit mode (`isAdmin === true` and shell `canEdit === true`)
- [ ] 2.4 Apply `disabled` attribute while `isExecuting === true`
- [ ] 2.5 Add inline `createFile` modal child component (filename prompt + Cancel/Create)
- [ ] 2.6 Resolve icon via shared `IconRenderer` (built-in MDI name OR custom URL)
- [ ] 2.7 Apply default colour fallback to `var(--color-primary)` / `var(--color-primary-text)` when empty
- [ ] 2.8 Add hover lift (translate up 2 px + soft drop shadow)

## 3. Internal actions composable

- [ ] 3.1 Create `src/composables/useInternalActions.js` exposing `register(id, fn)`, `invoke(id)`, `has(id)`
- [ ] 3.2 Use a singleton-style module-level `Map`
- [ ] 3.3 `invoke` MUST log `console.warn('Unknown internal action: <id>')` on missing id and not throw

## 4. Form

- [ ] 4.1 Create `src/components/Widgets/Forms/LinkButtonForm.vue` with all six fields
- [ ] 4.2 Placeholder text for `url` swaps based on `actionType` (`https://...`, `action-id`, `docx`)
- [ ] 4.3 `validate()` requires both `label` AND `url` non-empty; returns non-empty error array otherwise
- [ ] 4.4 Pre-fill all fields from `editingWidget.content` when editing an existing widget

## 5. Registry

- [ ] 5.1 Add `link` entry to `src/constants/widgetRegistry.js` with defaults `{label:'', url:'', icon:'', actionType:'external', backgroundColor:'', textColor:''}`

## 6. Tests

- [ ] 6.1 PHPUnit: filename validation rejects path traversal, special chars, oversized input
- [ ] 6.2 PHPUnit: extension allow-list enforced (allowed extension OK, disallowed extension HTTP 400)
- [ ] 6.3 PHPUnit: existing file overwritten and returned `fileId` matches the existing entry
- [ ] 6.4 PHPUnit: raw exception messages NOT leaked to caller
- [ ] 6.5 Vitest: three click branches; admin-mode suppression; disabled-while-in-flight
- [ ] 6.6 Vitest: internal action registry warn-on-miss; register/invoke happy path
- [ ] 6.7 Vitest: form validation requires label + url; placeholder swaps with actionType
- [ ] 6.8 Playwright: createFile flow end-to-end (modal opens → POST → opens Files tab)
- [ ] 6.9 Playwright: external link opens in a `_blank` tab

## 7. Quality

- [ ] 7.1 `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan)
- [ ] 7.2 ESLint clean
- [ ] 7.3 OpenAPI spec updated for `POST /api/files/create`
- [ ] 7.4 Translation entries added in `l10n/en.js` and `l10n/nl.js`: `Link Button`, `Action Type`, `External Link`, `Internal Function`, `Create File`, `Background Color`, `Text Color`, `Upload Icon (optional)`, `Create Document`, `File Name`, `Enter filename`, `Cancel`, `Create`, `Creating…`, `Failed to create document`, `Please enter a file name`
