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

use Exception;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;

use OCA\Zimbra\Service\ZimbraAPIService;
use OCA\Zimbra\AppInfo\Application;

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
	 * @NoAdminRequired
	 * @return DataResponse
	 * @throws Exception
	 */
	public function getContacts(): DataResponse {
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
	 * @param int $offset
	 * @param int $limit
	 * @return DataResponse
	 * @throws Exception
	 */
	public function getUnreadEmails(int $offset = 0, int $limit = 10): DataResponse {
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
	 * @throws Exception
	 */
	public function getUpcomingEvents(): DataResponse {
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
