<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

use RuntimeException;

/**
 * Cron job of the report sender: status (heartbeat), user crontab management and the /etc/cron.d line.
 *
 * The frontend runs as the web server user, so it can only manage that user's own crontab
 * ("crontab -l" / "crontab -"). /etc/cron.d is owned by root and is only detected, never written.
 */
class CronManager {

	public const MARKER = '# zabbix-custom-reports';
	public const CRON_D_FILE = '/etc/cron.d/zabbix-custom-reports';

	private const HEARTBEAT_FILE = 'heartbeat.json';

	// The sender runs every minute; a heartbeat older than this means that cron does not run it.
	public const HEARTBEAT_MAX_AGE = 180;

	private array $config;

	public function __construct(array $config) {
		$this->config = $config;
	}

	public function getScript(): string {
		return ModuleConfig::moduleDir().'/bin/send_reports.php';
	}

	/**
	 * PHP CLI binary. PHP_BINARY points to php-fpm or the web server when called from the frontend.
	 */
	public function getPhpBinary(): string {
		if (($this->config['php_binary'] ?? '') !== '') {
			return $this->config['php_binary'];
		}

		if (PHP_SAPI === 'cli' && PHP_BINARY !== '') {
			return PHP_BINARY;
		}

		foreach (['/usr/bin/php', '/usr/local/bin/php', '/usr/bin/php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION] as $path) {
			if (is_file($path) && is_executable($path)) {
				return $path;
			}
		}

		return '/usr/bin/php';
	}

	public function getCommand(): string {
		return escapeshellarg($this->getPhpBinary()).' '.escapeshellarg($this->getScript()).' >/dev/null 2>&1';
	}

	/**
	 * Line for the crontab of the web server user.
	 */
	public function getUserCrontabLine(): string {
		return '* * * * * '.$this->getCommand().' '.self::MARKER;
	}

	/**
	 * Line for /etc/cron.d (has the user field).
	 */
	public function getCronDLine(): string {
		return '* * * * * '.self::getCurrentUser().' '.$this->getCommand();
	}

	public static function getCurrentUser(): string {
		if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
			$user = posix_getpwuid(posix_geteuid());

			if (is_array($user) && $user['name'] !== '') {
				return $user['name'];
			}
		}

		return get_current_user();
	}

	public function isCronDInstalled(): bool {
		if (!is_readable(self::CRON_D_FILE)) {
			return false;
		}

		$content = (string) file_get_contents(self::CRON_D_FILE);

		return strpos($content, 'send_reports.php') !== false;
	}

	/**
	 * @throws RuntimeException
	 */
	public function isUserCrontabInstalled(): bool {
		foreach ($this->readUserCrontab() as $line) {
			if (strpos($line, self::MARKER) !== false) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Adds (or replaces) the sender line in the crontab of the current user, keeping all other lines.
	 *
	 * @throws RuntimeException
	 */
	public function installUserCrontab(): void {
		$lines = $this->withoutOwnLines($this->readUserCrontab());
		$lines[] = $this->getUserCrontabLine();

		$this->writeUserCrontab($lines);
	}

	/**
	 * @throws RuntimeException
	 */
	public function removeUserCrontab(): void {
		$this->writeUserCrontab($this->withoutOwnLines($this->readUserCrontab()));
	}

	/**
	 * Called by the sender on every run.
	 */
	public static function writeHeartbeat(string $data_dir, array $info = []): void {
		$data = $info + ['time' => time(), 'user' => self::getCurrentUser(), 'pid' => getmypid()];

		@file_put_contents(rtrim($data_dir, '/').'/'.self::HEARTBEAT_FILE, json_encode($data), LOCK_EX);
	}

	/**
	 * @return array|null  ['time' => int, 'user' => string, ...] of the last sender run.
	 */
	public function getHeartbeat(): ?array {
		$file = rtrim($this->config['data_dir'], '/').'/'.self::HEARTBEAT_FILE;

		if (!is_readable($file)) {
			return null;
		}

		$data = json_decode((string) file_get_contents($file), true);

		return is_array($data) && array_key_exists('time', $data) ? $data : null;
	}

	private function withoutOwnLines(array $lines): array {
		return array_values(array_filter($lines, static function (string $line): bool {
			return strpos($line, self::MARKER) === false;
		}));
	}

	private function readUserCrontab(): array {
		[$exit_code, $stdout, $stderr] = $this->runCrontab(['-l'], null);

		if ($exit_code != 0) {
			// "no crontab for <user>" is not an error.
			if (stripos($stderr, 'no crontab') !== false) {
				return [];
			}

			throw new RuntimeException('crontab -l failed: '.trim($stderr !== '' ? $stderr : $stdout));
		}

		$lines = preg_split('/\r?\n/', rtrim($stdout, "\n"));

		return $lines === [''] ? [] : $lines;
	}

	private function writeUserCrontab(array $lines): void {
		[$exit_code, $stdout, $stderr] = $this->runCrontab(['-'], $lines ? implode("\n", $lines)."\n" : '');

		if ($exit_code != 0) {
			throw new RuntimeException('crontab update failed: '.trim($stderr !== '' ? $stderr : $stdout));
		}
	}

	private function runCrontab(array $args, ?string $stdin): array {
		if (!function_exists('proc_open')) {
			throw new RuntimeException('PHP function proc_open() is disabled (disable_functions).');
		}

		$binary = null;
		$candidates = ($this->config['crontab_binary'] ?? '') !== ''
			? [$this->config['crontab_binary']]
			: ['/usr/bin/crontab', '/bin/crontab', '/usr/local/bin/crontab'];

		foreach ($candidates as $path) {
			if (is_file($path) && is_executable($path)) {
				$binary = $path;
				break;
			}
		}

		if ($binary === null) {
			throw new RuntimeException('The "crontab" command is not installed.');
		}

		$process = proc_open(array_merge([$binary], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);

		if (!is_resource($process)) {
			throw new RuntimeException('Cannot run "crontab".');
		}

		if ($stdin !== null) {
			fwrite($pipes[0], $stdin);
		}

		fclose($pipes[0]);
		$stdout = (string) stream_get_contents($pipes[1]);
		$stderr = (string) stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);

		return [proc_close($process), $stdout, $stderr];
	}
}
