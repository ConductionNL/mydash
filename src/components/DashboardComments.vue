<!--
  - SPDX-FileCopyrightText: 2026 MyDash Contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - DashboardComments — UI for the dashboard-comments capability
  - (REQ-CMNT-001..009). Renders the threaded comment list with one
  - level of nesting, inline reply / edit forms, and the
  - "comments disabled" placeholder when the dashboard is gated.
-->

<template>
	<section class="dashboard-comments" :aria-label="t('mydash', 'Comments')">
		<header class="dashboard-comments__header">
			<h2 class="dashboard-comments__title">
				{{ t('mydash', 'Comments') }}
			</h2>
		</header>

		<p v-if="!commentsEnabled || !thread.enabled" class="dashboard-comments__disabled" role="status">
			{{ t('mydash', 'Comments are disabled on this dashboard') }}
		</p>

		<template v-else>
			<form
				v-if="canPost"
				class="dashboard-comments__form"
				@submit.prevent="submitTopLevel">
				<label class="dashboard-comments__label" :for="topLevelInputId">
					{{ t('mydash', 'Write a comment…') }}
				</label>
				<NcTextField
					:id="topLevelInputId"
					v-model="topLevelDraft"
					:placeholder="t('mydash', 'Write a comment…')"
					:disabled="submitting"
					:label="t('mydash', 'Comment text')"
					hide-label
					class="dashboard-comments__input" />
				<NcButton
					type="primary"
					:disabled="!canSubmitTopLevel"
					native-type="submit">
					{{ t('mydash', 'Post comment') }}
				</NcButton>
			</form>

			<p v-if="topLevelError" class="dashboard-comments__error" role="alert">
				{{ topLevelError }}
			</p>

			<ol class="dashboard-comments__list">
				<li
					v-for="comment in thread.comments"
					:key="comment.id"
					class="dashboard-comments__item">
					<article class="dashboard-comments__comment">
						<header class="dashboard-comments__meta">
							<span class="dashboard-comments__author">{{ comment.author }}</span>
							<time
								class="dashboard-comments__timestamp"
								:datetime="comment.createdAt">
								{{ formatTimestamp(comment.createdAt) }}
							</time>
							<span v-if="comment.wasEdited" class="dashboard-comments__edited">
								{{ t('mydash', 'Edited') }}
							</span>
						</header>

						<form
							v-if="editingId === comment.id"
							class="dashboard-comments__edit"
							@submit.prevent="submitEdit(comment)">
							<NcTextField
								v-model="editDraft"
								:label="t('mydash', 'Edit comment')"
								hide-label
								:disabled="submitting" />
							<NcButton type="primary" native-type="submit">
								{{ t('mydash', 'Save') }}
							</NcButton>
							<NcButton @click="cancelEdit">
								{{ t('mydash', 'Cancel') }}
							</NcButton>
						</form>

						<p v-else class="dashboard-comments__message">
							{{ comment.message }}
						</p>

						<footer class="dashboard-comments__actions">
							<NcButton
								v-if="canPost"
								type="tertiary"
								@click="startReply(comment)">
								{{ t('mydash', 'Reply') }}
							</NcButton>
							<NcButton
								v-if="canEditComment(comment)"
								type="tertiary"
								@click="startEdit(comment)">
								{{ t('mydash', 'Edit') }}
							</NcButton>
							<NcButton
								v-if="canEditComment(comment)"
								type="tertiary"
								@click="confirmDelete(comment)">
								{{ t('mydash', 'Delete') }}
							</NcButton>
						</footer>

						<form
							v-if="replyingTo === comment.id"
							class="dashboard-comments__reply"
							@submit.prevent="submitReply(comment)">
							<NcTextField
								v-model="replyDraft"
								:label="t('mydash', 'Write a reply…')"
								:placeholder="t('mydash', 'Write a reply…')"
								hide-label
								:disabled="submitting" />
							<NcButton
								type="primary"
								:disabled="!canSubmitReply"
								native-type="submit">
								{{ t('mydash', 'Reply') }}
							</NcButton>
							<NcButton @click="cancelReply">
								{{ t('mydash', 'Cancel reply') }}
							</NcButton>
						</form>

						<ol
							v-if="(comment.replies || []).length > 0"
							class="dashboard-comments__replies">
							<li
								v-for="reply in comment.replies"
								:key="reply.id"
								class="dashboard-comments__item dashboard-comments__item--reply">
								<header class="dashboard-comments__meta">
									<span class="dashboard-comments__author">{{ reply.author }}</span>
									<time
										class="dashboard-comments__timestamp"
										:datetime="reply.createdAt">
										{{ formatTimestamp(reply.createdAt) }}
									</time>
									<span v-if="reply.wasEdited" class="dashboard-comments__edited">
										{{ t('mydash', 'Edited') }}
									</span>
								</header>

								<form
									v-if="editingId === reply.id"
									class="dashboard-comments__edit"
									@submit.prevent="submitEdit(reply)">
									<NcTextField
										v-model="editDraft"
										:label="t('mydash', 'Edit comment')"
										hide-label
										:disabled="submitting" />
									<NcButton type="primary" native-type="submit">
										{{ t('mydash', 'Save') }}
									</NcButton>
									<NcButton @click="cancelEdit">
										{{ t('mydash', 'Cancel') }}
									</NcButton>
								</form>

								<p v-else class="dashboard-comments__message">
									{{ reply.message }}
								</p>

								<footer class="dashboard-comments__actions">
									<NcButton
										v-if="canEditComment(reply)"
										type="tertiary"
										@click="startEdit(reply)">
										{{ t('mydash', 'Edit') }}
									</NcButton>
									<NcButton
										v-if="canEditComment(reply)"
										type="tertiary"
										@click="confirmDelete(reply)">
										{{ t('mydash', 'Delete') }}
									</NcButton>
								</footer>
							</li>
						</ol>
					</article>
				</li>
			</ol>
		</template>
	</section>
</template>

<script>
import { NcButton, NcTextField } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'

import { useCommentsStore } from '../stores/comments.js'

export default {
	name: 'DashboardComments',

	components: {
		NcButton,
		NcTextField,
	},

	props: {
		dashboardUuid: {
			type: String,
			required: true,
		},
		// `true` when the user has at least Editor-grade access; toggles
		// visibility of post / reply forms (REQ-CMNT-009).
		canPost: {
			type: Boolean,
			default: false,
		},
		// Effective per-dashboard toggle (REQ-CMNT-007). When `false` the
		// component renders the disabled placeholder regardless of the
		// thread envelope returned by the API.
		commentsEnabled: {
			type: Boolean,
			default: true,
		},
		// User who is currently signed in — used by `canEditComment` to
		// decide whether to show the Edit / Delete affordances.
		currentUserId: {
			type: String,
			default: '',
		},
		// `true` when the current user is a Nextcloud admin or MyDash
		// admin — admins can edit / delete any comment (REQ-CMNT-004,
		// REQ-CMNT-005).
		isAdmin: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			topLevelDraft: '',
			topLevelError: '',
			replyingTo: null,
			replyDraft: '',
			editingId: null,
			editDraft: '',
			submitting: false,
		}
	},

	computed: {
		store() {
			return useCommentsStore()
		},

		thread() {
			return this.store.threadFor(this.dashboardUuid)
		},

		topLevelInputId() {
			return `mydash-comments-input-${this.dashboardUuid}`
		},

		canSubmitTopLevel() {
			return this.topLevelDraft.trim().length > 0 && !this.submitting
		},

		canSubmitReply() {
			return this.replyDraft.trim().length > 0 && !this.submitting
		},
	},

	watch: {
		dashboardUuid: {
			immediate: true,
			handler(uuid) {
				if (uuid) {
					this.store.loadComments(uuid).catch((err) => {
						console.error('Failed to load comments', err)
					})
				}
			},
		},
	},

	methods: {
		t,

		canEditComment(comment) {
			if (this.isAdmin) {
				return true
			}
			return this.currentUserId !== '' && comment.author === this.currentUserId
		},

		formatTimestamp(iso) {
			if (!iso) {
				return ''
			}
			try {
				const date = new Date(iso)
				return date.toLocaleString()
			} catch (err) {
				return iso
			}
		},

		async submitTopLevel() {
			if (!this.canSubmitTopLevel) {
				return
			}
			this.submitting = true
			this.topLevelError = ''
			try {
				await this.store.createComment(this.dashboardUuid, {
					message: this.topLevelDraft.trim(),
				})
				this.topLevelDraft = ''
			} catch (err) {
				this.topLevelError = err?.response?.data?.error
					|| t('mydash', 'Failed to post comment')
			} finally {
				this.submitting = false
			}
		},

		startReply(comment) {
			this.replyingTo = comment.id
			this.replyDraft = ''
		},

		cancelReply() {
			this.replyingTo = null
			this.replyDraft = ''
		},

		async submitReply(parent) {
			if (!this.canSubmitReply) {
				return
			}
			this.submitting = true
			try {
				await this.store.createComment(this.dashboardUuid, {
					message: this.replyDraft.trim(),
					parentId: parent.id,
				})
				this.cancelReply()
			} catch (err) {
				this.topLevelError = err?.response?.data?.error
					|| t('mydash', 'Failed to post reply')
			} finally {
				this.submitting = false
			}
		},

		startEdit(comment) {
			this.editingId = comment.id
			this.editDraft = comment.message
		},

		cancelEdit() {
			this.editingId = null
			this.editDraft = ''
		},

		async submitEdit(comment) {
			if (this.editDraft.trim().length === 0) {
				return
			}
			this.submitting = true
			try {
				await this.store.updateComment(this.dashboardUuid, comment.id, {
					message: this.editDraft.trim(),
				})
				this.cancelEdit()
			} catch (err) {
				this.topLevelError = err?.response?.data?.error
					|| t('mydash', 'Failed to update comment')
			} finally {
				this.submitting = false
			}
		},

		async confirmDelete(comment) {
			const isTopLevel = comment.parentId === null || comment.parentId === undefined
			const message = isTopLevel
				? `${t('mydash', 'Are you sure you want to delete this comment?')} `
					+ t('mydash', 'Deleting this top-level comment will also remove all its replies.')
				: t('mydash', 'Are you sure you want to delete this comment?')
			// Use the native confirm so the component stays free of dialog
			// dependencies; e2e tests intercept it with `dialog.accept()`.
			// eslint-disable-next-line no-alert
			if (!window.confirm(message)) {
				return
			}
			this.submitting = true
			try {
				await this.store.deleteComment(this.dashboardUuid, comment.id)
			} catch (err) {
				this.topLevelError = err?.response?.data?.error
					|| t('mydash', 'Failed to delete comment')
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.dashboard-comments {
	margin-top: 1.5rem;
	padding: 1rem;
	border-top: 1px solid var(--color-border);
}

.dashboard-comments__title {
	font-size: 1.1rem;
	font-weight: 600;
	margin: 0 0 0.5rem;
}

.dashboard-comments__disabled {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.dashboard-comments__form,
.dashboard-comments__edit,
.dashboard-comments__reply {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	margin-bottom: 1rem;
}

.dashboard-comments__error {
	color: var(--color-error);
	margin: 0.25rem 0 0.75rem;
}

.dashboard-comments__list,
.dashboard-comments__replies {
	list-style: none;
	padding: 0;
	margin: 0;
}

.dashboard-comments__replies {
	margin-top: 0.5rem;
	padding-left: 1.5rem;
	border-left: 2px solid var(--color-border);
}

.dashboard-comments__item {
	margin-bottom: 1rem;
}

.dashboard-comments__meta {
	display: flex;
	gap: 0.5rem;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.dashboard-comments__author {
	font-weight: 600;
	color: var(--color-main-text);
}

.dashboard-comments__edited {
	font-style: italic;
}

.dashboard-comments__message {
	margin: 0.25rem 0 0.5rem;
	white-space: pre-wrap;
}

.dashboard-comments__actions {
	display: flex;
	gap: 0.25rem;
}
</style>
