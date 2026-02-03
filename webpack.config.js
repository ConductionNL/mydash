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

module.exports = webpackConfig
