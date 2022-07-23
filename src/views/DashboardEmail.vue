<template>
	<DashboardWidget :items="items"
		:show-more-url="showMoreUrl"
		:show-more-text="title"
		:loading="widgetState === 'loading'">
		<template #empty-content>
			<EmptyContent
				v-if="emptyContentMessage"
				:icon="emptyContentIcon">
				<template #desc>
					{{ emptyContentMessage }}
					<div v-if="widgetState === 'no-token' || widgetState === 'error'" class="connect-button">
						<a v-if="!initialState.oauth_is_possible"
							:href="settingsUrl">
							<Button>
								<template #icon>
									<LoginVariantIcon />
								</template>
								{{ t('integration_zimbra', 'Connect to Zimbra') }}
							</Button>
						</a>
						<Button v-else
							@click="onOauthClick">
							<template #icon>
								<LoginVariantIcon />
							</template>
							{{ t('integration_zimbra', 'Connect to Zimbra') }}
						</Button>
					</div>
				</template>
			</EmptyContent>
		</template>
	</DashboardWidget>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl, imagePath } from '@nextcloud/router'
import { DashboardWidget } from '@nextcloud/vue-dashboard'
import { showError } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import moment from '@nextcloud/moment'
import EmptyContent from '@nextcloud/vue/dist/Components/EmptyContent'
import LoginVariantIcon from 'vue-material-design-icons/LoginVariant'
import Button from '@nextcloud/vue/dist/Components/Button'

import { oauthConnect, oauthConnectConfirmDialog } from '../utils'

export default {
	name: 'DashboardEmail',

	components: {
		DashboardWidget,
		EmptyContent,
		Button,
		LoginVariantIcon,
	},

	props: {
		title: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			emails: [],
			loop: null,
			widgetState: 'loading',
			settingsUrl: generateUrl('/settings/user/connected-accounts#zimbra_prefs'),
			initialState: loadState('integration_zimbra', 'zimbra-email-config'),
			windowVisibility: true,
		}
	},

	computed: {
		zimbraUrl() {
			return this.initialState?.url?.replace(/\/+$/, '')
		},
		showMoreUrl() {
			return this.zimbraUrl
		},
		items() {
			return this.emails.map((email) => {
				return {
					id: this.getUniqueKey(email),
					targetUrl: this.getEmailTarget(email),
					avatarUrl: this.getAvatarImage(email),
					// avatarUsername: this.getAvatarName(email),
					avatarIsNoUser: true,
					// overlayIconUrl: this.getOverlayImage(email),
					mainText: this.getMainText(email),
					subText: this.getSubline(email),
				}
			})
		},
		lastTimestamp() {
			const nbEmail = this.emails.length
			return (nbEmail > 0) ? (this.emails[0].d / 1000) : null
		},
		lastMoment() {
			return moment.unix(this.lastTimestamp)
		},
		emptyContentMessage() {
			if (this.widgetState === 'no-token') {
				return t('integration_zimbra', 'No Zimbra account connected')
			} else if (this.widgetState === 'error') {
				return t('integration_zimbra', 'Error connecting to Zimbra')
			} else if (this.widgetState === 'ok') {
				return t('integration_zimbra', 'No Zimbra unread email!')
			}
			return ''
		},
		emptyContentIcon() {
			if (this.widgetState === 'no-token') {
				return 'icon-zimbra'
			} else if (this.widgetState === 'error') {
				return 'icon-close'
			} else if (this.widgetState === 'ok') {
				return 'icon-checkmark'
			}
			return 'icon-checkmark'
		},
	},

	watch: {
		windowVisibility(newValue) {
			if (newValue) {
				this.launchLoop()
			} else {
				this.stopLoop()
			}
		},
	},

	beforeDestroy() {
		document.removeEventListener('visibilitychange', this.changeWindowVisibility)
	},

	beforeMount() {
		this.launchLoop()
		document.addEventListener('visibilitychange', this.changeWindowVisibility)
	},

	mounted() {
	},

	methods: {
		onOauthClick() {
			oauthConnectConfirmDialog(this.zimbraUrl).then((result) => {
				if (result) {
					if (this.initialState.use_popup) {
						oauthConnect(this.zimbraUrl, this.initialState.client_id, null, true)
							.then((data) => {
								this.stopLoop()
								this.launchLoop()
							})
					} else {
						oauthConnect(this.zimbraUrl, this.initialState.client_id, 'dashboard')
					}
				}
			})
		},
		changeWindowVisibility() {
			this.windowVisibility = !document.hidden
		},
		stopLoop() {
			clearInterval(this.loop)
		},
		async launchLoop() {
			this.fetchEmails()
			this.loop = setInterval(() => this.fetchEmails(), 60000)
		},
		fetchEmails() {
			// always get all unread emails in case some new ones appeared in the middle of the ones we already have
			axios.get(generateUrl('/apps/integration_zimbra/unread-emails')).then((response) => {
				this.processEmails(response.data)
				this.widgetState = 'ok'
			}).catch((error) => {
				clearInterval(this.loop)
				if (error.response && error.response.status === 400) {
					this.widgetState = 'no-token'
				} else if (error.response && error.response.status === 401) {
					showError(t('integration_zimbra', 'Failed to get Zimbra emails'))
					this.widgetState = 'error'
				} else {
					// there was an error in email processing
					console.debug(error)
				}
			})
		},
		processEmails(newEmails) {
			this.emails = this.filter(newEmails)
		},
		filter(emails) {
			/*
			return emails.filter((n) => {
				return true
			})
			*/
			return emails
		},
		getUniqueKey(email) {
			return email.id
		},
		getEmailTarget(email) {
			return this.zimbraUrl + '/modern/email/Inbox/conversation/' + email.cid
		},
		getAvatarName(email) {
			return email.e[email.e.length - 1].d
		},
		getAvatarImage(email) {
			return imagePath('core', 'mail.svg')
		},
		getOverlayImage(email) {
			return imagePath('integration_zimbra', 'mention.svg')
		},
		getMainText(email) {
			return email.su
		},
		getSubline(email) {
			return email.e[0].a + ' ' + this.getFormattedDate(email)
		},
		getFormattedDate(email) {
			return moment.unix(email.d / 1000).format('LLL')
		},
		/*
		editTodo(id, action) {
			axios.put(generateUrl('/apps/integration_zimbra/todos/' + id + '/' + action)).then((response) => {
			}).catch((error) => {
				showError(t('integration_zimbra', 'Failed to edit Zimbra todo'))
				console.debug(error)
			})
		},
		*/
	},
}
</script>

<style scoped lang="scss">
:deep(.connect-button) {
	margin-top: 10px;
}
</style>
