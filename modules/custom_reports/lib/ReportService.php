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

	public function buildReport(array $filter): array {
		return (new ReportBuilder($this->api, (int) $this->config['problem_limit']))->build($filter);
	}

	/**
	 * Builds the report of a schedule and sends it to all its recipients.
	 *
	 * @return array  ['sent' => int, 'failed' => int, 'errors' => string[], 'problems' => int]
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

		$report = $this->buildReport($schedule);

		$result = $this->dispatcher->dispatch($media_type, $sendto, $report, $schedule['format'], $schedule['name']);
		$result['problems'] = $report['total'];

		return $result;
	}

	/**
	 * Sends an already built report to explicit addresses.
	 */
	public function sendReport(array $report, string $mediatypeid, array $sendto, string $format, string $title): array {
		$media_type = $this->dispatcher->getMediaType($mediatypeid);

		if (!$sendto) {
			throw new RuntimeException(sprintf('No recipients for media type "%s".', $media_type['name']));
		}

		return $this->dispatcher->dispatch($media_type, $sendto, $report, $format, $title);
	}
}
