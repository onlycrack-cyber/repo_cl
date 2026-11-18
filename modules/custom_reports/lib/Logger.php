<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

/**
 * Minimal file logger.
 */
class Logger {

	private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

	private string $file;
	private int $level;
	private bool $echo;

	public function __construct(string $file, string $level = 'info', bool $echo = false) {
		$this->file = $file;
		$this->level = self::LEVELS[$level] ?? self::LEVELS['info'];
		$this->echo = $echo;
	}

	public function debug(string $message): void {
		$this->write('debug', $message);
	}

	public function info(string $message): void {
		$this->write('info', $message);
	}

	public function warning(string $message): void {
		$this->write('warning', $message);
	}

	public function error(string $message): void {
		$this->write('error', $message);
	}

	private function write(string $level, string $message): void {
		if (self::LEVELS[$level] < $this->level) {
			return;
		}

		$line = sprintf("%s [%d] %-7s %s\n", date('Y-m-d H:i:s'), getmypid(), strtoupper($level),
			str_replace(["\r", "\n"], ' ', $message)
		);

		if ($this->echo) {
			fwrite($level === 'error' ? STDERR : STDOUT, $line);
		}

		$dir = dirname($this->file);

		if (!is_dir($dir)) {
			@mkdir($dir, 0750, true);
		}

		@file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
	}
}
