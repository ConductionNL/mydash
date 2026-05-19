<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div
		class="video-widget"
		:style="wrapperStyle">
		<div v-if="!hasSource" class="video-widget__placeholder">
			<VideoIcon :size="48" :fill-color="placeholderColor" />
			<span class="video-widget__placeholder-label">{{ t('mydash', 'No video URL configured') }}</span>
		</div>
		<div v-else-if="hasError" class="video-widget__placeholder">
			<VideoIcon :size="48" :fill-color="placeholderColor" />
			<span class="video-widget__placeholder-label">{{ errorMessage }}</span>
		</div>
		<div
			v-else
			class="video-widget__media"
			:style="mediaStyle">
			<iframe
				v-if="isIframeSource"
				class="video-widget__iframe"
				:src="embedUrl"
				sandbox="allow-scripts allow-same-origin allow-presentation"
				allowfullscreen
				frameborder="0" />
			<video
				v-else-if="isFileSource"
				class="video-widget__video"
				:src="fileStreamingUrl"
				:poster="posterUrl || null"
				:autoplay="effectiveAutoplay"
				:muted="effectiveMuted"
				:loop="loop"
				:controls="controls"
				@error="onVideoError" />
		</div>
	</div>
</template>

<script>
import VideoIcon from 'vue-material-design-icons/Video.vue'

const VALID_SOURCE_TYPES = ['youtube', 'vimeo', 'peertube', 'nc-file']
const VALID_ASPECT_RATIOS = ['16:9', '4:3', '1:1', '9:16']
const DEFAULT_ASPECT_RATIO = '16:9'

/**
 * VideoWidget renders an embedded video inside a dashboard cell. It dispatches
 * on `content.sourceType` to one of three render branches:
 *
 *   - `youtube` / `vimeo` / `peertube` → sandboxed `<iframe>` whose `src` is
 *     the canonical embed URL stored in `content.videoUrl`. The frontend never
 *     re-parses raw user URLs (REQ-VID-002, REQ-VID-005, REQ-VID-007); the
 *     backend is the parsing boundary at save time.
 *   - `nc-file` → HTML5 `<video>` whose `src` is the streaming URL the
 *     backend hands back from `GET /api/widgets/video/file/{fileId}`. ACL is
 *     enforced server-side; the renderer just displays whatever the parent
 *     resolved.
 *   - Anything else, missing source, or render error → centered placeholder
 *     with a video icon and a localised message (REQ-VID-011).
 *
 * Autoplay and muting interact: per REQ-VID-008, autoplay forces muted=true
 * because all major browsers block autoplay of unmuted media. The renderer
 * coerces `effectiveMuted` to true whenever `autoplay` is true; the backend
 * also enforces this invariant at save time.
 *
 * Aspect ratio uses the modern `aspect-ratio` CSS property (REQ-VID-009);
 * `4:3`, `16:9`, `1:1`, and `9:16` are the four supported options. Unknown
 * values fall back to `16:9`.
 */
export default {
	name: 'VideoWidget',

	components: {
		VideoIcon,
	},

	props: {
		/**
		 * Persisted content blob:
		 *   {sourceType, videoUrl, fileId, fileStreamingUrl, autoplay, muted,
		 *    loop, controls, aspectRatio, posterUrl, error}
		 *
		 * `videoUrl` is the canonical embed URL written by the backend at
		 * save time — never a raw user-pasted form. `fileStreamingUrl` is
		 * the backend-resolved URL for `nc-file` sources (already ACL-checked).
		 * `error` is an optional string set by the parent when domain or ACL
		 * checks fail; presence triggers the error placeholder.
		 */
		content: {
			type: Object,
			default: () => ({}),
		},
		/** Reserved for future use — kept to match the renderer contract. */
		placement: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			// Set when the HTML5 <video> element fires its error event so
			// subsequent renders show the error placeholder rather than a
			// broken video element. Iframes don't expose a load-error event
			// reliably, so this flag is only set by the nc-file branch.
			videoLoadFailed: false,
		}
	},

	computed: {
		sourceType() {
			const raw = this.content && this.content.sourceType
			return VALID_SOURCE_TYPES.includes(raw) ? raw : null
		},

		videoUrl() {
			const value = this.content && this.content.videoUrl
			return typeof value === 'string' ? value : ''
		},

		fileStreamingUrl() {
			const value = this.content && this.content.fileStreamingUrl
			return typeof value === 'string' ? value : ''
		},

		fileId() {
			const value = this.content && this.content.fileId
			return typeof value === 'number' && Number.isFinite(value) ? value : null
		},

		autoplay() {
			return Boolean(this.content && this.content.autoplay)
		},

		muted() {
			// Default to true so autoplay-friendly behaviour matches the
			// backend invariant; explicit false in content stays false unless
			// autoplay overrides it via effectiveMuted below.
			if (this.content && typeof this.content.muted === 'boolean') {
				return this.content.muted
			}
			return true
		},

		loop() {
			return Boolean(this.content && this.content.loop)
		},

		controls() {
			if (this.content && typeof this.content.controls === 'boolean') {
				return this.content.controls
			}
			return true
		},

		aspectRatio() {
			const raw = this.content && this.content.aspectRatio
			if (typeof raw === 'string' && VALID_ASPECT_RATIOS.includes(raw)) {
				return raw
			}
			return DEFAULT_ASPECT_RATIO
		},

		posterUrl() {
			const value = this.content && this.content.posterUrl
			return typeof value === 'string' && value.trim() !== '' ? value : ''
		},

		/**
		 * REQ-VID-008: autoplay enforces muted. Browsers block autoplay of
		 * unmuted media, so coerce muted=true whenever autoplay is true.
		 *
		 * @return {boolean} effective muted value passed to <video>/iframe
		 */
		effectiveMuted() {
			return this.autoplay ? true : this.muted
		},

		effectiveAutoplay() {
			// Don't autoplay a failed video — the error branch already
			// renders, but defensive: never set autoplay when the source
			// hasn't resolved.
			return this.autoplay && this.hasSource
		},

		hasSource() {
			if (this.sourceType === null) {
				return false
			}
			if (this.sourceType === 'nc-file') {
				return this.fileId !== null
			}
			return this.videoUrl.trim() !== ''
		},

		isIframeSource() {
			return ['youtube', 'vimeo', 'peertube'].includes(this.sourceType)
		},

		isFileSource() {
			return this.sourceType === 'nc-file'
		},

		hasError() {
			if (this.content && typeof this.content.error === 'string' && this.content.error.trim() !== '') {
				return true
			}
			if (this.isFileSource && this.fileId !== null && this.fileStreamingUrl === '') {
				return true
			}
			return this.videoLoadFailed
		},

		errorMessage() {
			const raw = this.content && this.content.error
			if (typeof raw === 'string' && raw.trim() !== '') {
				return raw
			}
			if (this.isFileSource && this.fileId !== null && this.fileStreamingUrl === '') {
				return t('mydash', 'Video not accessible')
			}
			if (this.videoLoadFailed) {
				return t('mydash', 'Video failed to load')
			}
			return t('mydash', 'Invalid video URL or domain not allowed.')
		},

		/**
		 * REQ-VID-007 / REQ-VID-008: build the iframe URL by appending
		 * playback query params (autoplay, mute, loop, controls) to the
		 * canonical embed URL. The backend already substituted the privacy
		 * variant (e.g. youtube-nocookie.com) at save time when the admin
		 * setting is enabled, so the renderer treats `videoUrl` as opaque
		 * and only appends params.
		 *
		 * @return {string} iframe src with query params applied
		 */
		embedUrl() {
			if (!this.isIframeSource || this.videoUrl === '') {
				return ''
			}
			const params = []
			if (this.autoplay) {
				params.push('autoplay=1')
				params.push('mute=1')
			} else if (this.muted) {
				params.push('mute=1')
			}
			if (this.loop) {
				params.push('loop=1')
			}
			if (params.length === 0) {
				return this.videoUrl
			}
			const separator = this.videoUrl.includes('?') ? '&' : '?'
			return this.videoUrl + separator + params.join('&')
		},

		placeholderColor() {
			return 'var(--color-text-maxcontrast)'
		},

		wrapperStyle() {
			return {
				width: '100%',
				height: '100%',
				overflow: 'hidden',
				position: 'relative',
				display: 'flex',
				'align-items': 'center',
				'justify-content': 'center',
			}
		},

		/**
		 * REQ-VID-009: enforce aspect ratio via the modern `aspect-ratio`
		 * CSS property. The container width fills the cell; the height is
		 * derived from the configured ratio so iframes and videos never
		 * distort regardless of cell size.
		 *
		 * @return {object} inline style object for the media wrapper
		 */
		mediaStyle() {
			const [w, h] = this.aspectRatio.split(':')
			return {
				width: '100%',
				'max-width': '100%',
				'aspect-ratio': `${w} / ${h}`,
				display: 'flex',
				'align-items': 'center',
				'justify-content': 'center',
				background: 'var(--color-background-dark)',
			}
		},
	},

	watch: {
		// Re-arm the failure flag when the video source changes so a
		// previously failed file does not permanently lock the cell.
		fileStreamingUrl() {
			this.videoLoadFailed = false
		},
		videoUrl() {
			this.videoLoadFailed = false
		},
	},

	methods: {
		/**
		 * REQ-VID-011: HTML5 <video> error event swaps to the error
		 * placeholder + localised "Video failed to load" message. We
		 * deliberately swallow the event so no exception bubbles up into
		 * the GridStack grid layer (which would crash the dashboard).
		 */
		onVideoError() {
			this.videoLoadFailed = true
		},
	},
}
</script>

<style scoped>
.video-widget {
	width: 100%;
	height: 100%;
	overflow: hidden;
}

.video-widget__media {
	position: relative;
}

.video-widget__iframe,
.video-widget__video {
	width: 100%;
	height: 100%;
	border: 0;
	display: block;
	background: var(--color-background-dark);
}

.video-widget__placeholder {
	width: 100%;
	height: 100%;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 8px;
	color: var(--color-text-maxcontrast);
	padding: 16px;
	text-align: center;
}

.video-widget__placeholder-label {
	font-size: 13px;
}
</style>
