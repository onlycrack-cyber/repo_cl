<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

use RuntimeException;

/**
 * Runs an external command without a shell, with a timeout.
 */
class ProcessRunner {

	/**
	 * @param string[]   $command  Executable and arguments (no shell interpretation).
	 * @param int        $timeout  Seconds.
	 * @param array|null $env      Environment variables, null to inherit.
	 *
	 * @return array  [exit code, combined stdout and stderr]
	 *
	 * @throws RuntimeException  If the command cannot be started or times out.
	 */
	public static function run(array $command, int $timeout, ?array $env = null): array {
		[$code, $stdout, $stderr] = self::exec($command, $timeout, $env);

		return [$code, $stdout.$stderr];
	}

	/**
	 * The same as run(), with stdout and stderr kept apart.
	 *
	 * @param int $max_output  Maximum size of stdout in bytes (0 - unlimited); a bigger output stops the command.
	 *
	 * @return array  [exit code, stdout, stderr]
	 *
	 * @throws RuntimeException  If the command cannot be started, times out or prints too much.
	 */
	public static function exec(array $command, int $timeout, ?array $env = null, int $max_output = 0): array {
		if (!function_exists('proc_open')) {
			throw new RuntimeException('PHP function proc_open() is disabled (disable_functions).');
		}

		$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
			$env
		);

		if (!is_resource($process)) {
			throw new RuntimeException(sprintf('Cannot start "%s".', $command[0]));
		}

		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$stdout = '';
		$stderr = '';
		$deadline = microtime(true) + $timeout;
		$stop = static function () use ($process, $pipes): void {
			proc_terminate($process, 9);
			fclose($pipes[1]);
			fclose($pipes[2]);
			proc_close($process);
		};

		while (true) {
			$stdout .= stream_get_contents($pipes[1]);
			$stderr .= stream_get_contents($pipes[2]);
			$status = proc_get_status($process);

			if ($max_output > 0 && strlen($stdout) > $max_output) {
				$stop();

				throw new RuntimeException(sprintf('"%s" printed more than %d bytes.', basename($command[0]), $max_output));
			}

			if (!$status['running']) {
				break;
			}

			if (microtime(true) > $deadline) {
				$stop();

				throw new RuntimeException(sprintf('"%s" timed out after %d s.', basename($command[0]), $timeout));
			}

			usleep(50000);
		}

		$stdout .= stream_get_contents($pipes[1]);
		$stderr .= stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		return [(int) $status['exitcode'], $stdout, $stderr];
	}
}
