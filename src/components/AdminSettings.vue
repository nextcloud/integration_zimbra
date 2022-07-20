<template>
	<div id="zimbra_prefs" class="section">
		<h2>
			<ZimbraIcon class="icon" />
			{{ t('integration_zimbra', 'Zimbra integration') }}
		</h2>
		<p class="settings-hint">
			{{ t('integration_zimbra', 'If you want to allow your Nextcloud users to use OAuth to authenticate to a Zimbra instance of your choice, create an application in your Zimbra settings and set the ID and secret here.') }}
		</p>
		<br>
		<p class="settings-hint">
			<InformationVariantIcon :size="24" class="icon" />
			{{ t('integration_zimbra', 'Make sure you set the "Redirect URI" to') }}
			&nbsp;<b> {{ redirect_uri }} </b>
		</p>
		<br>
		<p class="settings-hint">
			{{ t('integration_zimbra', 'Put the "Application ID" and "Application secret" below. Your Nextcloud users will then see a "Connect to Zimbra" button in their personal settings if they select the Zimbra instance defined here.') }}
		</p>
		<div class="field">
			<label for="zimbra-oauth-instance">
				<EarthIcon :size="20" class="icon" />
				{{ t('integration_zimbra', 'Zimbra address') }}
			</label>
			<input id="zimbra-oauth-instance"
				v-model="state.oauth_instance_url"
				type="text"
				placeholder="https://..."
				@input="onInput">
		</div>
		<div class="field">
			<label for="zimbra-client-id">
				<KeyIcon :size="20" class="icon" />
				{{ t('integration_zimbra', 'Application ID') }}
			</label>
			<input id="zimbra-client-id"
				v-model="state.client_id"
				type="password"
				:readonly="readonly"
				:placeholder="t('integration_zimbra', 'ID of your Zimbra application')"
				@input="onInput"
				@focus="readonly = false">
		</div>
		<div class="field">
			<label for="zimbra-client-secret">
				<KeyIcon :size="20" class="icon" />
				{{ t('integration_zimbra', 'Application secret') }}
			</label>
			<input id="zimbra-client-secret"
				v-model="state.client_secret"
				type="password"
				:readonly="readonly"
				:placeholder="t('integration_zimbra', 'Client secret of your Zimbra application')"
				@focus="readonly = false"
				@input="onInput">
		</div>
		<CheckboxRadioSwitch
			class="field"
			:checked.sync="state.use_popup"
			@update:checked="onUsePopupChanged">
			{{ t('integration_zimbra', 'Use a popup to authenticate') }}
		</CheckboxRadioSwitch>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { delay } from '../utils'
import { showSuccess, showError } from '@nextcloud/dialogs'
import CheckboxRadioSwitch from '@nextcloud/vue/dist/Components/CheckboxRadioSwitch'
import InformationVariantIcon from 'vue-material-design-icons/InformationVariant'
import EarthIcon from 'vue-material-design-icons/Earth'
import KeyIcon from 'vue-material-design-icons/Key'
import ZimbraIcon from './icons/ZimbraIcon'

export default {
	name: 'AdminSettings',

	components: {
		ZimbraIcon,
		CheckboxRadioSwitch,
		InformationVariantIcon,
		EarthIcon,
		KeyIcon,
	},

	props: [],

	data() {
		return {
			state: loadState('integration_zimbra', 'admin-config'),
			// to prevent some browsers to fill fields with remembered passwords
			readonly: true,
			redirect_uri: window.location.protocol + '//' + window.location.host + generateUrl('/apps/integration_zimbra/oauth-redirect'),
		}
	},

	watch: {
	},

	mounted() {
	},

	methods: {
		onUsePopupChanged(newValue) {
			this.saveOptions({ use_popup: newValue ? '1' : '0' })
		},
		onInput() {
			delay(() => {
				this.saveOptions({
					client_id: this.state.client_id,
					client_secret: this.state.client_secret,
					oauth_instance_url: this.state.oauth_instance_url,
				})
			}, 2000)()
		},
		saveOptions(values) {
			const req = {
				values,
			}
			const url = generateUrl('/apps/integration_zimbra/admin-config')
			axios.put(url, req).then((response) => {
				showSuccess(t('integration_zimbra', 'Zimbra admin options saved'))
			}).catch((error) => {
				showError(
					t('integration_zimbra', 'Failed to save Zimbra admin options')
					+ ': ' + (error.response?.request?.responseText ?? '')
				)
				console.debug(error)
			})
		},
	},
}
</script>

<style scoped lang="scss">
#zimbra_prefs {
	.field {
		display: flex;
		align-items: center;
		margin-left: 30px;

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
	}

	h2 {
		display: flex;
		.icon {
			margin-right: 12px;
		}
	}
}
</style>
