<template>
	<div id="zimbra_prefs" class="section">
		<h2>
			<ZimbraIcon class="icon" />
			{{ t('integration_zimbra', 'Zimbra integration') }}
		</h2>
		<div class="field">
			<label for="zimbra-instance">
				<EarthIcon :size="20" class="icon" />
				{{ t('integration_zimbra', 'Default Zimbra server') }}
			</label>
			<input id="zimbra-instance"
				v-model="state.admin_instance_url"
				type="text"
				placeholder="https://..."
				@input="onInput">
		</div>
	</div>
</template>

<script>
import EarthIcon from 'vue-material-design-icons/Earth.vue'

import ZimbraIcon from './icons/ZimbraIcon.vue'

import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { delay } from '../utils.js'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'AdminSettings',

	components: {
		ZimbraIcon,
		EarthIcon,
	},

	props: [],

	data() {
		return {
			state: loadState('integration_zimbra', 'admin-config'),
			// to prevent some browsers to fill fields with remembered passwords
			readonly: true,
		}
	},

	watch: {
	},

	mounted() {
	},

	methods: {
		onInput() {
			delay(() => {
				this.saveOptions({
					admin_instance_url: this.state.admin_instance_url,
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
