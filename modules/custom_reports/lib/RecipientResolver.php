<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

/**
 * Resolves Zabbix users and user groups into "Send to" addresses of a given media type.
 */
class RecipientResolver {

	// MEDIA_STATUS_ACTIVE and GROUP_STATUS_DISABLED.
	private const MEDIA_STATUS_ACTIVE = 0;
	private const GROUP_STATUS_DISABLED = 1;

	private ApiClientInterface $api;

	public function __construct(ApiClientInterface $api) {
		$this->api = $api;
	}

	/**
	 * @return string[]
	 *
	 * @throws ApiException
	 */
	public function resolve(array $userids, array $usrgrpids, string $mediatypeid): array {
		$userids = ReportBuilder::ids($userids);
		$usrgrpids = ReportBuilder::ids($usrgrpids);

		if ($usrgrpids) {
			$groups = $this->api->call('usergroup.get', [
				'output' => ['usrgrpid', 'users_status'],
				'usrgrpids' => $usrgrpids,
				'selectUsers' => ['userid']
			]);

			foreach ($groups as $group) {
				if ((int) $group['users_status'] == self::GROUP_STATUS_DISABLED) {
					continue;
				}

				foreach ($group['users'] as $user) {
					$userids[] = (string) $user['userid'];
				}
			}
		}

		$userids = array_values(array_unique($userids));

		if (!$userids) {
			return [];
		}

		$users = $this->api->call('user.get', [
			'output' => ['userid'],
			'userids' => $userids,
			'selectMedias' => ['mediatypeid', 'sendto', 'active']
		]);

		$sendto = [];

		foreach ($users as $user) {
			foreach ($user['medias'] as $media) {
				if ((string) $media['mediatypeid'] === $mediatypeid && (int) $media['active'] == self::MEDIA_STATUS_ACTIVE) {
					$sendto = array_merge($sendto, self::normalizeSendto($media['sendto']));
				}
			}
		}

		return array_values(array_unique($sendto));
	}

	/**
	 * "sendto" is an array for e-mail media and a string for other media types.
	 *
	 * @param mixed $sendto
	 *
	 * @return string[]
	 */
	public static function normalizeSendto($sendto): array {
		$values = is_array($sendto) ? $sendto : preg_split('/[\r\n]+/', (string) $sendto);
		$result = [];

		foreach ($values as $value) {
			$value = trim((string) $value);

			if ($value !== '') {
				$result[] = $value;
			}
		}

		return $result;
	}
}
