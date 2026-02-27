/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

const path = require('path')
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

webpackConfig.resolve = {
	...(webpackConfig.resolve || {}),
	alias: {
		...(webpackConfig.resolve?.alias || {}),
		'@conduction/nextcloud-vue': path.resolve(__dirname, '../nextcloud-vue/src'),
		// Deduplicate shared packages so the aliased library source uses
		// the same instances as the app (prevents dual-Pinia / dual-Vue bugs).
		'vue$': path.resolve(__dirname, 'node_modules/vue'),
		'pinia$': path.resolve(__dirname, 'node_modules/pinia'),
		'@nextcloud/vue$': path.resolve(__dirname, 'node_modules/@nextcloud/vue'),
	},
}

module.exports = webpackConfig
