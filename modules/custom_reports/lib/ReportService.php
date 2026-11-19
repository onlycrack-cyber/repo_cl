<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

use RuntimeException;

/**
 * Builds and delivers reports: used by the CLI sender and by the "Send now" actions.
 */
class ReportService {

	private ApiClientInterface $api;
	private Logger $logger;
	private array $config;
	private Dispatcher $dispatcher;

	public function __construct(ApiClientInterface $api, Logger $logger, array $config,
			?callable $secret_resolver = null) {
		$this->api = $api;
		$this->logger = $logger;
		$this->config = $config;
		$this->dispatcher = new Dispatcher($api, $logger, $config, $secret_resolver);
	}

	public function buildReport(array $filter, string $split = ReportSplitter::SPLIT_NONE): array {
		return (new ReportBuilder($this->api, (int) $this->config['problem_limit'],
			(int) $this->config['analytics_limit']
		))->build($filter, null, $split === ReportSplitter::SPLIT_GROUP);
	}

	/**
	 * Report of a schedule: problems of Zabbix or the output of an external script.
	 *
	 * @throws RuntimeException  The script failed.
	 */
	public function buildScheduleReport(array $schedule): array {
		if (($schedule['source'] ?? ScheduleHelper::SOURCE_PROBLEMS) === ScheduleHelper::SOURCE_SCRIPT) {
			$period = $schedule['frequency'] === ScheduleHelper::FREQUENCY_WEEKLY ? 7 * 86400 : 86400;

			return ScriptReport::build($this->config, $schedule['script'], $schedule['script_params'],
				$schedule['script_format'], ScriptReport::macros(time(), $period, $schedule['name'])
			);
		}

		return $this->buildReport(self::getScheduleFilter($schedule), $schedule['split']);
	}

	/**
	 * Report filter of a schedule: "auto" analytics period follows the schedule frequency.
	 */
	private static function getScheduleFilter(array $schedule): array {
		$schedule['analytics'] = PeriodAnalytics::resolvePeriod($schedule['analytics'], $schedule['frequency'])
			?? PeriodAnalytics::PERIOD_NONE;
		// The "Changes" block compares the analytics period, or the schedule period if analytics is off.
		$schedule['changes_period'] = $schedule['analytics'] !== PeriodAnalytics::PERIOD_NONE
			? $schedule['analytics']
			: PeriodAnalytics::resolvePeriod(PeriodAnalytics::PERIOD_AUTO, $schedule['frequency']);

		return $schedule;
	}

	/**
	 * Builds the report of a schedule and sends it to all its recipients
	 * (one message per host group / host if the schedule is split).
	 *
	 * Media: one media type for everybody ("fixed"), or every user gets the report through his own active media
	 * ("user": e.g. a Telegram script with its own bot per host group); the media type of the schedule is then
	 * used only for the additional addresses.
	 *
	 * Permissions ("recipient_access" of the schedule): every user gets only the problems of the hosts he may see in
	 * Zabbix; the report is built once per set of rights. Additional addresses get the report as it is.
	 *
	 * @param array|null $only  Resend: only these deliveries (keys of self::deliveryKey()).
	 *
	 * @return array  ['sent' => int, 'failed' => int, 'skipped' => int, 'errors' => string[], 'problems' => int,
	 *                 'parts' => int, 'media_type' => string,
	 *                 'deliveries' => list of ['part', 'recipient', 'mediatypeid', 'status', 'error', 'time']]
	 *
	 * @throws RuntimeException
	 */
	public function runSchedule(array $schedule, ?array $only = null): array {
		$user_media = ($schedule['media_mode'] ?? ScheduleHelper::MEDIA_FIXED) === ScheduleHelper::MEDIA_USER;

		// A disabled or missing media type of a fixed schedule is an error of the whole run.
		$media_type = !$user_media || $schedule['mediatypeid'] !== '0'
			? $this->dispatcher->getMediaType($schedule['mediatypeid'])
			: null;

		$access = $this->accessFor($schedule);
		$resolver = new RecipientResolver($this->api);
		$common = $this->resolveTargets($resolver, $schedule, $schedule, $access);
		$active_rules = ScheduleHelper::getActiveRecipientRules($schedule);

		if ($active_rules === null) {
			if (!self::countByAccess($common)) {
				throw new RuntimeException($user_media
					? I18n::t('No recipients: selected users have no active media.')
					: I18n::s('No recipients: selected users have no active media of type "%1$s".', $media_type['name'])
				);
			}

			return $this->deliverByAccess($schedule, $access, $common, static function (string $key) use ($common) {
				return static function () use ($common, $key): ?array {
					return $common[$key] ?? null;
				};
			}, $schedule['name'], $only);
		}

		// Split with own recipients: groupid/hostid => access key => addresses of all rules that list the group/host.
		$by_part = [];

		foreach ($active_rules['rules'] as $rule) {
			$targets = $this->resolveTargets($resolver, $rule, $schedule, $access);

			foreach ($rule[$active_rules['key']] as $id) {
				$by_part[$id] = self::mergeByAccess($by_part[$id] ?? [], $targets);
			}
		}

		$fallback_only = $schedule['common_recipients'] === ScheduleHelper::COMMON_FALLBACK;
		$has_common = ScheduleHelper::hasRecipients($schedule);

		$all = $common;

		foreach ($by_part as $targets) {
			$all = self::mergeByAccess($all, $targets);
		}

		// "No recipients with an active media" of a part is reported once: by the first access key.
		if (!$all) {
			$all = [RecipientAccess::FULL => []];
		}

		$keys = array_map('strval', array_keys($all));

		$recipients_for = static function (string $key) use ($by_part, $common, $fallback_only, $has_common, $keys) {
			return static function (array $part) use ($key, $by_part, $common, $fallback_only, $has_common, $keys)
					: ?array {
				$has_rule = array_key_exists($part['id'], $by_part);

				// The group/host is not addressed by any rule and there are no common recipients.
				if (!$has_rule && !$has_common) {
					return null;
				}

				$pick = static function (string $k) use ($part, $by_part, $common, $fallback_only, $has_rule): array {
					$own = $by_part[$part['id']][$k] ?? [];

					return ($has_rule && $fallback_only) ? $own : self::mergeTargets($own, $common[$k] ?? []);
				};

				$targets = $pick($key);

				if (self::countTargets($targets)) {
					return $targets;
				}

				if ($key !== $keys[0]) {
					return null;
				}

				foreach ($keys as $k) {
					if (self::countTargets($pick($k))) {
						return null;
					}
				}

				return [];
			};
		};

		return $this->deliverByAccess($schedule, $access, $all, $recipients_for, $schedule['name'], $only);
	}

	/**
	 * Permissions of the recipients are applied to reports of Zabbix problems if the schedule asks for it.
	 */
	private function accessFor(array $schedule): ?RecipientAccess {
		if (($schedule['source'] ?? ScheduleHelper::SOURCE_PROBLEMS) === ScheduleHelper::SOURCE_SCRIPT
				|| empty($schedule['recipient_access'] ?? 1)) {
			return null;
		}

		return new RecipientAccess($this->api);
	}

	/**
	 * Builds the report for every set of recipient rights and delivers it.
	 *
	 * @param array    $all             [access key => [mediatypeid => addresses]]: all recipients of the run.
	 * @param callable $recipients_for  function (string $key): callable - the $recipients of deliver() for a key.
	 */
	private function deliverByAccess(array $schedule, ?RecipientAccess $access, array $all, callable $recipients_for,
			string $title, ?array $only = null): array {
		$result = null;
		$skipped = [];
		$full_report = null;

		foreach (array_keys($all) as $key) {
			$key = (string) $key;

			if ($access !== null && $key !== RecipientAccess::FULL) {
				$filter = $access->restrictFilter(self::getScheduleFilter($schedule), $key);

				// The recipients may see none of the hosts of the report: nothing is sent to them.
				if ($filter === null) {
					foreach ($all[$key] as $mediatypeid => $sendto) {
						foreach ($sendto as $recipient) {
							if ($only === null || array_key_exists(self::deliveryKey('', (string) $mediatypeid, $recipient),
									$only)) {
								$skipped[] = ['part' => '', 'recipient' => $recipient, 'mediatypeid' => (string) $mediatypeid,
									'status' => 'skipped',
									'error' => I18n::t('No access in Zabbix to the hosts of the report.'), 'time' => time()
								];
							}
						}
					}

					$this->logger->info(sprintf('[%s] %d recipient(s) have no access to the hosts of the report, skipped.',
						$title, count($skipped)
					));

					continue;
				}

				$report = $this->buildReport($filter, $schedule['split']);
			}
			else {
				$report = $full_report ?? ($full_report = $this->buildScheduleReport($schedule));
			}

			$part = $this->deliver($recipients_for($key), $report, $schedule['format'], $title, $schedule['split'],
				(bool) $schedule['skip_empty'], $only
			);
			$result = $result === null ? $part : self::mergeResults($result, $part);
		}

		$result = $result ?? ['sent' => 0, 'failed' => 0, 'errors' => [], 'problems' => 0, 'parts' => 0, 'media_type' => '',
			'deliveries' => [], 'source' => ScheduleHelper::SOURCE_PROBLEMS
		];
		$result['skipped'] = count($skipped);
		$result['deliveries'] = array_merge($result['deliveries'], $skipped);

		return $result;
	}

	private static function mergeResults(array $a, array $b): array {
		$media_types = array_filter(array_unique(array_merge(explode(', ', $a['media_type']),
			explode(', ', $b['media_type'])
		)), 'strlen');

		return [
			'sent' => $a['sent'] + $b['sent'],
			'failed' => $a['failed'] + $b['failed'],
			'errors' => array_values(array_unique(array_merge($a['errors'], $b['errors']))),
			'problems' => max($a['problems'], $b['problems']),
			'parts' => $a['parts'] + $b['parts'],
			'media_type' => implode(', ', $media_types),
			'deliveries' => array_merge($a['deliveries'], $b['deliveries']),
			'source' => $a['source']
		];
	}

	/**
	 * Key of a delivery for a resend: report part, media type and address.
	 */
	public static function deliveryKey(string $part, string $mediatypeid, string $recipient): string {
		return $part."\x1F".$mediatypeid."\x1F".$recipient;
	}

	/**
	 * Addresses of users, user groups and additional addresses of a schedule or of a recipient rule by the rights of
	 * the users: [access key => [mediatypeid => string[]]] (one key RecipientAccess::FULL without permissions).
	 */
	private function resolveTargets(RecipientResolver $resolver, array $recipients, array $schedule,
			?RecipientAccess $access = null): array {
		$mediatypeid = (string) $schedule['mediatypeid'];
		$user_media = ($schedule['media_mode'] ?? ScheduleHelper::MEDIA_FIXED) === ScheduleHelper::MEDIA_USER;

		// Media the module cannot use (SMS, other webhooks, disabled media types) are left out, not failed.
		$usable = function (array $media) use ($user_media, $mediatypeid): array {
			return $user_media
				? array_filter($media, function ($id): bool {
					return $this->isUsableMediaType((string) $id);
				}, ARRAY_FILTER_USE_KEY)
				: [$mediatypeid => $media[$mediatypeid] ?? []];
		};

		$result = [];

		if ($access === null) {
			$result[RecipientAccess::FULL] = $usable($resolver->resolveAll($recipients['userids'],
				$recipients['usrgrpids']
			));
		}
		else {
			$by_user = $resolver->resolveByUser($recipients['userids'], $recipients['usrgrpids']);
			$keys = $access->keys(array_keys($by_user));

			foreach ($by_user as $userid => $media) {
				$key = $keys[(string) $userid] ?? null;

				if ($key !== null) {
					$result[$key] = self::mergeTargets($result[$key] ?? [], $usable($media));
				}
			}
		}

		// Additional addresses always go through the media type of the schedule and get the whole report.
		if ($recipients['extra_sendto'] && $mediatypeid !== '0') {
			$result[RecipientAccess::FULL] = self::mergeTargets($result[RecipientAccess::FULL] ?? [],
				[$mediatypeid => $recipients['extra_sendto']]
			);
		}

		foreach ($result as $key => $targets) {
			$result[$key] = self::mergeTargets($targets, []);

			if (!$result[$key]) {
				unset($result[$key]);
			}
		}

		return $result;
	}

	private static function mergeByAccess(array $a, array $b): array {
		foreach ($b as $key => $targets) {
			$a[$key] = self::mergeTargets($a[$key] ?? [], $targets);
		}

		return $a;
	}

	private static function countByAccess(array $by_access): int {
		return array_sum(array_map([self::class, 'countTargets'], $by_access));
	}

	/** @var bool[] */
	private array $usable = [];

	private function isUsableMediaType(string $mediatypeid): bool {
		if (!array_key_exists($mediatypeid, $this->usable)) {
			try {
				$this->usable[$mediatypeid] = Dispatcher::getChannel($this->dispatcher->getMediaType($mediatypeid))
					!== Dispatcher::CHANNEL_UNSUPPORTED;
			}
			catch (RuntimeException $e) {
				$this->usable[$mediatypeid] = false;
			}
		}

		return $this->usable[$mediatypeid];
	}

	private static function mergeTargets(array $a, array $b): array {
		foreach ($b as $mediatypeid => $sendto) {
			$a[$mediatypeid] = array_merge($a[$mediatypeid] ?? [], $sendto);
		}

		foreach ($a as $mediatypeid => $sendto) {
			$a[$mediatypeid] = array_values(array_unique($sendto));

			if (!$a[$mediatypeid]) {
				unset($a[$mediatypeid]);
			}
		}

		ksort($a);

		return $a;
	}

	private static function countTargets(array $targets): int {
		return array_sum(array_map('count', $targets));
	}

	/**
	 * "Test to me": the report of a schedule with all its settings (filter, format, split, analytics), delivered
	 * only to the current user instead of the schedule recipients.
	 *
	 * @param array $user_media  Active media of the current user: [mediatypeid => string[]].
	 *
	 * @throws RuntimeException
	 */
	public function testSchedule(array $schedule, array $user_media): array {
		if (($schedule['media_mode'] ?? ScheduleHelper::MEDIA_FIXED) === ScheduleHelper::MEDIA_USER) {
			$targets = self::mergeTargets(array_filter($user_media, function ($id): bool {
				return $this->isUsableMediaType((string) $id);
			}, ARRAY_FILTER_USE_KEY), []);

			if (!$targets) {
				throw new RuntimeException(I18n::t('You have no active media: add it in User settings -> Profile -> Media.'));
			}
		}
		else {
			$media_type = $this->dispatcher->getMediaType($schedule['mediatypeid']);
			$targets = self::mergeTargets([], [$schedule['mediatypeid'] => $user_media[$schedule['mediatypeid']] ?? []]);

			if (!$targets) {
				throw new RuntimeException(I18n::s('You have no active media of type "%1$s": add it in User settings -> '.
					'Profile -> Media.', $media_type['name']
				));
			}
		}

		$report = $this->buildScheduleReport($schedule);

		return $this->deliver(static function () use ($targets): array {
			return $targets;
		}, $report, $schedule['format'], '['.I18n::t('Test').'] '.$schedule['name'], $schedule['split'],
			(bool) $schedule['skip_empty']
		);
	}

	/**
	 * Addresses of users the way the schedule sends: its media type, or their own media ("own media of every user"),
	 * by the rights of the users: [access key => [mediatypeid => string[]]].
	 */
	public function targetsForUsers(array $schedule, array $userids): array {
		return $this->resolveTargets(new RecipientResolver($this->api),
			['userids' => $userids, 'usrgrpids' => [], 'extra_sendto' => []], $schedule, $this->accessFor($schedule)
		);
	}

	/**
	 * Test delivery of a schedule to explicit addresses chosen in the test form: [mediatypeid => string[]] (the whole
	 * report) or the result of targetsForUsers() (by the rights of the users).
	 *
	 * @throws RuntimeException
	 */
	public function testScheduleTargets(array $schedule, array $targets, bool $by_access = false): array {
		$by_access = $by_access ? $targets : [RecipientAccess::FULL => $targets];

		foreach ($by_access as $key => $list) {
			$by_access[$key] = self::mergeTargets($list, []);
		}

		if (!self::countByAccess($by_access)) {
			throw new RuntimeException(I18n::t('No recipients: selected users have no active media.'));
		}

		return $this->deliverByAccess($schedule, $this->accessFor($schedule), $by_access,
			static function (string $key) use ($by_access) {
				return static function () use ($by_access, $key): ?array {
					return $by_access[$key] ?: null;
				};
			}, '['.I18n::t('Test').'] '.$schedule['name']
		);
	}

	/**
	 * Sends an already built report to explicit addresses.
	 */
	public function sendReport(array $report, string $mediatypeid, array $sendto, string $format, string $title,
			string $split = ReportSplitter::SPLIT_NONE, bool $skip_empty = false): array {
		$media_type = $this->dispatcher->getMediaType($mediatypeid);

		if (!$sendto) {
			throw new RuntimeException(I18n::s('No recipients for media type "%1$s".', $media_type['name']));
		}

		$targets = [$mediatypeid => array_values(array_unique($sendto))];

		return $this->deliver(static function () use ($targets): array {
			return $targets;
		}, $report, $format, $title, $split, $skip_empty);
	}

	/**
	 * @param callable $recipients  function (array $part): ?array - [mediatypeid => addresses] for a report part,
	 *                              null if nobody is meant to receive this part.
	 */
	private function deliver(callable $recipients, array $report, string $format, string $title, string $split,
			bool $skip_empty, ?array $only = null): array {
		$result = ['sent' => 0, 'failed' => 0, 'errors' => [], 'problems' => $report['total'], 'parts' => 0,
			'media_type' => '', 'deliveries' => [],
			'source' => Dispatcher::isScript($report) ? ScheduleHelper::SOURCE_SCRIPT : ScheduleHelper::SOURCE_PROBLEMS
		];

		// A script report is never split by host group / host.
		if (Dispatcher::isScript($report)) {
			$split = ReportSplitter::SPLIT_NONE;
		}

		$media_types = [];
		$used = [];

		// Media type by ID (cached); an exception - disabled, deleted or not supported.
		$get_media_type = function (string $mediatypeid) use (&$media_types): array {
			if (!array_key_exists($mediatypeid, $media_types)) {
				try {
					$media_type = $this->dispatcher->getMediaType($mediatypeid);
					$media_types[$mediatypeid] = Dispatcher::getChannel($media_type) === Dispatcher::CHANNEL_UNSUPPORTED
						? I18n::s('Media type "%1$s" is not supported: only Email, Script and Telegram webhook media types '.
							'can deliver reports.', $media_type['name'])
						: $media_type;
				}
				catch (RuntimeException $e) {
					$media_types[$mediatypeid] = $e->getMessage();
				}
			}

			if (is_string($media_types[$mediatypeid])) {
				throw new RuntimeException($media_types[$mediatypeid]);
			}

			return $media_types[$mediatypeid];
		};

		foreach (ReportSplitter::split($report, $split, $skip_empty) as $part) {
			$part_title = $part['name'] === '' ? $title : $title.' - '.$part['name'];
			$targets = $recipients($part);

			// Resend: only the deliveries that failed last time.
			if ($only !== null && $targets !== null) {
				foreach ($targets as $mediatypeid => $sendto) {
					$targets[$mediatypeid] = array_values(array_filter($sendto,
						static function (string $recipient) use ($only, $part, $mediatypeid): bool {
							return array_key_exists(self::deliveryKey($part['name'], (string) $mediatypeid, $recipient),
								$only
							);
						}
					));
				}

				$targets = array_filter($targets) ?: null;
			}

			if ($targets === null) {
				$this->logger->info(sprintf('[%s] no recipients are configured for this part, skipped.', $part_title));
				continue;
			}

			if (!self::countTargets($targets)) {
				$message = I18n::s('[%1$s] no recipients with an active media, the report is not sent.', $part_title);
				$this->logger->warning($message);
				$result['errors'][] = $message;
				$result['failed']++;
				$result['deliveries'][] = ['part' => $part['name'], 'recipient' => '', 'mediatypeid' => '', 'status' => 'failed',
					'error' => I18n::t('No recipients with an active media.'), 'time' => time()
				];
				continue;
			}

			$result['parts']++;

			foreach ($targets as $mediatypeid => $sendto) {
				try {
					$media_type = $get_media_type((string) $mediatypeid);
				}
				catch (RuntimeException $e) {
					$this->logger->warning(sprintf('[%s] %s', $part_title, $e->getMessage()));
					$result['errors'][] = $e->getMessage();

					foreach ($sendto as $recipient) {
						$result['failed']++;
						$result['deliveries'][] = ['part' => $part['name'], 'recipient' => $recipient,
							'mediatypeid' => (string) $mediatypeid, 'status' => 'failed',
							'error' => $e->getMessage(), 'time' => time()
						];
					}

					continue;
				}

				$used[$media_type['name']] = true;
				$part_result = $this->dispatcher->dispatch($media_type, $sendto, $part['report'], $format, $part_title);

				$result['sent'] += $part_result['sent'];
				$result['failed'] += $part_result['failed'];
				$result['errors'] = array_merge($result['errors'], $part_result['errors']);

				foreach ($part_result['deliveries'] as $delivery) {
					$result['deliveries'][] = ['part' => $part['name'], 'mediatypeid' => (string) $mediatypeid] + $delivery;
				}
			}
		}

		$result['media_type'] = implode(', ', array_keys($used));

		if ($result['parts'] == 0 && $result['failed'] == 0) {
			$this->logger->info(sprintf('[%s] nothing to send: the report is empty.', $title));
		}

		return $result;
	}
}
