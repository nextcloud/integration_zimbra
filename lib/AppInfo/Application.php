<?php
/**
 * Nextcloud - Zimbra
 *
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2022
 */

namespace OCA\Zimbra\AppInfo;

use Closure;
use OCA\DAV\Events\CalendarObjectCreatedEvent;
use OCA\DAV\Events\CalendarObjectUpdatedEvent;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Zimbra\Dashboard\ZimbraEmailWidget;
use OCA\Zimbra\Dashboard\ZimbraEventWidget;
use OCA\Zimbra\Listener\CalendarObjectCreatedListener;
use OCA\Zimbra\Listener\CalendarObjectUpdatedListener;
use OCA\Zimbra\Service\ZimbraAPIService;
use OCA\Zimbra\ZimbraAddressBook;
use OCP\Contacts\IManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IL10N;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserSession;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;

use OCA\Zimbra\Dashboard\ZimbraWidget;
use OCA\Zimbra\Search\ZimbraSearchMailProvider;
use OCP\Util;

/**
 * Class Application
 *
 * @package OCA\Zimbra\AppInfo
 */
class Application extends App implements IBootstrap {
	public const APP_ID = 'integration_zimbra';

	public const INTEGRATION_USER_AGENT = 'Nextcloud Zimbra integration';
	public const APP_CONFIG_CACHE_TTL_CONTACTS = 'cache-ttl-contacts';
	public const APP_CONFIG_CACHE_TTL_CONTACTS_DEFAULT = 600;
	/**
	 * @var mixed
	 */
	private $config;

	/**
	 * Constructor
	 *
	 * @param array $urlParams
	 */
	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);

		$container = $this->getContainer();
		$this->config = $container->get(IConfig::class);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerDashboardWidget(ZimbraEventWidget::class);
		$context->registerDashboardWidget(ZimbraEmailWidget::class);
		$context->registerSearchProvider(ZimbraSearchMailProvider::class);
	}

	public function boot(IBootContext $context): void {
		$context->injectFn(Closure::fromCallable([$this, 'registerNavigation']));
		$context->injectFn(Closure::fromCallable([$this, 'registerAddressBook']));
		Util::addStyle(self::APP_ID, 'zimbra-search');
	}

	public function registerAddressBook(IUserSession $userSession,
										IManager $contactsManager,
										ZimbraAddressBook $zimbraAddressBook,
										ZimbraAPIService $zimbraAPIService): void {
		$user = $userSession->getUser();
		if ($user !== null) {
			$userId = $user->getUID();
			if ($zimbraAPIService->isUserConnected($userId)) {
				$contactsManager->registerAddressBook($zimbraAddressBook);
			}
		}
	}

	public function registerNavigation(IUserSession $userSession): void {
		$user = $userSession->getUser();
		if ($user !== null) {
			$userId = $user->getUID();
			$container = $this->getContainer();

			if ($this->config->getUserValue($userId, self::APP_ID, 'navigation_enabled', '0') === '1') {
				$adminOauthUrl = $this->config->getAppValue(Application::APP_ID, 'oauth_instance_url');
				$zimbraUrl = $this->config->getUserValue($userId, self::APP_ID, 'url', $adminOauthUrl) ?: $adminOauthUrl;
				if ($zimbraUrl === '') {
					return;
				}
				$container->get(INavigationManager::class)->add(function () use ($container, $zimbraUrl) {
					$urlGenerator = $container->get(IURLGenerator::class);
					$l10n = $container->get(IL10N::class);
					return [
						'id' => self::APP_ID,
						'order' => 10,
						'href' => $zimbraUrl,
						'icon' => $urlGenerator->imagePath(self::APP_ID, 'app.svg'),
						'name' => $l10n->t('Zimbra'),
						'target' => '_blank',
					];
				});
			}
		}
	}
}

