# Retrofit — legacy-widget-bridge

Describes observed behavior of 5 methods on the `WidgetBridge` singleton as 4 new REQs in a new capability. Code already exists (`src/services/widgetBridge.js`) — this change retroactively specifies it so the existing behaviour is reviewable against a spec and so future changes to the bridge have a baseline to amend.

## Affected code units

- `src/services/widgetBridge.js::interceptRegistration` (construction-time side effect)
- `src/services/widgetBridge.js::mountWidget`
- `src/services/widgetBridge.js::mountStatusWidget`
- `src/services/widgetBridge.js::hasWidgetCallback`
- `src/services/widgetBridge.js::getRegisteredWidgetIds`

Consumed from `src/components/WidgetRenderer.vue` (lines around the `widgetBridge.hasWidgetCallback` + `widgetBridge.mountWidget` call). The bridge has no other consumers; the singleton is instantiated at module load and self-installs by monkey-patching `window.OCA.Dashboard`.

## Why a new capability (`--cluster`) rather than extending `widgets`

The `widgets` capability (REQ-WDG-001..010) covers widget *discovery*, *placement CRUD*, and *item loading* for modern v1/v2 Nextcloud widgets via `OCP\Dashboard\IManager`. The bridge is a different concern: it is a purely client-side compatibility layer for the *older* `window.OCA.Dashboard.register(appId, callback)` pattern that predates the `IAPIWidget` interface. It does not touch the widget API in any of its existing dimensions — no PHP, no placements, no items, no visibility. Folding it into `widgets` would conflate two unrelated widget eras and make the capability harder to reason about.

A separate capability also makes the retrofit marker (`retrofit: true`) honest: every REQ in this capability describes *existing legacy-compat code*, so a reader can tell at a glance that this capability is not aspirational.

## Approach

- For each of the 5 methods in `WidgetBridge`: describe observed inputs, outputs, preconditions, postconditions, failure modes
- Draft REQs that match observed behavior — do not silently "fix" the warn-vs-silent asymmetry between `mountWidget` and `mountStatusWidget` (flagged in REQ-LWB-003 Notes)
- Combine `hasWidgetCallback` + `getRegisteredWidgetIds` into one REQ (REQ-LWB-004) because they're the same inspection capability over the same map
- Combine `register` and `registerStatus` intercept behavior into one REQ (REQ-LWB-001) — they share identical bootstrap semantics (namespace init, override install, passthrough to prior registrar)
- Annotate each method's docblock with `@spec openspec/changes/retrofit-legacy-widget-bridge-2026-04-24/tasks.md#task-N`

## Notes

- `interceptRegistration` is called only from the constructor, so it is a construction-time side effect rather than a public API. It is still covered by its own REQ (LWB-001) because the observable behavior (monkey-patching `window.OCA.Dashboard`) is the capability's core contract, not something tucked away in the constructor.
- Private helpers: the class has none — all observable behavior is on the 5 listed methods.
- No other consumers: `WidgetRenderer.vue` is the only caller. If a second consumer is added in future, REQ-LWB-004 may want to extend the introspection API to status callbacks too.

Source: `openspec/coverage-report.md` generated 2026-04-24. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
