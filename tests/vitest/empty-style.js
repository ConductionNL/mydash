/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Empty stub used by Vitest to replace any `*.css` side-effect import.
 * Required because some `@nextcloud/vue` SFCs reference asset CSS files
 * that the upstream package only ships in the parallel Vite build; the
 * raw imports survive transpilation and would crash the test runner
 * otherwise (`ERR_UNKNOWN_FILE_EXTENSION`).
 */

export default {}
