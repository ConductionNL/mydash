/*
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * No-op stub for `*.css` imports during Vitest runs. Vue components
 * (especially those re-exported by @nextcloud/vue) emit side-effect CSS
 * imports that crash Node's loader with `ERR_UNKNOWN_FILE_EXTENSION`.
 * Aliasing every `.css` request to this empty module sidesteps that without
 * affecting the production webpack build.
 */

export default {}
