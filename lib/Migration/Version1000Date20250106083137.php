<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Zimbra\Migration;

use Closure;
use OCA\Zimbra\AppInfo\Application;
use OCP\DB\ISchemaWrapper;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Security\ICrypto;
use OCP\Server;

class Version1000Date20250106083137 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$config = Server::get(IConfig::class);
		$crypto = Server::get(ICrypto::class);
		$userManager = Server::get(IUserManager::class);

		$config->setAppValue(Application::APP_ID, 'pre_auth_key', $crypto->encrypt($config->getAppValue(Application::APP_ID, 'pre_auth_key')));

		$userManager->callForAllUsers(function (IUser $user) use ($config, $crypto) {
			$uid = $user->getUID();
			foreach (['token', 'password'] as $key) {
				$config->setUserValue($uid, Application::APP_ID, $key, $crypto->encrypt($config->getUserValue($uid, Application::APP_ID, $key)));
			}
		});
	}
}
