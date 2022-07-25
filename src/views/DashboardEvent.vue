<template>
	<DashboardWidget :items="items"
		:show-more-url="showMoreUrl"
		:show-more-text="title"
		:loading="widgetState === 'loading'">
		<template #default="{item}">
			<DashboardWidgetItem
				:id="item.id"
				:target-url="item.targetUrl"
				:avatar-url="item.avatarUrl"
				:avatar-username="item.avatarUsername"
				:avatar-is-no-user="item.avatarIsNoUser"
				:overlay-icon-url="item.overlayIconUrl"
				:main-text="item.mainText"
				:sub-text="item.subText">
				<template #avatar>
					<Avatar :size="44"
						icon-class="icon-calendar-dark icon-calendar-fff">
						<!-- FOR NEW @NC/VUE template #icon>
							<CalendarIcon />
						</template-->
					</Avatar>
				</template>
			</DashboardWidgetItem>
		</template>
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
import { DashboardWidget, DashboardWidgetItem } from '@nextcloud/vue-dashboard'
import { showError } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import moment from '@nextcloud/moment'
import EmptyContent from '@nextcloud/vue/dist/Components/EmptyContent'
import LoginVariantIcon from 'vue-material-design-icons/LoginVariant'
import Button from '@nextcloud/vue/dist/Components/Button'
import Avatar from '@nextcloud/vue/dist/Components/Avatar'
// import CalendarIcon from 'vue-material-design-icons/Calendar'

import { oauthConnect, oauthConnectConfirmDialog } from '../utils'

export default {
	name: 'DashboardEvent',

	components: {
		DashboardWidget,
		DashboardWidgetItem,
		EmptyContent,
		Button,
		Avatar,
		LoginVariantIcon,
		// CalendarIcon,
	},

	props: {
		title: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			events: [],
			loop: null,
			widgetState: 'loading',
			settingsUrl: generateUrl('/settings/user/connected-accounts#zimbra_prefs'),
			initialState: loadState('integration_zimbra', 'zimbra-event-config'),
			windowVisibility: true,
		}
	},

	computed: {
		zimbraUrl() {
			return this.initialState?.url?.replace(/\/+$/, '')
		},
		showMoreUrl() {
			return this.zimbraUrl + '/calendar'
		},
		items() {
			return this.events.map((event) => {
				return {
					id: this.getUniqueKey(event),
					targetUrl: this.getEventTarget(event),
					avatarUrl: this.getAvatarImage(event),
					// avatarUsername: this.getRepositoryName(event),
					avatarIsNoUser: true,
					// overlayIconUrl: this.getOverlayImage(event),
					mainText: this.getMainText(event),
					subText: this.getSubText(event),
				}
			})
		},
		lastTimestamp() {
			const nbEvent = this.events.length
			return (nbEvent > 0) ? (this.events[0].inv[0]?.comp[0]?.s[0]?.u / 1000) : null
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
				return t('integration_zimbra', 'No Zimbra upcoming events!')
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
			this.fetchEvents()
			this.loop = setInterval(() => this.fetchEvents(), 60000)
		},
		fetchEvents() {
			// always get all events in case there are some new ones in the middle of the ones we know
			axios.get(generateUrl('/apps/integration_zimbra/upcoming-events')).then((response) => {
				this.processEvents(response.data)
				this.widgetState = 'ok'
			}).catch((error) => {
				clearInterval(this.loop)
				if (error.response && error.response.status === 400) {
					this.widgetState = 'no-token'
				} else if (error.response && error.response.status === 401) {
					showError(t('integration_zimbra', 'Failed to get Zimbra upcoming events'))
					this.widgetState = 'error'
				} else {
					// there was an error in notif processing
					console.debug(error)
				}
			})
		},
		processEvents(newEvents) {
			this.events = this.filter(newEvents)
		},
		filter(events) {
			return events
		},
		getUniqueKey(event) {
			return event.id + ':' + event.inv[0]?.comp[0]?.s[0]?.u
		},
		getEventTarget(event) {
			const startTs = moment(event.inv[0]?.comp[0]?.s[0]?.d).unix() * 1000
			const endTs = moment(event.inv[0]?.comp[0]?.e[0]?.d).unix() * 1000
			return this.zimbraUrl + '/modern/calendar/event/details/' + event.id + '-' + event.inv[0]?.id
				+ '?utcRecurrenceId=' + event.inv[0]?.comp[0]?.s[0]?.d
				+ '&start=' + startTs
				+ '&end=' + endTs
		},
		getAvatarImage(event) {
			return imagePath('core', 'places/calendar.svg')
		},
		getOverlayImage(event) {
			return imagePath('integration_zimbra', 'mention.svg')
		},
		getSubText(event) {
			return this.getFormattedDate(event)
		},
		getMainText(event) {
			return event.inv[0]?.comp[0]?.name
		},
		getFormattedDate(event) {
			return event.inv[0]?.comp[0]?.s[0]?.u
				? moment.unix(event.inv[0]?.comp[0]?.s[0]?.u / 1000).format('LLL')
					+ ' -> '
					+ moment.unix(event.inv[0]?.comp[0]?.e[0]?.u / 1000).format('LLL')
				: moment(event.inv[0]?.comp[0]?.s[0]?.d).format('LL')
					+ ' -> '
					+ moment(event.inv[0]?.comp[0]?.e[0]?.d).format('LL')
		},
	},
}
</script>

<style scoped lang="scss">
:deep(.connect-button) {
	margin-top: 10px;
}
</style>
