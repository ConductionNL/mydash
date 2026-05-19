# Tasks — link-button-widget

## Tasks

- [ ] Task 1: Add `lib/Service/FileService::createFile(userId, filename, dir, content)` with strict filename validation (`^[a-zA-Z0-9_\-. ]+$`, ≤255 chars, no `..`/`/`/`\`/null byte), dir validation (no `..`/null), and admin-configured extension allow-list (default `txt, md, docx, xlsx, csv, odt`)
- [ ] Task 2: `FileService::createFile` resolves the user folder via `IRootFolder::getUserFolder`, creates subdirectories on demand, overwrites existing files, and returns `{status, fileId, url}` using `URLGenerator::linkToRouteAbsolute('files.view.index', ['openfile' => $fileId])`; raw exception messages are NEVER returned to the caller
- [ ] Task 3: Expose `POST /api/files/create` via `lib/Controller/FileController::createFile`, registered in `appinfo/routes.php`
- [ ] Task 4: Build `src/components/Widgets/Renderers/LinkButtonWidget.vue` with three click branches per `actionType` (`external`/`internal`/`createFile`), all click handlers suppressed in admin/edit mode, `disabled` while `isExecuting`, and hover lift (translate-up 2px + soft drop shadow)
- [ ] Task 5: Renderer mounts an inline `createFile` modal (filename prompt + Cancel/Create), resolves icons via the shared `IconRenderer` (MDI name OR custom URL), and falls back to `var(--color-primary)` / `var(--color-primary-text)` when colours are empty
- [ ] Task 6: Add `src/composables/useInternalActions.js` exposing `register(id, fn)` / `invoke(id)` / `has(id)` backed by a singleton module-level `Map`; `invoke` warns (`console.warn`) on missing ids and never throws
- [ ] Task 7: Build `src/components/Widgets/Forms/LinkButtonForm.vue` with all six fields, `url` placeholder swapping by `actionType` (`https://...` / `action-id` / `docx`), `validate()` requiring both `label` AND `url`, and pre-fill from `editingWidget.content` when editing
- [ ] Task 8: Register `link` in `src/constants/widgetRegistry.js` with defaults `{label:'', url:'', icon:'', actionType:'external', backgroundColor:'', textColor:''}`
- [ ] Task 9: PHPUnit coverage — filename validation (traversal/special chars/oversize), extension allow-list (allowed 200, disallowed 400), overwrite returns existing `fileId`, no raw exception leakage
- [ ] Task 10: Vitest coverage — three click branches; admin-mode suppression; disabled-while-in-flight; internal-action registry warn-on-miss + register/invoke happy path; form validation + placeholder swap
- [ ] Task 11: Playwright — createFile flow end-to-end (modal opens → POST → opens Files tab); external link opens in `_blank` tab
- [ ] Task 12: Quality gates — `composer check:strict`, ESLint clean, OpenAPI updated for `POST /api/files/create`, `nl`+`en` translations for all new UI strings (Link Button, Action Type, External Link, Internal Function, Create File, Background Color, Text Color, Upload Icon (optional), Create Document, File Name, Enter filename, Cancel, Create, Creating…, Failed to create document, Please enter a file name)

## Verification

`openspec validate` exits clean. Renderer behaves identically in admin vs view mode; createFile flow round-trips to the Files app.

## Tests (company-wide ADR-009)

PHPUnit, Vitest, and Playwright per Tasks 9–11. Newman/Postman updated for `POST /api/files/create`.

## Documentation (company-wide ADR-010)

Changelog entry covering the new widget type and the file-creation endpoint; user-guide screenshot of the widget configuration form.

## i18n (company-wide ADR-005)

`nl_NL` + `en_US` for all UI strings listed in Task 12.
