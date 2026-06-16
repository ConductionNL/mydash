/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

const path = require('path')
const fs = require('fs')
const webpackConfig = require('@nextcloud/webpack-vue-config')

webpackConfig.entry = {
	main: path.join(__dirname, 'src', 'main.js'),
	admin: path.join(__dirname, 'src', 'admin.js'),
}

webpackConfig.output = {
	...webpackConfig.output,
	filename: 'mydash-[name].js',
	chunkFilename: 'mydash-[name].js?v=[contenthash]',
}

// Use local source when available (monorepo dev), otherwise fall back to npm package
const localLib = path.resolve(__dirname, '../nextcloud-vue/src')
const useLocalLib = fs.existsSync(localLib)

webpackConfig.resolve = {
	...(webpackConfig.resolve || {}),
	alias: {
		...(webpackConfig.resolve?.alias || {}),
		...(useLocalLib ? { '@conduction/nextcloud-vue': localLib } : {}),
		// Deduplicate shared packages so the aliased library source uses
		// the same instances as the app (prevents dual-Pinia / dual-Vue bugs).
		'vue$': path.resolve(__dirname, 'node_modules/vue'),
		'pinia$': path.resolve(__dirname, 'node_modules/pinia'),
		'@nextcloud/vue$': path.resolve(__dirname, 'node_modules/@nextcloud/vue'),
		'@nextcloud/dialogs$': path.resolve(__dirname, 'node_modules/@nextcloud/dialogs'),
		// `@nextcloud/axios@2.6+` is ESM-only and its `exports` map has no
		// `require` condition, so the CJS bundle of `@nextcloud/vue@8.x`
		// (which does `require('@nextcloud/axios')`) fails to resolve.
		// Alias directly to the ESM entry so webpack bypasses the exports
		// map and transforms/interops the ESM module itself.
		'@nextcloud/axios$': path.resolve(__dirname, 'node_modules/@nextcloud/axios/dist/index.js'),
	},
	// Ensure webpack resolves dependencies from the app's node_modules first,
	// preventing Vue 3 packages from nextcloud-vue/node_modules leaking in.
	modules: [
		path.resolve(__dirname, 'node_modules'),
		'node_modules',
	],
}

module.exports = webpackConfig
