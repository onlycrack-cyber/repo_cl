<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib\Transport;

use RuntimeException;

/**
 * Runs the alert script of a Zabbix "Script" media type the same way Zabbix server does:
 * <AlertScriptsPath>/<exec_path> with the media type parameters, where {ALERT.SENDTO},
 * {ALERT.SUBJECT} and {ALERT.MESSAGE} are expanded.
 *
 * The report file (HTML, PDF or CSV) is offered as {REPORT.FILE} in the parameters and in the environment variables
 * CR_REPORT_FILE, CR_REPORT_FILENAME and CR_REPORT_MIME; scripts that do not know them just ignore them.
 */
class ScriptTransport {

	// One argument of a command is limited by the kernel (MAX_ARG_STRLEN, 128 KB on Linux): longer values are cut,
	// otherwise the script cannot be started at all ("Argument list too long").
	public const MAX_ARGUMENT = 100000;

	private array $media_type;
	private string $scripts_path;
	private int $timeout;

	public function __construct(array $media_type, string $scripts_path, int $timeout = 30) {
		$this->media_type = $media_type;
		$this->scripts_path = rtrim($scripts_path, '/');
		$this->timeout = $timeout;
	}

	/**
	 * @param array|null $file  ['path' => ..., 'name' => ..., 'type' => ...]: the report file for this call.
	 */
	public function send(string $sendto, string $subject, string $message, ?array $file = null): void {
		// basename(): the script must reside in AlertScriptsPath, as on Zabbix server.
		$script = $this->scripts_path.'/'.basename((string) $this->media_type['exec_path']);

		if (!is_file($script) || !is_executable($script)) {
			throw new RuntimeException(sprintf('Alert script "%s" does not exist or is not executable.', $script));
		}

		$parameters = $this->media_type['parameters'] ?? [];
		usort($parameters, static function (array $a, array $b): int {
			return (int) ($a['sortorder'] ?? 0) <=> (int) ($b['sortorder'] ?? 0);
		});

		$macros = [
			'{ALERT.SENDTO}' => $sendto,
			'{ALERT.SUBJECT}' => $subject,
			'{ALERT.MESSAGE}' => $message,
			'{REPORT.FILE}' => $file['path'] ?? ''
		];

		$command = [$script];

		foreach ($parameters as $parameter) {
			$command[] = self::limit(strtr((string) $parameter['value'], $macros));
		}

		$env = getenv();
		$env = is_array($env) ? $env : [];
		// Lets a script tell a report of this module from an ordinary Zabbix alert.
		$env['CR_REPORT'] = '1';

		if ($file !== null) {
			$env['CR_REPORT_FILE'] = $file['path'];
			$env['CR_REPORT_FILENAME'] = $file['name'];
			$env['CR_REPORT_MIME'] = $file['type'];
		}

		$process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
			$env
		);

		if (!is_resource($process)) {
			$error = error_get_last();

			throw new RuntimeException(sprintf('Cannot start alert script "%s"%s.', $script,
				$error !== null ? ': '.$error['message'] : ''
			));
		}

		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$output = '';
		$deadline = microtime(true) + $this->timeout;

		do {
			$output .= stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
			$status = proc_get_status($process);

			if (!$status['running']) {
				break;
			}

			if (microtime(true) > $deadline) {
				proc_terminate($process);
				fclose($pipes[1]);
				fclose($pipes[2]);
				proc_close($process);

				throw new RuntimeException(sprintf('Alert script "%s" timed out after %d s.', $script, $this->timeout));
			}

			usleep(100000);
		}
		while (true);

		$output .= stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		if ($status['exitcode'] != 0) {
			throw new RuntimeException(sprintf('Alert script "%s" exited with code %d: %s', $script,
				$status['exitcode'], self::tail($output)
			));
		}
	}

	/**
	 * The end of the output: a Python traceback or a shell error has the reason in the last lines.
	 */
	public static function tail(string $output, int $length = 700): string {
		$output = trim($output);

		if (strlen($output) <= $length) {
			return $output;
		}

		return '...'.ltrim(mb_strcut($output, strlen($output) - $length, $length, 'UTF-8'));
	}

	/**
	 * Cuts a too long argument at a character boundary.
	 */
	public static function limit(string $value): string {
		if (strlen($value) <= self::MAX_ARGUMENT) {
			return $value;
		}

		return mb_strcut($value, 0, self::MAX_ARGUMENT - 3, 'UTF-8').'...';
	}
}
