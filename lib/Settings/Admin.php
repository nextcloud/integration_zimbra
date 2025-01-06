<?php

namespace OCA\Zimbra\Settings;

use OCA\Zimbra\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\Security\ICrypto;
use OCP\Settings\ISettings;

class Admin implements ISettings {

	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var IInitialState
	 */
	private $initialStateService;

	public function __construct(
		IConfig $config,
		IInitialState $initialStateService,
		private ICrypto $crypto,
	) {
		$this->config = $config;
		$this->initialStateService = $initialStateService;
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		$adminUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url');
		$preAuthKey = $this->crypto->decrypt($this->config->getAppValue(Application::APP_ID, 'pre_auth_key'));

		$adminConfig = [
			'admin_instance_url' => $adminUrl,
			'pre_auth_key' => $preAuthKey,
		];
		$this->initialStateService->provideInitialState('admin-config', $adminConfig);
		return new TemplateResponse(Application::APP_ID, 'adminSettings');
	}

	public function getSection(): string {
		return 'connected-accounts';
	}

	public function getPriority(): int {
		return 10;
	}
}
