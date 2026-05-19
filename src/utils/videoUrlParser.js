/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * videoUrlParser — small client-side helpers for the VideoForm picker.
 *
 * The authoritative URL parser is server-side (`VideoParsingService.php`):
 * the backend extracts the video ID, validates the domain against the
 * admin allow-list, and stores the canonical embed URL in the placement
 * config. These helpers exist purely for the form-level UX hint and form
 * validation — they let the modal show "Detected: YouTube" the moment the
 * user pastes a URL, and they pre-compute the canonical embed URL the
 * renderer expects so the form preview branch can render correctly without
 * a round-trip through the parse endpoint.
 *
 * Source-type detection (REQ-VID-003):
 *   - youtube  → `youtube.com`, `www.youtube.com`, `youtu.be`,
 *                `youtube-nocookie.com`, `m.youtube.com`
 *   - vimeo    → `vimeo.com`, `player.vimeo.com`
 *   - peertube → URL contains `/w/{uuid-or-slug}` segment (PeerTube embed
 *                path) AND domain is not one of the above (PeerTube is
 *                self-hosted on arbitrary domains, so the path is the
 *                only reliable discriminator without a server-side
 *                allow-list lookup)
 *   - nc-file  → URL starts with `/apps/files/`, `/index.php/apps/files/`,
 *                or `/f/` (Nextcloud's deep-link prefixes for files)
 *
 * Embed URL normalisation (REQ-VID-005):
 *   - YouTube  → `https://www.youtube.com/embed/{ID}` plus optional
 *                `?start=N` when the source URL has `t=Ns`
 *   - Vimeo    → `https://player.vimeo.com/video/{ID}`
 *   - PeerTube → preserves origin and rewrites `/w/{slug}` to
 *                `/videos/embed/{slug}` (PeerTube's iframe embed path)
 */

const YOUTUBE_HOSTS = new Set([
	'youtube.com',
	'www.youtube.com',
	'youtu.be',
	'youtube-nocookie.com',
	'www.youtube-nocookie.com',
	'm.youtube.com',
])

const VIMEO_HOSTS = new Set([
	'vimeo.com',
	'www.vimeo.com',
	'player.vimeo.com',
])

const NC_FILE_PATH_PREFIXES = [
	'/apps/files/',
	'/index.php/apps/files/',
	'/f/',
]

/**
 * Try to parse a string as a URL. We accept both fully-qualified
 * (`https://...`) and path-relative (`/apps/files/...`) inputs because the
 * Nextcloud Files deep-links most users will paste are path-relative.
 *
 * @param {string} input the candidate URL
 * @return {URL|null} the parsed URL or null when input is unparseable
 */
function safeParseUrl(input) {
	if (typeof input !== 'string' || input.trim() === '') {
		return null
	}
	const trimmed = input.trim()
	try {
		// Anchor relative paths to a synthetic origin so URL() accepts them;
		// callers that need to distinguish absolute vs relative re-check
		// `parsed.origin` against the synthetic value.
		return new URL(trimmed, 'https://__local__/')
	} catch (e) {
		return null
	}
}

/**
 * Detect which source type a URL belongs to.
 *
 * @param {string} input the user-provided URL
 * @return {('youtube'|'vimeo'|'peertube'|'nc-file'|null)} the detected source type or null
 */
export function detectVideoSource(input) {
	const parsed = safeParseUrl(input)
	if (!parsed) {
		return null
	}

	const isRelative = parsed.origin === 'https://__local__'
	const path = parsed.pathname || ''

	if (isRelative) {
		for (const prefix of NC_FILE_PATH_PREFIXES) {
			if (path.startsWith(prefix)) {
				return 'nc-file'
			}
		}
		return null
	}

	const host = (parsed.hostname || '').toLowerCase()

	if (YOUTUBE_HOSTS.has(host)) {
		return 'youtube'
	}
	if (VIMEO_HOSTS.has(host)) {
		return 'vimeo'
	}

	// Nextcloud-served file with absolute origin (rare but valid).
	for (const prefix of NC_FILE_PATH_PREFIXES) {
		if (path.startsWith(prefix)) {
			return 'nc-file'
		}
	}

	// PeerTube discriminator: URL path contains `/w/` (watch) or
	// `/videos/watch/` segment. Self-hosted, so we cannot match by host.
	if (/\/w\/[A-Za-z0-9_-]+/.test(path) || /\/videos\/watch\/[A-Za-z0-9_-]+/.test(path)) {
		return 'peertube'
	}

	return null
}

/**
 * Extract a YouTube video ID from any of YouTube's URL formats. Recognises:
 *   - https://youtu.be/{ID}
 *   - https://www.youtube.com/watch?v={ID}
 *   - https://www.youtube.com/embed/{ID}
 *   - https://youtube-nocookie.com/embed/{ID}
 *   - https://m.youtube.com/watch?v={ID}
 *
 * @param {URL} url parsed URL
 * @return {string|null} the 11-char video ID or null when not extractable
 */
function extractYouTubeId(url) {
	const host = (url.hostname || '').toLowerCase()
	if (host === 'youtu.be') {
		return url.pathname.replace(/^\//, '').split('/')[0] || null
	}
	const v = url.searchParams.get('v')
	if (v) {
		return v
	}
	const match = url.pathname.match(/^\/embed\/([A-Za-z0-9_-]+)/)
	if (match) {
		return match[1]
	}
	return null
}

/**
 * Extract a Vimeo video ID from any of Vimeo's URL formats.
 *
 * @param {URL} url parsed URL
 * @return {string|null} the numeric video ID as a string or null
 */
function extractVimeoId(url) {
	// player.vimeo.com/video/{ID} or vimeo.com/{ID}
	const playerMatch = url.pathname.match(/^\/video\/(\d+)/)
	if (playerMatch) {
		return playerMatch[1]
	}
	const directMatch = url.pathname.match(/^\/(\d+)/)
	if (directMatch) {
		return directMatch[1]
	}
	return null
}

/**
 * Parse a `t=NNs` parameter (or plain seconds) from a YouTube URL. YouTube
 * accepts `t=30`, `t=30s`, `t=1m30s`, etc.; the embed parameter is plain
 * seconds (`start=N`).
 *
 * @param {URL} url parsed URL
 * @return {number|null} time offset in seconds or null
 */
function extractYouTubeTimeOffset(url) {
	const t = url.searchParams.get('t') || url.searchParams.get('start')
	if (!t) {
		return null
	}
	// Plain integer
	if (/^\d+$/.test(t)) {
		return parseInt(t, 10)
	}
	// `1h2m3s` style
	const match = t.match(/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s?)?$/)
	if (match) {
		const hours = parseInt(match[1] || '0', 10)
		const minutes = parseInt(match[2] || '0', 10)
		const seconds = parseInt(match[3] || '0', 10)
		const total = hours * 3600 + minutes * 60 + seconds
		return total > 0 ? total : null
	}
	return null
}

/**
 * Build the canonical embed URL the iframe renderer expects.
 *
 * @param {string} input the user-supplied URL
 * @param {('youtube'|'vimeo'|'peertube')} sourceType the detected source type
 * @return {string|null} canonical embed URL, or null when unparseable
 */
export function normalizeEmbedUrl(input, sourceType) {
	const parsed = safeParseUrl(input)
	if (!parsed) {
		return null
	}

	if (sourceType === 'youtube') {
		const id = extractYouTubeId(parsed)
		if (!id) {
			return null
		}
		const start = extractYouTubeTimeOffset(parsed)
		const base = `https://www.youtube.com/embed/${id}`
		return start ? `${base}?start=${start}` : base
	}

	if (sourceType === 'vimeo') {
		const id = extractVimeoId(parsed)
		if (!id) {
			return null
		}
		return `https://player.vimeo.com/video/${id}`
	}

	if (sourceType === 'peertube') {
		const watchMatch = parsed.pathname.match(/\/w\/([A-Za-z0-9_-]+)/)
			|| parsed.pathname.match(/\/videos\/watch\/([A-Za-z0-9_-]+)/)
		if (!watchMatch) {
			return null
		}
		return `${parsed.origin}/videos/embed/${watchMatch[1]}`
	}

	return null
}
