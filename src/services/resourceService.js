/**
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Upload an image from a data URL to the resource-uploads endpoint.
 *
 * @param {string} dataUrl the data URL to upload (e.g. from FileReader.readAsDataURL)
 * @return {Promise<{url: string}>} promise resolving to the response object with the uploaded resource URL
 * @throws {Error} on HTTP or network failure
 */
export async function uploadDataUrl(dataUrl) {
	const response = await fetch('/index.php/apps/mydash/api/resources', {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
		},
		body: JSON.stringify({ base64: dataUrl }),
	})

	if (!response.ok) {
		throw new Error(`Upload failed with HTTP ${response.status}`)
	}

	return response.json()
}
