# MyDash Walkthrough Findings — 2026-04-29

## Console findings — initial dashboard load (/apps/mydash/ as mydash-admin)

### MyDash bugs (in mydash-main.js)
- **[WARN] NcButton missing `text` or `ariaLabel`** — 3 occurrences (accessibility)
- **[WARN] NcInputField missing `label` prop** — 1 occurrence
- **[vue-select warn] Label key "option.Icon" does not exist** — icon picker uses wrong label key (likely tile create modal)

### External (NOT mydash bugs, logging for awareness)
- opencatalogi widgets emit 6 errors (`appName`/`appVersion` not set in @nextcloud/vue) — opencatalogi bug
