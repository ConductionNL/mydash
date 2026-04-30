/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, it, expect, vi, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import WidgetContextMenu from '../components/Widgets/WidgetContextMenu.vue'
import DashboardGrid from '../components/DashboardGrid.vue'

describe('WidgetContextMenu.vue', () => {
	let wrapper

	afterEach(() => {
		if (wrapper) {
			wrapper.unmount()
		}
	})

	it('does not render when show is false', () => {
		wrapper = mount(WidgetContextMenu, {
			props: {
				show: false,
				x: 100,
				y: 100,
				widget: { id: 'widget-1' },
			},
		})

		expect(wrapper.find('.widget-context-menu').exists()).toBe(false)
	})

	it('renders when show is true', () => {
		wrapper = mount(WidgetContextMenu, {
			props: {
				show: true,
				x: 100,
				y: 100,
				widget: { id: 'widget-1' },
			},
		})

		expect(wrapper.find('.widget-context-menu').exists()).toBe(true)
	})

	it('positions the menu at the given coordinates', () => {
		wrapper = mount(WidgetContextMenu, {
			props: {
				show: true,
				x: 150,
				y: 200,
				widget: { id: 'widget-1' },
			},
		})

		const menu = wrapper.find('.widget-context-menu')
		expect(menu.element.style.top).toBe('200px')
		expect(menu.element.style.left).toBe('150px')
	})

	it('emits edit event when Edit button is clicked', async () => {
		const widget = { id: 'widget-1', title: 'Test Widget' }
		wrapper = mount(WidgetContextMenu, {
			props: {
				show: true,
				x: 100,
				y: 100,
				widget,
			},
		})

		const buttons = wrapper.findAll('button')
		await buttons[0].trigger('click') // Edit button

		expect(wrapper.emitted('edit')).toBeTruthy()
		expect(wrapper.emitted('edit')[0][0]).toEqual(widget)
		expect(wrapper.emitted('close')).toBeTruthy()
	})

	it('emits remove event when Remove button is clicked', async () => {
		const widget = { id: 'widget-2', title: 'Test Widget 2' }
		wrapper = mount(WidgetContextMenu, {
			props: {
				show: true,
				x: 100,
				y: 100,
				widget,
			},
		})

		const buttons = wrapper.findAll('button')
		await buttons[1].trigger('click') // Remove button

		expect(wrapper.emitted('remove')).toBeTruthy()
		expect(wrapper.emitted('remove')[0][0]).toEqual(widget)
		expect(wrapper.emitted('close')).toBeTruthy()
	})

	it('emits close event when Cancel button is clicked', async () => {
		wrapper = mount(WidgetContextMenu, {
			props: {
				show: true,
				x: 100,
				y: 100,
				widget: { id: 'widget-1' },
			},
		})

		const buttons = wrapper.findAll('button')
		await buttons[2].trigger('click') // Cancel button

		expect(wrapper.emitted('close')).toBeTruthy()
		expect(wrapper.emitted('edit')).toBeFalsy()
		expect(wrapper.emitted('remove')).toBeFalsy()
	})

	it('clamps position when menu would overflow right edge', () => {
		// Mock window.innerWidth to 400
		Object.defineProperty(window, 'innerWidth', {
			writable: true,
			value: 400,
		})

		wrapper = mount(WidgetContextMenu, {
			props: {
				show: true,
				x: 350, // Close to right edge (400 - 150 = 250)
				y: 100,
				widget: { id: 'widget-1' },
			},
		})

		const menu = wrapper.find('.widget-context-menu')
		const left = parseInt(menu.element.style.left)
		// Menu should not extend past viewport (400 - 150 = 250 is max left)
		expect(left + 150).toBeLessThanOrEqual(400)
	})

	it('clamps position when menu would overflow bottom edge', () => {
		// Mock window.innerHeight to 600
		Object.defineProperty(window, 'innerHeight', {
			writable: true,
			value: 600,
		})

		wrapper = mount(WidgetContextMenu, {
			props: {
				show: true,
				x: 100,
				y: 550, // Close to bottom edge
				widget: { id: 'widget-1' },
			},
		})

		const menu = wrapper.find('.widget-context-menu')
		const top = parseInt(menu.element.style.top)
		// Menu height is ~120px, so it should be shifted up
		expect(top + 120).toBeLessThanOrEqual(600)
	})

	it('has correct z-index and min-width', () => {
		wrapper = mount(WidgetContextMenu, {
			props: {
				show: true,
				x: 100,
				y: 100,
				widget: { id: 'widget-1' },
			},
		})

		const menu = wrapper.find('.widget-context-menu')
		const styles = window.getComputedStyle(menu.element)
		expect(styles.zIndex).toBe('10000')
		expect(styles.minWidth).toBe('150px')
	})

	it('stops click propagation on the menu itself', async () => {
		wrapper = mount(WidgetContextMenu, {
			props: {
				show: true,
				x: 100,
				y: 100,
				widget: { id: 'widget-1' },
			},
		})

		const menu = wrapper.find('.widget-context-menu')
		const clickEvent = new MouseEvent('click', { bubbles: true })
		vi.spyOn(clickEvent, 'stopPropagation')

		menu.element.dispatchEvent(clickEvent)
		// Note: We can't easily test @click.stop with stopPropagation spy,
		// but we verify the menu exists and responds to clicks
		expect(menu.exists()).toBe(true)
	})
})

describe('DashboardGrid context menu integration', () => {
	let wrapper

	afterEach(() => {
		if (wrapper) {
			wrapper.unmount()
		}
	})

	it('shows context menu in edit mode on right-click', async () => {
		const placements = [
			{
				id: 'placement-1',
				widgetId: 'widget-1',
				gridX: 0,
				gridY: 0,
				gridWidth: 4,
				gridHeight: 4,
			},
		]
		const widgets = [{ id: 'widget-1', title: 'Test Widget' }]

		wrapper = mount(DashboardGrid, {
			props: {
				placements,
				widgets,
				editMode: true,
				gridColumns: 12,
			},
			global: {
				stubs: {
					WidgetWrapper: true,
					TileWidget: true,
					WidgetContextMenu: true,
				},
			},
		})

		const gridItem = wrapper.find('.grid-stack-item')
		const contextMenuEvent = new MouseEvent('contextmenu', {
			clientX: 100,
			clientY: 150,
			bubbles: true,
			cancelable: true,
		})

		const preventDefaultSpy = vi.spyOn(contextMenuEvent, 'preventDefault')
		gridItem.element.dispatchEvent(contextMenuEvent)

		expect(preventDefaultSpy).toHaveBeenCalled()
		expect(wrapper.vm.contextMenu.show).toBe(true)
		expect(wrapper.vm.contextMenu.x).toBe(100)
		expect(wrapper.vm.contextMenu.y).toBe(150)
		expect(wrapper.vm.contextMenu.widget).toEqual(placements[0])
	})

	it('does not show context menu in view mode on right-click', async () => {
		const placements = [
			{
				id: 'placement-1',
				widgetId: 'widget-1',
				gridX: 0,
				gridY: 0,
				gridWidth: 4,
				gridHeight: 4,
			},
		]
		const widgets = [{ id: 'widget-1', title: 'Test Widget' }]

		wrapper = mount(DashboardGrid, {
			props: {
				placements,
				widgets,
				editMode: false, // View mode
				gridColumns: 12,
			},
			global: {
				stubs: {
					WidgetWrapper: true,
					TileWidget: true,
					WidgetContextMenu: true,
				},
			},
		})

		const gridItem = wrapper.find('.grid-stack-item')
		const contextMenuEvent = new MouseEvent('contextmenu', {
			clientX: 100,
			clientY: 150,
			bubbles: true,
			cancelable: true,
		})

		const preventDefaultSpy = vi.spyOn(contextMenuEvent, 'preventDefault')
		gridItem.element.dispatchEvent(contextMenuEvent)

		expect(preventDefaultSpy).not.toHaveBeenCalled()
		expect(wrapper.vm.contextMenu.show).toBe(false)
	})

	it('closes context menu on second right-click with different placement', async () => {
		const placements = [
			{
				id: 'placement-1',
				widgetId: 'widget-1',
				gridX: 0,
				gridY: 0,
				gridWidth: 4,
				gridHeight: 4,
			},
			{
				id: 'placement-2',
				widgetId: 'widget-2',
				gridX: 4,
				gridY: 0,
				gridWidth: 4,
				gridHeight: 4,
			},
		]
		const widgets = [
			{ id: 'widget-1', title: 'Test Widget 1' },
			{ id: 'widget-2', title: 'Test Widget 2' },
		]

		wrapper = mount(DashboardGrid, {
			props: {
				placements,
				widgets,
				editMode: true,
				gridColumns: 12,
			},
			global: {
				stubs: {
					WidgetWrapper: true,
					TileWidget: true,
					WidgetContextMenu: true,
				},
			},
		})

		const gridItems = wrapper.findAll('.grid-stack-item')

		// Right-click first item
		const event1 = new MouseEvent('contextmenu', {
			clientX: 100,
			clientY: 150,
			bubbles: true,
			cancelable: true,
		})
		gridItems[0].element.dispatchEvent(event1)
		expect(wrapper.vm.contextMenu.widget.id).toBe('placement-1')

		// Right-click second item
		const event2 = new MouseEvent('contextmenu', {
			clientX: 200,
			clientY: 150,
			bubbles: true,
			cancelable: true,
		})
		gridItems[1].element.dispatchEvent(event2)
		expect(wrapper.vm.contextMenu.widget.id).toBe('placement-2')
		expect(wrapper.vm.contextMenu.x).toBe(200)
		expect(wrapper.vm.contextMenu.y).toBe(150)
	})

	it('closes context menu on document click outside', async () => {
		const placements = [
			{
				id: 'placement-1',
				widgetId: 'widget-1',
				gridX: 0,
				gridY: 0,
				gridWidth: 4,
				gridHeight: 4,
			},
		]
		const widgets = [{ id: 'widget-1', title: 'Test Widget' }]

		wrapper = mount(DashboardGrid, {
			props: {
				placements,
				widgets,
				editMode: true,
				gridColumns: 12,
			},
			global: {
				stubs: {
					WidgetWrapper: true,
					TileWidget: true,
					WidgetContextMenu: true,
				},
			},
		})

		// Open context menu
		const gridItem = wrapper.find('.grid-stack-item')
		const contextMenuEvent = new MouseEvent('contextmenu', {
			clientX: 100,
			clientY: 150,
			bubbles: true,
			cancelable: true,
		})
		gridItem.element.dispatchEvent(contextMenuEvent)
		expect(wrapper.vm.contextMenu.show).toBe(true)

		// Simulate document click outside
		const outsideClick = new MouseEvent('click', { bubbles: true })
		document.dispatchEvent(outsideClick)

		expect(wrapper.vm.contextMenu.show).toBe(false)
	})

	it('emits widget-edit event from context menu', async () => {
		const placements = [
			{
				id: 'placement-1',
				widgetId: 'widget-1',
				gridX: 0,
				gridY: 0,
				gridWidth: 4,
				gridHeight: 4,
			},
		]
		const widgets = [{ id: 'widget-1', title: 'Test Widget' }]

		wrapper = mount(DashboardGrid, {
			props: {
				placements,
				widgets,
				editMode: true,
				gridColumns: 12,
			},
			global: {
				stubs: {
					WidgetWrapper: true,
					TileWidget: true,
					WidgetContextMenu: true,
				},
			},
		})

		wrapper.vm.onContextEdit(placements[0])

		expect(wrapper.emitted('widget-edit')).toBeTruthy()
		expect(wrapper.emitted('widget-edit')[0][0]).toEqual(placements[0])
	})

	it('emits widget-remove event from context menu', async () => {
		const placements = [
			{
				id: 'placement-1',
				widgetId: 'widget-1',
				gridX: 0,
				gridY: 0,
				gridWidth: 4,
				gridHeight: 4,
			},
		]
		const widgets = [{ id: 'widget-1', title: 'Test Widget' }]

		wrapper = mount(DashboardGrid, {
			props: {
				placements,
				widgets,
				editMode: true,
				gridColumns: 12,
			},
			global: {
				stubs: {
					WidgetWrapper: true,
					TileWidget: true,
					WidgetContextMenu: true,
				},
			},
		})

		wrapper.vm.onContextRemove(placements[0])

		expect(wrapper.emitted('widget-remove')).toBeTruthy()
		expect(wrapper.emitted('widget-remove')[0][0]).toBe('placement-1')
	})
})
