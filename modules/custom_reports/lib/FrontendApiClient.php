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

		// The frontend API wrapper reports errors through CMessageHelper. Remember its state, so that only the
		// messages of this call go into the exception and a handled failure does not show an error box on the page.
		$type = CMessageHelper::getType();
		$title = CMessageHelper::getTitle();
		$messages = CMessageHelper::getMessages();

		$result = $service->get($params);

		if ($result === false) {
			$errors = array_column(array_slice(CMessageHelper::getMessages(), count($messages)), 'message');
			self::restoreMessages($type, $title, $messages);

			throw new ApiException($errors ? implode('; ', $errors) : sprintf('API call "%s" failed.', $method));
		}

		return $result;
	}

	private static function restoreMessages(?string $type, ?string $title, array $messages): void {
		CMessageHelper::clear();

		foreach ($messages as $message) {
			CMessageHelper::addMessage($message + ['is_technical_error' => false]);
		}

		if ($title !== null) {
			switch ($type) {
				case CMessageHelper::MESSAGE_TYPE_SUCCESS:
					CMessageHelper::setSuccessTitle($title);
					break;

				case CMessageHelper::MESSAGE_TYPE_WARNING:
					CMessageHelper::setWarningTitle($title);
					break;

				default:
					CMessageHelper::setErrorTitle($title);
			}
		}
	}
}
