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
	 * @return array  ['sent' => int, 'failed' => int, 'errors' => string[], 'problems' => int, 'parts' => int,
	 *                 'media_type' => string, 'deliveries' => list of ['part', 'recipient', 'status', 'error', 'time']]
	 *
	 * @throws RuntimeException
	 */
	public function runSchedule(array $schedule): array {
		$media_type = $this->dispatcher->getMediaType($schedule['mediatypeid']);
		$resolver = new RecipientResolver($this->api);
		$common = $this->resolveRecipients($resolver, $schedule, $schedule['mediatypeid']);

		$active_rules = ScheduleHelper::getActiveRecipientRules($schedule);

		if ($active_rules === null) {
			if (!$common) {
				throw new RuntimeException(sprintf('No recipients: selected users have no active media of type "%s".',
					$media_type['name']
				));
			}

			$report = $this->buildReport(self::getScheduleFilter($schedule), $schedule['split']);

			return $this->deliver($media_type, static function () use ($common): array {
				return $common;
			}, $report, $schedule['format'], $schedule['name'], $schedule['split'], (bool) $schedule['skip_empty']);
		}

		// Split with own recipients: groupid/hostid => addresses of all rules that list the group/host.
		$by_part = [];

		foreach ($active_rules['rules'] as $rule) {
			$sendto = $this->resolveRecipients($resolver, $rule, $schedule['mediatypeid']);

			foreach ($rule[$active_rules['key']] as $id) {
				$by_part[$id] = array_merge($by_part[$id] ?? [], $sendto);
			}
		}

		$fallback_only = $schedule['common_recipients'] === ScheduleHelper::COMMON_FALLBACK;
		$has_common = ScheduleHelper::hasRecipients($schedule);

		$recipients = static function (array $part) use ($by_part, $common, $fallback_only, $has_common): ?array {
			$has_rule = array_key_exists($part['id'], $by_part);

			// The group/host is not addressed by any rule and there are no common recipients.
			if (!$has_rule && !$has_common) {
				return null;
			}

			$own = $by_part[$part['id']] ?? [];
			$sendto = ($has_rule && $fallback_only) ? $own : array_merge($own, $common);

			return array_values(array_unique($sendto));
		};

		$report = $this->buildReport(self::getScheduleFilter($schedule), $schedule['split']);

		return $this->deliver($media_type, $recipients, $report, $schedule['format'], $schedule['name'],
			$schedule['split'], (bool) $schedule['skip_empty']
		);
	}

	/**
	 * Addresses of users, user groups and additional addresses of a schedule or of a group recipient rule.
	 */
	private function resolveRecipients(RecipientResolver $resolver, array $recipients, string $mediatypeid): array {
		$sendto = $resolver->resolve($recipients['userids'], $recipients['usrgrpids'], $mediatypeid);

		return array_values(array_unique(array_merge($sendto, $recipients['extra_sendto'])));
	}

	/**
	 * "Test to me": the report of a schedule with all its settings (filter, format, split, analytics), delivered
	 * only to the given addresses instead of the schedule recipients.
	 *
	 * @throws RuntimeException
	 */
	public function testSchedule(array $schedule, array $sendto): array {
		$media_type = $this->dispatcher->getMediaType($schedule['mediatypeid']);

		if (!$sendto) {
			throw new RuntimeException(sprintf('You have no active media of type "%s": add it in User settings -> '.
				'Profile -> Media.', $media_type['name']
			));
		}

		$report = $this->buildReport(self::getScheduleFilter($schedule), $schedule['split']);

		return $this->deliver($media_type, static function () use ($sendto): array {
			return $sendto;
		}, $report, $schedule['format'], '[Test] '.$schedule['name'], $schedule['split'], (bool) $schedule['skip_empty']);
	}

	/**
	 * Sends an already built report to explicit addresses.
	 */
	public function sendReport(array $report, string $mediatypeid, array $sendto, string $format, string $title,
			string $split = ReportSplitter::SPLIT_NONE, bool $skip_empty = false): array {
		$media_type = $this->dispatcher->getMediaType($mediatypeid);

		if (!$sendto) {
			throw new RuntimeException(sprintf('No recipients for media type "%s".', $media_type['name']));
		}

		return $this->deliver($media_type, static function () use ($sendto): array {
			return $sendto;
		}, $report, $format, $title, $split, $skip_empty);
	}

	/**
	 * @param callable $recipients  function (array $part): ?string[] - addresses for a report part,
	 *                              null if nobody is meant to receive this part.
	 */
	private function deliver(array $media_type, callable $recipients, array $report, string $format, string $title,
			string $split, bool $skip_empty): array {
		$result = ['sent' => 0, 'failed' => 0, 'errors' => [], 'problems' => $report['total'], 'parts' => 0,
			'media_type' => $media_type['name'], 'deliveries' => []
		];

		foreach (ReportSplitter::split($report, $split, $skip_empty) as $part) {
			$part_title = $part['name'] === '' ? $title : $title.' - '.$part['name'];
			$sendto = $recipients($part);

			if ($sendto === null) {
				$this->logger->info(sprintf('[%s] no recipients are configured for this part, skipped.', $part_title));
				continue;
			}

			if (!$sendto) {
				$message = sprintf('[%s] no recipients with an active "%s" media, the report is not sent.', $part_title,
					$media_type['name']
				);
				$this->logger->warning($message);
				$result['errors'][] = $message;
				$result['failed']++;
				$result['deliveries'][] = ['part' => $part['name'], 'recipient' => '', 'status' => 'failed',
					'error' => sprintf('No recipients with an active "%s" media.', $media_type['name']), 'time' => time()
				];
				continue;
			}

			$part_result = $this->dispatcher->dispatch($media_type, $sendto, $part['report'], $format, $part_title);

			$result['parts']++;
			$result['sent'] += $part_result['sent'];
			$result['failed'] += $part_result['failed'];
			$result['errors'] = array_merge($result['errors'], $part_result['errors']);

			foreach ($part_result['deliveries'] as $delivery) {
				$result['deliveries'][] = ['part' => $part['name']] + $delivery;
			}
		}

		if ($result['parts'] == 0 && $result['failed'] == 0) {
			$this->logger->info(sprintf('[%s] nothing to send: no problems match the filter.', $title));
		}

		return $result;
	}
}
