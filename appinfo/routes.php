<?php
/**
 * Nextcloud - Zimbra
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2022
 */

return [
	'routes' => [
		['name' => 'config#isUserConnected', 'url' => '/is-connected', 'verb' => 'GET'],
		['name' => 'config#setConfig', 'url' => '/config', 'verb' => 'PUT'],
		['name' => 'config#setAdminConfig', 'url' => '/admin-config', 'verb' => 'PUT'],

		['name' => 'zimbraAPI#getContacts', 'url' => '/contacts', 'verb' => 'GET'],
		['name' => 'zimbraAPI#getUpcomingEvents', 'url' => '/upcoming-events', 'verb' => 'GET'],
		['name' => 'zimbraAPI#getUnreadEmails', 'url' => '/unread-emails', 'verb' => 'GET'],
	]
];
