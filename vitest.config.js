/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest configuration for MyDash Vue 2 unit tests.
 *
 * Test files live next to the code they cover under
 * `src/<area>/__tests__/<Subject>.spec.js` and run in a jsdom environment
 * so DOM assertions (`wrapper.find`, `wrapper.text()`, inline-style
 * inspection) work without launching a browser.
 *
 * The Nextcloud `t()` translation helper is stubbed in each test (see
 * `beforeEach` in the spec files) — we deliberately do NOT install a
 * global setup file so the stub stays visible inside the test that uses it.
 */

const path = require('path')
const vue2 = require('@vitejs/plugin-vue2')

/**
 * Side-effect imports of `*.css` from `@nextcloud/vue` (and friends) crash
 * Vite's transform pipeline because those CSS files don't exist on disk —
 * they are produced by a parallel vite build and referenced via tree-shaken
 * `import './foo.css'` lines that survive transpilation. A small plugin
 * intercepts `*.css` resolution and returns a virtual empty module so unit
 * tests can mount components without ever loading a stylesheet.
 */
const cssNoop = {
	name: 'mydash-css-noop',
	enforce: 'pre',
	resolveId(id) {
		if (id.endsWith('.css')) {
			return '\0virtual:css-noop'
		}
		return null
	},
	load(id) {
		if (id === '\0virtual:css-noop') {
			return 'export default {}'
		}
		return null
	},
}

module.exports = {
	plugins: [
		cssNoop,
		vue2.default ? vue2.default() : vue2(),
	],
	test: {
		environment: 'jsdom',
		globals: false,
		include: ['src/**/__tests__/**/*.spec.{js,ts}'],
		setupFiles: [path.resolve(__dirname, 'tests/vitest/setup.js')],
		server: {
			deps: {
				inline: ['@nextcloud/vue'],
			},
		},
	},
	resolve: {
		alias: [
			{ find: '@', replacement: path.resolve(__dirname, 'src') },
		],
	},
}
