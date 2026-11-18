<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

/**
 * Module configuration (conf/config.php merged over defaults).
 *
 * The class has no dependencies on the Zabbix frontend so it can be used both by
 * controllers and by the CLI sender (bin/send_reports.php).
 */
class ModuleConfig {

	public const DEFAULTS = [
		// Zabbix API endpoint and API token used by the CLI sender.
		'api_url' => 'http://localhost/zabbix/api_jsonrpc.php',
		'api_token' => '',
		'verify_ssl' => true,
		'timeout' => 30,

		// Timezone in which schedule times are evaluated; empty = PHP default.
		'timezone' => '',

		// Storage and logging; empty values = <module>/data and <module>/data/logs/custom_reports.log.
		'data_dir' => '',
		'log_file' => '',
		'log_level' => 'info',

		// Optional link to the frontend placed into HTML reports.
		'frontend_url' => '',

		// Maximum number of problems placed into one report.
		'problem_limit' => 1000,

		// A missed schedule (cron was down) is still sent if no more than this many seconds passed.
		'grace_period' => 3600,

		// Directory with alert scripts (AlertScriptsPath from zabbix_server.conf).
		'alert_scripts_path' => '/usr/lib/zabbix/alertscripts',

		// SMTP passwords by mediatypeid, used if the API does not return the password.
		'smtp_passwords' => [],

		// Read the SMTP password directly from the Zabbix database (credentials from this file).
		'db_fallback' => true,
		'zabbix_web_conf' => '/etc/zabbix/web/zabbix.conf.php',

		// Telegram.
		'telegram_api_url' => 'https://api.telegram.org',
		'telegram_max_length' => 4096,

		// HTTP proxy for Telegram, if the media type does not define one.
		'proxy' => ''
	];

	private static ?array $config = null;

	public static function moduleDir(): string {
		return dirname(__DIR__);
	}

	public static function get(): array {
		if (self::$config === null) {
			$config = self::DEFAULTS;
			$file = self::moduleDir().'/conf/config.php';

			if (is_file($file)) {
				$user_config = include $file;

				if (is_array($user_config)) {
					$config = array_replace($config, $user_config);
				}
			}

			if ($config['data_dir'] === '') {
				$config['data_dir'] = self::moduleDir().'/data';
			}

			if ($config['log_file'] === '') {
				$config['log_file'] = rtrim($config['data_dir'], '/').'/logs/custom_reports.log';
			}

			self::$config = $config;
		}

		return self::$config;
	}

	/**
	 * Timezone used to evaluate schedule times.
	 */
	public static function timezone(): string {
		$timezone = self::get()['timezone'];

		return $timezone !== '' ? $timezone : date_default_timezone_get();
	}
}
