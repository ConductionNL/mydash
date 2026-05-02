/**
 * SPDX-FileCopyrightText: 2024 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest tests for `src/services/resourceService.js`. Confirms the wrapper:
 *
 *  - posts `{base64: <dataUrl>}` to `/apps/mydash/api/resources`
 *  - returns the success envelope's `{url, name, size}` shape
 *  - rethrows the server's `error` enum + `message` as a `ResourceUploadError`
 *  - falls back to a `network_error` code when the network call fails
 *  - returns `unknown_error` when the body is malformed
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => `http://localhost${path}`,
}))

const postMock = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { post: (...args) => postMock(...args) },
}))

let uploadDataUrl
let ResourceUploadError

beforeEach(async () => {
	postMock.mockReset()
	const mod = await import('../resourceService.js')
	uploadDataUrl = mod.uploadDataUrl
	ResourceUploadError = mod.ResourceUploadError
})

describe('resourceService.uploadDataUrl', () => {
	it('posts the data URL and returns {url, name, size} on success', async () => {
		postMock.mockResolvedValueOnce({
			status: 200,
			data: {
				status: 'success',
				url: '/apps/mydash/resource/resource_abc.png',
				name: 'resource_abc.png',
				size: 1234,
			},
		})

		const result = await uploadDataUrl('data:image/png;base64,xxx')

		expect(postMock).toHaveBeenCalledWith(
			'http://localhost/apps/mydash/api/resources',
			{ base64: 'data:image/png;base64,xxx' },
		)
		expect(result).toEqual({
			url: '/apps/mydash/resource/resource_abc.png',
			name: 'resource_abc.png',
			size: 1234,
		})
	})

	it('throws ResourceUploadError carrying the stable code on server error envelope', async () => {
		const err = new Error('Request failed')
		err.response = {
			status: 400,
			data: {
				status: 'error',
				error: 'file_too_large',
				message: 'Maximum size is 5MB',
			},
		}
		postMock.mockRejectedValueOnce(err)

		await expect(uploadDataUrl('data:image/png;base64,xxx')).rejects
			.toMatchObject({
				name: 'ResourceUploadError',
				code: 'file_too_large',
				message: 'Maximum size is 5MB',
				httpStatus: 400,
			})
	})

	it('throws network_error when the transport itself fails', async () => {
		postMock.mockRejectedValueOnce(new Error('boom'))

		try {
			await uploadDataUrl('data:image/png;base64,xxx')
			throw new Error('should have thrown')
		} catch (e) {
			expect(e).toBeInstanceOf(ResourceUploadError)
			expect(e.code).toBe('network_error')
		}
	})

	it('throws unknown_error when server returns 200 with malformed body', async () => {
		postMock.mockResolvedValueOnce({ status: 200, data: { status: 'success' } })

		await expect(uploadDataUrl('data:image/png;base64,xxx')).rejects
			.toMatchObject({ code: 'unknown_error' })
	})
})
