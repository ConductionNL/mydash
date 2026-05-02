/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Thin client wrapper for the resource-uploads capability. The backend
 * exposes `POST /apps/mydash/api/resources` (admin-only) which accepts
 * `{base64: 'data:<mime>;base64,<payload>'}` and returns one of:
 *
 *   success → `{status: 'success', url, name, size}` (HTTP 200)
 *   error   → `{status: 'error',   error, message}` (HTTP 4xx/5xx)
 *
 * Callers in the front-end only need the URL string on success, and a
 * machine-readable `code` plus a human-readable `message` on failure.
 * `uploadDataUrl()` returns just the response payload on success and
 * throws a `ResourceUploadError` on failure so consumers can use a normal
 * try/catch instead of branching on the envelope `status`.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Typed error thrown by `uploadDataUrl()` for any non-success path
 * (network failure, 4xx envelope, malformed response). Carries a stable
 * `code` (e.g. `'forbidden'`, `'invalid_svg'`, `'file_too_large'`,
 * `'network'`, `'malformed_response'`) so the form layer can branch on
 * the cause and pick the right inline error string.
 */
export class ResourceUploadError extends Error {

	/**
	 * @param {string} code    Stable error code (`'network'` for transport-level failures).
	 * @param {string} message Human-readable display message.
	 * @param {number} [status] Optional HTTP status when the error came from a response.
	 */
	constructor(code, message, status) {
		super(message)
		this.name = 'ResourceUploadError'
		this.code = code
		this.status = status
	}

}

/**
 * Upload a base64 data URL to the resource-uploads endpoint and return
 * the success payload. Throws a {@link ResourceUploadError} on any
 * failure path so callers can use a normal try/catch.
 *
 * @param {string} dataUrl A `data:<mime>;base64,<payload>` URL.
 * @return {Promise<{url: string, name: string, size: number}>} The persisted resource.
 * @throws {ResourceUploadError} On network failure or a non-success envelope.
 */
export async function uploadDataUrl(dataUrl) {
	if (typeof dataUrl !== 'string' || dataUrl === '') {
		throw new ResourceUploadError('invalid_input', 'Empty upload payload')
	}

	const url = generateUrl('/apps/mydash/api/resources')
	let response
	try {
		response = await axios.post(url, { base64: dataUrl })
	} catch (err) {
		// axios populates `err.response` only when a response was
		// received. Anything without a response is a transport failure.
		const env = err && err.response && err.response.data
		if (env && env.status === 'error') {
			throw new ResourceUploadError(
				env.error || 'unknown',
				env.message || 'Upload failed',
				err.response.status,
			)
		}
		throw new ResourceUploadError(
			'network',
			(err && err.message) ? err.message : 'Network error',
		)
	}

	const payload = response && response.data
	if (!payload || payload.status !== 'success' || typeof payload.url !== 'string') {
		throw new ResourceUploadError(
			'malformed_response',
			'Unexpected response from resource upload',
		)
	}

	return {
		url: payload.url,
		name: payload.name,
		size: payload.size,
	}
}

/**
 * Read a `File` object as a base64 data URL — convenience wrapper around
 * `FileReader` that returns a promise so callers can `await` it.
 *
 * @param {File|Blob} file File or Blob (typically from an `<input type="file">`).
 * @return {Promise<string>} Resolves to a `data:<mime>;base64,<payload>` URL.
 */
export function readFileAsDataUrl(file) {
	return new Promise((resolve, reject) => {
		const reader = new FileReader()
		reader.onload = () => resolve(typeof reader.result === 'string' ? reader.result : '')
		reader.onerror = () => reject(reader.error || new Error('FileReader failed'))
		reader.readAsDataURL(file)
	})
}
