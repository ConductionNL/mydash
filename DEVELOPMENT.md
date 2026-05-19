# Development

## Prerequisites

- Docker & Docker Compose
- Node.js >= 18
- npm
- A running Nextcloud instance

## Local Development

This app is developed using the [nextcloud-docker-dev](https://github.com/juliushaertl/nextcloud-docker-dev) environment. The app is volume-mounted into the Nextcloud container.

```bash
# Start the development environment
docker compose -f openregister/docker-compose.yml up -d

# Build the frontend
cd mydash
npm install
npm run dev
```

The app will be available at `http://localhost:8080/apps/mydash`.

## Frontend Build

```bash
npm install          # Install dependencies
npm run dev          # Development build (watch mode)
npm run build        # Production build
```

## Product Page

The product page at [mydash.app](https://mydash.app) is built with [Docusaurus 3](https://docusaurus.io/) and deployed via GitHub Pages.

### How it works

- The Docusaurus setup lives in the `docusaurus/` folder
- Documentation content comes from the `docs/` folder at the project root — **not** duplicated inside `docusaurus/`
- The Docusaurus config uses `path: '../docs'` to reference the root docs directly
- Pushing to the `development` branch triggers the GitHub Actions workflow (`.github/workflows/documentation.yml`) which builds and deploys to the `gh-pages` branch
- GitHub Pages serves the built site at `mydash.app` (configured via `static/CNAME`)

### Local preview

```bash
cd docusaurus
npm install
npm start            # Dev server at http://localhost:3000 with hot reload
```

### Adding documentation

Simply add or edit Markdown files in the `docs/` folder. The sidebar is auto-generated from the folder structure. Changes will appear on the product page after pushing to `development`.

## Adding a widget type

The widget registry (`src/constants/widgetRegistry.js`) is the single source of truth for "addable" custom widget types (REQ-WDG-014). Because many widget capabilities ship in parallel branches that all touch the same object, the file is a heavy merge-conflict site -- a regression that silently drops a type would silently disappear from the Add Custom Widget picker without any test signal. To add a new widget type:

- Update `src/constants/widgetRegistry.js` with the new entry (`renderer`, `form`, `defaultContent`, `displayName`, `icon`).
- Update `EXPECTED_TYPES` in `src/constants/__tests__/widgetRegistry.completeness.spec.js` (REQ-WDG-023) so the completeness guard stays in sync. The test will fail with a precise diff if you forget.
- Implement the renderer + form Vue components following the pattern of an existing widget (e.g. `LabelWidget.vue` + `LabelForm.vue`).
- Add i18n keys for the type's `displayName` and any user-facing form labels to all four `l10n/` files (`en.js`, `en.json`, `nl.js`, `nl.json`).
- Add the canonical capability spec under `openspec/specs/<widget-name>/spec.md` so the type's REQ-* identifiers, defaults, and acceptance scenarios are documented.

## Security review checklist

### Extending the SVG sanitiser whitelist

`lib/Service/SvgSanitiser.php` enforces a deliberately conservative whitelist of allowed SVG element and attribute names (REQ-RES-010 / REQ-RES-011 in `openspec/specs/resource-uploads/spec.md`). Every uploaded SVG is parsed via `DOMDocument` with `LIBXML_NONET | LIBXML_NOENT` and the resulting tree is filtered against `ALLOWED_ELEMENTS` and `ALLOWED_ATTRIBUTES`. Anything not on those lists is removed before persistence, and the persisted bytes (NOT the original) are what subsequently get served back to other users' browsers.

If you propose adding a new element name to `ALLOWED_ELEMENTS` or a new attribute to `ALLOWED_ATTRIBUTES`:

- A security review is required before merge (XSS surface change).
- Verify the new element / attribute cannot carry executable payloads in any browser SVG renderer (e.g. `<animate>` `attributeName` injection, `<set>` event triggering, etc.).
- Add a PHPUnit scenario covering the new surface, plus a negative scenario showing that a known-bad construct involving the new name is still rejected.
- Update REQ-RES-010 / REQ-RES-011 in the canonical spec to reflect the new whitelist size and add the element / attribute name explicitly.
- The sanitiser runs server-side BEFORE the size cap (REQ-RES-009), so an over-permissive whitelist becomes stored XSS the moment a sanitised-looking SVG is rendered back into a logged-in user's browser.
