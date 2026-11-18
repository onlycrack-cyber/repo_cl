<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

use API;
use CMessageHelper;

/**
 * Calls the Zabbix API in-process on behalf of the logged-in frontend user.
 *
 * All permission checks (host group access rights, user role) are performed by the API itself,
 * so a user never sees problems of hosts they have no read access to.
 */
class FrontendApiClient implements ApiClientInterface {

	public function call(string $method, array $params) {
		[$object, $operation] = explode('.', $method, 2) + [1 => ''];

		switch ($object) {
			case 'problem':
				$service = API::Problem();
				break;

			case 'trigger':
				$service = API::Trigger();
				break;

			case 'event':
				$service = API::Event();
				break;

			case 'alert':
				$service = API::Alert();
				break;

			case 'host':
				$service = API::Host();
				break;

			case 'hostgroup':
				$service = API::HostGroup();
				break;

			case 'mediatype':
				$service = API::MediaType();
				break;

			case 'user':
				$service = API::User();
				break;

			case 'usergroup':
				$service = API::UserGroup();
				break;

			case 'settings':
				$service = API::Settings();
				break;

			default:
				throw new ApiException(sprintf('API object "%s" is not allowed in this module.', $object));
		}

		if ($operation !== 'get') {
			throw new ApiException(sprintf('API method "%s" is not allowed in this module.', $method));
		}

		$result = $service->get($params);

		if ($result === false) {
			$errors = [];

			foreach (CMessageHelper::getMessages() as $message) {
				$errors[] = $message['message'];
			}

			throw new ApiException($errors ? implode('; ', $errors) : sprintf('API call "%s" failed.', $method));
		}

		return $result;
	}
}
