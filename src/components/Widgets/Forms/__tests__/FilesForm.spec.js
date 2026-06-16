/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `FilesForm.vue` covering REQ-FLS-002:
 * validation requires either `folderPath` OR `fileId`, the form
 * pre-fills every control from `editingWidget.content`, and the form
 * normalises the comma-separated MIME filter input into the persisted
 * array shape.
 */

import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import FilesForm from '../FilesForm.vue'

beforeEach(() => {
	globalThis.t = (_app, key, vars) => {
		if (!vars) {
			return key
		}
		return Object.entries(vars).reduce(
			(acc, [varName, value]) => acc.replace(`{${varName}}`, value),
			key,
		)
	}
})

describe('FilesForm', () => {
	it('REQ-FLS-002: validate() returns one error when neither folderPath nor fileId is set', () => {
		const wrapper = mount(FilesForm, {
			propsData: { value: { folderPath: '', fileId: null } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		expect(wrapper.vm.validate()).toContain('Folder path or folder id is required')
	})

	it('REQ-FLS-002: validate() returns empty array when folderPath is set', () => {
		const wrapper = mount(FilesForm, {
			propsData: { value: { folderPath: '/Documents', fileId: null } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		expect(wrapper.vm.validate()).toEqual([])
	})

	it('REQ-FLS-002: validate() returns empty array when fileId is set', () => {
		const wrapper = mount(FilesForm, {
			propsData: { value: { folderPath: '', fileId: 42 } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		expect(wrapper.vm.validate()).toEqual([])
	})

	it('REQ-FLS-002: pre-fills all controls from editingWidget.content', () => {
		const editingWidget = {
			content: {
				folderPath: '/Documents/Marketing',
				fileId: 99,
				viewMode: 'grid',
				showThumbnails: false,
				mimeTypeFilter: ['image/*', 'application/pdf'],
				allowUpload: true,
				allowDelete: true,
				sortBy: 'modified',
				sortDescending: true,
			},
		}
		const wrapper = mount(FilesForm, {
			propsData: { editingWidget },
			stubs: { NcTextField: true, NcSelect: true },
		})
		expect(wrapper.vm.folderPath).toBe('/Documents/Marketing')
		expect(wrapper.vm.fileId).toBe(99)
		expect(wrapper.vm.viewMode).toBe('grid')
		expect(wrapper.vm.showThumbnails).toBe(false)
		expect(wrapper.vm.mimeTypeFilter).toEqual(['image/*', 'application/pdf'])
		expect(wrapper.vm.allowUpload).toBe(true)
		expect(wrapper.vm.allowDelete).toBe(true)
		expect(wrapper.vm.sortBy).toBe('modified')
		expect(wrapper.vm.sortDescending).toBe(true)
	})

	it('REQ-FLS-002: defaults applied when value is empty', () => {
		const wrapper = mount(FilesForm, {
			propsData: { value: {} },
			stubs: { NcTextField: true, NcSelect: true },
		})
		expect(wrapper.vm.viewMode).toBe('list')
		expect(wrapper.vm.showThumbnails).toBe(true)
		expect(wrapper.vm.allowUpload).toBe(false)
		expect(wrapper.vm.allowDelete).toBe(false)
		expect(wrapper.vm.sortBy).toBe('name')
		expect(wrapper.vm.sortDescending).toBe(false)
		expect(wrapper.vm.mimeTypeFilter).toEqual([])
	})

	it('REQ-FLS-002: normalises comma-separated MIME filter input', () => {
		const wrapper = mount(FilesForm, {
			propsData: { value: { folderPath: '/x' } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		wrapper.vm.updateMimeFilter('image/*, application/pdf,  text/plain ,, ')
		expect(wrapper.vm.mimeTypeFilter).toEqual(['image/*', 'application/pdf', 'text/plain'])
	})

	it('REQ-FLS-002: rejects non-numeric fileId, accepts valid one', () => {
		const wrapper = mount(FilesForm, {
			propsData: { value: { folderPath: '/x' } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		wrapper.vm.updateFileId('garbage')
		expect(wrapper.vm.fileId).toBeNull()
		wrapper.vm.updateFileId('42')
		expect(wrapper.vm.fileId).toBe(42)
		wrapper.vm.updateFileId('-3')
		expect(wrapper.vm.fileId).toBeNull()
	})

	it('emits update:content with the assembled payload when a field changes', () => {
		const wrapper = mount(FilesForm, {
			propsData: { value: { folderPath: '/x' } },
			stubs: { NcTextField: true, NcSelect: true },
		})
		wrapper.vm.updateField('viewMode', 'grid')
		const emitted = wrapper.emitted('update:content')
		expect(emitted).toBeTruthy()
		expect(emitted[emitted.length - 1][0]).toMatchObject({
			folderPath: '/x',
			viewMode: 'grid',
		})
	})
})
