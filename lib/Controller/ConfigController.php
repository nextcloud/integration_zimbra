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

use DateTime;
use OCA\Activity\Data;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IURLGenerator;
use OCP\IConfig;
use OCP\IL10N;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;

use OCA\Zimbra\Service\ZimbraAPIService;
use OCA\Zimbra\AppInfo\Application;

class ConfigController extends Controller {

	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var IURLGenerator
	 */
	private $urlGenerator;
	/**
	 * @var IL10N
	 */
	private $l;
	/**
	 * @var ZimbraAPIService
	 */
	private $zimbraAPIService;
	/**
	 * @var string|null
	 */
	private $userId;
	/**
	 * @var IInitialState
	 */
	private $initialStateService;

	public function __construct(string $appName,
								IRequest $request,
								IConfig $config,
								IURLGenerator $urlGenerator,
								IL10N $l,
								IInitialState $initialStateService,
								ZimbraAPIService $zimbraAPIService,
								?string $userId) {
		parent::__construct($appName, $request);
		$this->config = $config;
		$this->urlGenerator = $urlGenerator;
		$this->l = $l;
		$this->zimbraAPIService = $zimbraAPIService;
		$this->userId = $userId;
		$this->initialStateService = $initialStateService;
	}

	/**
	 * set config values
	 * @NoAdminRequired
	 *
	 * @param array $values
	 * @return DataResponse
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
			if ($values['token'] && $values['token'] !== '') {
				$adminOauthUrl = $this->config->getAppValue(Application::APP_ID, 'oauth_instance_url');
				$zimbraUrl = $this->config->getUserValue($this->userId, Application::APP_ID, 'url', $adminOauthUrl) ?: $adminOauthUrl;
				$result = $this->storeUserInfo($zimbraUrl);
			} else {
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_id');
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_name');
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_displayname');
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'token');
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'login');
				$this->config->deleteUserValue($this->userId, Application::APP_ID, 'password');
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
			$infoReqResp = $this->zimbraAPIService->soapRequest($this->userId, 'GetInfoRequest', 'urn:zimbraAccount');
			$userInfo = $infoReqResp['Body']['GetInfoResponse'] ?? [];
			$zUserId = $userInfo['id'] ?? $login;
			$zUserName = $userInfo['name'] ?? $login;
			$zUserDisplayName = $userInfo['attrs']['_attrs']['displayName'] ?? $login;
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', $zUserId);
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', $zUserName);
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_displayname', $zUserDisplayName);
			return new DataResponse([
				'user_id' => $zUserId,
				'user_name' => $zUserName,
				'user_displayname' => $zUserDisplayName,
//				'contacts' => $this->zimbraAPIService->getContacts($this->userId),
				'events' => $this->zimbraAPIService->getUpcomingEventsSoap($this->userId),
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

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $username
	 * @return TemplateResponse
	 */
	public function popupSuccessPage(string $user_name, string $user_displayname): TemplateResponse {
		$this->initialStateService->provideInitialState('popup-data', ['user_name' => $user_name, 'user_displayname' => $user_displayname]);
		return new TemplateResponse(Application::APP_ID, 'popupSuccess', [], TemplateResponse::RENDER_AS_GUEST);
	}

	/**
	 * receive oauth code and get oauth access token
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $code
	 * @param string $state
	 * @return RedirectResponse
	 */
	public function oauthRedirect(string $code = '', string $state = ''): RedirectResponse {
		$configState = $this->config->getUserValue($this->userId, Application::APP_ID, 'oauth_state');
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret');

		// anyway, reset state
		$this->config->deleteUserValue($this->userId, Application::APP_ID, 'oauth_state');

		if ($clientID and $clientSecret and $configState !== '' and $configState === $state) {
			$redirect_uri = $this->config->getUserValue($this->userId, Application::APP_ID, 'redirect_uri');
			$adminOauthUrl = $this->config->getAppValue(Application::APP_ID, 'oauth_instance_url');
			$zimbraUrl = $this->config->getUserValue($this->userId, Application::APP_ID, 'url', $adminOauthUrl) ?: $adminOauthUrl;
			$result = $this->zimbraAPIService->requestOAuthAccessToken($zimbraUrl, [
				'client_id' => $clientID,
				'client_secret' => $clientSecret,
				'code' => $code,
				'redirect_uri' => $redirect_uri,
				'grant_type' => 'authorization_code'
			], 'POST');
			if (isset($result['access_token'])) {
				$accessToken = $result['access_token'];
				$refreshToken = $result['refresh_token'] ?? '';
				if (isset($result['expires_in'])) {
					$nowTs = (new Datetime())->getTimestamp();
					$expiresAt = $nowTs + (int) $result['expires_in'];
					$this->config->setUserValue($this->userId, Application::APP_ID, 'token_expires_at', $expiresAt);
				}
				$this->config->setUserValue($this->userId, Application::APP_ID, 'token', $accessToken);
				$this->config->setUserValue($this->userId, Application::APP_ID, 'refresh_token', $refreshToken);
				$userInfo = $this->storeUserInfo($zimbraUrl);
				$usePopup = $this->config->getAppValue(Application::APP_ID, 'use_popup', '0') === '1';
				if ($usePopup) {
					return new RedirectResponse(
						$this->urlGenerator->linkToRoute('integration_zimbra.config.popupSuccessPage', [
							'user_name' => $userInfo['user_name'] ?? '',
							'user_displayname' => $userInfo['user_displayname'] ?? '',
						])
					);
				} else {
					$oauthOrigin = $this->config->getUserValue($this->userId, Application::APP_ID, 'oauth_origin');
					$this->config->deleteUserValue($this->userId, Application::APP_ID, 'oauth_origin');
					if ($oauthOrigin === 'settings') {
						return new RedirectResponse(
							$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'connected-accounts']) .
							'?zimbraToken=success'
						);
					} elseif ($oauthOrigin === 'dashboard') {
						return new RedirectResponse(
							$this->urlGenerator->linkToRoute('dashboard.dashboard.index')
						);
					} elseif (preg_match('/^files--.*/', $oauthOrigin)) {
						$parts = explode('--', $oauthOrigin);
						if (count($parts) > 1) {
							// $path = preg_replace('/^files--/', '', $oauthOrigin);
							$path = $parts[1];
							if (count($parts) > 2) {
								$this->config->setUserValue($this->userId, Application::APP_ID, 'file_ids_to_send_after_oauth', $parts[2]);
								$this->config->setUserValue($this->userId, Application::APP_ID, 'current_dir_after_oauth', $path);
							}
							return new RedirectResponse(
								$this->urlGenerator->linkToRoute('files.view.index', ['dir' => $path])
							);
						}
					}
				}
			}
			$result = $this->l->t('Error getting OAuth access token. ' . $result['error']);
		} else {
			$result = $this->l->t('Error during OAuth exchanges');
		}
		return new RedirectResponse(
			$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'connected-accounts']) .
			'?zimbraToken=error&message=' . urlencode($result)
		);
	}

	/**
	 * @param string $zimbraUrl
	 * @return string
	 */
	private function storeUserInfo(string $zimbraUrl): array {
		$info = $this->zimbraAPIService->request($this->userId, $zimbraUrl, 'users/me');
		if (isset($info['first_name'], $info['last_name'], $info['id'], $info['username'])) {
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', $info['id'] ?? '');
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', $info['username'] ?? '');
			$userDisplayName = ($info['first_name'] ?? '') . ' ' . ($info['last_name'] ?? '');
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_displayname', $userDisplayName);

			return [
				'user_id' => $info['id'] ?? '',
				'user_name' => $info['username'] ?? '',
				'user_displayname' => $userDisplayName,
			];
		} else {
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', '');
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', '');
			return [
				'user_id' => '',
				'user_name' => '',
				'user_displayname' => '',
				// TODO change perso settings to get/check user name errors correctly
			];
		}
	}
}
