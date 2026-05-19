/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit tests for `SetupWizardModal.vue`. Covers REQ-WIZ-002
 * (multi-step shell), REQ-WIZ-003 (storage backend persistence on
 * Step 2 → Next), REQ-WIZ-008 (state load on mount), and REQ-WIZ-009
 * (Finish triggers `completeSetupWizard`).
 *
 * The `api` module is mocked at the import boundary so no HTTP traffic
 * is generated. The embedded `GroupPriorityOrder` is stubbed to avoid
 * pulling its mount-time API call into the wizard test scope.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import SetupWizardModal from '../SetupWizardModal.vue'
import { api } from '../../../services/api.js'

vi.mock('../../../services/api.js', () => ({
	api: {
		getSetupWizardState: vi.fn(),
		setSetupWizardStorage: vi.fn(),
		completeSetupWizard: vi.fn(),
	},
}))

const ncButtonStub = {
	name: 'NcButton',
	props: ['type', 'disabled'],
	template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
}
const ncModalStub = {
	name: 'NcModal',
	template: '<div class="nc-modal-stub"><slot /></div>',
}
const groupPriorityStub = {
	name: 'GroupPriorityOrder',
	template: '<div class="group-priority-stub" />',
}

beforeEach(() => {
	api.getSetupWizardState.mockReset().mockResolvedValue({
		data: {
			complete: false,
			currentRecommendedStep: 1,
			contentStorage: 'database',
			groupfolderAvailable: true,
			stepStatuses: { 1: 'done', 2: 'pending' },
		},
	})
	api.setSetupWizardStorage.mockReset().mockResolvedValue({ data: { complete: false } })
	api.completeSetupWizard.mockReset().mockResolvedValue({ data: { complete: true } })
})

function mountWizard() {
	return mount(SetupWizardModal, {
		stubs: {
			NcButton: ncButtonStub,
			NcModal: ncModalStub,
			GroupPriorityOrder: groupPriorityStub,
		},
	})
}

async function flush() {
	await new Promise((resolve) => setTimeout(resolve, 0))
	await new Promise((resolve) => setTimeout(resolve, 0))
}

describe('SetupWizardModal', () => {
	it('REQ-WIZ-008: loads wizard state on mount', async () => {
		mountWizard()
		await flush()

		expect(api.getSetupWizardState).toHaveBeenCalledOnce()
	})

	it('REQ-WIZ-002: starts at step 1 with the counter rendered', async () => {
		const wrapper = mountWizard()
		await flush()

		expect(wrapper.vm.currentStep).toBe(1)
		// The Vue mixin's t() stub returns the bare key; we just assert the
		// counter element is present and tied to the wizard's step state.
		expect(wrapper.find('[data-test="setup-wizard-counter"]').exists()).toBe(true)
		expect(wrapper.vm.totalSteps).toBe(7)
	})

	it('REQ-WIZ-002: Next advances; Back returns; counter updates', async () => {
		const wrapper = mountWizard()
		await flush()

		await wrapper.find('[data-test="setup-wizard-next"]').trigger('click')
		await flush()
		expect(wrapper.vm.currentStep).toBe(2)

		await wrapper.find('[data-test="setup-wizard-back"]').trigger('click')
		expect(wrapper.vm.currentStep).toBe(1)
	})

	it('REQ-WIZ-003: Step 2 Next persists the storage choice via the API', async () => {
		const wrapper = mountWizard()
		await flush()

		// Advance to Step 2.
		await wrapper.find('[data-test="setup-wizard-next"]').trigger('click')
		await flush()
		expect(wrapper.vm.currentStep).toBe(2)

		// Change selection then click Next.
		wrapper.vm.storage = 'groupfolder'
		await wrapper.find('[data-test="setup-wizard-next"]').trigger('click')
		await flush()

		expect(api.setSetupWizardStorage).toHaveBeenCalledWith('groupfolder')
		expect(wrapper.vm.currentStep).toBe(3)
	})

	it('REQ-WIZ-002: Step 7 Next is labelled Finish and calls completeSetupWizard', async () => {
		const wrapper = mountWizard()
		await flush()

		// Jump straight to step 7 to keep the test focused.
		wrapper.vm.currentStep = 7
		await flush()

		// The Vue mixin's t() stub returns the bare key; the component
		// branches on `isFinalStep` to swap "Next" → "Finish".
		expect(wrapper.vm.isFinalStep).toBe(true)
		expect(wrapper.find('[data-test="setup-wizard-skip"]').exists()).toBe(false)

		await wrapper.find('[data-test="setup-wizard-next"]').trigger('click')
		await flush()
		expect(api.completeSetupWizard).toHaveBeenCalledOnce()
		expect(wrapper.emitted('completed')).toBeTruthy()
		expect(wrapper.emitted('close')).toBeTruthy()
	})

	it('REQ-WIZ-003: GroupFolder radio is disabled when the app is missing', async () => {
		api.getSetupWizardState.mockResolvedValueOnce({
			data: {
				complete: false,
				currentRecommendedStep: 1,
				contentStorage: 'database',
				groupfolderAvailable: false,
				stepStatuses: { 1: 'done' },
			},
		})
		const wrapper = mountWizard()
		await flush()

		// Advance to step 2.
		await wrapper.find('[data-test="setup-wizard-next"]').trigger('click')
		await flush()

		const radio = wrapper.find('[data-test="storage-groupfolder"]')
		expect(radio.attributes('disabled')).toBeDefined()
	})

	it('REQ-WIZ-002: Skip advances without committing on optional steps', async () => {
		const wrapper = mountWizard()
		await flush()
		wrapper.vm.currentStep = 4
		await flush()

		await wrapper.find('[data-test="setup-wizard-skip"]').trigger('click')
		expect(wrapper.vm.currentStep).toBe(5)
		expect(api.setSetupWizardStorage).not.toHaveBeenCalled()
	})
})
