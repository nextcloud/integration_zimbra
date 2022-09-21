<?php
/**
 * Nextcloud - Zimbra
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Zimbra\Controller;

use OCP\IConfig;
use OCP\IRequest;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;

use OCA\Zimbra\Service\ZimbraAPIService;
use OCA\Zimbra\AppInfo\Application;
use OCP\PreConditionNotMetException;

class ConfigController extends Controller {

	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var ZimbraAPIService
	 */
	private $zimbraAPIService;
	/**
	 * @var string|null
	 */
	private $userId;

	public function __construct(string $appName,
								IRequest $request,
								IConfig $config,
								ZimbraAPIService $zimbraAPIService,
								?string $userId) {
		parent::__construct($appName, $request);
		$this->config = $config;
		$this->zimbraAPIService = $zimbraAPIService;
		$this->userId = $userId;
	}

	/**
	 * set config values
	 * @NoAdminRequired
	 *
	 * @param array $values
	 * @return DataResponse
	 * @throws PreConditionNotMetException
	 */
	public function setConfig(array $values): DataResponse {
		if (isset($values['url'], $values['login'], $values['password'])) {
			$this->config->setUserValue($this->userId, Application::APP_ID, 'url', $values['url']);
			return $this->loginWithCredentials($values['login'], $values['password']);
		}

		foreach ($values as $key => $value) {
			$this->config->setUserValue($this->userId, Application::APP_ID, $key, $value);
		}
		$result = [];

		if (isset($values['token'])) {
			if ($values['token'] === '') {
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_id');
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_name');
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_displayname');
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'token');
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'login');
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'password');
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'zimbra_version');
				$result['user_id'] = '';
				$result['user_name'] = '';
				$result['user_displayname'] = '';
			}
			// if the token is set, cleanup refresh token and expiration date
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'refresh_token');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'token_expires_at');
		}
		return new DataResponse($result);
	}

	/**
	 * @param string $login
	 * @param string $password
	 * @return DataResponse
	 * @throws PreConditionNotMetException
	 */
	private function loginWithCredentials(string $login, string $password): DataResponse {
		// cleanup refresh token and expiration date on classic login
		$this->config->deleteUserValue($this->userId, Application::APP_ID, 'refresh_token');
		$this->config->deleteUserValue($this->userId, Application::APP_ID, 'token_expires_at');

		$result = $this->zimbraAPIService->login($this->userId, $login, $password);
		if (isset($result['token'])) {
			// we have to store login and password for now
			// even if we use a token, it expires and we can only get a new one with login/password
			$this->config->setUserValue($this->userId, Application::APP_ID, 'login', $login);
			$this->config->setUserValue($this->userId, Application::APP_ID, 'password', $password);

			$this->config->setUserValue($this->userId, Application::APP_ID, 'token', $result['token']);
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', $login);
			// get user info
			$infoReqResp = $this->zimbraAPIService->soapRequest($this->userId, 'GetInfoRequest', 'urn:zimbraAccount', ['rights' => '', 'sections' => 'attrs']);
			$userInfo = $infoReqResp['Body']['GetInfoResponse'] ?? [];
			$zUserId = $userInfo['id'] ?? $login;
			$zUserName = $userInfo['name'] ?? $login;
			$zUserDisplayName = $userInfo['attrs']['_attrs']['displayName'] ?? $login;
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', $zUserId);
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', $zUserName);
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_displayname', $zUserDisplayName);
			$this->config->setUserValue($this->userId, Application::APP_ID, 'zimbra_version', $userInfo['version'] ?? '');
			return new DataResponse([
				'user_id' => $zUserId,
				'user_name' => $zUserName,
				'user_displayname' => $zUserDisplayName,
//				'contacts' => $this->zimbraAPIService->getContacts($this->userId),
//				'events' => $this->zimbraAPIService->getUpcomingEventsSoap($this->userId),
//				'mail' => $this->zimbraAPIService->getUnreadEmails($this->userId),
//				'GetInfoRequest' => $this->zimbraAPIService->soapRequest($this->userId, 'GetInfoRequest', 'urn:zimbraAccount'),
			]);
		}
		return new DataResponse([
			'user_id' => '',
			'user_name' => '',
			'user_displayname' => '',
		]);
	}

	/**
	 * set admin config values
	 *
	 * @param array $values
	 * @return DataResponse
	 */
	public function setAdminConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			$this->config->setAppValue(Application::APP_ID, $key, $value);
		}
		return new DataResponse(1);
	}
}
