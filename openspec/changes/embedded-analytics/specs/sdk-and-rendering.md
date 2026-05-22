# Spec — JS-SDK and Rendering (REQ-EMB-006)

## REQ-EMB-006 — JS-SDK exposes render, event, and resize APIs

The system SHALL publish a JS-SDK (`@mydash/embed-sdk`, ESM and UMD builds) that exposes `MyDashEmbed.render(container, {token, subjectType, subjectId})`, `.on(eventType, handler)` for filter/drill/export events, and `.resize()` for host-driven layout changes.

### Scenario 6.1 — SDK initialization and iframe creation

GIVEN a host page (e.g., a SaaS customer portal) that imports the SDK:
  ```html
  <script src="https://cdn.example.com/@mydash/embed-sdk@1.0.0/dist/embed-sdk.umd.js"></script>
  <!-- OR ESM: import MyDashEmbed from '@mydash/embed-sdk'; -->
  ```
WHEN the host page calls:
  ```javascript
  const embed = MyDashEmbed.render('#embed-container', {
    token: 'eyJhbGciOiJSUzI1NiIsImtpZCI6InJzYS0xIn0...',
    subjectType: 'widget',
    subjectId: 'usage-widget-uuid'
  });
  ```
THEN the SDK SHALL:
  1. Create an `<iframe>` element with `src="https://mydash.app/apps/mydash/embed/widget/usage-widget-uuid"`
  2. Insert the iframe into the `#embed-container` div
  3. Wait for the iframe's `load` event
  4. Perform a postMessage handshake with the iframe to inject the JWT token
  5. Resolve a promise when the first render completes
  AND the host page code continues:
  ```javascript
  embed.then(() => {
    console.log('Widget loaded');
  });
  ```

### Scenario 6.2 — PostMessage handshake for token injection

GIVEN the SDK has created an iframe
WHEN the iframe loads (fires `load` event)
THEN the SDK SHALL:
  1. Post a message to the iframe:
     ```javascript
     iframe.contentWindow.postMessage({
       type: 'embed-token',
       token: 'eyJhbGciOiJSUzI1NiIsImtpZCI6InJzYS0xIn0...',
       origin: 'https://portal.example.com'
     }, 'https://mydash.app')
     ```
  2. The iframe's origin verification (in the render route) SHALL check that `event.origin === 'https://mydash.app'` before accepting the message
  3. The iframe stores the token in a secure context and uses it for subsequent API requests
  4. The iframe posts back a 'ready' message when the widget is fully rendered:
     ```javascript
     window.parent.postMessage({type: 'embed-ready', success: true}, '<SDK-origin>')
     ```
  5. The SDK resolves the promise returned by `render()` when the 'embed-ready' message arrives

### Scenario 6.3 — Filter event subscription and relay

GIVEN the host page has called `render()` successfully
WHEN the host page subscribes to filter events:
  ```javascript
  embed.on('filterApplied', ({dimension, value, source}) => {
    console.log(`Filter applied: ${dimension} = ${value}`);
    // Host page can now sync its own UI
  });
  ```
AND the end-user clicks on a bar in the embedded chart (drill-down interaction)
THEN the iframe SHALL:
  1. Emit an internal event (in-app bus) with `{dimension: 'gemeente', value: 'Amsterdam'}`
  2. Post a message to the parent:
     ```javascript
     window.parent.postMessage({
       type: 'embed-event',
       eventType: 'filterApplied',
       payload: {dimension: 'gemeente', value: 'Amsterdam', source: 'user-interaction'}
     }, '<SDK-origin>')
     ```
  3. The SDK receives the message and invokes the registered handler
  4. The host page can read the filter and update its own UI/URL

### Scenario 6.4 — Export event (read-with-interactions)

GIVEN an embed with `scope.allowedActions=["export"]`
WHEN the user clicks "Export to CSV" in the widget
THEN the iframe SHALL:
  1. Invoke the export API (allowed by scope)
  2. Post a 'export' event to the parent:
     ```javascript
     window.parent.postMessage({
       type: 'embed-event',
       eventType: 'export',
       payload: {format: 'csv', url: 'https://mydash.app/api/downloads/export-123.csv'}
     }, '<SDK-origin>')
     ```
  3. The host page can download the file or handle it custom (e.g., send to email)

### Scenario 6.5 — Resize API for responsive layout

GIVEN the host page's layout changes (e.g., browser window resized, sidebar collapsed)
WHEN the host page calls:
  ```javascript
  embed.resize();
  ```
THEN the SDK SHALL:
  1. Post a message to the iframe:
     ```javascript
     iframe.contentWindow.postMessage({
       type: 'embed-resize',
       containerWidth: 1200,
       containerHeight: 600
     }, 'https://mydash.app')
     ```
  2. The iframe receives the message and re-measures its container
  3. The iframe re-renders the widget with the new dimensions
  4. The iframe posts back a 'resized' message when complete:
     ```javascript
     window.parent.postMessage({type: 'embed-resized', success: true}, '<SDK-origin>')
     ```
  5. The SDK resolves the promise returned by `resize()`

### Scenario 6.6 — ESM build with TypeScript types

GIVEN a TypeScript host application
WHEN it imports the SDK:
  ```typescript
  import MyDashEmbed from '@mydash/embed-sdk';
  import type { EmbedConfig, EmbedInstance } from '@mydash/embed-sdk';
  
  const config: EmbedConfig = {
    token: 'eyJ...',
    subjectType: 'widget',
    subjectId: 'uuid'
  };
  
  const embed: EmbedInstance = await MyDashEmbed.render('#container', config);
  embed.on('filterApplied', (event) => {
    // event is typed as EmbedFilterEvent
  });
  ```
THEN the IDE SHALL provide autocomplete and type checking
  AND the TypeScript types SHALL document the API surface

### Scenario 6.7 — UMD build for vanilla JS

GIVEN a vanilla JavaScript page (no build step, no module bundler)
WHEN it loads the UMD bundle:
  ```html
  <script src="https://unpkg.com/@mydash/embed-sdk@1.0.0/dist/embed-sdk.umd.js"></script>
  ```
THEN the SDK is available as a global:
  ```javascript
  window.MyDashEmbed.render('#container', {token: '...', ...})
    .then(() => console.log('Ready'));
  ```

### Scenario 6.8 — Error handling in SDK

GIVEN the host page calls `render()` with an invalid token
WHEN the iframe attempts to load the render route
THEN the render route responds 401
  AND the iframe posts an 'error' message to the parent:
  ```javascript
  window.parent.postMessage({
    type: 'embed-error',
    error: 'invalid_token',
    message: 'The provided token is invalid or has expired'
  }, '<SDK-origin>')
  ```
  AND the SDK's promise rejects with a descriptive error:
  ```javascript
  embed.catch((err) => {
    console.error('Failed to load widget:', err.message);
  });
  ```

### Scenario 6.9 — Event listener management

GIVEN the host page has subscribed to events:
  ```javascript
  const handler = (event) => { ... };
  embed.on('filterApplied', handler);
  ```
WHEN the host page wants to unsubscribe:
  ```javascript
  embed.off('filterApplied', handler);
  ```
THEN the event listener SHALL be removed
  AND no further 'filterApplied' events are delivered to that handler
  AND other handlers (if registered) still receive events

### Scenario 6.10 — Multiple embeds on same page

GIVEN a host page with three embed containers:
  ```html
  <div id="embed-1"></div>
  <div id="embed-2"></div>
  <div id="embed-3"></div>
  ```
WHEN the host page initializes all three:
  ```javascript
  const embed1 = MyDashEmbed.render('#embed-1', {token: '...', subjectId: 'widget-1'});
  const embed2 = MyDashEmbed.render('#embed-2', {token: '...', subjectId: 'widget-2'});
  const embed3 = MyDashEmbed.render('#embed-3', {token: '...', subjectId: 'widget-3'});
  ```
THEN each embed operates independently
  AND events from embed1 do not leak to embed2 or embed3
  AND each postMessage handshake is isolated

### Scenario 6.11 — SDK package publishing

GIVEN the MyDash build process
WHEN `npm run build:sdk` executes
THEN the SDK package SHALL be published to npm with:
  - ESM build: `dist/embed-sdk.esm.js`
  - UMD build: `dist/embed-sdk.umd.js`
  - TypeScript definitions: `dist/embed-sdk.d.ts`
  - Minified versions: `dist/embed-sdk.esm.min.js`, `dist/embed-sdk.umd.min.js`
  - Source maps for debugging
  - Package name: `@mydash/embed-sdk`
  - Version matching the MyDash app version
