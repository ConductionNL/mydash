/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

const path = require('path')
const { defineConfig } = require('vitest/config')

module.exports = defineConfig({
	test: {
		environment: 'jsdom',
		globals: true,
		include: [
			'src/**/*.{test,spec}.{js,ts}',
			'src/__tests__/**/*.{test,spec}.{js,ts}',
		],
	},
	resolve: {
		alias: {
			'@': path.resolve(__dirname, 'src'),
		},
	},
})
