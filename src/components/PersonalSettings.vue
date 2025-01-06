<template>
	<div id="zimbra_prefs" class="section">
		<h2>
			<ZimbraIcon class="icon" />
			{{ t('integration_zimbra', 'Zimbra integration') }}
		</h2>
		<div id="zimbra-content">
			<div class="field">
				<label for="zimbra-url">
					<EarthIcon :size="20" class="icon" />
					{{ t('integration_zimbra', 'Zimbra instance address') }}
				</label>
				<input id="zimbra-url"
					v-model="state.url"
					type="text"
					:disabled="connected === true"
					:placeholder="t('integration_zimbra', 'Zimbra instance address')"
					@input="onInput">
			</div>
			<div v-show="showLoginPassword" class="field">
				<label for="zimbra-login">
					<AccountIcon :size="20" class="icon" />
					{{ t('integration_zimbra', 'Login') }}
				</label>
				<input id="zimbra-login"
					v-model="login"
					type="text"
					:placeholder="t('integration_zimbra', 'Zimbra login')"
					@keyup.enter="onConnectClick">
			</div>
			<div v-show="showLoginPassword" class="field">
				<label for="zimbra-password">
					<LockIcon :size="20" class="icon" />
					{{ t('integration_zimbra', 'Password') }}
				</label>
				<input id="zimbra-password"
					v-model="password"
					type="password"
					:placeholder="t('integration_zimbra', 'Zimbra password')"
					@keyup.enter="onConnectClick">
			</div>
			<div v-show="showLoginPassword && twoFactorRequired" class="field">
				<label for="zimbra-2fa">
					<LockIcon :size="20" class="icon" />
					{{ t('integration_zimbra', 'Second authentication factor') }}
				</label>
				<input id="zimbra-2fa"
					v-model="twoFactorCode"
					type="text"
					:placeholder="t('integration_zimbra', '123456')"
					@keyup.enter="onConnectClick">
			</div>
			<NcButton v-if="!connected"
				id="zimbra-connect"
				:disabled="loading === true || !(login && password)"
				:class="{ loading, field: true }"
				@click="onConnectClick">
				<template #icon>
					<OpenInNewIcon />
				</template>
				{{ t('integration_zimbra', 'Connect to Zimbra') }}
			</NcButton>
			<div v-if="connected" class="field">
				<label class="zimbra-connected">
					<a class="icon icon-checkmark-color" />
					{{ t('integration_zimbra', 'Connected as {user}', { user: connectedDisplayName }) }}
				</label>
				<NcButton id="zimbra-rm-cred" @click="onLogoutClick">
					<template #icon>
						<CloseIcon />
					</template>
					{{ t('integration_zimbra', 'Disconnect from Zimbra') }}
				</NcButton>
				<span />
			</div>
			<br>
			<NcCheckboxRadioSwitch v-if="connected"
				class="field"
				:checked.sync="state.search_mails_enabled"
				@update:checked="onSearchChange">
				{{ t('integration_zimbra', 'Enable searching for emails') }}
			</NcCheckboxRadioSwitch>
			<br>
			<p v-if="connected && state.search_mails_enabled" class="settings-hint">
				<InformationOutlineIcon :size="24" class="icon" />
				{{ t('integration_zimbra', 'Warning, everything you type in the search bar will be sent to Zimbra.') }}
			</p>
			<NcCheckboxRadioSwitch
				class="field"
				:checked.sync="state.navigation_enabled"
				@update:checked="onNavigationChange">
				{{ t('integration_zimbra', 'Enable navigation link (link to your Zimbra instance in the navigation menu)') }}
			</NcCheckboxRadioSwitch>
		</div>
	</div>
</template>

<script>
import InformationOutlineIcon from 'vue-material-design-icons/InformationOutline.vue'
import EarthIcon from 'vue-material-design-icons/Earth.vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import AccountIcon from 'vue-material-design-icons/Account.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'

import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { delay } from '../utils.js'
import { showSuccess, showError } from '@nextcloud/dialogs'
import ZimbraIcon from './icons/ZimbraIcon.vue'
import { confirmPassword } from '@nextcloud/password-confirmation'

export default {
	name: 'PersonalSettings',

	components: {
		ZimbraIcon,
		NcButton,
		NcCheckboxRadioSwitch,
		OpenInNewIcon,
		CloseIcon,
		InformationOutlineIcon,
		EarthIcon,
		AccountIcon,
		LockIcon,
	},

	props: [],

	data() {
		return {
			state: loadState('integration_zimbra', 'user-config'),
			loading: false,
			login: '',
			password: '',
			twoFactorRequired: false,
			twoFactorCode: '',
		}
	},

	computed: {
		connected() {
			return !!this.state.token && !!this.state.url && !!this.state.user_name
		},
		connectedDisplayName() {
			return this.state.user_displayname + ' (' + this.state.user_name + ')'
		},
		showLoginPassword() {
			return !this.connected
		},
	},

	watch: {
	},

	mounted() {
		const paramString = window.location.search.substr(1)
		// eslint-disable-next-line
		const urlParams = new URLSearchParams(paramString)
		const glToken = urlParams.get('zimbraToken')
		if (glToken === 'success') {
			showSuccess(t('integration_zimbra', 'Successfully connected to Zimbra!'))
		} else if (glToken === 'error') {
			showError(t('integration_zimbra', 'Error connecting to Zimbra:') + ' ' + urlParams.get('message'))
		}
	},

	methods: {
		async onLogoutClick() {
			await this.saveOptions({ token: '' }, true)
			this.state.token = ''
			this.login = ''
			this.password = ''
			this.twoFactorCode = ''
		},
		onSearchChange(newValue) {
			this.saveOptions({ search_mails_enabled: newValue ? '1' : '0' }, false)
		},
		onNavigationChange(newValue) {
			this.saveOptions({ navigation_enabled: newValue ? '1' : '0' }, false)
		},
		onInput() {
			this.loading = true
			if (this.state.url !== '' && !this.state.url.startsWith('https://')) {
				if (this.state.url.startsWith('http://')) {
					this.state.url = this.state.url.replace('http://', 'https://')
				} else {
					this.state.url = 'https://' + this.state.url
				}
			}
			delay(() => {
				this.saveOptions({
					url: this.state.url,
				}, true)
			}, 2000)()
		},
		async saveOptions(values, sensitive) {
			if (sensitive) {
				await confirmPassword()
			}
			const req = {
				values,
			}
			const url = sensitive ? generateUrl('/apps/integration_zimbra/sensitive-config') : generateUrl('/apps/integration_zimbra/config')
			axios.put(url, req).then((response) => {
				if (response.data.user_name !== undefined) {
					this.state.user_name = response.data.user_name
					if (this.state.token && response.data.user_name === '') {
						showError(t('integration_zimbra', 'Invalid access token'))
						this.state.token = ''
					} else if (this.login && this.password && response.data.user_name === '') {
						if (response.data.two_factor_required) {
							this.twoFactorRequired = true
							showError(t('integration_zimbra', 'Zimbra second factor is required'))
						} else {
							if (this.twoFactorRequired) {
								showError(t('integration_zimbra', 'Invalid login/password or second factor'))
							} else {
								showError(t('integration_zimbra', 'Invalid login/password'))
							}
						}
					} else if (response.data.user_name) {
						showSuccess(t('integration_zimbra', 'Successfully connected to Zimbra!'))
						this.state.user_id = response.data.user_id
						this.state.user_name = response.data.user_name
						this.state.user_displayname = response.data.user_displayname
						this.state.token = 'dumdum'
						this.twoFactorCode = ''
						this.twoFactorRequired = false
					}
				} else {
					showSuccess(t('integration_zimbra', 'Zimbra options saved'))
				}
			}).catch((error) => {
				showError(
					t('integration_zimbra', 'Failed to save Zimbra options')
					+ ': ' + (error.response?.request?.responseText ?? '')
				)
				console.error(error)
			}).then(() => {
				this.loading = false
			})
		},
		onConnectClick() {
			if (this.login && this.password) {
				this.connectWithCredentials()
			}
		},
		connectWithCredentials() {
			this.loading = true
			this.saveOptions({
				login: this.login,
				password: this.password,
				url: this.state.url,
				two_factor_code: this.twoFactorCode,
			}, true)
		},
	},
}
</script>

<style scoped lang="scss">
#zimbra_prefs {
	#zimbra-content {
		margin-left: 30px;
	}

	.field {
		display: flex;
		align-items: center;

		input,
		label {
			width: 300px;
		}

		label {
			display: flex;
			align-items: center;
		}

		.icon {
			margin-right: 8px;
		}
	}

	.settings-hint {
		display: flex;
		align-items: center;
		.icon {
			margin-right: 4px;
		}
	}

	h2 {
		display: flex;
		.icon {
			margin-right: 12px;
		}
	}
}
</style>
