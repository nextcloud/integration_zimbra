/* jshint esversion: 6 */

/**
 * Nextcloud - zimbra
 *
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2022
 */

import Vue from 'vue'
import './bootstrap'
import DashboardEmail from './views/DashboardEmail'

document.addEventListener('DOMContentLoaded', function() {

	OCA.Dashboard.register('zimbra_email', (el, { widget }) => {
		const View = Vue.extend(DashboardEmail)
		new View({
			propsData: { title: widget.title },
		}).$mount(el)
	})

})
