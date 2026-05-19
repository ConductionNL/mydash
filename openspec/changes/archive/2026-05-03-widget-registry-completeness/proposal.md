# Widget registry completeness

## Why

After `unified-add-widget-flow` collapses tile creation into the widget registry, AND after `nc-dashboard-widget-proxy` adds `nc-widget` as a registry type, every "addable" placement type lives in `widgetRegistry.js`. But the unified Add Custom Widget picker only surfaces types whose `form` component is non-null (per REQ-WDG-014's `listWidgetTypes` filter). Two types are at risk of silently disappearing:

1. **`video`** — registered in wave2's `video-widget` capability with both renderer and form. SHOULD already appear, but a registry-bug regression would silently hide it.
2. **`tile`** — added by `unified-add-widget-flow` as REQ-WDG-018. New addition; needs explicit verification it appears in the picker.

Plus a subtler issue: when capabilities ship across many parallel branches (as in wave2), the `widgetRegistry.js` file is a heavy merge-conflict site. A test that asserts the EXACT set of registered types catches accidental dropouts.

## What Changes

- Add a Vitest test `src/constants/__tests__/widgetRegistry.completeness.spec.js` that asserts the registry contains the canonical full set of types: `label`, `text`, `image`, `link`, `nc-widget`, `tile`, `video`, plus any other widget capabilities shipped between now and the test running. The test reads a `EXPECTED_TYPES` constant; any addition/removal of widget types MUST update this constant in the same commit (enforced by the test itself failing if the registry diverges).
- Verify the `listWidgetTypes(t)` filter still surfaces all of these types (i.e., each entry's `form` component is non-null and importable).
- Document the registry-as-merge-hot-zone concern in `DEVELOPMENT.md` so future widget-PR authors know to update `EXPECTED_TYPES`.

This change introduces no production behaviour; it's a CI guard. But it directly answers the user's "video and tile are missing" concern by making any future regression a test failure instead of a silent UX bug.

## Capabilities

### Modified Capabilities

- `widgets` — REQ-WDG-014 (single-source-of-truth registry) gains a verifiable acceptance criterion (the completeness test).

## Impact

**Affected code:**

- `src/constants/__tests__/widgetRegistry.completeness.spec.js` — new
- `DEVELOPMENT.md` — small note added

**Affected APIs:** none.

**Dependencies:**

- Soft-depends on `unified-add-widget-flow` for the `tile` registry entry.

**Migration:** none.
