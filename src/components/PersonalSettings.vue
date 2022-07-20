<template>
	<div id="zimbra_prefs" class="section">
		<h2>
			<a class="icon icon-zimbra" />
			{{ t('integration_zimbra', 'Zimbra integration') }}
		</h2>
		<div id="toggle-zimbra-navigation-link">
			<input
				id="zimbra-link"
				type="checkbox"
				class="checkbox"
				:checked="state.navigation_enabled"
				@input="onNavigationChange">
			<label for="zimbra-link">{{ t('integration_zimbra', 'Enable navigation link') }}</label>
		</div>
		<br><br>
		<p v-if="!showOAuth && !connected" class="settings-hint">
			{{ t('integration_zimbra', 'If you are allowed to, You can create a personal access token in your Zimbra profile -> Security -> Personal Access Tokens') }}
		</p>
		<p v-if="!showOAuth && !connected" class="settings-hint">
			{{ t('integration_zimbra', 'You can connect with a personal token OR just with your login/password') }}
		</p>
		<div id="zimbra-content">
			<div class="zimbra-grid-form">
				<label for="zimbra-url">
					<a class="icon icon-link" />
					{{ t('integration_zimbra', 'Zimbra instance address') }}
				</label>
				<input id="zimbra-url"
					v-model="state.url"
					type="text"
					:disabled="connected === true"
					:placeholder="t('integration_zimbra', 'Zimbra instance address')"
					@input="onInput">
				<label v-show="showToken"
					for="zimbra-token">
					<a class="icon icon-category-auth" />
					{{ t('integration_zimbra', 'Personal access token') }}
				</label>
				<input v-show="showToken"
					id="zimbra-token"
					v-model="state.token"
					type="password"
					:disabled="connected === true"
					:placeholder="t('integration_zimbra', 'Zimbra personal access token')"
					@keyup.enter="onConnectClick">
				<label v-show="showLoginPassword"
					for="zimbra-login">
					<a class="icon icon-user" />
					{{ t('integration_zimbra', 'Login') }}
				</label>
				<input v-show="showLoginPassword"
					id="zimbra-login"
					v-model="login"
					type="text"
					:placeholder="t('integration_zimbra', 'Zimbra login')"
					@keyup.enter="onConnectClick">
				<label v-show="showLoginPassword"
					for="zimbra-password">
					<a class="icon icon-password" />
					{{ t('integration_zimbra', 'Password') }}
				</label>
				<input v-show="showLoginPassword"
					id="zimbra-password"
					v-model="password"
					type="password"
					:placeholder="t('integration_zimbra', 'Zimbra password')"
					@keyup.enter="onConnectClick">
			</div>
			<Button v-if="!connected && (showOAuth || (login && password) || state.token)"
				id="zimbra-connect"
				:disabled="loading === true"
				:class="{ loading }"
				@click="onConnectClick">
				<template #icon>
					<OpenInNewIcon />
				</template>
				{{ t('integration_zimbra', 'Connect to Zimbra') }}
			</Button>
			<div v-if="connected" class="zimbra-grid-form">
				<label class="zimbra-connected">
					<a class="icon icon-checkmark-color" />
					{{ t('integration_zimbra', 'Connected as {user}', { user: connectedDisplayName }) }}
				</label>
				<Button id="zimbra-rm-cred" @click="onLogoutClick">
					<template #icon>
						<CloseIcon />
					</template>
					{{ t('integration_zimbra', 'Disconnect from Zimbra') }}
				</Button>
				<span />
			</div>
			<br>
			<div v-if="connected" id="zimbra-search-block">
				<input
					id="search-zimbra"
					type="checkbox"
					class="checkbox"
					:checked="state.search_mails_enabled"
					@input="onSearchChange">
				<label for="search-zimbra">{{ t('integration_zimbra', 'Enable searching for emails') }}</label>
				<br><br>
				<p v-if="state.search_mails_enabled" class="settings-hint">
					<span class="icon icon-details" />
					{{ t('integration_zimbra', 'Warning, everything you type in the search bar will be sent to Zimbra.') }}
				</p>
			</div>
		</div>
	</div>
</template>

<script>
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew'
import CloseIcon from 'vue-material-design-icons/Close'
import Button from '@nextcloud/vue/dist/Components/Button'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { delay, oauthConnect } from '../utils'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'PersonalSettings',

	components: {
		Button,
		OpenInNewIcon,
		CloseIcon,
	},

	props: [],

	data() {
		return {
			state: loadState('integration_zimbra', 'user-config'),
			loading: false,
			redirect_uri: window.location.protocol + '//' + window.location.host + generateUrl('/apps/integration_zimbra/oauth-redirect'),
			login: '',
			password: '',
		}
	},

	computed: {
		showOAuth() {
			return (this.state.url === this.state.oauth_instance_url) && this.state.client_id && this.state.client_secret
		},
		connected() {
			return this.state.token && this.state.token !== ''
				&& this.state.url && this.state.url !== ''
				&& this.state.user_name && this.state.user_name !== ''
		},
		connectedDisplayName() {
			return this.state.user_displayname + ' (' + this.state.user_name + ')'
		},
		showLoginPassword() {
			return !this.showOAuth && !this.connected && !this.state.token
		},
		showToken() {
			return !this.showOAuth && !this.login && !this.password
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
		onLogoutClick() {
			this.state.token = ''
			this.login = ''
			this.password = ''
			this.saveOptions({ token: '' })
		},
		onSearchChange(e) {
			this.state.search_mails_enabled = e.target.checked
			this.saveOptions({ search_mails_enabled: this.state.search_mails_enabled ? '1' : '0' })
		},
		onNavigationChange(e) {
			this.state.navigation_enabled = e.target.checked
			this.saveOptions({ navigation_enabled: this.state.navigation_enabled ? '1' : '0' })
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
				})
			}, 2000)()
		},
		saveOptions(values) {
			const req = {
				values,
			}
			const url = generateUrl('/apps/integration_zimbra/config')
			axios.put(url, req)
				.then((response) => {
					if (response.data.user_name !== undefined) {
						this.state.user_name = response.data.user_name
						if (this.state.token && response.data.user_name === '') {
							showError(t('integration_zimbra', 'Invalid access token'))
							this.state.token = ''
						} else if (this.login && this.password && response.data.user_name === '') {
							showError(t('integration_zimbra', 'Invalid login/password'))
						} else if (response.data.user_name) {
							showSuccess(t('integration_zimbra', 'Successfully connected to Zimbra!'))
							this.state.user_id = response.data.user_id
							this.state.user_name = response.data.user_name
							this.state.user_displayname = response.data.user_displayname
							this.state.token = 'dumdum'
						}
					} else {
						showSuccess(t('integration_zimbra', 'Zimbra options saved'))
					}
				})
				.catch((error) => {
					showError(
						t('integration_zimbra', 'Failed to save Zimbra options')
						+ ': ' + (error.response?.request?.responseText ?? '')
					)
					console.error(error)
				})
				.then(() => {
					this.loading = false
				})
		},
		onConnectClick() {
			if (this.showOAuth) {
				this.connectWithOauth()
			} else if (this.login && this.password) {
				this.connectWithCredentials()
			} else {
				this.connectWithToken()
			}
		},
		connectWithToken() {
			this.loading = true
			this.saveOptions({
				token: this.state.token,
			})
		},
		connectWithCredentials() {
			this.loading = true
			this.saveOptions({
				login: this.login,
				password: this.password,
				url: this.state.url,
			})
		},
		connectWithOauth() {
			if (this.state.use_popup) {
				oauthConnect(this.state.url, this.state.client_id, null, true)
					.then((data) => {
						this.state.token = 'dummyToken'
						this.state.user_name = data.userName
						this.state.user_displayname = data.userDisplayName
					})
			} else {
				oauthConnect(this.state.url, this.state.client_id, 'settings')
			}
		},
	},
}
</script>

<style scoped lang="scss">
.zimbra-grid-form label {
	line-height: 38px;
}

.zimbra-grid-form input {
	width: 100%;
}

.zimbra-grid-form {
	max-width: 600px;
	display: grid;
	grid-template: 1fr / 1fr 1fr;
	button .icon {
		margin-bottom: -1px;
	}
}

#zimbra_prefs .icon {
	display: inline-block;
	width: 32px;
}

#zimbra_prefs .grid-form .icon {
	margin-bottom: -3px;
}

.icon-zimbra {
	background-image: url('../../img/app-dark.svg');
	background-size: 23px 23px;
	height: 23px;
	margin-bottom: -4px;
	filter: var(--background-invert-if-dark);
}

// for NC <= 24
body.theme--dark .icon-zimbra {
	background-image: url('../../img/app.svg');
}

#zimbra-content {
	margin-left: 40px;
}

#zimbra-search-block .icon {
	width: 22px;
}

#toggle-zimbra-navigation-link {
	margin-left: 40px;
}
</style>
