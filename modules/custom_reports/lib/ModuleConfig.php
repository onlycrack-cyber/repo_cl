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

		// Timezone in which schedule times are evaluated, e.g. "Europe/Moscow"; empty = UTC.
		'timezone' => '',

		// Language of the interface and the reports: "ru", "en" or "auto" (language of the Zabbix user; English
		// in the cron sender).
		'language' => 'ru',

		// Storage and logging; empty values = <module>/data and <module>/data/logs/custom_reports.log.
		'data_dir' => '',
		'log_file' => '',
		'log_level' => 'info',

		// Optional link to the frontend placed into HTML reports.
		'frontend_url' => '',

		// Maximum number of problems placed into one report.
		'problem_limit' => 1000,

		// Maximum number of events read for period analytics (per period).
		'analytics_limit' => 10000,

		// PDF: "auto" (Chrome/Chromium, then wkhtmltopdf), "chrome", "wkhtmltopdf" or "none".
		'pdf_converter' => 'auto',
		'chrome_binary' => '',
		'wkhtmltopdf_binary' => '',
		'pdf_timeout' => 60,

		// A missed schedule (cron was down) is still sent if no more than this many seconds passed.
		'grace_period' => 3600,

		// Directory with alert scripts (AlertScriptsPath from zabbix_server.conf).
		'alert_scripts_path' => '/usr/lib/zabbix/alertscripts',
		// Media types of type "Script": the message ({ALERT.MESSAGE}) is the short summary, split into several calls
		// of the script if longer than this (characters; 0 - up to 100000); "text" or "html" (Telegram markup: <b>,
		// <pre> - for scripts that send with parse_mode=HTML). The report file is in {REPORT.FILE} / CR_REPORT_FILE.
		'script_message_max_length' => 4000,
		'script_message_format' => 'text',

		// Report scripts: executable files of this directory (ExternalScripts from zabbix_server.conf) can be the data
		// source of a report (tab "Scripts", schedules with the source "External script").
		'external_scripts_path' => '/usr/lib/zabbix/externalscripts',
		'script_timeout' => 60,
		// Limits of the script output: rows per table and bytes of stdout.
		'script_max_rows' => 5000,
		'script_max_output' => 10485760,

		// SMTP passwords by mediatypeid, used if the API does not return the password.
		'smtp_passwords' => [],

		// Read the SMTP password directly from the Zabbix database (credentials from this file).
		'db_fallback' => true,
		'zabbix_web_conf' => '/etc/zabbix/web/zabbix.conf.php',

		// Telegram.
		'telegram_api_url' => 'https://api.telegram.org',
		'telegram_max_length' => 4096,

		// Technical name of the Zabbix host with the template zabbix/zbx_template_custom_reports.yaml: the cron sender
		// pushes its status there (history.push), so that Zabbix raises a problem if delivery fails or cron stops.
		'zabbix_status_host' => '',

		// HTTP proxy for Telegram, if the media type does not define one.
		'proxy' => '',

		// PHP CLI binary used in the cron job created from the UI; empty = autodetect.
		'php_binary' => '',

		// "crontab" command used by the "Create cron job" button; empty = autodetect.
		'crontab_binary' => ''
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
	 *
	 * The same value must be used by the frontend (it shows the timezone in the forms) and by the cron sender.
	 * The PHP default cannot be used: the frontend switches it to the timezone of the logged-in user, and the CLI
	 * reads its own php.ini. Without a valid "timezone" in conf/config.php both use UTC (see timezoneProblem()).
	 */
	public static function timezone(): string {
		return self::timezoneProblem() === null ? self::get()['timezone'] : 'UTC';
	}

	/**
	 * @return string|null  null if "timezone" is set and valid, "not_set" or "invalid" otherwise.
	 */
	public static function timezoneProblem(): ?string {
		$timezone = (string) self::get()['timezone'];

		if ($timezone === '') {
			return 'not_set';
		}

		return in_array($timezone, timezone_identifiers_list(), true) ? null : 'invalid';
	}
}
