/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Thin wrapper around `POST /apps/mydash/api/resources`. Posts a
 * `data:image/<type>;base64,<payload>` data URL as JSON, returns the
 * server's standard `{status, url, name, size}` envelope on success,
 * and rethrows a normalised `ResourceUploadError` on the standardised
 * `{status: 'error', error, message}` envelope so callers can branch on
 * the stable `error` enum without parsing the human message.
 *
 * The actual upload endpoint lives in the parallel `resource-uploads`
 * capability — this file is the JS-side adapter the `IconPicker`
 * (and any future widget upload UI) uses to talk to it. Keep the
 * module dependency-light: only `@nextcloud/axios` and
 * `@nextcloud/router`. No store, no Pinia, no app-level state.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Resource upload error thrown when the server returns a `status: 'error'`
 * envelope or the network call fails. Carries the stable `error` enum
 * from the server (or `'network_error'` / `'unknown_error'` for
 * transport failures) so callers can branch on a machine-readable code
 * without parsing the human message.
 */
export class ResourceUploadError extends Error {

	/**
	 * Create a new resource upload error.
	 *
	 * @param {string} code Stable error enum from the server envelope.
	 * @param {string} message Human-readable display message.
	 * @param {number} [httpStatus] HTTP status code (when known).
	 */
	constructor(code, message, httpStatus) {
		super(message)
		this.name = 'ResourceUploadError'
		this.code = code
		this.httpStatus = httpStatus
	}

}

/**
 * Upload a base64 data URL via `POST /apps/mydash/api/resources`.
 *
 * @param {string} dataUrl A `data:image/<type>;base64,<payload>` string.
 * @return {Promise<{url: string, name: string, size: number}>} The
 *   persisted resource info (URL is the public path served by the
 *   `resource-serving` capability).
 * @throws {ResourceUploadError} On any server-side rejection (forbidden,
 *   invalid_image_format, file_too_large, mime_mismatch, etc.) or
 *   transport failure (`code === 'network_error'`).
 */
export async function uploadDataUrl(dataUrl) {
	const url = generateUrl('/apps/mydash/api/resources')

	let response
	try {
		response = await axios.post(url, { base64: dataUrl })
	} catch (err) {
		const data = err?.response?.data
		if (data && data.status === 'error' && typeof data.error === 'string') {
			throw new ResourceUploadError(
				data.error,
				typeof data.message === 'string' ? data.message : data.error,
				err?.response?.status,
			)
		}
		throw new ResourceUploadError(
			'network_error',
			err?.message || 'Network error',
			err?.response?.status,
		)
	}

	const body = response?.data
	if (!body || body.status !== 'success' || typeof body.url !== 'string') {
		throw new ResourceUploadError(
			body?.error || 'unknown_error',
			body?.message || 'Unexpected server response',
			response?.status,
		)
	}

	return {
		url: body.url,
		name: typeof body.name === 'string' ? body.name : '',
		size: typeof body.size === 'number' ? body.size : 0,
	}
}

export default { uploadDataUrl, ResourceUploadError }
