#!/usr/bin/env php
<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
**
** Scheduled report sender. Run every minute from cron as the web server user:
**
**   * * * * * www-data /usr/bin/php /usr/share/zabbix/modules/custom_reports/bin/send_reports.php
**
** Options:
**   --list           Show schedules, next due state and exit.
**   --run=<id>       Send the given schedule now (ignores time and "enabled").
**   --dry-run        Show which schedules are due without sending anything.
**   --verbose, -v    Duplicate log messages to stdout/stderr.
**   --help, -h       Show this help.
**
** Exit codes: 0 - success, 1 - some deliveries failed, 2 - configuration error, 3 - another instance is running.
*/


if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit(2);
}

const CR_EXIT_OK = 0;
const CR_EXIT_FAILED = 1;
const CR_EXIT_CONFIG = 2;
const CR_EXIT_LOCKED = 3;

$module_dir = dirname(__DIR__);

// Autoloader for Modules\CustomReports\Lib\* (directories are lower case, as in the Zabbix autoloader).
spl_autoload_register(static function (string $class) use ($module_dir): void {
	$prefix = 'Modules\\CustomReports\\';

	if (strncmp($class, $prefix, strlen($prefix)) != 0) {
		return;
	}

	$parts = explode('\\', substr($class, strlen($prefix)));
	$class_name = array_pop($parts);
	$path = $module_dir.'/'.($parts ? strtolower(implode('/', $parts)).'/' : '').$class_name.'.php';

	if (is_file($path)) {
		require_once $path;
	}
});

use Modules\CustomReports\Lib\CronManager;
use Modules\CustomReports\Lib\DbSecretResolver;
use Modules\CustomReports\Lib\JsonRpcApiClient;
use Modules\CustomReports\Lib\Logger;
use Modules\CustomReports\Lib\ModuleConfig;
use Modules\CustomReports\Lib\ReportService;
use Modules\CustomReports\Lib\ScheduleHelper;
use Modules\CustomReports\Lib\ScheduleStorage;

$options = getopt('vh', ['list', 'run:', 'dry-run', 'verbose', 'help']);

if (isset($options['h']) || isset($options['help'])) {
	echo <<<'HELP'
Usage: send_reports.php [--list] [--run=<scheduleid>] [--dry-run] [--verbose]

  --list         Show schedules and exit.
  --run=<id>     Send the given schedule immediately.
  --dry-run      Show due schedules without sending.
  --verbose, -v  Print log messages to the console.

HELP;
	exit(CR_EXIT_OK);
}

$verbose = isset($options['v']) || isset($options['verbose']);

try {
	$config = ModuleConfig::get();
	date_default_timezone_set(ModuleConfig::timezone());
}
catch (Throwable $e) {
	fwrite(STDERR, 'Configuration error: '.$e->getMessage().PHP_EOL);
	exit(CR_EXIT_CONFIG);
}

$logger = new Logger($config['log_file'], $config['log_level'], $verbose);

// A wrong timezone would make every schedule fire at an unexpected hour (or fail): stop with a clear error.
if (ModuleConfig::timezoneProblem() === 'invalid') {
	$logger->error(sprintf('Configuration error: unknown timezone "%s" in conf/config.php (e.g. "Europe/Moscow").',
		$config['timezone']
	));
	exit(CR_EXIT_CONFIG);
}

$storage = new ScheduleStorage($config['data_dir']);
$timezone = ModuleConfig::timezone();
$now = time();

// --list does not need the API.
if (isset($options['list'])) {
	$state = $storage->getState();

	foreach ($storage->getAll() as $scheduleid => $schedule) {
		$last_run = (int) ($state[$scheduleid]['last_run'] ?? 0);

		printf("%s  %-8s  %-30s  %-28s  last run: %s  due: %s\n",
			$scheduleid,
			$schedule['enabled'] ? 'enabled' : 'disabled',
			mb_strimwidth($schedule['name'], 0, 30, '...', 'UTF-8'),
			ScheduleHelper::describe($schedule),
			$last_run > 0 ? date('Y-m-d H:i', $last_run) : 'never',
			ScheduleHelper::isDue($schedule, $state[$scheduleid] ?? [], $now, (int) $config['grace_period'], $timezone)
				? 'yes'
				: 'no'
		);
	}

	exit(CR_EXIT_OK);
}

// Only one instance at a time: a slow SMTP server must not cause duplicate deliveries.
if (!is_dir($config['data_dir']) && !@mkdir($config['data_dir'], 0770, true)) {
	$logger->error(sprintf('Cannot create data directory "%s".', $config['data_dir']));
	exit(CR_EXIT_CONFIG);
}

$lock_file = rtrim($config['data_dir'], '/').'/.send_reports.lock';
$lock = @fopen($lock_file, 'c');

if ($lock === false) {
	$logger->error(sprintf('Cannot open lock file "%s".', $lock_file));
	exit(CR_EXIT_CONFIG);
}

$locked = flock($lock, LOCK_EX | LOCK_NB);

// Scheduled runs report that cron works (shown on the "Schedules and delivery" tab), also while a previous run
// is still busy sending, so that a long delivery does not look like a stopped cron.
if (!isset($options['run']) && !isset($options['dry-run'])) {
	CronManager::writeHeartbeat($config['data_dir'], ['php' => PHP_BINARY, 'busy' => !$locked]);
}

if (!$locked) {
	$logger->warning('Another instance is still running, exiting.');
	exit(CR_EXIT_LOCKED);
}

try {
	$schedules = $storage->getAll();
	$state = $storage->getState();
}
catch (Throwable $e) {
	$logger->error('Cannot read schedules: '.$e->getMessage());
	exit(CR_EXIT_CONFIG);
}

// Select schedules to send.
$due = [];

if (isset($options['run'])) {
	$scheduleid = (string) $options['run'];

	if (!array_key_exists($scheduleid, $schedules)) {
		$logger->error(sprintf('Schedule "%s" does not exist.', $scheduleid));
		exit(CR_EXIT_CONFIG);
	}

	$due[$scheduleid] = $schedules[$scheduleid];
}
else {
	foreach ($schedules as $scheduleid => $schedule) {
		if (ScheduleHelper::isDue($schedule, $state[$scheduleid] ?? [], $now, (int) $config['grace_period'], $timezone)) {
			$due[$scheduleid] = $schedule;
		}
	}
}

if (!$due) {
	$logger->debug('No schedules are due.');
	exit(CR_EXIT_OK);
}

if (isset($options['dry-run'])) {
	foreach ($due as $scheduleid => $schedule) {
		$logger->info(sprintf('[dry-run] due: %s "%s" (%s)', $scheduleid, $schedule['name'],
			ScheduleHelper::describe($schedule)
		));
	}

	exit(CR_EXIT_OK);
}

try {
	$api = new JsonRpcApiClient($config['api_url'], $config['api_token'], (bool) $config['verify_ssl'],
		(int) $config['timeout']
	);
}
catch (Throwable $e) {
	$logger->error($e->getMessage());
	exit(CR_EXIT_CONFIG);
}

$secret_resolver = $config['db_fallback'] ? new DbSecretResolver($config['zabbix_web_conf']) : null;
$service = new ReportService($api, $logger, $config, $secret_resolver);
$exit_code = CR_EXIT_OK;

foreach ($due as $scheduleid => $schedule) {
	$logger->info(sprintf('Running schedule %s "%s" (%s).', $scheduleid, $schedule['name'],
		ScheduleHelper::describe($schedule)
	));

	try {
		$result = $service->runSchedule($schedule);
		$status = $result['failed'] == 0 ? 'ok' : 'error';
		$message = sprintf('Problems: %d, messages: %d, sent: %d, failed: %d.', $result['problems'], $result['parts'],
			$result['sent'], $result['failed']
		);

		if ($result['errors']) {
			$message .= ' '.implode('; ', $result['errors']);
		}
	}
	catch (Throwable $e) {
		$status = 'error';
		$message = $e->getMessage();
	}

	if ($status === 'ok') {
		$logger->info(sprintf('Schedule %s finished. %s', $scheduleid, $message));
	}
	else {
		$logger->error(sprintf('Schedule %s failed. %s', $scheduleid, $message));
		$exit_code = CR_EXIT_FAILED;
	}

	// "last_run" is stored even after a failure: a broken schedule must not be retried every minute.
	try {
		$storage->updateState($scheduleid, [
			'last_run' => $now,
			'last_status' => $status,
			'last_message' => mb_substr($message, 0, 2000, 'UTF-8')
		]);
	}
	catch (Throwable $e) {
		$logger->error('Cannot save schedule state: '.$e->getMessage());
		$exit_code = CR_EXIT_FAILED;
	}
}

flock($lock, LOCK_UN);
fclose($lock);

exit($exit_code);
