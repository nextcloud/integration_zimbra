<?php
/**
 * Nextcloud - Zimbra
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 */

namespace OCA\Zimbra\Migration;

use OCA\Zimbra\AppInfo\Application;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Security\ICrypto;

/**
 * Reset broken encrypted Zimbra credentials that prevent the app from booting.
 * This fixes the "Authenticated ciphertext could not be decoded" error (issue #33).
 */
class Version1014Date20250707000000 extends SimpleMigrationStep {

	public function __construct(
		private IConfig $config,
		private ICrypto $crypto,
	) {
	}

	public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {
		$appId = Application::APP_ID;

		// Find all users that have a Zimbra login configured
		$usersWithLogin = $this->config->getUsersForUserValue($appId, 'login', '');
		$allUsers = $this->config->getUsersForUserValue($appId, 'url', '');
		$candidates = array_unique(array_merge($usersWithLogin, $allUsers));

		$resetCount = 0;
		foreach ($candidates as $userId) {
			foreach (['token', 'password', 'app_password'] as $key) {
				$encryptedValue = $this->config->getUserValue($userId, $appId, $key, '');
				if ($encryptedValue === '') {
					continue;
				}
				try {
					$this->crypto->decrypt($encryptedValue);
				} catch (\Exception $e) {
					$output->warning(
						sprintf('Resetting broken Zimbra %s for user "%s" (cannot decrypt).', $key, $userId)
					);
					$this->config->deleteUserValue($userId, $appId, $key);
					// Also clear dependent values so the user sees the login form
					$this->config->deleteUserValue($userId, $appId, 'token_expires_at');
					$resetCount++;
				}
			}
		}

		if ($resetCount > 0) {
			$output->info(sprintf('Zimbra migration: reset %d broken credential(s). Affected users must reconnect.', $resetCount));
		}
	}
}
