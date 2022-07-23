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
use OCP\IConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use OCP\Http\Client\IClientService;
use Throwable;

class ZimbraAPIService {
	/**
	 * @var string
	 */
	private $appName;
	/**
	 * @var LoggerInterface
	 */
	private $logger;
	/**
	 * @var IL10N
	 */
	private $l10n;
	/**
	 * @var \OCP\Http\Client\IClient
	 */
	private $client;
	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var IAppManager
	 */
	private $appManager;
	/**
	 * @var string
	 */
	private $appVersion;

	/**
	 * Service to make requests to Zimbra API
	 */
	public function __construct (string $appName,
								LoggerInterface $logger,
								IL10N $l10n,
								IConfig $config,
								IAppManager $appManager,
								IClientService $clientService) {
		$this->appName = $appName;
		$this->logger = $logger;
		$this->l10n = $l10n;
		$this->client = $clientService->newClient();
		$this->config = $config;
		$this->appManager = $appManager;
		$this->appVersion = $appManager->getAppVersion(Application::APP_ID);
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
	 * @return array|string[]
	 * @throws Exception
	 */
	public function getUpcomingEvents(string $userId, ?int $sinceTs = null): array {
		$zimbraUserName = $this->config->getUserValue($userId, Application::APP_ID, 'user_name');
		if ($sinceTs === null) {
			$sinceMilliTs = (new DateTime())->getTimestamp() * 1000;
		} else {
			$sinceMilliTs = $sinceTs * 1000;
		}
		$params = [
			'start' => $sinceMilliTs,
			// start + 30 days
			'end' => $sinceMilliTs + (60 * 60 * 24 * 30 * 1000),
		];
		$result = $this->restRequest($userId, 'home/' . $zimbraUserName . '/calendar', $params);
		if (isset($result['appt'])) {
			return $result['appt'];
		}
		return $result;
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
		$emails = $result['m'] ?? [];

		// sort emails by date, DESC, recents first
		usort($emails, function($a, $b) {
			return ($a['d'] > $b['d']) ? -1 : 1;
		});

		return array_slice($emails, $offset, $limit);
	}

	/**
	 * @param string $userId
	 * @param string $zimbraUserId
	 * @param string $zimbraUrl
	 * @return array
	 * @throws Exception
	 */
	public function getUserAvatar(string $userId, string $zimbraUserId, string $zimbraUrl): array {
		$image = $this->request($userId, $zimbraUrl, 'users/' . $zimbraUserId . '/image', [], 'GET', false);
		if (!is_array($image)) {
			return ['avatarContent' => $image];
		}
		$image = $this->request($userId, $zimbraUrl, 'users/' . $zimbraUserId . '/image/default', [], 'GET', false);
		if (!is_array($image)) {
			return ['avatarContent' => $image];
		}

		$userInfo = $this->request($userId, $zimbraUrl, 'users/' . $zimbraUserId);
		return ['userInfo' => $userInfo];
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
								bool $jsonResponse = true) {
		$adminOauthUrl = $this->config->getAppValue(Application::APP_ID, 'oauth_instance_url');
		$url = $this->config->getUserValue($userId, Application::APP_ID, 'url', $adminOauthUrl) ?: $adminOauthUrl;
		$this->checkTokenExpiration($userId, $url);
		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token');
		try {
			$url = $url . '/' . $endPoint;
			$options = [
				'headers' => [
					'User-Agent'  => Application::INTEGRATION_USER_AGENT,
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
				}
				$paramsContent .= http_build_query(array_merge($params, $extraGetParams));
				$url .= '?' . $paramsContent;
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
					return $body;
				}
			}
		} catch (ServerException | ClientException $e) {
			$this->logger->debug('Zimbra API error : '.$e->getMessage(), ['app' => $this->appName]);
			return ['error' => $e->getMessage()];
		}
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
	public function soapRequest(string $userId, string $function, string $ns, array $params = [],
							bool $jsonResponse = true) {
		$adminOauthUrl = $this->config->getAppValue(Application::APP_ID, 'oauth_instance_url');
		$url = $this->config->getUserValue($userId, Application::APP_ID, 'url', $adminOauthUrl) ?: $adminOauthUrl;
		$this->checkTokenExpiration($userId, $url);
		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token');
		$zimbraUserName = $this->config->getUserValue($userId, Application::APP_ID, 'user_name');
		try {
			$url = $url . '/service/soap';
			$options = [
				'headers' => [
					'User-Agent'  => Application::INTEGRATION_USER_AGENT,
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
					return $body;
				}
			}
		} catch (ServerException | ClientException $e) {
			$this->logger->debug('Zimbra API error : '.$e->getMessage(), ['app' => $this->appName]);
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * @param string $userId
	 * @param string $url
	 * @return void
	 * @throws \OCP\PreConditionNotMetException
	 */
	private function checkTokenExpiration(string $userId, string $url): void {
		$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token');
		$expireAt = $this->config->getUserValue($userId, Application::APP_ID, 'token_expires_at');
		if ($refreshToken !== '' && $expireAt !== '') {
			$nowTs = (new Datetime())->getTimestamp();
			$expireAt = (int) $expireAt;
			// if token expires in less than a minute or is already expired
			if ($nowTs > $expireAt - 60) {
				$this->refreshToken($userId, $url);
			}
		}
	}

	/**
	 * @param string $userId
	 * @param string $url
	 * @return bool
	 * @throws \OCP\PreConditionNotMetException
	 */
	private function refreshToken(string $userId, string $url): bool {
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret');
		$redirect_uri = $this->config->getUserValue($userId, Application::APP_ID, 'redirect_uri');
		$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token');
		if (!$refreshToken) {
			$this->logger->error('No Zimbra refresh token found', ['app' => $this->appName]);
			return false;
		}
		$result = $this->requestOAuthAccessToken($url, [
			'client_id' => $clientID,
			'client_secret' => $clientSecret,
			'grant_type' => 'refresh_token',
			'redirect_uri' => $redirect_uri,
			'refresh_token' => $refreshToken,
		], 'POST');
		if (isset($result['access_token'])) {
			$this->logger->info('Zimbra access token successfully refreshed', ['app' => $this->appName]);
			$accessToken = $result['access_token'];
			$refreshToken = $result['refresh_token'];
			$this->config->setUserValue($userId, Application::APP_ID, 'token', $accessToken);
			$this->config->setUserValue($userId, Application::APP_ID, 'refresh_token', $refreshToken);
			if (isset($result['expires_in'])) {
				$nowTs = (new Datetime())->getTimestamp();
				$expiresAt = $nowTs + (int) $result['expires_in'];
				$this->config->setUserValue($userId, Application::APP_ID, 'token_expires_at', $expiresAt);
			}
			return true;
		} else {
			// impossible to refresh the token
			$this->logger->error(
				'Token is not valid anymore. Impossible to refresh it. '
					. $result['error'] . ' '
					. $result['error_description'] ?? '[no error description]',
				['app' => $this->appName]
			);
			return false;
		}
	}

	/**
	 * @param string $url
	 * @param array $params
	 * @param string $method
	 * @return array
	 */
	public function requestOAuthAccessToken(string $url, array $params = [], string $method = 'GET'): array {
		try {
			$url = $url . '/oauth/access_token';
			$options = [
				'headers' => [
					'User-Agent'  => Application::INTEGRATION_USER_AGENT,
				]
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					$paramsContent = http_build_query($params);
					$url .= '?' . $paramsContent;
				} else {
					$options['body'] = $params;
				}
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
				return ['error' => $this->l10n->t('OAuth access token refused')];
			} else {
				return json_decode($body, true);
			}
		} catch (Exception $e) {
			$this->logger->warning('Zimbra OAuth error : '.$e->getMessage(), array('app' => $this->appName));
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * @param string $baseUrl
	 * @param string $login
	 * @param string $password
	 * @return array
	 */
	public function login(string $baseUrl, string $login, string $password): array {
		try {
			$url = $baseUrl . '/service/soap';
			$options = [
				'headers' => [
					'User-Agent'  => Application::INTEGRATION_USER_AGENT,
					'Content-Type' => 'application/json',
				],
			];
			$bodyArray = [
				'Header' => $this->getLoginRequestHeader(),
				'Body' => $this->getRequestBody('AuthRequest', 'urn:zimbraAccount', [
					'account' => [
						'_content' => $login,
						'by' => 'name',
					],
					'password' => $password,
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
						];
					}
				} catch (Exception | Throwable $e) {
				}
				$this->logger->warning('Zimbra login error : Invalid response', ['app' => Application::APP_ID]);
				return ['error' => $this->l10n->t('Invalid response')];
			}
		} catch (Exception | Throwable $e) {
			$this->logger->warning('Zimbra login error : '.$e->getMessage(), ['app' => Application::APP_ID]);
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

	private function getLoginRequestHeader(): array {
		return [
			'context' => [
				'_jsns' =>'urn:zimbra',
				'userAgent' => [
					'name' => Application::INTEGRATION_USER_AGENT,
					'version' => $this->appVersion,
				],
			]
		];
	}

	private function getRequestBody(string $function, string $ns, array $params): array {
		$nsArray = ['_jsns' => $ns];
		return [
			$function => array_merge($nsArray, $params)
		];
	}
}
