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

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;

use OCA\Zimbra\Service\ZimbraAPIService;
use OCA\Zimbra\AppInfo\Application;
use OCP\IURLGenerator;

class ZimbraAPIController extends Controller {

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
	/**
	 * @var string
	 */
	private $zimbraUrl;
	/**
	 * @var IURLGenerator
	 */
	private $urlGenerator;

	public function __construct(string $appName,
								IRequest $request,
								IConfig $config,
								IURLGenerator $urlGenerator,
								ZimbraAPIService $zimbraAPIService,
								?string $userId) {
		parent::__construct($appName, $request);
		$this->config = $config;
		$this->zimbraAPIService = $zimbraAPIService;
		$this->userId = $userId;
		$adminOauthUrl = $this->config->getAppValue(Application::APP_ID, 'oauth_instance_url');
		$this->zimbraUrl = $this->config->getUserValue($this->userId, Application::APP_ID, 'url', $adminOauthUrl) ?: $adminOauthUrl;
		$this->urlGenerator = $urlGenerator;
	}

	/**
	 * get zimbra user avatar
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $userId
	 * @param int $useFallback
	 * @return DataDisplayResponse|RedirectResponse
	 * @throws \Exception
	 */
	public function getUserAvatar(string $userId, int $useFallback = 1) {
		$result = $this->zimbraAPIService->getUserAvatar($this->userId, $userId, $this->zimbraUrl);
		if (isset($result['avatarContent'])) {
			$response = new DataDisplayResponse($result['avatarContent']);
			$response->cacheFor(60 * 60 * 24);
			return $response;
		} elseif ($useFallback !== 0 && isset($result['userInfo'])) {
			$userName = $result['userInfo']['username'] ?? '??';
			$fallbackAvatarUrl = $this->urlGenerator->linkToRouteAbsolute('core.GuestAvatar.getAvatar', ['guestName' => $userName, 'size' => 44]);
			return new RedirectResponse($fallbackAvatarUrl);
		}
		return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
	}

	/**
	 * @NoAdminRequired
	 * @return DataResponse
	 * @throws \Exception
	 */
	public function getContacts() {
		$zimbraUserName = $this->config->getUserValue($this->userId, Application::APP_ID, 'user_name');
		if ($zimbraUserName === '') {
			return new DataResponse('not connected', Http::STATUS_BAD_REQUEST);
		}
		$result = $this->zimbraAPIService->getContacts($this->userId);
		if (isset($result['error'])) {
			return new DataResponse($result['error'], Http::STATUS_BAD_REQUEST);
		} else {
			return new DataResponse($result);
		}
	}

	/**
	 * @NoAdminRequired
	 * @return DataResponse
	 * @throws \Exception
	 */
	public function getUnreadEmails(int $offset = 0, int $limit = 10) {
		$zimbraUserName = $this->config->getUserValue($this->userId, Application::APP_ID, 'user_name');
		if ($zimbraUserName === '') {
			return new DataResponse('not connected', Http::STATUS_BAD_REQUEST);
		}
		$result = $this->zimbraAPIService->getUnreadEmails($this->userId, $offset, $limit);
		if (isset($result['error'])) {
			return new DataResponse($result['error'], Http::STATUS_BAD_REQUEST);
		} else {
			return new DataResponse($result);
		}
	}

	/**
	 * @NoAdminRequired
	 * @return DataResponse
	 * @throws \Exception
	 */
	public function getUpcomingEvents() {
		$zimbraUserName = $this->config->getUserValue($this->userId, Application::APP_ID, 'user_name');
		if ($zimbraUserName === '') {
			return new DataResponse('not connected', Http::STATUS_BAD_REQUEST);
		}
		$result = $this->zimbraAPIService->getUpcomingEventsSoap($this->userId);
		if (isset($result['error'])) {
			return new DataResponse($result['error'], Http::STATUS_BAD_REQUEST);
		} else {
			return new DataResponse($result);
		}
	}
}
