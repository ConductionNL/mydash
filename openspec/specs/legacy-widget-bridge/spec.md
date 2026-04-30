---
status: implemented
retrofit: true
---

# Legacy Widget Bridge Specification

## Purpose

MyDash's grid can render widgets from two eras of the Nextcloud widget API: modern widgets that implement `IAPIWidget` / `IAPIWidgetV2` (covered by the [widgets](../widgets/spec.md) capability), and legacy widgets that use the older callback-registration pattern by calling `window.OCA.Dashboard.register(appId, callback)` at bootstrap. This capability covers the client-side bridge that captures those legacy registrations so MyDash can mount them into the grid on demand.

The bridge is purely a compatibility layer — it does not handle discovery (widgets capability), placement (widget placements in the widgets capability), visibility (conditional-visibility capability), or item loading (REQ-WDG-002). It only intercepts, stores, and replays a very specific global-callback API that third-party Nextcloud apps relied on before the v2 API existed.

## Data Model

The bridge holds two in-memory maps on a singleton:

- **widgetCallbacks**: `Map<appId: string, callback: (container: HTMLElement, meta: { widget: object }) => void>` — callbacks captured from `window.OCA.Dashboard.register`
- **statusCallbacks**: `Map<appId: string, callback: (container: HTMLElement) => void>` — callbacks captured from `window.OCA.Dashboard.registerStatus`

Both maps persist for the lifetime of the page. Registration is additive — re-registering an appId overwrites the previous callback.

## Requirements

### REQ-LWB-001: Intercept legacy widget registration at bootstrap

The system MUST intercept calls to `window.OCA.Dashboard.register` and `window.OCA.Dashboard.registerStatus` made by legacy Nextcloud widgets so that their render callbacks can be captured for later mounting by MyDash. Interception is installed once when the bridge singleton is constructed. The system MUST preserve any previously installed `register` / `registerStatus` implementation so that Nextcloud code that depends on the original registrar continues to work.

#### Scenario: Capture a regular widget callback

- GIVEN a fresh page load where the MyDash bridge singleton has just been constructed
- WHEN a legacy widget app calls `window.OCA.Dashboard.register("notes", callback)`
- THEN the system MUST store the `("notes", callback)` pair in the internal widget-callback map
- AND the system MUST invoke any previously installed `window.OCA.Dashboard.register` handler with the same arguments

#### Scenario: Capture a status-widget callback

- GIVEN the bridge singleton has been constructed
- WHEN a legacy app calls `window.OCA.Dashboard.registerStatus("user_status", callback)`
- THEN the system MUST store the `("user_status", callback)` pair in a separate status-callback map
- AND the system MUST invoke any previously installed `registerStatus` handler

#### Scenario: Initialise OCA namespace when missing

- GIVEN `window.OCA` or `window.OCA.Dashboard` does not yet exist at construction time
- WHEN the bridge singleton is constructed
- THEN the system MUST create the `OCA` and `OCA.Dashboard` objects before installing the `register` / `registerStatus` overrides
- AND subsequent legacy registrations MUST still be captured correctly

### REQ-LWB-002: Mount a captured legacy widget into a DOM container

The system MUST provide a way for MyDash to render a legacy widget by invoking its captured callback against a DOM container element. Widgets that did not register a callback MUST NOT silently mount; a diagnostic warning MUST be emitted so missing registrations surface during development. Errors raised by a widget's callback MUST NOT propagate — a single broken legacy widget MUST NOT prevent the rest of the dashboard from rendering.

#### Scenario: Mount a registered widget

- GIVEN the `notes` widget has registered a callback via REQ-LWB-001
- WHEN MyDash calls `mountWidget("notes", containerEl, widgetMetadata)`
- THEN the system MUST clear the container's existing content before mounting
- AND the system MUST invoke the captured callback with arguments `(containerEl, { widget: widgetMetadata })`
- AND the callback is responsible for painting the container

#### Scenario: Mount request for an unregistered widget

- GIVEN no callback has been registered for widgetId `"unknown"`
- WHEN MyDash calls `mountWidget("unknown", containerEl)`
- THEN the system MUST NOT clear the container
- AND the system MUST emit a diagnostic `console.warn` naming the missing widgetId

#### Scenario: Registered callback throws during rendering

- GIVEN a registered callback for `"broken"` throws on invocation
- WHEN MyDash calls `mountWidget("broken", containerEl)`
- THEN the system MUST catch the error and log it together with the widgetId
- AND the error MUST NOT propagate to the caller

### REQ-LWB-003: Mount a captured legacy status widget

The system MUST provide a way to render a legacy status widget. Status widgets differ from regular widgets in their callback signature: they receive only the container element, with no metadata argument.

#### Scenario: Mount a registered status widget

- GIVEN a status-widget callback has been registered for `"user_status"` via REQ-LWB-001
- WHEN MyDash calls `mountStatusWidget("user_status", containerEl)`
- THEN the system MUST clear the container
- AND the system MUST invoke the callback as `callback(containerEl)` with a single argument

#### Scenario: Mount request for an unregistered status widget

- GIVEN no callback has been registered for a status widgetId
- WHEN MyDash calls `mountStatusWidget` with that id
- THEN the system MUST NOT clear the container
- AND the system MUST NOT emit a diagnostic warning

#### Scenario: Status-widget callback throws during rendering

- GIVEN a registered status-widget callback throws on invocation
- WHEN MyDash calls `mountStatusWidget` for that widget
- THEN the system MUST catch the error and log it together with the widgetId
- AND the error MUST NOT propagate

**Notes**: The missing-callback behaviour differs between `mountWidget` (warn) and `mountStatusWidget` (silent). This is observed, not obviously intentional — status widgets may legitimately be absent on dashboards that do not request them, while regular widgets are usually explicit. Flagged for future REQ tightening once a call-site audit confirms the rationale.

### REQ-LWB-004: Query widget-bridge registration state

The system MUST expose a way for MyDash render logic to query which widget callbacks have been captured so that it can choose between the legacy mounting path and the modern v2 rendering path without attempting a mount first.

#### Scenario: Check whether a specific widget is registered

- GIVEN a callback has been registered for `"notes"` and not for `"weather_status"`
- WHEN MyDash calls `hasWidgetCallback("notes")`
- THEN the system MUST return `true`
- WHEN MyDash calls `hasWidgetCallback("weather_status")`
- THEN the system MUST return `false`

#### Scenario: Enumerate all registered widget IDs

- GIVEN callbacks have been registered for three widgets (`"notes"`, `"activity"`, `"recommendations"`) in that order
- WHEN MyDash calls `getRegisteredWidgetIds()`
- THEN the system MUST return an array containing exactly those three appIds
- AND the order of appIds MUST reflect registration order (Map iteration order)

**Notes**: `hasWidgetCallback` and `getRegisteredWidgetIds` are combined into a single REQ because they expose the same read-only inspection capability over the widget-callback map. Status callbacks are not exposed via either query — the bridge does not currently need introspection on the status map. Noted for future REQ extension if that need arises.
