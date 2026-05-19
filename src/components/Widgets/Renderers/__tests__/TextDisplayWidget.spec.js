/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `TextDisplayWidget.vue` covering REQ-TXT-001..005
 * and REQ-TXMD-001..007. Verifies DOMPurify-backed sanitisation
 * (script/handler/javascript-URL stripped while safe formatting preserved),
 * default-style application with theme-aware fallbacks, the empty-content
 * placeholder, the wrapper-fills-cell layout contract, the markdown
 * rendering path (CommonMark headings/emphasis/links/lists/tables), and
 * the unified sanitiser that protects both modes through one trust
 * boundary.
 */

import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import TextDisplayWidget from '../TextDisplayWidget.vue'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

describe('TextDisplayWidget', () => {
	describe('REQ-TXT-001: HTML sanitisation via DOMPurify', () => {
		it('preserves common formatting tags (<b>, <i>, <a>, <br>, <p>, <ul>, <li>)', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: {
					content: {
						contentMode: 'html',
						text: 'Hello <b>world</b> <i>x</i> <a href="https://example.com">link</a><br><p>p</p><ul><li>li</li></ul>',
					},
				},
			})
			const html = wrapper.find('.text-display-widget__content').html()
			expect(html).toContain('<b>world</b>')
			expect(html).toContain('<i>x</i>')
			expect(html).toContain('<a href="https://example.com">link</a>')
			expect(html).toContain('<br>')
			expect(html).toContain('<p>p</p>')
			expect(html).toContain('<ul>')
			expect(html).toContain('<li>li</li>')
		})

		it('strips <script> tags entirely', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: { content: { contentMode: 'html', text: 'Click <script>alert(1)</script> me' } },
			})
			expect(wrapper.find('script').exists()).toBe(false)
			expect(wrapper.find('.text-display-widget__content').html()).not.toContain('<script')
		})

		it('strips on* event handler attributes', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: { content: { contentMode: 'html', text: '<a href="x" onclick="alert(1)">x</a>' } },
			})
			const a = wrapper.find('.text-display-widget__content a')
			expect(a.exists()).toBe(true)
			expect(a.attributes('onclick')).toBeUndefined()
			// href should be preserved
			expect(a.attributes('href')).toBe('x')
		})

		it('strips javascript: URLs from href', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: { content: { contentMode: 'html', text: '<a href="javascript:alert(1)">x</a>' } },
			})
			const a = wrapper.find('.text-display-widget__content a')
			// DOMPurify removes the offending href entirely; the <a> may or
			// may not survive — the contract only requires no javascript: href.
			if (a.exists()) {
				const href = a.attributes('href') || ''
				expect(href.toLowerCase().startsWith('javascript:')).toBe(false)
			}
		})

		it('strips <style> and <link> tags so dashboards layout is not hijacked', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: { content: { contentMode: 'html', text: '<style>body{display:none}</style><link rel="stylesheet" href="x">hello' } },
			})
			const html = wrapper.find('.text-display-widget__content').html()
			expect(html).not.toContain('<style')
			expect(html).not.toContain('<link')
			expect(wrapper.text()).toContain('hello')
		})
	})

	describe('REQ-TXT-002: style application with theme-aware fallbacks', () => {
		it('applies provided custom values', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: {
					content: {
						text: 'X',
						fontSize: '24px',
						color: '#ff0000',
					},
				},
			})
			const inner = wrapper.find('.text-display-widget__content')
			const innerStyle = inner.attributes('style') || ''
			expect(innerStyle).toContain('font-size: 24px')
			expect(innerStyle).toContain('color: rgb(255, 0, 0)')
			expect(innerStyle).toContain('text-align: left')
			const outer = wrapper.find('.text-display-widget')
			const outerStyle = outer.attributes('style') || ''
			expect(outerStyle).toContain('background-color: transparent')
		})

		it('falls back to theme variable for color when empty', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: { content: { text: 'X', color: '' } },
			})
			const innerStyle = wrapper.find('.text-display-widget__content').attributes('style') || ''
			expect(innerStyle).toContain('color: var(--color-main-text)')
		})

		it('accepts free-form font-size like 1.2em verbatim', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: { content: { text: 'X', fontSize: '1.2em' } },
			})
			const innerStyle = wrapper.find('.text-display-widget__content').attributes('style') || ''
			expect(innerStyle).toContain('font-size: 1.2em')
		})

		it('falls back to all defaults when only text is provided', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: { content: { text: 'X' } },
			})
			const innerStyle = wrapper.find('.text-display-widget__content').attributes('style') || ''
			expect(innerStyle).toContain('font-size: 14px')
			expect(innerStyle).toContain('text-align: left')
			expect(innerStyle).toContain('color: var(--color-main-text)')
			const outerStyle = wrapper.find('.text-display-widget').attributes('style') || ''
			expect(outerStyle).toContain('background-color: transparent')
		})
	})

	describe('REQ-TXT-003: empty-content placeholder', () => {
		it('shows translated `No text content` placeholder when text is empty', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: { content: { text: '' } },
			})
			expect(wrapper.find('.text-display-widget__content').exists()).toBe(false)
			const placeholder = wrapper.find('.text-display-widget__placeholder')
			expect(placeholder.exists()).toBe(true)
			expect(placeholder.text()).toBe('No text content')
		})

		it('treats whitespace-only text as empty', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: { content: { text: '   \n  ' } },
			})
			const placeholder = wrapper.find('.text-display-widget__placeholder')
			expect(placeholder.exists()).toBe(true)
			expect(placeholder.text()).toBe('No text content')
		})

		it('placeholder is italic and uses var(--color-text-maxcontrast)', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: { content: { text: '' } },
			})
			const style = wrapper.find('.text-display-widget__placeholder').attributes('style') || ''
			expect(style).toContain('font-style: italic')
			expect(style).toContain('color: var(--color-text-maxcontrast)')
		})
	})

	describe('REQ-TXT-005: layout fills cell with padded scrollable content', () => {
		it('wrapper occupies full cell with padding 16px and overflow auto', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: { content: { text: 'X' } },
			})
			const style = wrapper.find('.text-display-widget').attributes('style') || ''
			expect(style).toContain('width: 100%')
			expect(style).toContain('height: 100%')
			expect(style).toContain('padding: 16px')
			expect(style).toContain('overflow: auto')
			expect(style).toContain('display: flex')
			expect(style).toContain('align-items: center')
			expect(style).toContain('justify-content: center')
		})
	})

	describe('REQ-TXMD-001 / REQ-TXMD-006: contentMode default and backward compatibility', () => {
		it('absent contentMode renders as legacy HTML (does not parse markdown syntax)', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: { content: { text: '# Heading' } },
			})
			const html = wrapper.find('.text-display-widget__content').html()
			// HTML mode shows the literal text — '#' is NOT parsed as h1.
			expect(html).toContain('# Heading')
			expect(wrapper.find('.text-display-widget__content h1').exists()).toBe(false)
		})

		it('contentMode = "html" renders raw HTML through DOMPurify', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: { content: { contentMode: 'html', text: '<b>bold</b>' } },
			})
			expect(wrapper.find('.text-display-widget__content').html()).toContain('<b>bold</b>')
		})

		it('content wrapper carries the html mode class for legacy widgets', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: { content: { text: 'plain' } },
			})
			expect(wrapper.find('.text-display-widget__content--html').exists()).toBe(true)
			expect(wrapper.find('.text-display-widget__content--markdown').exists()).toBe(false)
		})

		it('content wrapper carries the markdown mode class when active', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: { content: { contentMode: 'markdown', text: '# H' } },
			})
			expect(wrapper.find('.text-display-widget__content--markdown').exists()).toBe(true)
			expect(wrapper.find('.text-display-widget__content--html').exists()).toBe(false)
		})
	})

	describe('REQ-TXMD-002 / REQ-TXMD-007: CommonMark parsing in markdown mode', () => {
		it('parses # headings into <h1>..<h6>', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: {
					content: {
						contentMode: 'markdown',
						text: '# Main\n## Sub\n### Deep\n#### Four\n##### Five\n###### Six',
					},
				},
			})
			const content = wrapper.find('.text-display-widget__content')
			expect(content.find('h1').text()).toBe('Main')
			expect(content.find('h2').text()).toBe('Sub')
			expect(content.find('h3').text()).toBe('Deep')
			expect(content.find('h4').text()).toBe('Four')
			expect(content.find('h5').text()).toBe('Five')
			expect(content.find('h6').text()).toBe('Six')
		})

		it('treats seven hashes as a paragraph (CommonMark rule)', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: {
					content: { contentMode: 'markdown', text: '####### Too many' },
				},
			})
			const content = wrapper.find('.text-display-widget__content')
			expect(content.find('h7').exists()).toBe(false)
			expect(content.text()).toContain('####### Too many')
		})

		it('parses **bold** and *italic* into <strong>/<em>', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: {
					content: {
						contentMode: 'markdown',
						text: '**bold** and *italic* and ***both***',
					},
				},
			})
			const html = wrapper.find('.text-display-widget__content').html()
			expect(html).toContain('<strong>bold</strong>')
			expect(html).toContain('<em>italic</em>')
			// Combined emphasis is rendered as nested <em><strong> or
			// <strong><em>; either ordering is CommonMark-conformant.
			expect(html).toMatch(/<em><strong>both<\/strong><\/em>|<strong><em>both<\/em><\/strong>/)
		})

		it('parses inline `code` into a <code> element', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: {
					content: { contentMode: 'markdown', text: 'Use `npm install` to set up' },
				},
			})
			const code = wrapper.find('.text-display-widget__content code')
			expect(code.exists()).toBe(true)
			expect(code.text()).toBe('npm install')
		})

		it('parses [link](url) into an anchor with the correct href', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: {
					content: {
						contentMode: 'markdown',
						text: '[OpenRegister](https://openregister.nl)',
					},
				},
			})
			const a = wrapper.find('.text-display-widget__content a')
			expect(a.exists()).toBe(true)
			expect(a.attributes('href')).toBe('https://openregister.nl')
			expect(a.text()).toBe('OpenRegister')
		})

		it('parses bullet lists into <ul><li>', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: {
					content: {
						contentMode: 'markdown',
						text: '- Item A\n- Item B\n- Item C',
					},
				},
			})
			const ul = wrapper.find('.text-display-widget__content ul')
			expect(ul.exists()).toBe(true)
			expect(ul.findAll('li').length).toBe(3)
			expect(ul.findAll('li').at(0).text()).toBe('Item A')
		})

		it('parses ordered lists into <ol><li>', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: {
					content: {
						contentMode: 'markdown',
						text: '1. First\n2. Second\n3. Third',
					},
				},
			})
			const ol = wrapper.find('.text-display-widget__content ol')
			expect(ol.exists()).toBe(true)
			expect(ol.findAll('li').length).toBe(3)
		})

		it('parses block quotes into <blockquote>', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: {
					content: {
						contentMode: 'markdown',
						text: '> This is a quote\n> from someone',
					},
				},
			})
			const bq = wrapper.find('.text-display-widget__content blockquote')
			expect(bq.exists()).toBe(true)
			expect(bq.text()).toContain('This is a quote')
		})

		it('parses GFM tables into <table>/<thead>/<tbody>', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: {
					content: {
						contentMode: 'markdown',
						text: '| Header A | Header B |\n|---|---|\n| Cell A1 | Cell B1 |',
					},
				},
			})
			const content = wrapper.find('.text-display-widget__content')
			expect(content.find('table').exists()).toBe(true)
			expect(content.find('thead').exists()).toBe(true)
			expect(content.find('tbody').exists()).toBe(true)
			expect(content.findAll('th').length).toBe(2)
			expect(content.findAll('td').length).toBe(2)
		})
	})

	describe('REQ-TXMD-003: sanitisation of parsed markdown output', () => {
		it('strips <script> tags embedded in markdown source', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: {
					content: {
						contentMode: 'markdown',
						text: '<script>alert(1)</script>\n\n**bold survives**',
					},
				},
			})
			const html = wrapper.find('.text-display-widget__content').html()
			expect(html).not.toContain('<script')
			expect(html).toContain('<strong>bold survives</strong>')
		})

		it('strips javascript: URLs from markdown links', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: {
					content: {
						contentMode: 'markdown',
						text: '[click](javascript:alert(1))',
					},
				},
			})
			const a = wrapper.find('.text-display-widget__content a')
			if (a.exists()) {
				const href = a.attributes('href') || ''
				expect(href.toLowerCase().startsWith('javascript:')).toBe(false)
			}
		})

		it('strips on* event handlers from inline HTML inside markdown', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: {
					content: {
						contentMode: 'markdown',
						text: '<a href="x" onclick="alert(1)">click</a>',
					},
				},
			})
			const a = wrapper.find('.text-display-widget__content a')
			if (a.exists()) {
				expect(a.attributes('onclick')).toBeUndefined()
			}
		})

		it('adds rel="noopener noreferrer" to anchors with target="_blank"', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: {
					content: {
						contentMode: 'html',
						text: '<a href="https://example.com" target="_blank">x</a>',
					},
				},
			})
			const a = wrapper.find('.text-display-widget__content a')
			expect(a.exists()).toBe(true)
			expect(a.attributes('target')).toBe('_blank')
			const rel = a.attributes('rel') || ''
			expect(rel).toContain('noopener')
			expect(rel).toContain('noreferrer')
		})
	})

	describe('REQ-TBLE-009: table-mode rendering', () => {
		const sampleTable = (overrides = {}) => ({
			tableMode: true,
			tableData: {
				headerRow: false,
				columnAlignments: ['left', 'center'],
				rows: [
					[
						{ text: 'A', rowSpan: 1, colSpan: 1 },
						{ text: 'B', rowSpan: 1, colSpan: 1 },
					],
					[
						{ text: 'C', rowSpan: 1, colSpan: 1 },
						{ text: 'D', rowSpan: 1, colSpan: 1 },
					],
				],
				...overrides,
			},
		})

		it('renders an HTML <table> with one <td> per cell', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: { content: sampleTable() },
			})
			expect(wrapper.find('table.text-display-widget__table').exists()).toBe(true)
			expect(wrapper.findAll('td').length).toBe(4)
			expect(wrapper.find('th').exists()).toBe(false)
		})

		it('renders <th> for row 0 when headerRow is true', () => {
			const content = sampleTable()
			content.tableData.headerRow = true
			const wrapper = mount(TextDisplayWidget, { propsData: { content } })
			const ths = wrapper.findAll('th')
			expect(ths.length).toBe(2)
			expect(ths.at(0).text()).toBe('A')
		})

		it('applies per-column text-align based on columnAlignments', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: { content: sampleTable() },
			})
			const tds = wrapper.findAll('td')
			expect(tds.at(0).attributes('style') || '').toContain('text-align: left')
			expect(tds.at(1).attributes('style') || '').toContain('text-align: center')
		})

		it('emits rowspan / colspan attributes from cell metadata', () => {
			const content = {
				tableMode: true,
				tableData: {
					headerRow: false,
					columnAlignments: ['left', 'left'],
					rows: [
						[
							{ text: 'Wide', rowSpan: 1, colSpan: 2 },
							{ text: '', rowSpan: 1, colSpan: 1 },
						],
						[
							{ text: 'A', rowSpan: 1, colSpan: 1 },
							{ text: 'B', rowSpan: 1, colSpan: 1 },
						],
					],
				},
			}
			const wrapper = mount(TextDisplayWidget, { propsData: { content } })
			const wide = wrapper.find('td')
			expect(wide.attributes('colspan')).toBe('2')
		})

		it('runs cell text through DOMPurify (script tag stripped)', () => {
			const content = {
				tableMode: true,
				tableData: {
					headerRow: false,
					columnAlignments: ['left'],
					rows: [[{ text: '<script>alert(1)</script>safe', rowSpan: 1, colSpan: 1 }]],
				},
			}
			const wrapper = mount(TextDisplayWidget, { propsData: { content } })
			expect(wrapper.find('script').exists()).toBe(false)
			expect(wrapper.find('td').text()).toContain('safe')
		})

		it('shows the localised "Empty cell" placeholder for empty cells', () => {
			const content = {
				tableMode: true,
				tableData: {
					headerRow: false,
					columnAlignments: ['left'],
					rows: [[{ text: '', rowSpan: 1, colSpan: 1 }]],
				},
			}
			const wrapper = mount(TextDisplayWidget, { propsData: { content } })
			expect(wrapper.text()).toContain('Empty cell')
		})

		it('falls through to the text path when tableMode is false', () => {
			const wrapper = mount(TextDisplayWidget, {
				propsData: { content: { tableMode: false, text: 'plain' } },
			})
			expect(wrapper.find('table').exists()).toBe(false)
			expect(wrapper.text()).toContain('plain')
		})
	})
})
