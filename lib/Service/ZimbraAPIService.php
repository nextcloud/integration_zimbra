<?php
/**
 * Nextcloud - Zimbra
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2022
 */

namespace OCA\Zimbra\Service;

use Datetime;
use Exception;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use OCA\Zimbra\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\PreConditionNotMetException;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use Throwable;

class ZimbraAPIService {
	/**
	 * @var LoggerInterface
	 */
	private $logger;
	/**
	 * @var IL10N
	 */
	private $l10n;
	/**
	 * @var IClient
	 */
	private $client;
	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var string
	 */
	private $appVersion;

	/**
	 * Service to make requests to Zimbra API
	 */
	public function __construct(
		string $appName,
		LoggerInterface $logger,
		IL10N $l10n,
		IConfig $config,
		IAppManager $appManager,
		IClientService $clientService,
		private ICrypto $crypto,
	) {
		$this->logger = $logger;
		$this->l10n = $l10n;
		$this->client = $clientService->newClient();
		$this->config = $config;
		$this->appVersion = $appManager->getAppVersion(Application::APP_ID);
	}

	private function decryptIfNotEmpty(string $value): string {
		if ($value === '') {
			return $value;
		}
		return $this->crypto->decrypt($value);
	}

	public function isUserConnected(string $userId): bool {
		$adminUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url');
		$url = $this->config->getUserValue($userId, Application::APP_ID, 'url', $adminUrl) ?: $adminUrl;

		$userName = $this->config->getUserValue($userId, Application::APP_ID, 'user_name');
		$token = $this->decryptIfNotEmpty($this->config->getUserValue($userId, Application::APP_ID, 'token'));
		$login = $this->config->getUserValue($userId, Application::APP_ID, 'login');
		$password = $this->decryptIfNotEmpty($this->config->getUserValue($userId, Application::APP_ID, 'password'));
		return $url && $userName && $token && $login && $password;
	}

	public function getZimbraVersion(string $userId): array {
		$rawVersion = $this->config->getUserValue($userId, Application::APP_ID, 'zimbra_version');
		if ($rawVersion) {
			preg_match('/^(\d+)\.(\d+)\.(\d+)_/', $rawVersion, $matches);
			if (count($matches) > 2) {
				return [$matches[1], $matches[2], $matches[3]];
			}
		}
		return [0, 0, 0];
	}

	/**
	 * @param string $userId
	 * @return array|string[]
	 * @throws Exception
	 */
	public function getContacts(string $userId): array {
		$zimbraUserName = $this->config->getUserValue($userId, Application::APP_ID, 'user_name');
		return $this->restRequest($userId, 'home/' . $zimbraUserName . '/contacts');
	}

	/**
	 * @param string $userId
	 * @param int $resourceId
	 * @return array|string[]
	 * @throws Exception
	 */
	public function getContactAvatar(string $userId, int $resourceId): array {
		$params = [
			'id' => $resourceId,
			'part' => 1,
			'max_width' => 240,
			'max_height' => 240,
		];
		return $this->restRequest($userId, 'service/home/~/', $params, 'GET', false);
	}

	/**
	 * @param string $userId
	 * @param string $query
	 * @return array|string[]
	 * @throws Exception
	 */
	public function searchContacts(string $userId, string $query): array {
		$zimbraUserName = $this->config->getUserValue($userId, Application::APP_ID, 'user_name');
		$params = [
			'query' => $query,
		];
		$result = $this->restRequest($userId, 'home/' . $zimbraUserName . '/contacts', $params);
		if (isset($result['cn']) && is_array($result['cn'])) {
			return $result['cn'];
		}
		return [];
	}

	/**
	 * @param string $userId
	 * @param int|null $sinceTs
	 * @return array
	 * @throws Exception
	 */
	public function getUpcomingEventsSoap(string $userId, ?int $sinceTs = null): array {
		// get calendar list
		$calResp = $this->soapRequest($userId, 'GetFolderRequest', 'urn:zimbraMail', ['view' => 'appointment']);
		if (isset($calResp['error'])) {
			return $calResp;
		}
		$topFolders = $calResp['Body']['GetFolderResponse']['folder'] ?? [];
		$folders = [];
		foreach ($topFolders as $topFolder) {
			$folders[] = 'inid:"' . $topFolder['id'] . '"';
			foreach ($topFolder['folder'] ?? [] as $subFolder) {
				$folders[] = 'inid:"' . $subFolder['id'] . '"';
			}
		}
		$queryString = '(' . implode(' OR ', $folders) . ')';

		// get events
		if ($sinceTs === null) {
			$sinceMilliTs = (new DateTime())->getTimestamp() * 1000;
		} else {
			$sinceMilliTs = $sinceTs * 1000;
		}
		$params = [
			'query' => [
				'_content' => $queryString,
			],
			'sortBy' => 'dateAsc',
			'fetch' => 'all',
			'offset' => 0,
			'limit' => 100,
			'types' => 'appointment',
			'calExpandInstStart' => $sinceMilliTs,
			// start + 30 days
			'calExpandInstEnd' => $sinceMilliTs + (60 * 60 * 24 * 30 * 1000),
		];
		$eventResp = $this->soapRequest($userId, 'SearchRequest', 'urn:zimbraMail', $params);
		if (isset($eventResp['error'])) {
			return $eventResp;
		}
		$events = $eventResp['Body']['SearchResponse']['appt'] ?? [];
		usort($events, static function (array $a, array $b) {
			$aStart = $a['inst'][0]['s'];
			$bStart = $b['inst'][0]['s'];
			return ($aStart < $bStart) ? -1 : 1;
		});
		return $events;
	}

	/**
	 * @param string $userId
	 * @param int $offset
	 * @param int $limit
	 * @return array
	 * @throws Exception
	 */
	public function getUnreadEmails(string $userId, int $offset = 0, int $limit = 10): array {
		$zimbraUserName = $this->config->getUserValue($userId, Application::APP_ID, 'user_name');
		$params = [
			'query' => 'is:unread',
		];
		$result = $this->restRequest($userId, 'home/' . $zimbraUserName . '/inbox', $params);
		if (isset($result['error'])) {
			return $result;
		}
		$emails = $result['m'] ?? [];

		// sort emails by date, DESC, recents first
		usort($emails, function ($a, $b) {
			return ($a['d'] > $b['d']) ? -1 : 1;
		});

		return array_slice($emails, $offset, $limit);
	}

	/**
	 * @param string $userId
	 * @param string $query
	 * @param int $offset
	 * @param int $limit
	 * @return array
	 * @throws Exception
	 */
	public function searchEmails(string $userId, string $query, int $offset = 0, int $limit = 10): array {
		$zimbraUserName = $this->config->getUserValue($userId, Application::APP_ID, 'user_name');
		$params = [
			'query' => $query,
		];
		$result = $this->restRequest($userId, 'home/' . $zimbraUserName . '/inbox', $params);
		$emails = $result['m'] ?? [];

		// sort emails by date, DESC, recents first
		usort($emails, function ($a, $b) {
			return ($a['d'] > $b['d']) ? -1 : 1;
		});

		return array_slice($emails, $offset, $limit);
	}

	/**
	 * @param string $userId
	 * @param string $endPoint
	 * @param array $params
	 * @param string $method
	 * @param bool $jsonResponse
	 * @return array|mixed|resource|string|string[]
	 * @throws Exception
	 */
	public function restRequest(string $userId, string $endPoint, array $params = [], string $method = 'GET',
		bool $jsonResponse = true): array {
		$tokenIsOk = $this->checkTokenExpiration($userId);
		if (!$tokenIsOk) {
			return ['error' => $this->l10n->t('Your Zimbra session has expired, please re-authenticate in your user settings.')];
		}
		$adminUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url');
		$url = $this->config->getUserValue($userId, Application::APP_ID, 'url', $adminUrl) ?: $adminUrl;
		$accessToken = $this->decryptIfNotEmpty($this->config->getUserValue($userId, Application::APP_ID, 'token'));
		try {
			$url = $url . '/' . $endPoint;
			$options = [
				'headers' => [
					'User-Agent' => Application::INTEGRATION_USER_AGENT,
				],
			];

			// authentication
			$extraGetParams = [
				'auth' => 'qp',
				'zauthtoken' => $accessToken,
			];
			if ($jsonResponse) {
				$extraGetParams['fmt'] = 'json';
			}

			if ($method === 'GET') {
				if (count($params) > 0) {
					// manage array parameters
					$paramsContent = '';
					foreach ($params as $key => $value) {
						if (is_array($value)) {
							foreach ($value as $oneArrayValue) {
								$paramsContent .= $key . '[]=' . urlencode($oneArrayValue) . '&';
							}
							unset($params[$key]);
						}
					}
					$paramsContent .= http_build_query(array_merge($params, $extraGetParams));
					$url .= '?' . $paramsContent;
				}
			} else {
				if (count($params) > 0) {
					$options['json'] = $params;
				}
				// still authenticating with get params
				$paramsContent = http_build_query($extraGetParams);
				$url .= '?' . $paramsContent;
			}

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} else if ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} else if ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} else if ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			} else {
				return ['error' => $this->l10n->t('Bad HTTP method')];
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			} else {
				if ($jsonResponse) {
					return json_decode($body, true);
				} else {
					return [
						'body' => $body,
						'headers' => $response->getHeaders(),
					];
				}
			}
		} catch (ClientException $e) {
			$response = $e->getResponse();
			$this->logger->warning('Zimbra API client error : ' . $e->getMessage(), [
				'app' => Application::APP_ID,
				'responseBody' => $response->getBody(),
				'exception' => $e->getMessage(),
			]);
			return ['error' => $this->l10n->t('Zimbra request error')];
		} catch (ServerException $e) {
			$response = $e->getResponse();
			$this->logger->debug('Zimbra API server error : ' . $e->getMessage(), [
				'app' => Application::APP_ID,
				'responseBody' => $response->getBody(),
				'exception' => $e->getMessage(),
			]);
			return ['error' => $this->l10n->t('Zimbra request failure')];
		}
	}

	/**
	 * @param string $userId
	 * @param string $function
	 * @param string $ns
	 * @param array $params
	 * @param bool $jsonResponse
	 * @return array
	 * @throws PreConditionNotMetException
	 */
	public function soapRequest(string $userId, string $function, string $ns, array $params = [],
		bool $jsonResponse = true): array {
		$tokenIsOk = $this->checkTokenExpiration($userId);
		if (!$tokenIsOk) {
			return ['error' => $this->l10n->t('Your Zimbra session has expired, please re-authenticate in your user settings.')];
		}
		$adminUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url');
		$url = $this->config->getUserValue($userId, Application::APP_ID, 'url', $adminUrl) ?: $adminUrl;
		$accessToken = $this->decryptIfNotEmpty($this->config->getUserValue($userId, Application::APP_ID, 'token'));
		$zimbraUserName = $this->config->getUserValue($userId, Application::APP_ID, 'user_name');
		try {
			$url = $url . '/service/soap';
			$options = [
				'headers' => [
					'User-Agent' => Application::INTEGRATION_USER_AGENT,
					'Content-Type' => 'application/json',
				],
			];

			$bodyArray = [
				'Header' => $this->getRequestHeader($zimbraUserName, $accessToken),
				'Body' => $this->getRequestBody($function, $ns, $params),
			];
			$options['body'] = json_encode($bodyArray);
			$response = $this->client->post($url, $options);
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			} else {
				if ($jsonResponse) {
					return json_decode($body, true);
				} else {
					return [
						'body' => $body,
						'headers' => $response->getHeaders(),
					];
				}
			}
		} catch (ClientException $e) {
			$response = $e->getResponse();
			$this->logger->warning('Zimbra API client error : ' . $e->getMessage(), [
				'app' => Application::APP_ID,
				'responseBody' => $response->getBody(),
				'exception' => $e->getMessage(),
			]);
			return ['error' => $e->getMessage()];
		} catch (ServerException $e) {
			$response = $e->getResponse();
			$this->logger->debug('Zimbra API server error : ' . $e->getMessage(), [
				'app' => Application::APP_ID,
				'responseBody' => $response->getBody(),
				'exception' => $e->getMessage(),
			]);
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * @param string $userId
	 * @param string $login
	 * @param string $password
	 * @param string|null $twoFactorCode
	 * @return array
	 */
	public function login(string $userId, string $login, string $password,
		?string $twoFactorCode = null): array {
		$adminUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url');
		$baseUrl = $this->config->getUserValue($userId, Application::APP_ID, 'url', $adminUrl) ?: $adminUrl;
		try {
			$url = $baseUrl . '/service/soap';
			$options = [
				'headers' => [
					'User-Agent' => Application::INTEGRATION_USER_AGENT,
					'Content-Type' => 'application/json',
				],
			];
			$bodyRequestParams = [
				'account' => [
					'_content' => $login,
					'by' => 'name',
				],
				'password' => $password,
			];
			if ($twoFactorCode !== null) {
				$bodyRequestParams['twoFactorCode'] = $twoFactorCode;
			}
			$bodyArray = [
				'Header' => $this->getLoginRequestHeader(),
				'Body' => $this->getRequestBody('AuthRequest', 'urn:zimbraAccount', $bodyRequestParams),
			];
			$options['body'] = json_encode($bodyArray);
			$response = $this->client->post($url, $options);
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Invalid credentials')];
			} else {
				try {
					$r = json_decode($body, true);
					if (isset(
						$r['Body'],
						$r['Body']['AuthResponse'],
						$r['Body']['AuthResponse']['authToken'],
						$r['Body']['AuthResponse']['authToken'][0],
						$r['Body']['AuthResponse']['authToken'][0]['_content']
					)) {
						$token = $r['Body']['AuthResponse']['authToken'][0]['_content'];
						$twoFactorAuthRequired = $r['Body']['AuthResponse']['twoFactorAuthRequired']['_content'] ?? '';
						return [
							'token' => $token,
							'token_lifetime' => (int)($r['Body']['AuthResponse']['lifetime'] ?? 0),
							'two_factor_required' => $twoFactorAuthRequired === 'true',
							//'requestBody' => $bodyArray,
							//'responseBody' => $r,
						];
					}
				} catch (Exception|Throwable $e) {
				}
				$this->logger->warning('Zimbra login error : Invalid response', ['app' => Application::APP_ID]);
				return ['error' => $this->l10n->t('Invalid response')];
			}
		} catch (ServerException $e) {
			$response = $e->getResponse();
			$body = $response->getBody();
			$this->logger->warning('Zimbra login server error : ' . $body, ['app' => Application::APP_ID]);
			return ['error' => $this->l10n->t('Login server error')];
		} catch (Exception|Throwable $e) {
			$this->logger->warning('Zimbra login error : ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return ['error' => $this->l10n->t('Login error')];
		}
	}

	/**
	 * Check if the auth token has expired and try to refresh it if so
	 * @param string $userId
	 * @return bool true if the token is still valid or we managed to refresh it, false if there was an issue
	 * or if the second factor is outdated (more than a month old)
	 * @throws PreConditionNotMetException
	 */
	public function checkTokenExpiration(string $userId): bool {
		$tokenExpiresAt = $this->config->getUserValue($userId, Application::APP_ID, 'token_expires_at');
		if ($tokenExpiresAt !== '') {
			$nowTs = (new DateTime())->getTimestamp();
			$tokenExpiresAt = (int)$tokenExpiresAt;
			if ($nowTs > $tokenExpiresAt - 60) {
				// try login with credentials
				$login = $this->config->getUserValue($userId, Application::APP_ID, 'login');
				$password = $this->decryptIfNotEmpty($this->config->getUserValue($userId, Application::APP_ID, 'password'));
				$loginResult = $this->login($userId, $login, $password);
				if (isset($loginResult['error'])) {
					$this->logger->debug('Zimbra token refresh error : ' . $loginResult['error'], ['app' => Application::APP_ID]);
					return false;
				}
				// login success
				if (isset($loginResult['two_factor_required']) && $loginResult['two_factor_required']) {
					// 2fa is required: is it older than a month?
					$twoFactorExpiresAt = $this->config->getUserValue($userId, Application::APP_ID, '2fa_expires_at');
					if ($twoFactorExpiresAt === '') {
						return false;
					}
					if ($nowTs <= $twoFactorExpiresAt) {
						$preAuthKey = $this->decryptIfNotEmpty($this->config->getAppValue(Application::APP_ID, 'pre_auth_key'));
						if ($preAuthKey) {
							$preAuthResult = $this->preAuth($userId, $login);
							if (isset($preAuthResult['token'])) {
								$this->config->setUserValue($userId, Application::APP_ID, 'token', $preAuthResult['token']);
								$tokenExpireAt = $nowTs + (int)($preAuthResult['token_lifetime'] / 1000);
								$this->config->setUserValue($userId, Application::APP_ID, 'token_expires_at', (string)$tokenExpireAt);
								return true;
							} else {
								// failed
							}
						}
					} else {
						// 2fa has expired:
						$this->logger->debug('Zimbra token refresh: 2nd factor has expired', ['app' => Application::APP_ID]);
					}
				} elseif (isset($loginResult['token'], $loginResult['token_lifetime'])) {
					// no 2fa so we can use this token
					$this->config->setUserValue($userId, Application::APP_ID, 'token', $loginResult['token']);
					$tokenExpireAt = $nowTs + (int)($loginResult['token_lifetime'] / 1000);
					$this->config->setUserValue($userId, Application::APP_ID, 'token_expires_at', (string)$tokenExpireAt);
					$this->logger->debug('Zimbra token refresh: success', ['app' => Application::APP_ID]);
					return true;
				}
				$this->logger->debug('Zimbra token refresh error: no two_factor_required and no token, token_lifetime', ['app' => Application::APP_ID]);
			} else {
				// token has not expired
				return true;
			}
		}
		return false;
	}

	public function preAuth(string $userId, string $login): array {
		$preAuthKey = $this->decryptIfNotEmpty($this->config->getAppValue(Application::APP_ID, 'pre_auth_key'));
		$adminUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url');
		$baseUrl = $this->config->getUserValue($userId, Application::APP_ID, 'url', $adminUrl) ?: $adminUrl;
		try {
			$url = $baseUrl . '/service/soap/preauth';
			$options = [
				'headers' => [
					'User-Agent' => Application::INTEGRATION_USER_AGENT,
					'Content-Type' => 'application/json',
				],
			];
			$time = round(microtime(true) * 1000);
			$bodyArray = [
				'Body' => $this->getRequestBody('AuthRequest', 'urn:zimbraAccount', [
					'account' => [
						'_content' => $login,
						'by' => 'name',
					],
					'preauth' => [
						'timestamp' => $time,
						'expires' => '0',
						'_content' => $this->hmac_sha1($preAuthKey, $login . '|name|0|' . $time),
					],
				]),
			];
			$options['body'] = json_encode($bodyArray);
			$response = $this->client->post($url, $options);
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Invalid credentials')];
			} else {
				try {
					$r = json_decode($body, true);
					if (isset(
						$r['Body'],
						$r['Body']['AuthResponse'],
						$r['Body']['AuthResponse']['authToken'],
						$r['Body']['AuthResponse']['authToken'][0],
						$r['Body']['AuthResponse']['authToken'][0]['_content']
					)) {
						$token = $r['Body']['AuthResponse']['authToken'][0]['_content'];
						return [
							'token' => $token,
							'token_lifetime' => (int)($r['Body']['AuthResponse']['lifetime'] ?? 0),
						];
					}
				} catch (Exception|Throwable $e) {
				}
				$this->logger->warning('Zimbra preauth error : Invalid response', ['app' => Application::APP_ID]);
				return ['error' => $this->l10n->t('Invalid response')];
			}
		} catch (ServerException $e) {
			$response = $e->getResponse();
			$body = $response->getBody();
			$this->logger->warning('Zimbra preauth server error : ' . $body, ['app' => Application::APP_ID]);
			return ['error' => $e->getMessage()];
		} catch (Exception|Throwable $e) {
			$this->logger->warning('Zimbra preauth error : ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return ['error' => $e->getMessage()];
		}
	}

	private function getRequestHeader(string $login, string $token): array {
		return [
			'context' => [
				'_jsns' => 'urn:zimbra',
				'userAgent' => [
					'name' => Application::INTEGRATION_USER_AGENT,
					'version' => $this->appVersion,
				],
				'authTokenControl' => [
					'voidOnExpired' => true,
				],
				'account' => [
					'_content' => $login,
					'by' => 'name'
				],
				'authToken' => $token,
			]
		];
	}

	/**
	 * @return array[]
	 */
	private function getLoginRequestHeader(): array {
		return [
			'context' => [
				'_jsns' => 'urn:zimbra',
				'userAgent' => [
					'name' => Application::INTEGRATION_USER_AGENT,
					'version' => $this->appVersion,
				],
			]
		];
	}

	/**
	 * @param string $function
	 * @param string $ns
	 * @param array $params
	 * @return array
	 */
	private function getRequestBody(string $function, string $ns, array $params): array {
		$nsArray = ['_jsns' => $ns];
		return [
			$function => array_merge($nsArray, $params)
		];
	}

	/**
	 * From https://github.com/Zimbra-Community/zimbra-tools/blob/master/pre-auth-soap-saml.php
	 * @param string $key
	 * @param string $data
	 * @return string
	 */
	private function hmac_sha1(string $key, string $data): string {
		// Adjust key to exactly 64 bytes
		if (strlen($key) > 64) {
			$key = str_pad(sha1($key, true), 64, chr(0));
		}
		if (strlen($key) < 64) {
			$key = str_pad($key, 64, chr(0));
		}

		// Outter and Inner pad
		$opad = str_repeat(chr(0x5C), 64);
		$ipad = str_repeat(chr(0x36), 64);

		// Xor key with opad & ipad
		for ($i = 0; $i < strlen($key); $i++) {
			$opad[$i] = $opad[$i] ^ $key[$i];
			$ipad[$i] = $ipad[$i] ^ $key[$i];
		}

		return sha1($opad . sha1($ipad . $data, true));
	}
}
