/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `videoUrlParser.js` covering REQ-VID-003 and
 * REQ-VID-005:
 *   - Source-type detection across the four supported source kinds plus
 *     unrecognised inputs and unparseable strings.
 *   - Embed URL normalisation for YouTube (multiple URL formats + time
 *     offset preservation), Vimeo (player and direct forms), and PeerTube
 *     (preserves origin, rewrites watch path to embed path).
 */

import { describe, it, expect } from 'vitest'
import { detectVideoSource, normalizeEmbedUrl } from '../videoUrlParser.js'

describe('detectVideoSource', () => {
	it('REQ-VID-003: detects YouTube watch URL', () => {
		expect(detectVideoSource('https://www.youtube.com/watch?v=ABC123')).toBe('youtube')
	})

	it('REQ-VID-003: detects YouTube short link', () => {
		expect(detectVideoSource('https://youtu.be/ABC123')).toBe('youtube')
	})

	it('REQ-VID-003: detects YouTube nocookie embed', () => {
		expect(detectVideoSource('https://www.youtube-nocookie.com/embed/ABC123')).toBe('youtube')
	})

	it('REQ-VID-003: detects YouTube mobile URL', () => {
		expect(detectVideoSource('https://m.youtube.com/watch?v=ABC123')).toBe('youtube')
	})

	it('REQ-VID-003: detects Vimeo direct URL', () => {
		expect(detectVideoSource('https://vimeo.com/12345')).toBe('vimeo')
	})

	it('REQ-VID-003: detects Vimeo player URL', () => {
		expect(detectVideoSource('https://player.vimeo.com/video/12345')).toBe('vimeo')
	})

	it('REQ-VID-003: detects PeerTube watch URL', () => {
		expect(detectVideoSource('https://peertube.example.com/w/abc123')).toBe('peertube')
	})

	it('REQ-VID-003: detects PeerTube long watch path', () => {
		expect(detectVideoSource('https://peertube.example.com/videos/watch/abc-def-ghi')).toBe('peertube')
	})

	it('REQ-VID-003: detects Nextcloud Files relative path', () => {
		expect(detectVideoSource('/apps/files/?fileid=12345')).toBe('nc-file')
	})

	it('REQ-VID-003: detects Nextcloud Files /f/ deeplink', () => {
		expect(detectVideoSource('/f/12345')).toBe('nc-file')
	})

	it('REQ-VID-003: returns null for unrecognised host', () => {
		expect(detectVideoSource('https://example.com/video.mp4')).toBeNull()
	})

	it('REQ-VID-003: returns null for empty string', () => {
		expect(detectVideoSource('')).toBeNull()
	})

	it('REQ-VID-003: returns null for non-string input', () => {
		expect(detectVideoSource(null)).toBeNull()
		expect(detectVideoSource(undefined)).toBeNull()
		expect(detectVideoSource(123)).toBeNull()
	})

	it('REQ-VID-003: returns null for unparseable input', () => {
		// String built from chars that fail the URL parser — anchoring
		// against the synthetic origin still produces a valid path so we
		// pick a sentinel that doesn't match any known prefix or host.
		expect(detectVideoSource('not-a-url-just-words')).toBeNull()
	})
})

describe('normalizeEmbedUrl', () => {
	it('REQ-VID-005: YouTube watch URL → canonical embed', () => {
		expect(normalizeEmbedUrl('https://www.youtube.com/watch?v=ABC123', 'youtube'))
			.toBe('https://www.youtube.com/embed/ABC123')
	})

	it('REQ-VID-005: youtu.be short link → canonical embed', () => {
		expect(normalizeEmbedUrl('https://youtu.be/ABC123', 'youtube'))
			.toBe('https://www.youtube.com/embed/ABC123')
	})

	it('REQ-VID-005: YouTube embed URL passes through to canonical form', () => {
		expect(normalizeEmbedUrl('https://www.youtube.com/embed/ABC123', 'youtube'))
			.toBe('https://www.youtube.com/embed/ABC123')
	})

	it('REQ-VID-005: YouTube preserves t=30s as start=30', () => {
		expect(normalizeEmbedUrl('https://www.youtube.com/watch?v=ABC123&t=30s', 'youtube'))
			.toBe('https://www.youtube.com/embed/ABC123?start=30')
	})

	it('REQ-VID-005: YouTube preserves t=120 as start=120', () => {
		expect(normalizeEmbedUrl('https://www.youtube.com/watch?v=ABC123&t=120', 'youtube'))
			.toBe('https://www.youtube.com/embed/ABC123?start=120')
	})

	it('REQ-VID-005: YouTube parses t=1m30s as start=90', () => {
		expect(normalizeEmbedUrl('https://www.youtube.com/watch?v=ABC123&t=1m30s', 'youtube'))
			.toBe('https://www.youtube.com/embed/ABC123?start=90')
	})

	it('REQ-VID-005: Vimeo direct URL → player embed', () => {
		expect(normalizeEmbedUrl('https://vimeo.com/12345', 'vimeo'))
			.toBe('https://player.vimeo.com/video/12345')
	})

	it('REQ-VID-005: Vimeo player URL passes through', () => {
		expect(normalizeEmbedUrl('https://player.vimeo.com/video/12345', 'vimeo'))
			.toBe('https://player.vimeo.com/video/12345')
	})

	it('REQ-VID-005: PeerTube /w/ path becomes /videos/embed/', () => {
		expect(normalizeEmbedUrl('https://peertube.example.com/w/abc123', 'peertube'))
			.toBe('https://peertube.example.com/videos/embed/abc123')
	})

	it('REQ-VID-005: PeerTube preserves origin (custom port + scheme)', () => {
		expect(normalizeEmbedUrl('http://pt.local:9000/w/xyz789', 'peertube'))
			.toBe('http://pt.local:9000/videos/embed/xyz789')
	})

	it('REQ-VID-005: returns null when YouTube URL has no extractable ID', () => {
		expect(normalizeEmbedUrl('https://www.youtube.com/feed/trending', 'youtube')).toBeNull()
	})

	it('REQ-VID-005: returns null when Vimeo URL has no extractable ID', () => {
		expect(normalizeEmbedUrl('https://vimeo.com/channels/staffpicks', 'vimeo')).toBeNull()
	})

	it('REQ-VID-005: returns null for unparseable input', () => {
		expect(normalizeEmbedUrl('', 'youtube')).toBeNull()
		expect(normalizeEmbedUrl(null, 'youtube')).toBeNull()
	})
})
