<?php

namespace OCA\Zimbra\Settings;

use OCA\Zimbra\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\Security\ICrypto;
use OCP\Settings\ISettings;

class Personal implements ISettings {

	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var IInitialState
	 */
	private $initialStateService;
	/**
	 * @var string|null
	 */
	private $userId;

	public function __construct(
		IConfig $config,
		IInitialState $initialStateService,
		?string $userId,
		private ICrypto $crypto,
	) {
		$this->config = $config;
		$this->initialStateService = $initialStateService;
		$this->userId = $userId;
	}

	private function decryptIfNotEmpty(string $value): string {
		if ($value === '') {
			return $value;
		}
		return $this->crypto->decrypt($value);
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		$token = $this->decryptIfNotEmpty($this->config->getUserValue($this->userId, Application::APP_ID, 'token'));
		$searchMailsEnabled = $this->config->getUserValue($this->userId, Application::APP_ID, 'search_mails_enabled', '0') === '1';
		$navigationEnabled = $this->config->getUserValue($this->userId, Application::APP_ID, 'navigation_enabled', '0') === '1';
		$zimbraUserId = $this->config->getUserValue($this->userId, Application::APP_ID, 'user_id');
		$zimbraUserName = $this->config->getUserValue($this->userId, Application::APP_ID, 'user_name');
		$zimbraUserDisplayName = $this->config->getUserValue($this->userId, Application::APP_ID, 'user_displayname');
		$adminUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url');
		$url = $this->config->getUserValue($this->userId, Application::APP_ID, 'url', $adminUrl) ?: $adminUrl;

		$userConfig = [
			'token' => $token ? 'dummyTokenContent' : '',
			'url' => $url,
			'user_id' => $zimbraUserId,
			'user_name' => $zimbraUserName,
			'user_displayname' => $zimbraUserDisplayName,
			'search_mails_enabled' => $searchMailsEnabled,
			'navigation_enabled' => $navigationEnabled,
		];
		$this->initialStateService->provideInitialState('user-config', $userConfig);
		return new TemplateResponse(Application::APP_ID, 'personalSettings');
	}

	public function getSection(): string {
		return 'connected-accounts';
	}

	public function getPriority(): int {
		return 10;
	}
}
