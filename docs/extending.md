# Extending MyDash

This guide is for app developers who want their content to appear on a MyDash dashboard. There are two extension paths and you pick one based on **where the data lives**:

| You have… | Use… | Where the code lives |
|---|---|---|
| A Nextcloud app with PHP services and a dedicated widget UI | A **widget** registered through Nextcloud's standard `IWidget` API | Inside your app (`lib/Dashboard/`, `src/`) |
| Records in OpenRegister (objects, schemas) you want to surface as a card | A **dynamic widget driven by OpenRegister** — registered the same way, but the UI fetches its data from the OpenRegister REST/GraphQL API at render time | Inside your app, but the body of the widget is data-driven from OpenRegister |

Both paths produce something users can place via the **Add Widget** picker, drag around the grid, and resize. MyDash itself does not need to be modified — it auto-discovers any widget registered through Nextcloud's `OCP\Dashboard\IManager`.

If you are looking to add a **shortcut tile** (a static link card a user creates themselves, not driven by app data), see [Custom Tiles](features/tiles.md) instead — that path requires no PHP at all.

---

## Path 1 — Add a widget from a Nextcloud app

This is the standard Nextcloud Dashboard API. MyDash treats every widget registered this way as a first-class citizen.

### 1. Implement `OCP\Dashboard\IWidget`

Create a class under `lib/Dashboard/` in your app:

```php
<?php
namespace OCA\YourApp\Dashboard;

use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\Util;
use OCA\YourApp\AppInfo\Application;

class MyWidget implements IWidget {
    public function __construct(private IL10N $l10n) {}

    public function getId(): string       { return 'yourapp_my_widget'; }
    public function getTitle(): string    { return $this->l10n->t('My widget'); }
    public function getOrder(): int       { return 10; }
    public function getIconClass(): string { return 'icon-yourapp'; }
    public function getUrl(): ?string     { return null; }

    public function load(): void {
        Util::addScript(Application::APP_ID, Application::APP_ID . '-myWidget');
        Util::addStyle(Application::APP_ID, 'dashboardWidgets');
    }
}
```

The `getId()` value is the contract between PHP and JavaScript — it must match the string passed to `OCA.Dashboard.register(...)` in step 3.

### 2. Register the widget with Nextcloud

In your app's `lib/AppInfo/Application.php`, register the widget inside `register()`:

```php
public function register(IRegistrationContext $context): void {
    $context->registerDashboardWidget(MyWidget::class);
}
```

`IManager::getWidgets()` will pick it up on next request — no cache invalidation needed.

### 3. Build a Vue entry-point that registers a renderer

Add a webpack entry in your app's `webpack.config.js`:

```js
webpackConfig.entry = {
    main: { import: path.join(__dirname, 'src', 'main.js'),     filename: appId + '-main.js' },
    myWidget: { import: path.join(__dirname, 'src', 'myWidget.js'), filename: appId + '-myWidget.js' },
}
```

Create `src/myWidget.js`:

```js
import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'
import MyWidget from './views/widgets/MyWidget.vue'

Vue.use(PiniaVuePlugin)

OCA.Dashboard.register('yourapp_my_widget', (el, { widget }) => {
    Vue.mixin({ methods: { t, n } })
    const View = Vue.extend(MyWidget)
    new View({
        pinia,
        propsData: { title: widget.title },
    }).$mount(el)
})
```

The first argument to `OCA.Dashboard.register` **MUST** equal the PHP `getId()` — that's how Nextcloud finds your renderer when MyDash mounts the widget.

### 4. Bundle-size note

Each widget entry-point is currently bundled independently, which means Vue + `@nextcloud/vue` are inlined per entry. When you add a third or fourth widget to the same app, configure webpack `optimization.splitChunks` with `chunks: 'all'` and a `cacheGroups.vendor` rule covering `vue`, `@nextcloud/vue`, `pinia`, and `vue-material-design-icons`, then add a second `Util::addScript()` call in `load()` for the shared chunk. See `pipelinq` and `procest` for a working example. Without this each widget tile costs **~5–10 MB** of duplicated framework code in the user's browser.

### 5. Where the widget appears

MyDash's `index()` controller builds the **Add Widget** picker from `IManager::getWidgets()` (via `WidgetService::getAvailableWidgets()`). Your widget shows up automatically with the title and icon-class declared in your `IWidget`. No template hooks, no front-end registration step in MyDash.

---

## Path 2 — Drive a widget from OpenRegister

OpenRegister stores arbitrary objects against schemas. A common pattern is "show a list of register objects as a dashboard card" — invoices due this week, recently created cases, top contacts. There is **no separate dashboard API for this** — you register a normal `IWidget` and the widget's Vue component fetches its data from the OpenRegister API at runtime.

### What stays the same

- The PHP class still implements `IWidget` and lives under `lib/Dashboard/`.
- The webpack entry-point and `OCA.Dashboard.register(...)` registration follow Path 1 verbatim.
- `getId()` ↔ `OCA.Dashboard.register` contract is unchanged.

### What changes — the Vue layer

Your widget component fetches its rows from OpenRegister using `@nextcloud/axios`:

```vue
<script>
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

export default {
    name: 'OpenInvoicesWidget',
    data: () => ({ items: [], loading: true }),
    async mounted() {
        const register = 'finance'
        const schema   = 'invoice'
        const url      = generateOcsUrl(`apps/openregister/api/objects/${register}/${schema}`)
        const { data } = await axios.get(url, {
            params: {
                _filter: JSON.stringify({ status: 'open' }),
                _limit: 7,
                _order: '-dueDate',
            },
        })
        this.items   = data.results
        this.loading = false
    },
}
</script>
```

The widget tile is now a thin presentation layer; the data shape is whatever OpenRegister returns for the register/schema pair you query.

### When the data lives in OpenRegister but the *widget* logic is generic

If you want one widget that can be re-pointed at any register/schema (a "list widget" picker), expose `register` and `schema` as configuration on the placement and read them via `widget.config` in the `OCA.Dashboard.register` callback. MyDash's placement editor will surface them under the widget's settings panel as long as they are declared in the widget's `IWidget` options.

### Why not a separate "OpenRegister widget API"?

Two reasons. First, the `IWidget` contract is the only widget API Nextcloud knows — anything else would be a bridge that ultimately delegates back to it. Second, the widget UI varies wildly by use case (a count, a chart, a list, a heat-map) so a generic "render this register as a widget" component would be either too restrictive or too configurable to be useful. Keeping the contract small and pushing the data fetch into the Vue layer keeps each widget cohesive.

---

## Testing your widget

| Step | Command |
|---|---|
| Install the app | `occ app:enable yourapp` |
| Force MyDash to re-list | Reload `/apps/mydash/` |
| Open the picker | Click **Add widget** in edit mode |
| Confirm registration | Your widget appears with the title from `getTitle()` |

If the picker shows the widget but it renders blank, the most common cause is that `OCA.Dashboard.register('id', ...)` was called with a different id than `getId()` returns — Nextcloud's registry silently ignores unmatched callbacks. Check the browser console for the warning `No callback registered for widget 'yourapp_my_widget'`.

## See also

- [Widgets vs Tiles](widgets-vs-tiles.md) — the broader explainer on the two surface types in MyDash
- [Custom Tiles](features/tiles.md) — for static shortcut cards a user creates without writing code
- [Widgets feature reference](features/widgets.md) — what users can do with widgets once they appear
- Nextcloud's [`OCP\Dashboard\IWidget` reference](https://docs.nextcloud.com/server/latest/developer_manual/digging_deeper/dashboard.html)
