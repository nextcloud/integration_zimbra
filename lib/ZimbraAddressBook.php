<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2022 Julien Veyssier <eneiluj@posteo.net>
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 *
 * Zimbra Integration
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\Zimbra;

use OCA\Zimbra\AppInfo\Application;
use OCA\Zimbra\Exception\ServiceException;
use OCA\Zimbra\Service\ZimbraAPIService;
use OCP\IAddressBook;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;

class ZimbraAddressBook implements IAddressBook {

	/** @var IConfig */
	private $config;

	/** @var ICache */
	private $cache;

	/** @var ZimbraAPIService */
	private $zimbraAPIService;

	/** @var string|null */
	private $userId;

	public function __construct(IConfig $config,
								ICacheFactory $cacheFactory,
								ZimbraAPIService $zimbraAPIService,
								?string $userId) {
		$this->zimbraAPIService = $zimbraAPIService;
		$this->config = $config;
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '_contacts');
		$this->userId = $userId;
	}

	public function getKey() {
		return 'zimbraAddressBook';
	}

	public function getUri(): string {
		$adminOauthUrl = $this->config->getAppValue(Application::APP_ID, 'oauth_instance_url');
		return $this->config->getUserValue($this->userId, Application::APP_ID, 'url', $adminOauthUrl) ?: $adminOauthUrl;
	}

	public function getDisplayName() {
		// translate this?
		return 'Zimbra Address Book';
	}

	/**
	 *
	 * return array an array of contacts which are arrays of key-value-pairs
	 *  example result:
	 *  [
	 *		['id' => 0, 'FN' => 'Thomas Müller', 'EMAIL' => 'a@b.c', 'GEO' => '37.386013;-122.082932'],
	 *		['id' => 5, 'FN' => 'Thomas Tanghus', 'EMAIL' => ['d@e.f', 'g@h.i']]
	 *	]
	 * @since 5.0.0
	 */
	public function search($pattern, $searchProperties, $options): array {
		// use all arguments combined with the user id as a cache key
		$cacheKey = md5(json_encode([
			$this->userId, $pattern, $searchProperties, $options
		], JSON_THROW_ON_ERROR));
		$hit = $this->cache->get($cacheKey);

		if ($hit !== null) {
			return $hit;
		}

		$offset = $options['offset'] ?? 0;
		$limit = $options['limit'] ?? 25;

		try {
			$contacts = $this->zimbraAPIService->searchContacts($this->userId, $pattern);
		} catch (ServiceException $e) {
			return [];
		}

		$formattedResult = array_map(
			static function ($c) use ($options) {
				$attrs = $c['_attrs'] ?? [];
				$formattedContact = [
					// 'id' => $c['id'],
					'FN' => $attrs['fullName'] ?? '',
				];
				if (isset($attrs['firstName']) || isset($attrs['lastName'])) {
					$formattedContact['N'] = ($attrs['lastName'] ?? '') . ';' . ($attrs['firstName'] ?? '') . ';;;';
				}

				// EMAILS
				$formattedContact['EMAIL'] = [];
				if ($options['types'] ?? false) {
					foreach (['email' => 'OTHER', 'homeEmail' => 'HOME', 'workEmail' => 'WORK'] as $emailKey => $type) {
						if (isset($attrs[$emailKey])) {
							$formattedContact['EMAIL'][] = ['type' => $type, 'value' => $attrs[$emailKey]];
							$i = 2;
							while (isset($attrs[$emailKey . $i])) {
								$formattedContact['EMAIL'][] = ['type' => $type, 'value' => $attrs[$emailKey . $i]];
								$i++;
							}
						}
					}
				} else {
					foreach (['email', 'homeEmail', 'workEmail'] as $emailKey) {
						if (isset($attrs[$emailKey])) {
							$formattedContact['EMAIL'][] = $attrs[$emailKey];
							$i = 2;
							while (isset($attrs[$emailKey . $i])) {
								$formattedContact['EMAIL'][] = $attrs[$emailKey . $i];
								$i++;
							}
						}
					}
				}
				return $formattedContact;
			},
			array_slice($contacts, $offset, $limit)
		);

		$this->cache->set($cacheKey, $formattedResult,
			$this->config->getAppValue(Application::APP_ID, Application::APP_CONFIG_CACHE_TTL_CONTACTS, Application::APP_CONFIG_CACHE_TTL_CONTACTS_DEFAULT)
		);

		return $formattedResult;
	}

	/**
	 * @throws ServiceException
	 */
	public function createOrUpdate($properties) {
		throw new ServiceException('Operation not available', 403);
	}

	/**
	 * @throws ServiceException
	 */
	public function getPermissions() {
		throw new ServiceException('Operation not available', 403);
	}

	/**
	 * @throws ServiceException
	 */
	public function delete($id) {
		throw new ServiceException('Operation not available', 403);
	}

	public function isShared(): bool {
		return false;
	}

	public function isSystemAddressBook(): bool {
		return true;
	}
}
