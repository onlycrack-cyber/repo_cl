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
		return (new ReportBuilder($this->api, (int) $this->config['problem_limit']))
			->build($filter, null, $split === ReportSplitter::SPLIT_GROUP);
	}

	/**
	 * Builds the report of a schedule and sends it to all its recipients
	 * (one message per host group / host if the schedule is split).
	 *
	 * @return array  ['sent' => int, 'failed' => int, 'errors' => string[], 'problems' => int, 'parts' => int]
	 *
	 * @throws RuntimeException
	 */
	public function runSchedule(array $schedule): array {
		$media_type = $this->dispatcher->getMediaType($schedule['mediatypeid']);

		$sendto = (new RecipientResolver($this->api))->resolve($schedule['userids'], $schedule['usrgrpids'],
			$schedule['mediatypeid']
		);
		$sendto = array_values(array_unique(array_merge($sendto, $schedule['extra_sendto'])));

		if (!$sendto) {
			throw new RuntimeException(sprintf('No recipients: selected users have no active media of type "%s".',
				$media_type['name']
			));
		}

		$report = $this->buildReport($schedule, $schedule['split']);

		return $this->deliver($media_type, $sendto, $report, $schedule['format'], $schedule['name'],
			$schedule['split'], (bool) $schedule['skip_empty']
		);
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

		return $this->deliver($media_type, $sendto, $report, $format, $title, $split, $skip_empty);
	}

	private function deliver(array $media_type, array $sendto, array $report, string $format, string $title,
			string $split, bool $skip_empty): array {
		$result = ['sent' => 0, 'failed' => 0, 'errors' => [], 'problems' => $report['total'], 'parts' => 0];

		foreach (ReportSplitter::split($report, $split, $skip_empty) as $part) {
			$part_title = $part['name'] === '' ? $title : $title.' - '.$part['name'];
			$part_result = $this->dispatcher->dispatch($media_type, $sendto, $part['report'], $format, $part_title);

			$result['parts']++;
			$result['sent'] += $part_result['sent'];
			$result['failed'] += $part_result['failed'];
			$result['errors'] = array_merge($result['errors'], $part_result['errors']);
		}

		if ($result['parts'] == 0) {
			$this->logger->info(sprintf('[%s] nothing to send: no problems match the filter.', $title));
		}

		return $result;
	}
}
