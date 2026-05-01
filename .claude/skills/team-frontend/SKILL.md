---
name: team-frontend
description: Frontend Developer — Scrum Team Agent
metadata:
  category: Team
  tags: [team, frontend, vue, scrum]
---

# Frontend Developer — Scrum Team Agent

Implement Vue.js frontend code following Conduction's Nextcloud app patterns. Knows the exact component patterns, store architecture, and quality tools used across the workspace.

## Instructions

You are a **Frontend Developer** on a Conduction scrum team. You implement Vue.js frontend code for Nextcloud apps following the established patterns in this workspace.

### Input

Accept an optional argument:
- No argument → pick up the next pending frontend task from plan.json
- Task number → implement that specific task
- `review` → self-review your recent changes against frontend standards

### Step 1: Load task context

1. Read `plan.json` from the active change
2. Find the target task (next pending, or specified)
3. Read ONLY the referenced spec section (`spec_ref`)
4. Read the `acceptance_criteria`
5. Read the `files_likely_affected` to understand scope

### Step 2: Implement following Conduction frontend patterns

#### Tech Stack

- **Vue 2** (with Vue 3 migration path via `@vue/compat`)
- **Pinia** for state management (NOT Vuex)
- **Vue Router v3** (history mode)
- **@nextcloud/vue** component library (NcButton, NcAppSidebar, NcSelect, etc.)
- **vue-material-design-icons** for icons
- **Webpack** with `@nextcloud/webpack-vue-config`
- **TypeScript** supported (`.ts` files, `tsconfig.json` with strict mode)

#### Directory Structure

```
src/
├── components/     # Reusable Vue components
├── composables/    # Vue composition API helpers
├── dialogs/        # Modal dialogs
├── entities/       # Frontend data models / entity classes
├── modals/         # Modal components
├── navigation/     # Navigation components
├── router/         # Vue Router configuration
│   └── index.js
├── services/       # API service helpers (if any)
├── sidebars/       # Sidebar components
├── store/          # Pinia stores
│   ├── store.js    # Central store export
│   └── modules/    # Individual store modules
├── views/          # Page-level view components
└── main.js         # App entry point
```

#### Component Pattern — Script Setup

Use `<script setup>` for new components:

```vue
<template>
    <NcAppSidebar
        ref="sidebar"
        :name="t('openregister', 'Details')"
        :title="item?.name || ''"
        @close="navigationStore.setSidebarState('items', false)">

        <NcAppSidebarTab id="overview-tab" :name="t('openregister', 'Overview')" :order="1">
            <div v-if="loading" class="loadingContainer">
                <NcLoadingIcon :size="20" />
            </div>
            <div v-else>
                <!-- Content -->
            </div>
        </NcAppSidebarTab>
    </NcAppSidebar>
</template>

<script setup>
import { objectStore, navigationStore } from '../../store/store.js'
</script>

<script>
import { NcAppSidebar, NcAppSidebarTab, NcLoadingIcon } from '@nextcloud/vue'
import ChartBar from 'vue-material-design-icons/ChartBar.vue'

export default {
    name: 'ItemSidebar',
    components: {
        NcAppSidebar,
        NcAppSidebarTab,
        NcLoadingIcon,
        ChartBar,
    },
}
</script>

<style scoped>
.loadingContainer {
    display: flex;
    justify-content: center;
    padding: 2rem;
}
</style>
```

Rules:
- Store imports go in `<script setup>` block
- Component registration and imports go in regular `<script>` block with `export default`
- Always provide a `name` property on the component
- Use `<style scoped>` — never unscoped styles
- Indentation: tabs in `<template>`, spaces in `<script>` and `<style>`

#### Translations

Use the `t()` function from `@nextcloud/l10n`:
```vue
<template>
    <span>{{ t('openregister', 'Objects') }}</span>
    <NcButton :aria-label="t('openregister', 'Add new item')">
</template>
```

Rules:
- First argument is always the app name (e.g., `'openregister'`)
- All user-visible strings MUST use `t()`
- Never hardcode Dutch or English strings directly in templates

#### Pinia Store Pattern

**Use `useObjectStore` from `@conduction/nextcloud-vue` for all OpenRegister CRUD.** Do NOT hand-roll a per-schema Pinia store with its own `fetchX` / `saveX` / `deleteX` actions. Per ADR-022 (`apps-consume-or-abstractions`) the canonical store already covers every shape of CRUD — pagination, search, filters, caching, errors, plus optional plugins for files, audit trails, locks, relations.

A per-schema fork is a violation of ADR-022 and accumulates dead code: decidesk shipped four such stores (`meetings.js`, `minutes.js`, `decisions.js`, `actionItems.js` — ~840 lines) and 770 of those lines were never imported by any view. Deleted in 2026-04-28.

##### Bootstrap (`main.js` and `store/store.js`)

```javascript
// main.js
import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'                         // setActivePinia inside; see below
import { initializeStores } from './store/store.js'

Vue.use(PiniaVuePlugin)

initializeStores().then(() => {
    new Vue({ pinia, router, render: h => h(App) }).$mount('#content')
})
```

```javascript
// pinia.js — setActivePinia is required because initializeStores() runs
// before app.$mount(). Without it, useStore() returns a detached store
// missing its plugin chain and registerObjectType() silently no-ops.
import { createPinia, setActivePinia } from 'pinia'
const pinia = createPinia()
setActivePinia(pinia)
export default pinia
```

```javascript
// store/store.js — register every object type the views reference,
// then re-export the canonical store + any genuinely-app-specific
// stores (settings, etc.).
import { generateUrl } from '@nextcloud/router'
import { useObjectStore } from '@conduction/nextcloud-vue'
import { useSettingsStore } from './modules/settings.js'

export async function initializeStores() {
    const settingsStore = useSettingsStore()
    const objectStore = useObjectStore()

    objectStore.configure({
        baseUrl: generateUrl('/apps/openregister/api/objects'),
    })

    await settingsStore.fetchSettings()
    const register = settingsStore.getSettings.register || '<your-app-slug>'

    // Register every object type any view references. Missing a type
    // here makes `useListView('that-type', { objectStore })` throw
    // "Object type X is not registered in the store".
    objectStore.registerObjectType('meeting',         'meeting',         register)
    objectStore.registerObjectType('agenda-item',     'agenda-item',     register)
    // ...one line per schema...

    return { settingsStore, objectStore }
}

export { useObjectStore, useSettingsStore }
```

##### Using the store from views

```javascript
// MyResourceList.vue — index page
import { useObjectStore, useListView, CnIndexPage } from '@conduction/nextcloud-vue'

export default {
    setup() {
        const objectStore = useObjectStore()
        const listView = useListView('meeting', { objectStore })
        return { ...listView, objectStore }
    },
    // template binds <CnIndexPage :objects="objects" :loading="loading" ... />
}
```

```javascript
// MyResourceDetail.vue — detail page
import { useObjectStore, useDetailView } from '@conduction/nextcloud-vue'

export default {
    setup(props) {
        const objectStore = useObjectStore()
        const detailView = useDetailView('meeting', props.id, {
            objectStore,
            listRouteName: 'Meetings',
            detailRouteName: 'MeetingDetail',
        })
        return { ...detailView, objectStore }
    },
}
```

Rules:
- ONE `useObjectStore` instance for the whole app. Do not `defineStore('meeting', ...)` / `defineStore('decision', ...)` / etc. — those are per-schema forks.
- Every type referenced by a view (`useListView('X')`, `useDetailView('X')`, `objectStore.fetchCollection('X')`, etc.) must be registered in `initializeStores()`. Forgetting one is a runtime error, not a missing-feature.
- Genuine domain actions that the canonical store can't express — lifecycle transitions, LLM workflows, multi-step approval flows — are fine as **separate** small stores or composables. Just don't duplicate CRUD.
- `setActivePinia(pinia)` in `pinia.js` if `initializeStores()` runs before mount.
- App-specific reads of an external endpoint (e.g. `/apps/<your-app>/api/settings`) belong in their own small store, like `settings.js`.

##### Tier 4 — manifest-driven app shell

For new apps, prefer the manifest-driven shell shipped by `@conduction/nextcloud-vue` instead of hand-writing `App.vue` + a `MainMenu.vue` + a router config. Declare routes, navigation, and dependencies in `src/manifest.json` (validated against [`schemas/app-manifest.schema.json`](https://raw.githubusercontent.com/ConductionNL/nextcloud-vue/main/src/schemas/app-manifest.schema.json)) and let the library render the shell:

```vue
<!-- App.vue -->
<template>
    <CnAppRoot
        :manifest="manifest"
        app-id="<your-app-slug>"
        :is-loading="isLoading"
        :custom-components="customComponents"
        :translate="translate" />
</template>

<script>
import { CnAppRoot, useAppManifest } from '@conduction/nextcloud-vue'
import { translate } from '@nextcloud/l10n'
import bundledManifest from './manifest.json'
import customComponents from './customComponents.js'

export default {
    setup() {
        const { manifest, isLoading } = useAppManifest('<your-app-slug>', bundledManifest)
        return {
            manifest, isLoading, customComponents,
            translate: (key) => translate('<your-app-slug>', key),
        }
    },
}
</script>
```

```javascript
// router/index.js — generated from the manifest, no hand-written routes.
import { CnPageRenderer } from '@conduction/nextcloud-vue'
import manifest from '../manifest.json'

const routes = manifest.pages.map((page) => ({
    name: page.id,
    path: page.route,
    component: CnPageRenderer,
    props: page.route.includes(':'),
}))
routes.push({ path: '*', redirect: '/' })

export default new Router({ mode: 'history', base: generateUrl('/apps/<slug>'), routes })
```

The manifest's `menu[]` entries support a `section` field (`"main"` | `"settings"`) — `section: "settings"` items render in `NcAppNavigation`'s `#footer` slot, anchored to the bottom of the navigation. External links use `href` instead of `route`. See [decidesk/src/manifest.json](https://github.com/ConductionNL/decidesk/blob/development/src/manifest.json) and the [migration guide](https://github.com/ConductionNL/nextcloud-vue/blob/main/docs/migrating-to-manifest.md) for a full Tier 4 example.

Adoption is incremental — the four tiers (just `useAppManifest` → `+ CnPageRenderer` → `+ CnAppNav` → `+ CnAppRoot`) all work; pick the tier that matches what the app needs.

#### Router Pattern

```javascript
import Vue from 'vue'
import Router from 'vue-router'

Vue.use(Router)

const router = new Router({
    mode: 'history',
    base: '/index.php/apps/openregister/',
    routes: [
        { path: '/', component: Dashboard },
        { path: '/registers', component: RegistersIndex },
        { path: '/registers/:id', component: RegisterDetail },
        { path: '*', redirect: '/' },  // Catch-all
    ],
})

export default router
```

Rules:
- History mode (not hash)
- Base path includes `/index.php/apps/{appname}/`
- Catch-all `*` route redirects to `/`
- Named routes only for detail/specific views

#### Nextcloud Page Layout

Read the layout patterns reference at [references/nextcloud-layout-patterns.md](references/nextcloud-layout-patterns.md). It covers:
- **Pattern 1**: Navigation → Content → Sidebar (Files, Calendar, Deck style)
- **Pattern 2**: Navigation → List → Content (Mail, Contacts style)
- Implementation example with `NcContent`, `NcAppNavigation`, `NcAppContent`, `NcAppSidebar`
- Rules: pick ONE layout pattern per app, don't override responsive behavior

#### Nextcloud Vue Components

Always prefer `@nextcloud/vue` components:

| Use | Component |
|-----|-----------|
| **Layout containers** | `NcContent`, `NcAppContent`, `NcAppNavigation`, `NcAppSidebar` |
| Buttons | `NcButton` |
| Sidebar tabs | `NcAppSidebarTab` |
| Loading | `NcLoadingIcon` |
| Dropdowns | `NcSelect` |
| Modals | `NcModal`, `NcDialog` |
| Navigation items | `NcAppNavigationItem`, `NcAppNavigationNew` |
| Actions | `NcActions`, `NcActionButton` |
| Inputs | `NcTextField`, `NcTextArea`, `NcCheckboxRadioSwitch` |
| Empty states | `NcEmptyContent` |

NEVER build custom versions of these standard components.

#### @conduction/nextcloud-vue Shared Library

All Conduction apps MUST use the shared `@conduction/nextcloud-vue` library (npm package, published via semantic-release from `github.com/ConductionNL/nextcloud-vue`). This provides:

| Category | Components |
|----------|-----------|
| **Data display** | `CnDataTable`, `CnCellRenderer`, `CnObjectCard`, `CnCardGrid`, `CnStatsBlock`, `CnKpiGrid` |
| **Page layouts** | `CnListViewLayout`, `CnDetailViewLayout`, `CnIndexPage` |
| **Filtering** | `CnFilterBar`, `CnFacetSidebar`, `CnViewModeToggle` |
| **Status** | `CnStatusBadge`, `CnEmptyState`, `CnPagination` |
| **Admin settings** | `CnSettingsSection`, `CnVersionInfoCard`, `CnSettingsCard`, `CnConfigurationCard` |
| **Actions** | `CnRowActions`, `CnMassActionBar`, `CnMassDeleteDialog`, `CnMassCopyDialog` |
| **Store** | `useObjectStore` (with plugins: auditTrailsPlugin, filesPlugin, relationsPlugin, lifecyclePlugin) |
| **Composables** | `useListView`, `useDetailView`, `useSubResource` |

**Admin settings pages** MUST use `CnSettingsSection` (NOT raw `NcSettingsSection`) and start with a `CnVersionInfoCard`. See `openspec/specs/nextcloud-app/spec.md` for the full pattern.

**User settings dialogs** MUST use `NcAppSettingsDialog` (NOT `NcDialog`). See `openspec/specs/nextcloud-app/spec.md`.

**Package distribution**: Published on npm as `@conduction/nextcloud-vue` via semantic-release from `github.com/ConductionNL/nextcloud-vue`.
- **Beta releases**: Push/merge to `beta` branch → `x.y.z-beta.N` (prerelease on npm)
- **Stable releases**: Merge to `main` branch → `x.y.z` (latest on npm)
- **Conventional commits required**: `feat:` = minor bump, `fix:` = patch bump, `BREAKING CHANGE:` footer = major bump
- **To update the library**: Make changes in `nextcloud-vue/`, commit with conventional prefix, push to `beta` or merge to `main`
- **To consume updates**: Run `npm update @conduction/nextcloud-vue` in consuming apps, or bump the version range in package.json

**package.json** MUST include the npm dependency:
```json
"@conduction/nextcloud-vue": "^0.1.0-beta.1"
```

**Webpack config** MUST include the conditional alias (uses local source in monorepo, npm package in CI) + dedup:
```js
const fs = require('fs')
const localLib = path.resolve(__dirname, '../nextcloud-vue/src')
const useLocalLib = fs.existsSync(localLib)

// In resolve.alias:
...(useLocalLib ? { '@conduction/nextcloud-vue': localLib } : {}),
'vue$': path.resolve(__dirname, 'node_modules/vue'),
'pinia$': path.resolve(__dirname, 'node_modules/pinia'),
'@nextcloud/vue$': path.resolve(__dirname, 'node_modules/@nextcloud/vue'),
```

#### CSS & Styling Rules

- Use CSS variables from Nextcloud — never hardcode colors
- Support NL Design System tokens (nldesign app)
- `<style scoped>` on all components
- 2-space indentation in CSS/SCSS
- Use Nextcloud CSS variables: `var(--color-main-text)`, `var(--color-primary)`, etc.
- WCAG AA contrast ratios required

#### Path Aliases

TypeScript/webpack alias `@` maps to `src/`:
```javascript
import { objectStore } from '@/store/store.js'
import ObjectEntity from '@/entities/ObjectEntity.js'
```

Use relative imports for same-directory or nearby files, `@/` for cross-directory.

### Step 3: Run quality checks

After implementing, run the frontend quality pipeline:

```bash
# Lint check
cd {app-dir} && npm run lint

# Auto-fix
cd {app-dir} && npm run lint-fix

# Stylelint
cd {app-dir} && npm run stylelint

# Unit tests
cd {app-dir} && npm run test

# Build (verify no webpack errors)
cd {app-dir} && npm run build
```

Fix all lint errors and warnings before marking complete.

### Step 4: Verify & update progress

1. Verify acceptance criteria are met
2. Run `npm run build` — must succeed without errors
3. Test in browser if possible (use Playwright MCP browser tools)
4. Update plan.json: set task status to `completed`
5. Update tasks.md: check off completed checkboxes
6. Close the GitHub issue:
   ```bash
   gh issue close <number> --repo <repo> --comment "Completed: <summary>"
   ```

### Dutch Government Accessibility & Design Standards

Read the full standards reference at [references/dutch-gov-frontend-standards.md](references/dutch-gov-frontend-standards.md). It covers:
- **WCAG 2.1 AA** — legally required since 2018 (Besluit digitale toegankelijkheid overheid). Perceivable, Operable, Understandable, Robust criteria with Vue implementation examples
- **NL Design System** — CSS token compatibility for municipality theming (Rijkshuisstijl, Utrecht, Amsterdam). Never hardcode colors — use CSS custom properties
- **Multi-Language Support** — all strings via `t()`, RTL support, `Intl.DateTimeFormat`/`Intl.NumberFormat`

### Frontend Standards Quick Reference

| Rule | Value |
|------|-------|
| Framework | Vue 2 (with Vue 3 compat) |
| State | Pinia (defineStore) |
| HTTP | Native fetch() |
| Router | Vue Router v3, history mode |
| Components | @nextcloud/vue |
| Icons | vue-material-design-icons |
| Translations | t('appname', 'text') |
| Styling | Scoped CSS, CSS variables, no hardcoded colors |
| Linter | ESLint (@nextcloud config) |
| CSS linter | Stylelint (recommended-vue) |
| Tests | Jest |
| Build | Webpack (@nextcloud/webpack-vue-config) |
| Path alias | @ → src/ |
| Line width | 120 chars (Prettier) |
| Quotes | Double quotes in TS (Prettier) |
| Trailing commas | Yes (Prettier) |
| Accessibility | WCAG AA required |

---

## Capture Learnings

After execution, review what happened and append new observations to [learnings.md](learnings.md) under the appropriate section:

- **Patterns That Work** — approaches that produced good results
- **Mistakes to Avoid** — errors encountered and how they were resolved
- **Domain Knowledge** — facts discovered during this run
- **Open Questions** — unresolved items for future investigation

Each entry must include today's date. One insight per bullet. Skip if nothing new was learned.
