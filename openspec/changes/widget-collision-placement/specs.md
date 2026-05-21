# Specifications — Widget Collision Placement

## REQ-GRID-006 (Modified): Widget Auto-Layout

**Title:** Widget auto-layout with fallback push-down strategy

**Description:** When a new widget is added to a dashboard, the system attempts to place it in the first available empty rectangle that fits. If auto-positioning fails or places the widget below the visible viewport, the system falls back to placing the widget at the top-left corner `(0, 0)` and pushing overlapping existing widgets down.

**Acceptance Criteria:**

**Scenario 6.1:** Auto-position succeeds with empty space
```gherkin
Given a dashboard with GridStack configured for a 12-column layout
  And 3 existing widgets at positions {(x:0, y:0), (x:4, y:0), (x:8, y:0)} each 4 cells wide by 4 cells tall
  And a viewport height of 8 rows
When a new widget with dimensions w=4, h=4 is added via placeNewWidget({w:4, h:4})
Then the widget is placed at position (x:0, y:4) (first free vertical slot)
  And no existing widget is moved
  And the returned position object is {x:0, y:4, w:4, h:4}
```

**Scenario 6.2:** Auto-position would place widget below viewport
```gherkin
Given a dashboard with GridStack 12-column layout at 720p (viewportRows = 6)
  And the dashboard is densely packed: widgets occupy (x:0-12, y:0-5)
When a new widget with dimensions w=4, h=4 is added via placeNewWidget({w:4, h:4})
  And GridStack's autoPosition would return a slot at (x:0, y:6) — below the visible fold
Then the system triggers the push-down fallback
  And the new widget is placed at (x:0, y:0)
  And all widgets overlapping [0..4] × [0..4] are pushed to gridY=4
  And the returned position object is {x:0, y:0, w:4, h:4}
```

**Scenario 6.3:** Push-down preserves non-overlapping widgets
```gherkin
Given a dashboard with GridStack 12-column layout
  And widgets at {(x:0, y:0, w:4, h:3), (x:4, y:0, w:4, h:3), (x:0, y:4, w:4, h:3)}
When a new widget w=4, h=4 is added via placeNewWidget({w:4, h:4})
  And the push-down fallback is triggered
Then the new widget is placed at (x:0, y:0)
  And the first two widgets (non-overlapping on the y-axis, x:0-4 and x:4-8) remain at y=0
  And the widget at (x:0, y:4) is pushed to (x:0, y:4) unchanged (no y-overlap with [0..4])
  And only widgets truly overlapping [0..4] × [0..4] are affected
```

**Scenario 6.4:** Default size applied when dimensions omitted
```gherkin
Given a dashboard with GridStack 12-column layout
  And empty space available
When a new widget is added via placeNewWidget({}) (w and h omitted)
Then the widget is placed with default dimensions w=4, h=4
  And the returned position object includes w:4, h:4
```

**Scenario 6.5:** Position persistence is triggered after placement
```gherkin
Given a dashboard with GridStack 12-column layout
  And the persistence batch service is configured with 300ms debounce (REQ-WDG-008)
When a new widget with dimensions w=4, h=4 is added via placeNewWidget({w:4, h:4})
  And the fallback push-down shifts 2 existing widgets
Then within 300ms, a batch update request is dispatched containing:
  - The new widget with its placed position and dimensions
  - All pushed-down widgets with their new gridY values
  And the persistence round-trip resolves successfully
  And the in-memory layout.value matches the persisted positions
```

## REQ-GRID-014 (New): Placement Helper Single Authority

**Title:** Unified widget placement entry point

**Description:** All add-widget code paths (toolbar dropdown, keyboard shortcut, drag-from-picker, modal submit) MUST use the `placeNewWidget(spec)` helper exported from `useGridManager.js`. Direct calls to `grid.addWidget(...)` outside the composable are forbidden and enforced by automated testing.

**Acceptance Criteria:**

**Scenario 14.1:** AddWidgetModal routes through placeNewWidget
```gherkin
Given AddWidgetModal.vue with a submit handler
When the user submits the "Add Widget" form with a widget spec
Then the submit handler calls placeNewWidget(spec)
  And the function returns {x, y, w, h}
  And the handler updates the local layout with the returned position
  And no direct grid.addWidget() call appears in the handler
```

**Scenario 14.2:** Toolbar dropdown uses unified helper
```gherkin
Given a toolbar button "Add Widget" that triggers a dropdown menu
When the user selects a widget type from the dropdown
Then the selection handler calls placeNewWidget({type: selectedType, ...})
  And no direct grid.addWidget() invocation exists in the dropdown handler
```

**Scenario 14.3:** Keyboard shortcut uses unified helper
```gherkin
Given a keyboard shortcut (e.g., Ctrl+W) bound to "Add Widget"
When the user presses the shortcut
Then the event handler calls placeNewWidget(defaultSpec)
  And no direct grid.addWidget() invocation exists in the event handler
```

**Scenario 14.4:** Architectural enforcement — grep test blocks non-compliant direct calls
```gherkin
Given the test suite includes a grep-based enforcement test
When the test scans for the pattern grid\.addWidget\(
Then it MUST find matches only in:
  - src/composables/useGridManager.js (the approved implementation)
  - src/composables/useGridManager.test.js (unit test mocking)
  And zero matches in:
  - AddWidgetModal.vue or any other component
  - Toolbar handlers
  - Event listeners
  And the test fails the CI build if any non-compliant matches are found
```

**Scenario 14.5:** Pushed widgets update only gridY, not gridX or gridW
```gherkin
Given a push-down fallback that shifts overlapping widgets
When overlapping widgets are moved to gridY = newH
Then each overlapping widget retains:
  - Its original gridX coordinate (unchanged)
  - Its original gridW width (unchanged)
  - Only its gridY coordinate is modified
  And the widget's dimensions (w, h) remain unchanged
```

## Rationale

- **REQ-GRID-006** extends the existing auto-layout requirement with explicit fallback logic. The algorithm is deterministic: try auto-position in the viewport-visible region first, fall back to top-left + push-down when needed. This prevents silent off-screen placement and centralises the decision logic.

- **REQ-GRID-014** enforces a single authority for placement so the behaviour is testable, auditable, and immune to future ad-hoc placement calls added in new code paths. The grep test is a lightweight but effective enforcement mechanism for the UI layer.

- Push-down is intentionally naive (single-pass, shift by `newH`) to keep the algorithm O(n) and predictable. Optimal bin-packing is rejected in favour of discoverability: users expect new items at the top-left.
