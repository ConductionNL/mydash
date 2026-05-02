/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest unit test for `resourceService.js` — the JS wrapper around the
 * `POST /api/resources` endpoint owned by the `resource-uploads`
 * capability. Asserts the happy path and the three error envelopes
 * (server `{status: 'error'}`, network failure, and malformed body) are
 * normalised into a `ResourceUploadError` with the expected `code`.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import axios from '@nextcloud/axios'

import { uploadDataUrl, ResourceUploadError } from '../resourceService.js'

// `vi.mock` calls are hoisted by Vitest above the imports, so the
// stubs are in place before `axios` and `resourceService.js` are
// resolved — the import-order ESLint rule still flags them otherwise,
// hence the imports above and the mocks below remain visually adjacent.
vi.mock('@nextcloud/axios', () => ({
	default: { post: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => path,
}))

beforeEach(() => {
	axios.post.mockReset()
})

afterEach(() => {
	vi.clearAllMocks()
})

describe('uploadDataUrl', () => {
	it('returns {url, name, size} on a successful envelope', async () => {
		axios.post.mockResolvedValueOnce({
			status: 200,
			data: {
				status: 'success',
				url: '/apps/mydash/resource/abc.png',
				name: 'abc.png',
				size: 1024,
			},
		})

		const result = await uploadDataUrl('data:image/png;base64,AAAA')

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/mydash/api/resources',
			{ base64: 'data:image/png;base64,AAAA' },
		)
		expect(result).toEqual({
			url: '/apps/mydash/resource/abc.png',
			name: 'abc.png',
			size: 1024,
		})
	})

	it('throws ResourceUploadError with the server error code on a {status: error} envelope', async () => {
		axios.post.mockRejectedValueOnce({
			response: {
				status: 413,
				data: {
					status: 'error',
					error: 'file_too_large',
					message: 'File is too large',
				},
			},
		})

		await expect(uploadDataUrl('data:image/png;base64,AAAA')).rejects.toMatchObject({
			name: 'ResourceUploadError',
			code: 'file_too_large',
			message: 'File is too large',
			httpStatus: 413,
		})
	})

	it('throws ResourceUploadError with code network_error on transport failure', async () => {
		axios.post.mockRejectedValueOnce(new Error('socket hang up'))

		try {
			await uploadDataUrl('data:image/png;base64,AAAA')
			expect.fail('expected throw')
		} catch (err) {
			expect(err).toBeInstanceOf(ResourceUploadError)
			expect(err.code).toBe('network_error')
		}
	})

	it('throws ResourceUploadError with code unknown_error when the body is malformed', async () => {
		axios.post.mockResolvedValueOnce({
			status: 200,
			data: { status: 'success' /* missing url */ },
		})

		await expect(uploadDataUrl('data:image/png;base64,AAAA')).rejects.toMatchObject({
			name: 'ResourceUploadError',
			code: 'unknown_error',
		})
	})
})
