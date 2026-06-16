#!/usr/bin/env node

/*
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * CI guard for the initial-state-contract capability (REQ-INIT-003).
 *
 * Forbids any `loadState('mydash', ...)` call outside the canonical
 * reader at `src/utils/loadInitialState.js`. Runs as `npm run
 * lint:initial-state` and exits non-zero on the first offending file.
 *
 * The check is a pure-Node text grep — no extra deps, no AST. The
 * pattern matches both single- and double-quoted forms.
 */

const fs = require('node:fs')
const path = require('node:path')

const ROOT = path.resolve(__dirname, '..')
const SRC_DIR = path.join(ROOT, 'src')
const ALLOWED_FILE = path.join(SRC_DIR, 'utils', 'loadInitialState.js')
const PATTERN = /loadState\s*\(\s*['"]mydash['"]/

/**
 * Walk a directory recursively yielding every file path under it,
 * skipping `node_modules`, `__tests__`, and any non-source artefacts.
 *
 * @param {string} dir Directory to walk.
 * @return {Array<string>} Absolute file paths.
 */
function walk(dir) {
	const out = []
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const full = path.join(dir, entry.name)
		if (entry.isDirectory()) {
			if (entry.name === 'node_modules' || entry.name === '__tests__') {
				continue
			}
			out.push(...walk(full))
			continue
		}
		if (/\.(?:js|ts|vue|mjs|cjs)$/.test(entry.name)) {
			out.push(full)
		}
	}
	return out
}

const offenders = []
for (const file of walk(SRC_DIR)) {
	if (file === ALLOWED_FILE) {
		continue
	}
	const content = fs.readFileSync(file, 'utf8')
	if (PATTERN.test(content)) {
		offenders.push(path.relative(ROOT, file))
	}
}

if (offenders.length > 0) {
	process.stderr.write(
		'lint:initial-state — REQ-INIT-003 violation:\n'
		+ '  Direct loadState(\'mydash\', ...) call found outside src/utils/loadInitialState.js.\n'
		+ '  Use loadInitialState(page) and inject the keys instead.\n'
		+ '  Offending files:\n'
		+ offenders.map(f => `    - ${f}\n`).join(''),
	)
	process.exit(1)
}

process.stdout.write('lint:initial-state — OK (no direct loadState(\'mydash\', ...) calls outside the reader)\n')
