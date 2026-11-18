<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

use PDO;
use Throwable;

/**
 * Reads the SMTP password of a media type directly from the Zabbix database (CLI only).
 *
 * The Zabbix API does not return media type passwords, so the CLI sender takes database credentials
 * from the frontend configuration file (zabbix.conf.php) and runs a single read-only query.
 */
class DbSecretResolver {

	private string $conf_file;
	private ?PDO $pdo = null;

	public function __construct(string $conf_file) {
		$this->conf_file = $conf_file;
	}

	public function __invoke(string $mediatypeid): ?string {
		try {
			$pdo = $this->connect();

			if ($pdo === null) {
				return null;
			}

			$statement = $pdo->prepare('SELECT passwd FROM media_type WHERE mediatypeid=?');
			$statement->execute([$mediatypeid]);
			$password = $statement->fetchColumn();

			return $password === false ? null : (string) $password;
		}
		catch (Throwable $e) {
			return null;
		}
	}

	private function connect(): ?PDO {
		if ($this->pdo !== null) {
			return $this->pdo;
		}

		if (!is_readable($this->conf_file)) {
			return null;
		}

		$DB = [];
		include $this->conf_file;

		if (!$DB || empty($DB['TYPE'])) {
			return null;
		}

		$server = (string) ($DB['SERVER'] ?? 'localhost');
		$port = (string) ($DB['PORT'] ?? '');
		$database = (string) ($DB['DATABASE'] ?? 'zabbix');

		switch (strtoupper($DB['TYPE'])) {
			case 'MYSQL':
				$dsn = $server !== '' && $server[0] === '/'
					? 'mysql:unix_socket='.$server
					: 'mysql:host='.$server.($port !== '' && $port !== '0' ? ';port='.$port : '');
				$dsn .= ';dbname='.$database.';charset=utf8mb4';
				break;

			case 'POSTGRESQL':
				$dsn = 'pgsql:host='.$server.($port !== '' && $port !== '0' ? ';port='.$port : '').';dbname='.$database;
				break;

			default:
				return null;
		}

		$this->pdo = new PDO($dsn, (string) ($DB['USER'] ?? ''), (string) ($DB['PASSWORD'] ?? ''), [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_TIMEOUT => 10
		]);

		if (strtoupper($DB['TYPE']) === 'POSTGRESQL' && !empty($DB['SCHEMA'])) {
			$this->pdo->exec('SET search_path TO '.$this->pdo->quote($DB['SCHEMA']));
		}

		return $this->pdo;
	}
}
