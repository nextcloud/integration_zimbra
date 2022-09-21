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
					<NcAvatar :size="44">
						<template #icon>
							<CalendarIcon />
						</template>
					</NcAvatar>
				</template>
			</DashboardWidgetItem>
		</template>
		<template #empty-content>
			<NcEmptyContent
				v-if="emptyContentMessage"
				:title="emptyContentMessage">
				<template #icon>
					<component :is="emptyContentIcon" />
				</template>
				<template #action>
					<div v-if="widgetState === 'no-token' || widgetState === 'error'" class="connect-button">
						<a :href="settingsUrl">
							<NcButton>
								<template #icon>
									<LoginVariantIcon />
								</template>
								{{ t('integration_zimbra', 'Connect to Zimbra') }}
							</NcButton>
						</a>
					</div>
				</template>
			</NcEmptyContent>
		</template>
	</DashboardWidget>
</template>

<script>
import CloseIcon from 'vue-material-design-icons/Close.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import LoginVariantIcon from 'vue-material-design-icons/LoginVariant.vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'

import ZimbraIcon from '../components/icons/ZimbraIcon.vue'

import axios from '@nextcloud/axios'
import { generateUrl, imagePath } from '@nextcloud/router'
import { DashboardWidget, DashboardWidgetItem } from '@nextcloud/vue-dashboard'
import { showError } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import moment from '@nextcloud/moment'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcAvatar from '@nextcloud/vue/dist/Components/NcAvatar.js'

export default {
	name: 'DashboardEvent',

	components: {
		DashboardWidget,
		DashboardWidgetItem,
		NcEmptyContent,
		NcButton,
		NcAvatar,
		LoginVariantIcon,
		CalendarIcon,
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
			return (nbEvent > 0) ? (this.events[0].inst[0]?.s / 1000) : null
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
				return ZimbraIcon
			} else if (this.widgetState === 'error') {
				return CloseIcon
			} else if (this.widgetState === 'ok') {
				return CheckIcon
			}
			return CheckIcon
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
			return event.id + ':' + event.inst[0]?.s
		},
		getEventTarget(event) {
			const duration = event.dur
			const startTimestampMilli = event.inst[0]?.s
			const endTimestampMilli = startTimestampMilli + duration
			return this.zimbraUrl + '/modern/calendar/event/details/' + event.invId
				+ '?utcRecurrenceId=' + event.inst[0]?.ridZ
				+ '&start=' + startTimestampMilli
				+ '&end=' + endTimestampMilli
				+ '&tabid=' + (moment().unix() * 1000)
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
			return event.name
		},
		getFormattedDate(event) {
			const duration = event.dur / 1000
			// as we search with a min date, we know the first inst is the next event occurence
			const startTimestamp = event.inst[0]?.s / 1000
			const endTimestamp = startTimestamp + duration

			return moment.unix(startTimestamp).format('LLL')
				+ ' -> '
				+ moment.unix(endTimestamp).format('LLL')
		},
	},
}
</script>

<style scoped lang="scss">
:deep(.connect-button) {
	margin-top: 10px;
}
</style>
