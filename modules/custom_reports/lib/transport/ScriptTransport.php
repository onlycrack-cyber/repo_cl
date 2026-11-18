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
 */
class ScriptTransport {

	private array $media_type;
	private string $scripts_path;
	private int $timeout;

	public function __construct(array $media_type, string $scripts_path, int $timeout = 30) {
		$this->media_type = $media_type;
		$this->scripts_path = rtrim($scripts_path, '/');
		$this->timeout = $timeout;
	}

	public function send(string $sendto, string $subject, string $message): void {
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
			'{ALERT.MESSAGE}' => $message
		];

		$command = [$script];

		foreach ($parameters as $parameter) {
			$command[] = strtr((string) $parameter['value'], $macros);
		}

		$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

		if (!is_resource($process)) {
			throw new RuntimeException(sprintf('Cannot start alert script "%s".', $script));
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
				$status['exitcode'], trim(substr($output, 0, 500))
			));
		}
	}
}
