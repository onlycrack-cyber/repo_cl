<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

use RuntimeException;

/**
 * File storage of schedules (data/schedules.json) and delivery state (data/state.json).
 *
 * The frontend writes schedules, the CLI sender writes state, so the two never overwrite each other.
 * Every read-modify-write cycle is serialized with an exclusive lock, files are replaced atomically.
 */
class ScheduleStorage {

	private const SCHEDULES_FILE = 'schedules.json';
	private const STATE_FILE = 'state.json';
	private const LOCK_FILE = '.storage.lock';

	private string $dir;

	public function __construct(string $data_dir) {
		$this->dir = rtrim($data_dir, '/');
	}

	public function getDir(): string {
		return $this->dir;
	}

	/**
	 * @return array  scheduleid => schedule
	 */
	public function getAll(): array {
		return $this->withLock(LOCK_SH, function (): array {
			return $this->read(self::SCHEDULES_FILE);
		});
	}

	public function get(string $scheduleid): ?array {
		return $this->getAll()[$scheduleid] ?? null;
	}

	/**
	 * Creates or updates a schedule. Returns the stored schedule.
	 */
	public function save(array $schedule): array {
		return $this->withLock(LOCK_EX, function () use ($schedule): array {
			$schedules = $this->read(self::SCHEDULES_FILE);

			if ($schedule['scheduleid'] === '') {
				$schedule['scheduleid'] = bin2hex(random_bytes(8));
			}
			elseif (!array_key_exists($schedule['scheduleid'], $schedules)) {
				throw new RuntimeException('Schedule does not exist.');
			}

			$schedule['updated_at'] = time();
			$schedules[$schedule['scheduleid']] = $schedule;
			$this->write(self::SCHEDULES_FILE, $schedules);

			return $schedule;
		});
	}

	public function delete(string $scheduleid): bool {
		return $this->withLock(LOCK_EX, function () use ($scheduleid): bool {
			$schedules = $this->read(self::SCHEDULES_FILE);

			if (!array_key_exists($scheduleid, $schedules)) {
				return false;
			}

			unset($schedules[$scheduleid]);
			$this->write(self::SCHEDULES_FILE, $schedules);

			$state = $this->read(self::STATE_FILE);

			if (array_key_exists($scheduleid, $state)) {
				unset($state[$scheduleid]);
				$this->write(self::STATE_FILE, $state);
			}

			return true;
		});
	}

	/**
	 * @return array  scheduleid => ['last_run' => int, 'last_status' => string, 'last_message' => string]
	 */
	public function getState(): array {
		return $this->withLock(LOCK_SH, function (): array {
			return $this->read(self::STATE_FILE);
		});
	}

	public function updateState(string $scheduleid, array $values): void {
		$this->withLock(LOCK_EX, function () use ($scheduleid, $values): void {
			$state = $this->read(self::STATE_FILE);
			$state[$scheduleid] = array_replace($state[$scheduleid] ?? [], $values);
			$this->write(self::STATE_FILE, $state);
		});
	}

	private function withLock(int $operation, callable $callback) {
		$this->ensureDir();

		$lock = @fopen($this->dir.'/'.self::LOCK_FILE, 'c');

		if ($lock === false) {
			throw new RuntimeException(sprintf('Cannot open lock file in "%s". Check directory permissions.', $this->dir));
		}

		try {
			if (!flock($lock, $operation)) {
				throw new RuntimeException('Cannot lock schedule storage.');
			}

			return $callback();
		}
		finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}

	private function read(string $file): array {
		$path = $this->dir.'/'.$file;

		if (!is_file($path)) {
			return [];
		}

		$content = file_get_contents($path);

		if ($content === false) {
			throw new RuntimeException(sprintf('Cannot read "%s".', $path));
		}

		$data = json_decode($content, true);

		return is_array($data) ? $data : [];
	}

	private function write(string $file, array $data): void {
		$path = $this->dir.'/'.$file;
		$tmp_path = $path.'.'.getmypid().'.tmp';
		$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		if (@file_put_contents($tmp_path, $json) === false || !@rename($tmp_path, $path)) {
			@unlink($tmp_path);

			throw new RuntimeException(sprintf('Cannot write "%s". Check directory permissions.', $path));
		}

		@chmod($path, 0660);
	}

	private function ensureDir(): void {
		if (!is_dir($this->dir) && !@mkdir($this->dir, 0770, true)) {
			throw new RuntimeException(sprintf('Cannot create data directory "%s".', $this->dir));
		}
	}
}
