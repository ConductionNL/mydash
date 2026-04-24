# Design — retrofit-legacy-widget-bridge

**Retrofit change. Tasks describe retroactive annotation, not new implementation work.**

## Context

MyDash's dashboard grid renders widgets from two Nextcloud widget eras. Modern widgets implement `IAPIWidget` / `IAPIWidgetV2` and are covered by the existing `widgets` capability (discovery via `OCP\Dashboard\IManager`, placement CRUD, item loading). Legacy widgets predate that interface and instead call `window.OCA.Dashboard.register(appId, callback)` from their own bootstrap script. To render a legacy widget MyDash has to capture its callback at registration time and invoke it later against a DOM container.

`src/services/widgetBridge.js` does exactly this: it monkey-patches `window.OCA.Dashboard.register` and `registerStatus` on construction, stores captured callbacks in two Maps, and exposes `mountWidget` / `mountStatusWidget` / `hasWidgetCallback` / `getRegisteredWidgetIds` for `WidgetRenderer.vue` to use when it decides which rendering path to take.

The code has existed for the lifetime of MyDash without a spec. This retrofit pins the observed behaviour as four REQs under a new `legacy-widget-bridge` capability so future changes to the bridge amend a baseline rather than rediscovering it.

## Approach — what was written

- New capability: `legacy-widget-bridge` (4 REQs, 5 methods tagged)
- Delta in `openspec/changes/retrofit-legacy-widget-bridge-2026-04-24/specs/legacy-widget-bridge/spec.md` with `retrofit: true` frontmatter marker
- `tasks.md` with four `[x]` tasks — one per REQ, each marked complete because code already exists
- Annotations in `src/services/widgetBridge.js` using the `@spec openspec/changes/.../tasks.md#task-N` convention (ADR-003 §Spec traceability)
- `WidgetRenderer.vue` is not re-annotated here — it's a consumer, not part of this cluster. Its own retrofit would fall under the `widgets` capability.

## Deliberate judgement calls

- **`mountWidget` vs `mountStatusWidget` get separate REQs** (LWB-002 and LWB-003) even though their happy-path code is nearly identical. Justification: the missing-callback behaviour is observably different — `mountWidget` warns, `mountStatusWidget` stays silent. That divergence is the kind of thing a spec has to pin down; collapsing the two REQs would erase it.
- **`hasWidgetCallback` and `getRegisteredWidgetIds` share one REQ** (LWB-004). Justification: they are both read-only queries over the same `widgetCallbacks` map; splitting them would be classic REQ inflation. The two scenarios inside LWB-004 cover the two entry points.
- **`interceptRegistration` is covered by a REQ** (LWB-001) despite being called only from the constructor. Justification: the observable behaviour (monkey-patching a global) *is* the capability's core contract. Hiding it inside "construction boilerplate" would misrepresent what the bridge does.
- **Warn-vs-silent asymmetry surfaced, not fixed.** REQ-LWB-003's Notes flag the asymmetry explicitly. Observed, not aspirational — a future change can tighten or unify the behaviour, but that decision is out of scope for a retrofit.

## Archive behaviour

On `/opsx-archive retrofit-legacy-widget-bridge-2026-04-24`:

- `specs/legacy-widget-bridge/spec.md` becomes `openspec/specs/legacy-widget-bridge/spec.md` (new capability)
- `retrofit: true` frontmatter marker survives the merge (Specter sync reads it to flag the retrofit cohort)
- The change directory moves under `openspec/changes/archive/`

No other capabilities are affected — no cross-capability REQ references in this delta.
