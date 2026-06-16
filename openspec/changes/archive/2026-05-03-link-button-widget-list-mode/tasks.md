# Tasks — link-button-widget-list-mode

## 1. Domain model

- [ ] 1.1 Add `displayMode` field to `WidgetPlacement` entity with getter/setter; default `'button'`
- [ ] 1.2 Add `links` field to `WidgetPlacement` entity (JSON, nullable) with getter/setter
- [ ] 1.3 Add `listOrientation` field to `WidgetPlacement` entity; default `'vertical'`
- [ ] 1.4 Add `listItemGap` field to `WidgetPlacement` entity; default `'normal'`
- [ ] 1.5 Update `WidgetPlacement::jsonSerialize()` to include all four new fields (with null checks for backward compat)

## 2. Service validation layer

- [ ] 2.1 In `PlacementService::updatePlacement()`, add validation: when `displayMode = 'list'`, the `links` field MUST be a non-empty array
- [ ] 2.2 Add validation: when `displayMode = 'button'`, `links` MAY be empty, null, or have one entry
- [ ] 2.3 Return HTTP 400 with error message `'At least one link is required for list mode'` if validation fails
- [ ] 2.4 Each link in the `links` array MUST have non-empty `label` and `url` fields; return HTTP 400 if either is missing
- [ ] 2.5 Add fixture-based PHPUnit test covering: button mode with empty links, button mode with one link, list mode with multiple links, list mode with zero links (error case)

## 3. Frontend — renderer component

- [ ] 3.1 In `LinkButtonWidget.vue`, detect `displayMode` from the placement config
- [ ] 3.2 When `displayMode = 'button'` or `displayMode` is missing, render the existing single-button layout (no changes to existing code path)
- [ ] 3.3 When `displayMode = 'list'`, render a list container:
  - Vertical: `<ul role="list">` with `<li>` items, `display: flex; flex-direction: column`
  - Horizontal: `<div role="list">` with `<div role="listitem">` items, `display: flex; flex-wrap: wrap`
- [ ] 3.4 For each link in the `links` array, render a clickable item with:
  - Icon (24 px, left of label, or omitted if empty)
  - Label (left-aligned text)
  - Click handler dispatching to the appropriate action type (URL, action_id, or createFile per REQ-LBN-001)
  - Styling: background color (from link entry or theme default), text color (from link entry or theme default)
  - Hover effect: translate up 2 px, add soft drop shadow (matching single-button)
- [ ] 3.5 Apply `listItemGap` spacing via CSS `gap` property (compact 0.5rem, normal 1rem, spacious 1.5rem)
- [ ] 3.6 Suppress all click handlers when in edit mode (`isAdmin === true` AND dashboard `canEdit === true`)
- [ ] 3.7 Reuse existing icon-resolution logic (REQ-LBN-002 + REQ-LBLM-004): URL-based icons render as `<img>`, name-based render via `IconRenderer`, empty/null render as no icon
- [ ] 3.8 Add unit test: vertical list with 3 links renders correct HTML structure
- [ ] 3.9 Add unit test: horizontal list with 2 links renders correct HTML structure
- [ ] 3.10 Add unit test: click handlers dispatch correctly for external URL, action_id, and createFile action types
- [ ] 3.11 Add unit test: click is suppressed in edit mode

## 4. Frontend — edit form component

- [ ] 4.1 In `LinkButtonEditForm.vue` (or a wrapper/modal), add a "Display mode" toggle/select with options `'button'` and `'list'`
- [ ] 4.2 When `displayMode = 'button'` (or missing), show the existing single-link form (label, url, actionType, icon, backgroundColor, textColor)
- [ ] 4.3 When `displayMode = 'list'`, show a list editor UI with:
  - A table/list of existing links with columns: [icon/label, drag handle, edit button, remove button]
  - An "Add link" button to append a new empty entry
- [ ] 4.4 For each link in the list editor, "Edit" button opens a modal with the existing single-link form pre-populated with that link's values
- [ ] 4.5 Implement drag-to-reorder for list items (reorder the `links` array based on user drag)
- [ ] 4.6 "Remove" button deletes a link from the array (splice at index)
- [ ] 4.7 Add form validation: when `displayMode = 'list'`, prevent saving if `links` is empty; show error `'At least one link is required'`
- [ ] 4.8 Add form validation: each link MUST have non-empty `label` and `url`; highlight the offending row if validation fails
- [ ] 4.9 When editing a placement in legacy format (no `displayMode` or `links`), pre-populate the single-link form from the legacy fields
- [ ] 4.10 When user toggles `displayMode` from `'button'` to `'list'` in an existing placement, auto-populate the first `links` entry from the legacy single-link fields (if present)
- [ ] 4.11 Add integration test: user creates a button-mode placement, edits it to switch to list mode with 2 links, saves, and the placement stores correctly
- [ ] 4.12 Add integration test: user creates a list-mode placement with 3 links, drags one to reorder, removes one, adds another, and the final array is correct

## 5. Frontend — store integration

- [ ] 5.1 Verify the placement store (`src/stores/placements.js` or wherever placements are tracked) serialises the new fields correctly
- [ ] 5.2 If the store manually reconstructs placement objects, update the reconstruction logic to include `displayMode`, `links`, `listOrientation`, `listItemGap`
- [ ] 5.3 No schema change to the store state; the new fields are already included in the placement object from the API

## 6. PHPUnit tests

- [ ] 6.1 `PlacementServiceTest::testListModeRequiresNonEmptyLinks` — list mode with empty links array returns error
- [ ] 6.2 `PlacementServiceTest::testListModeWithValidLinks` — list mode with multiple valid link entries saves correctly
- [ ] 6.3 `PlacementServiceTest::testButtonModeBackwardCompat` — button mode placement without `displayMode` field is treated as button mode
- [ ] 6.4 `PlacementServiceTest::testEachLinkRequiresLabelAndUrl` — link entry missing `label` or `url` returns error
- [ ] 6.5 Test all 3 `listOrientation` values (vertical, horizontal, missing/default)
- [ ] 6.6 Test all 3 `listItemGap` values (compact, normal, spacious, missing/default)

## 7. E2E Playwright tests

- [ ] 7.1 Create a link-button-widget placement in button mode, verify it renders a single button
- [ ] 7.2 Edit the placement to switch to list mode with 2 links, verify the list renders vertically with both links
- [ ] 7.3 Change `listOrientation` to horizontal, verify the list renders inline
- [ ] 7.4 Change `listItemGap` to spacious, verify the spacing increases (visual or DOM inspection)
- [ ] 7.5 Click a link with `actionType: 'url'`, verify it opens in a new tab (mock `window.open`)
- [ ] 7.6 Click a link with `actionType: 'action_id'`, verify the registered action is invoked
- [ ] 7.7 Click a link with `actionType: 'createFile'`, verify the modal opens and file is created
- [ ] 7.8 In edit mode, click a list item, verify no action fires (edit-mode suppression)
- [ ] 7.9 Verify a pre-list-mode placement (legacy format) still renders with legacy fields

## 8. Quality gates

- [ ] 8.1 `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan)
- [ ] 8.2 ESLint + Stylelint clean on all Vue/JS files
- [ ] 8.3 Test coverage for new code ≥ 75% (PHPUnit + Playwright combined)
- [ ] 8.4 i18n keys added for all user-facing strings (error messages, button labels, field labels) in both `nl` and `en`
- [ ] 8.5 SPDX-License-Identifier header added to any new PHP files (inside the docblock per convention)
- [ ] 8.6 Update OpenAPI spec / Postman collection if the placement schema is documented there
- [ ] 8.7 Run all hydra-gates locally before opening PR

## 9. Documentation

- [ ] 9.1 Update the link-button-widget capability documentation (or parent `widgets` docs) to reference the new list-mode capability
- [ ] 9.2 Add a note in the changelog: "Link-button widget now supports a 'list' display mode for rendering multiple links in a single widget placement"
- [ ] 9.3 If there is an admin guide or widget configuration guide, add a section explaining how to configure list mode and the available options (orientation, spacing)
