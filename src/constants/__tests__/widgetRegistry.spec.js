/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit test for `widgetRegistry.js` covering REQ-LBL-007: importing
 * the registry exposes a `label` entry with the correct `defaultContent` and
 * the type appears in `listWidgetTypes()` so the AddWidgetModal type picker
 * can list it as a selectable option distinct from `text`.
 */

import { describe, it, expect, beforeEach } from 'vitest'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

describe('widgetRegistry', () => {
	it('REQ-LBL-007: exposes a `label` entry with the proper defaultContent', async () => {
		const { widgetRegistry } = await import('../widgetRegistry.js')
		expect(widgetRegistry.label).toBeDefined()
		expect(widgetRegistry.label.defaultContent).toEqual({
			text: '',
			fontSize: '16px',
			color: '',
			backgroundColor: '',
			fontWeight: 'bold',
			textAlign: 'center',
		})
	})

	it('REQ-LBL-007: `label` appears in listWidgetTypes() output', async () => {
		const { listWidgetTypes } = await import('../widgetRegistry.js')
		expect(listWidgetTypes()).toContain('label')
	})

	it('REQ-LBN-001..007 / REQ-LBLM-001..009: exposes a `link` entry with the proper defaultContent', async () => {
		const { widgetRegistry } = await import('../widgetRegistry.js')
		expect(widgetRegistry.link).toBeDefined()
		expect(widgetRegistry.link.defaultContent).toEqual({
			label: '',
			url: '',
			icon: '',
			actionType: 'external',
			backgroundColor: '',
			textColor: '',
			displayMode: 'button',
			listOrientation: 'vertical',
			listItemGap: 'normal',
			links: [],
		})
	})

	it('REQ-LBN-001..007: `link` appears in listWidgetTypes() output', async () => {
		const { listWidgetTypes } = await import('../widgetRegistry.js')
		expect(listWidgetTypes()).toContain('link')
	})

	it('getWidgetTypeEntry returns null for unknown type', async () => {
		const { getWidgetTypeEntry } = await import('../widgetRegistry.js')
		expect(getWidgetTypeEntry('does-not-exist')).toBeNull()
	})

	it('getDefaultContent returns a fresh copy of defaults', async () => {
		const { getDefaultContent } = await import('../widgetRegistry.js')
		const a = getDefaultContent('label')
		const b = getDefaultContent('label')
		expect(a).toEqual(b)
		expect(a).not.toBe(b)
	})

	it('REQ-TXT-004/005 + REQ-TXMD-001 + REQ-TBLE-002: exposes a `text` entry with the proper defaultContent', async () => {
		const { widgetRegistry } = await import('../widgetRegistry.js')
		expect(widgetRegistry.text).toBeDefined()
		expect(widgetRegistry.text.defaultContent).toEqual({
			text: '',
			fontSize: '14px',
			color: '',
			backgroundColor: '',
			textAlign: 'left',
			// REQ-TXMD-001 / REQ-TXMD-005: new text widgets default to
			// markdown so authors can use lightweight syntax out of the
			// box; existing placements without `contentMode` keep their
			// legacy HTML rendering.
			contentMode: 'markdown',
			tableMode: false,
			tableData: null,
		})
	})

	it('REQ-TXT-004: `text` appears in listWidgetTypes() output', async () => {
		const { listWidgetTypes } = await import('../widgetRegistry.js')
		expect(listWidgetTypes()).toContain('text')
	})

	it('REQ-WDG-018: exposes an `nc-widget` entry with the proper defaultContent', async () => {
		const { widgetRegistry } = await import('../widgetRegistry.js')
		expect(widgetRegistry['nc-widget']).toBeDefined()
		expect(widgetRegistry['nc-widget'].defaultContent).toEqual({
			widgetId: '',
			displayMode: 'vertical',
		})
	})

	it('REQ-WDG-018: `nc-widget` appears in listWidgetTypes() output', async () => {
		const { listWidgetTypes } = await import('../widgetRegistry.js')
		expect(listWidgetTypes()).toContain('nc-widget')
	})

	it('REQ-WDG-014: listWidgetTypes() omits entries without a registered form', async () => {
		// Per-widget proposals (text, image, link-button, nc-dashboard-proxy)
		// each register their sub-form when they ship. Until then those
		// entries either don't exist or carry `form: null` — the picker
		// MUST exclude them so the user is never offered an unconfigurable
		// type. We simulate the situation by mutating the registry in
		// place and asserting the filter behaviour, then reset.
		const mod = await import('../widgetRegistry.js')
		mod.widgetRegistry.__formless_test__ = {
			renderer: {},
			form: null,
			defaultContent: {},
			displayName: 'Formless',
			icon: 'X',
		}
		try {
			const types = mod.listWidgetTypes()
			expect(types).toContain('label')
			expect(types).not.toContain('__formless_test__')
		} finally {
			delete mod.widgetRegistry.__formless_test__
		}
	})

	it('REQ-IMG-005: exposes an `image` entry with renderer + form + defaults', async () => {
		const { widgetRegistry } = await import('../widgetRegistry.js')
		expect(widgetRegistry.image).toBeDefined()
		expect(widgetRegistry.image.renderer).toBeTruthy()
		expect(widgetRegistry.image.form).toBeTruthy()
		expect(widgetRegistry.image.defaultContent).toEqual({
			url: '',
			alt: '',
			link: '',
			fit: 'cover',
		})
	})

	it('REQ-IMG-005: `image` appears in listWidgetTypes() output', async () => {
		const { listWidgetTypes } = await import('../widgetRegistry.js')
		expect(listWidgetTypes()).toContain('image')
	})

	it('REQ-DIV-002: exposes a `divider` entry with renderer + form + defaults', async () => {
		const { widgetRegistry } = await import('../widgetRegistry.js')
		expect(widgetRegistry.divider).toBeDefined()
		expect(widgetRegistry.divider.renderer).toBeTruthy()
		expect(widgetRegistry.divider.form).toBeTruthy()
		expect(widgetRegistry.divider.defaultContent).toEqual({
			style: 'line',
			lineColor: '',
			lineThickness: 1,
			lineStyle: 'solid',
			whitespaceSize: 'medium',
			headingText: '',
		})
	})

	it('REQ-DIV-002: `divider` appears in listWidgetTypes() output', async () => {
		const { listWidgetTypes } = await import('../widgetRegistry.js')
		expect(listWidgetTypes()).toContain('divider')
	})

	it('REQ-NEWS-001..011: exposes a `news` entry with the proper defaultContent', async () => {
		const { widgetRegistry } = await import('../widgetRegistry.js')
		expect(widgetRegistry.news).toBeDefined()
		expect(widgetRegistry.news.renderer).toBeTruthy()
		expect(widgetRegistry.news.form).toBeTruthy()
		expect(widgetRegistry.news.defaultContent).toEqual({
			feedUrls: [],
			layout: 'list',
			itemLimit: 10,
			showThumbnails: true,
			showSummary: true,
			summaryMaxChars: 200,
			dateFormat: 'relative',
			metadataFilter: null,
		})
	})

	it('REQ-NEWS-001..011: `news` appears in listWidgetTypes() output', async () => {
		const { listWidgetTypes } = await import('../widgetRegistry.js')
		expect(listWidgetTypes()).toContain('news')
	})

	it('REQ-VID-001/002: exposes a `video` entry with renderer + form + defaults', async () => {
		const { widgetRegistry } = await import('../widgetRegistry.js')
		expect(widgetRegistry.video).toBeDefined()
		expect(widgetRegistry.video.renderer).toBeTruthy()
		expect(widgetRegistry.video.form).toBeTruthy()
		expect(widgetRegistry.video.defaultContent).toEqual({
			sourceType: null,
			videoUrl: '',
			fileId: null,
			autoplay: false,
			muted: true,
			loop: false,
			controls: true,
			aspectRatio: '16:9',
			posterUrl: '',
		})
	})

	it('REQ-VID-001: `video` appears in listWidgetTypes() output', async () => {
		const { listWidgetTypes } = await import('../widgetRegistry.js')
		expect(listWidgetTypes()).toContain('video')
	})

	it('REQ-CAL-001: exposes a `calendar` entry with renderer + form + defaults', async () => {
		const { widgetRegistry } = await import('../widgetRegistry.js')
		expect(widgetRegistry.calendar).toBeDefined()
		expect(widgetRegistry.calendar.renderer).toBeTruthy()
		expect(widgetRegistry.calendar.form).toBeTruthy()
		expect(widgetRegistry.calendar.defaultContent).toEqual({
			internalCalendars: [],
			externalIcsUrls: [],
			viewMode: 'agenda',
			daysAhead: 14,
			colorByCalendar: true,
		})
	})

	it('REQ-CAL-001: `calendar` appears in listWidgetTypes() output', async () => {
		const { listWidgetTypes } = await import('../widgetRegistry.js')
		expect(listWidgetTypes()).toContain('calendar')
	})

	it('REQ-LNKS-001..002: exposes a `links` entry with renderer + form + spec defaults', async () => {
		const { widgetRegistry } = await import('../widgetRegistry.js')
		expect(widgetRegistry.links).toBeDefined()
		expect(widgetRegistry.links.renderer).toBeTruthy()
		expect(widgetRegistry.links.form).toBeTruthy()
		expect(widgetRegistry.links.defaultContent).toEqual({
			sections: [],
			columns: 3,
			linkLayout: 'card',
			iconSize: 'medium',
			openInNewTab: true,
			showSectionTitles: true,
			showLinkDescriptions: true,
		})
	})

	it('REQ-LNKS-001: `links` appears in listWidgetTypes() output (visible in picker)', async () => {
		const { listWidgetTypes } = await import('../widgetRegistry.js')
		expect(listWidgetTypes()).toContain('links')
	})

	it('REQ-CONT-001: exposes a `container` entry with renderer + form + spec defaults', async () => {
		const { widgetRegistry } = await import('../widgetRegistry.js')
		expect(widgetRegistry.container).toBeDefined()
		expect(widgetRegistry.container.renderer).toBeTruthy()
		expect(widgetRegistry.container.form).toBeTruthy()
		expect(widgetRegistry.container.defaultContent).toEqual({
			placements: [],
			backgroundColor: 'transparent',
			padding: 'medium',
			title: '',
		})
	})

	it('REQ-CONT-001: `container` appears in listWidgetTypes() output (visible in picker)', async () => {
		const { listWidgetTypes } = await import('../widgetRegistry.js')
		expect(listWidgetTypes()).toContain('container')
	})

	it('REQ-WDG-022 / REQ-TILE-PLACEMENT: exposes a `tile` entry with renderer + form + spec defaults', async () => {
		const { widgetRegistry } = await import('../widgetRegistry.js')
		expect(widgetRegistry.tile).toBeDefined()
		expect(widgetRegistry.tile.renderer).toBeTruthy()
		expect(widgetRegistry.tile.form).toBeTruthy()
		expect(widgetRegistry.tile.defaultContent).toEqual({
			title: '',
			icon: '',
			iconType: 'class',
			backgroundColor: '#3b82f6',
			textColor: '#ffffff',
			linkType: 'app',
			linkValue: '',
		})
	})

	it('REQ-WDG-022: `tile` appears in listWidgetTypes() output (visible in picker)', async () => {
		const { listWidgetTypes } = await import('../widgetRegistry.js')
		expect(listWidgetTypes()).toContain('tile')
	})
})
