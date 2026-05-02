/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `useWidgetForm` composable. Covers the four helpers
 * the AddWidgetModal relies on (REQ-WDG-010, REQ-WDG-012):
 *  - resetForm() drops state to registry defaults
 *  - loadEditingWidget() pre-fills from an existing placement
 *  - validate() forwards to the active sub-form
 *  - assembleContent() builds {type, content} payload
 */

import { describe, it, expect, beforeEach } from 'vitest'

beforeEach(() => {
	globalThis.t = (_app, key) => key
})

describe('useWidgetForm', () => {
	it('resetForm() seeds state with the registry default content for the type', async () => {
		const { useWidgetForm } = await import('../useWidgetForm.js')
		const form = useWidgetForm()
		form.resetForm('label')
		expect(form.state.type).toBe('label')
		expect(form.state.editingWidget).toBeNull()
		expect(form.state.content).toEqual({
			text: '',
			fontSize: '16px',
			color: '',
			backgroundColor: '',
			fontWeight: 'bold',
			textAlign: 'center',
		})
	})

	it('resetForm() clears any previously loaded editing widget (no leak across reopens)', async () => {
		const { useWidgetForm } = await import('../useWidgetForm.js')
		const form = useWidgetForm()
		form.loadEditingWidget({ type: 'label', content: { text: 'Hi' } })
		form.resetForm('label')
		expect(form.state.editingWidget).toBeNull()
		expect(form.state.content.text).toBe('')
	})

	it('loadEditingWidget() pre-fills state from an existing placement, merged over defaults', async () => {
		const { useWidgetForm } = await import('../useWidgetForm.js')
		const form = useWidgetForm()
		form.loadEditingWidget({
			type: 'label',
			content: { text: 'Hello', color: '#ff0000' },
		})
		expect(form.state.type).toBe('label')
		// merged over defaults so missing keys still get sensible values
		expect(form.state.content.text).toBe('Hello')
		expect(form.state.content.color).toBe('#ff0000')
		expect(form.state.content.fontSize).toBe('16px') // from defaults
		expect(form.state.editingWidget).toBeTruthy()
	})

	it('validate() returns "no active form" sentinel when no sub-form is mounted', async () => {
		const { useWidgetForm } = await import('../useWidgetForm.js')
		const form = useWidgetForm()
		const errors = form.validate(null)
		expect(errors).toEqual(['__no-active-form__'])
	})

	it('validate() forwards to the active sub-form\'s validate() method', async () => {
		const { useWidgetForm } = await import('../useWidgetForm.js')
		const form = useWidgetForm()
		const fakeRef = { validate: () => ['oops'] }
		expect(form.validate(fakeRef)).toEqual(['oops'])
		const okRef = { validate: () => [] }
		expect(form.validate(okRef)).toEqual([])
	})

	it('assembleContent() prefers sub-form\'s assembledContent getter when present', async () => {
		const { useWidgetForm } = await import('../useWidgetForm.js')
		const form = useWidgetForm()
		form.resetForm('label')
		const fakeRef = { assembledContent: { text: 'from-getter' } }
		const payload = form.assembleContent(fakeRef)
		expect(payload).toEqual({
			type: 'label',
			content: { text: 'from-getter' },
		})
	})

	it('assembleContent() falls back to internal state.content when sub-form has no getter', async () => {
		const { useWidgetForm } = await import('../useWidgetForm.js')
		const form = useWidgetForm()
		form.resetForm('label')
		form.state.content = { text: 'from-state' }
		const payload = form.assembleContent({})
		expect(payload).toEqual({
			type: 'label',
			content: { text: 'from-state' },
		})
	})
})
