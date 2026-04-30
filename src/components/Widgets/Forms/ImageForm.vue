<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="image-form">
		<div class="image-form__field">
			<label :for="fileInputId" class="image-form__label">
				{{ tt('Upload Image') }}
			</label>
			<input
				:id="fileInputId"
				type="file"
				accept="image/*"
				class="image-form__input"
				@change="handleFileSelect">
			<p v-if="uploadError" class="image-form__error">
				{{ uploadError }}
			</p>
		</div>

		<div class="image-form__field">
			<label :for="urlInputId" class="image-form__label">
				{{ tt('Or enter Image URL') }}
			</label>
			<input
				:id="urlInputId"
				v-model="form.url"
				type="text"
				class="image-form__input"
				@input="emitUpdate">
		</div>

		<div v-if="form.url" class="image-form__preview">
			<img
				:src="form.url"
				:alt="form.alt"
				class="image-form__preview-img">
		</div>

		<div class="image-form__field">
			<label :for="altInputId" class="image-form__label">
				{{ tt('Alt Text') }}
			</label>
			<input
				:id="altInputId"
				v-model="form.alt"
				type="text"
				class="image-form__input"
				@input="emitUpdate">
		</div>

		<div class="image-form__field">
			<label :for="linkInputId" class="image-form__label">
				{{ tt('Link (optional)') }}
			</label>
			<input
				:id="linkInputId"
				v-model="form.link"
				type="text"
				class="image-form__input"
				@input="emitUpdate">
		</div>

		<div class="image-form__field">
			<label :for="fitSelectId" class="image-form__label">
				{{ tt('Fit') }}
			</label>
			<select
				:id="fitSelectId"
				v-model="form.fit"
				class="image-form__select"
				@change="emitUpdate">
				<option value="cover">
					{{ tt('Cover') }}
				</option>
				<option value="contain">
					{{ tt('Contain') }}
				</option>
				<option value="fill">
					{{ tt('Fill') }}
				</option>
				<option value="none">
					{{ tt('None') }}
				</option>
			</select>
		</div>
	</div>
</template>

<script>
/**
 * ImageForm
 *
 * Sub-form for AddWidgetModal that authors the persisted `content` blob for an
 * `image` widget. Provides file upload (via resource-uploads endpoint) and
 * direct URL input, along with alt text, link, and fit controls. Pre-fills from
 * `editingWidget.content` on mount, emits `update:content` reactively, and
 * exposes a `validate()` method per REQ-IMG-005.
 */

const DEFAULTS = {
	url: '',
	alt: '',
	link: '',
	fit: 'cover',
}

let uidCounter = 0

export default {
	name: 'ImageForm',

	props: {
		editingWidget: {
			type: Object,
			default: null,
		},
	},

	emits: ['update:content'],

	data() {
		return {
			uid: ++uidCounter,
			form: { ...DEFAULTS },
			uploadError: '',
		}
	},

	computed: {
		fileInputId() {
			return `image-form-file-${this.uid}`
		},
		urlInputId() {
			return `image-form-url-${this.uid}`
		},
		altInputId() {
			return `image-form-alt-${this.uid}`
		},
		linkInputId() {
			return `image-form-link-${this.uid}`
		},
		fitSelectId() {
			return `image-form-fit-${this.uid}`
		},
	},

	mounted() {
		const content = this.editingWidget?.content || {}
		this.form = {
			url: typeof content.url === 'string' ? content.url : DEFAULTS.url,
			alt: typeof content.alt === 'string' ? content.alt : DEFAULTS.alt,
			link: typeof content.link === 'string' ? content.link : DEFAULTS.link,
			fit: ['cover', 'contain', 'fill', 'none'].includes(content.fit)
				? content.fit
				: DEFAULTS.fit,
		}
	},

	methods: {
		tt(key) {
			if (typeof t === 'function') {
				return t('mydash', key)
			}
			return key
		},

		emitUpdate() {
			this.$emit('update:content', { ...this.form })
		},

		handleFileSelect(event) {
			const file = event.target.files?.[0]
			if (!file) {
				return
			}

			this.uploadError = ''
			const reader = new FileReader()

			reader.onload = async (e) => {
				try {
					const dataUrl = e.target.result
					const response = await fetch('/index.php/apps/mydash/api/resources', {
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
						},
						body: JSON.stringify({ base64: dataUrl }),
					})

					if (!response.ok) {
						throw new Error(`HTTP ${response.status}`)
					}

					const data = await response.json()
					this.form.url = data.url
					this.emitUpdate()
					// Reset file input
					event.target.value = ''
				} catch (error) {
					this.uploadError = this.tt('Failed to upload image')
					console.error('Image upload failed:', error)
					// Reset file input
					event.target.value = ''
				}
			}

			reader.onerror = () => {
				this.uploadError = this.tt('Failed to upload image')
				// Reset file input
				event.target.value = ''
			}

			reader.readAsDataURL(file)
		},

		/**
		 * Validate the form. Returns an array of localised error strings.
		 * Empty array means the form is valid (REQ-IMG-005).
		 *
		 * @return {string[]} array of error messages
		 */
		validate() {
			if (!this.form.url || this.form.url.trim() === '') {
				return [this.tt('Image URL is required')]
			}
			return []
		},
	},
}
</script>

<style scoped>
.image-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.image-form__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.image-form__label {
	font-weight: bold;
}

.image-form__input,
.image-form__select {
	width: 100%;
}

.image-form__error {
	margin: 0;
	padding: 4px 8px;
	font-size: 12px;
	color: var(--color-error);
	background-color: rgba(192, 0, 0, 0.1);
	border-radius: 2px;
}

.image-form__preview {
	width: 100%;
	height: 200px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	overflow: hidden;
	background-color: var(--color-background-secondary);
}

.image-form__preview-img {
	width: 100%;
	height: 100%;
	object-fit: contain;
}
</style>
