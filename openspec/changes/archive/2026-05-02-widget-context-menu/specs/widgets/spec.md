---
capability: widgets
delta: true
status: draft
---

# Widgets — Delta from change `widget-context-menu`

## ADDED Requirements

### Requirement: REQ-WDG-015 Right-click context menu in edit mode

When the user is in edit mode (per REQ-SHELL-002 `canEdit === true`), right-clicking any widget placement on the grid MUST open a small popover at the cursor position offering at least these three actions: **Edit**, **Remove**, **Cancel**. The popover MUST suppress the browser's native context menu via `event.preventDefault()`. In view mode the right-click MUST fall through to native behaviour (no popover).

#### Scenario: Right-click in edit mode opens popover

- GIVEN `canEdit === true` and a widget placement is rendered at coordinates (300, 400) on screen
- WHEN the user right-clicks anywhere within that widget's content area
- THEN the system MUST emit a popover at the click position (300, 400)
- AND the popover MUST contain three buttons: `t('Edit')`, `t('Remove')`, `t('Cancel')`
- AND the browser's native context menu MUST NOT appear

#### Scenario: Right-click in view mode does nothing

- GIVEN `canEdit === false`
- WHEN the user right-clicks any widget
- THEN the popover MUST NOT open
- AND the browser's native context menu MUST appear normally

#### Scenario: Edit click opens the add/edit modal

- GIVEN the popover is open for widget `W`
- WHEN the user clicks `Edit`
- THEN the system MUST close the popover
- AND MUST open the add/edit modal (REQ-WDG-010) with `editingWidget = W`

#### Scenario: Remove click deletes the placement

- GIVEN the popover is open for widget `W`
- WHEN the user clicks `Remove`
- THEN the system MUST close the popover
- AND MUST trigger the placement deletion path of REQ-WDG-005 (DELETE `/api/placements/{id}`)
- AND on success MUST remove the widget's DOM via GridStack `removeWidget`

#### Scenario: Cancel click closes without action

- GIVEN the popover is open
- WHEN the user clicks `Cancel`
- THEN the popover MUST close
- AND no API call MUST fire
- AND no widget state MUST change

### Requirement: REQ-WDG-016 Auto-close on outside interaction

The popover MUST close when the user clicks anywhere outside its bounding box (including on another widget). Right-clicking a different widget while the popover is open MUST close the current popover and open a new one at the new cursor position. Closing on outside click MUST be wired via a single document-level listener that the grid composable manages on mount/unmount.

#### Scenario: Click outside closes

- GIVEN the popover is open
- WHEN the user clicks anywhere not inside `.widget-context-menu`
- THEN the popover MUST close

#### Scenario: Right-click another widget switches popover

- GIVEN the popover is open for widget `W1`
- WHEN the user right-clicks widget `W2`
- THEN the popover MUST close for `W1` and reopen for `W2` at the new cursor position
- AND only one popover MUST be visible at a time

#### Scenario: Listener cleanup on unmount

- GIVEN the workspace shell unmounts
- WHEN the unmount lifecycle runs
- THEN the document-level `click` listener MUST be removed
- AND no popover state MUST leak into a subsequent mount

### Requirement: REQ-WDG-017 Position constraints

The popover MUST be absolutely positioned at the click coordinates with `min-width: 150px`. If the popover would overflow the viewport on the right or bottom edge, the system SHOULD shift it left/up so it remains fully visible. Z-index MUST be `10000` (above grid, level with modals — popover-then-modal interaction is acceptable since clicking a popover item closes it before the modal opens).

#### Scenario: Popover stays within viewport on right edge

- GIVEN the user right-clicks at `(viewportWidth - 50, 200)` (50 px from right edge)
- AND the popover's `min-width` is 150 px
- WHEN the popover renders
- THEN its `right` edge MUST NOT exceed `viewportWidth`
- AND its rendered `left` MUST be adjusted to keep it on-screen

#### Scenario: Popover stays within viewport on bottom edge

- GIVEN the user right-clicks at `(400, viewportHeight - 20)` (20 px from bottom edge)
- WHEN the popover renders
- THEN its `bottom` edge MUST NOT exceed `viewportHeight`
- AND its rendered `top` MUST be adjusted upward so the popover is fully visible

#### Scenario: Z-index sits at 10000

- GIVEN the popover is open over a widget
- WHEN computed styles are inspected
- THEN the popover element MUST have `z-index: 10000`
- AND MUST have `min-width: 150px`
