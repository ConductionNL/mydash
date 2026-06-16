#!/usr/bin/env node
// Internal coverage check — verifies every route in appinfo/routes.php has at least one matching request in the collection.
const fs = require('fs');
const path = require('path');

const repoRoot = path.resolve(__dirname, '..', '..');
const collection = require(path.join(repoRoot, 'tests/integration/mydash.postman_collection.json'));

function walk(items, out) {
	for (const it of items) {
		if (it.item) walk(it.item, out);
		if (it.request) {
			let u = (it.request.url.raw || '');
			// Strip leading {{baseUrl}} or http(s)://host placeholder
			u = u.replace(/^\{\{[A-Za-z0-9_]+\}\}/, '');
			u = u.replace(/^https?:\/\/[^/]+/, '');
			u = u.replace(/^\/index\.php\/apps\/mydash/, '');
			u = u.replace(/[?#].*$/, '');
			// Replace remaining {{var}} placeholders with a generic value so route patterns can match.
			u = u.replace(/\{\{[A-Za-z0-9_]+\}\}/g, '__VAR__');
			if (u === '') u = '/';
			out.push({ method: it.request.method, url: u });
		}
	}
}
const requests = [];
walk(collection.item, requests);

const routesPhp = fs.readFileSync(path.join(repoRoot, 'appinfo/routes.php'), 'utf8');
const re = /'name'\s*=>\s*'([^']+)'[\s\S]*?'url'\s*=>\s*'([^']+)'[\s\S]*?'verb'\s*=>\s*'([^']+)'/g;
const routes = [];
let m;
while ((m = re.exec(routesPhp)) !== null) {
	routes.push({ name: m[1], url: m[2], verb: m[3] });
}

function patternToRegex(p) {
	// Replace {param} placeholders FIRST (before escaping other regex specials).
	// Use sentinels that won't collide with characters in the URL.
	let s = p;
	s = s.replace(/\{path\}/g, '__PATH__');
	s = s.replace(/\{[^}]+\}/g, '__SEG__');
	// Escape regex specials.
	s = s.replace(/[.+?^$()|[\]\\/]/g, '\\$&');
	// Substitute sentinels with regex.
	s = s.replace(/__PATH__/g, '.+');
	s = s.replace(/__SEG__/g, '[^/]+');
	return new RegExp('^' + s + '$');
}

function normalizeRequestUrl(u) {
	// Replace Postman {{var}} placeholders with a wildcard segment matcher
	// matching either a UUID-like or numeric or arbitrary slug.
	return u.replace(/\{\{[A-Za-z0-9_]+\}\}/g, '__VAR__');
}

const missing = [];
for (const r of routes) {
	const rx = patternToRegex(r.url);
	const ok = requests.some((req) => {
		if (req.method !== r.verb) return false;
		// Substitute Postman vars with wildcard tokens before matching against route pattern.
		const subst = req.url.replace(/__VAR__/g, '00000000-0000-0000-0000-000000000000');
		const candidate = normalizeRequestUrl(req.url).replace(/__VAR__/g, 'X');
		return rx.test(subst) || rx.test(candidate);
	});
	if (!ok) missing.push(r.verb + ' ' + r.url + ' (' + r.name + ')');
}
console.log('Declared routes :', routes.length);
console.log('Collection items:', requests.length);
console.log('Missing         :', missing.length);
for (const item of missing) console.log('  - ' + item);
process.exit(missing.length === 0 ? 0 : 1);
