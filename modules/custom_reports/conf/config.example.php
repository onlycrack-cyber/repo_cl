<?php
/*
** Custom Problem Reports: module configuration.
**
** Copy to conf/config.php and adjust. The file contains the API token, so restrict access:
**   chown root:www-data conf/config.php && chmod 640 conf/config.php
** Any key that is omitted takes the default value from lib/ModuleConfig.php.
*/

return [
	// Zabbix API used by bin/send_reports.php (cron). The report content is limited by the
	// permissions of the token owner, so use a dedicated user with read access to the needed host groups.
	// Recipients are resolved with user.get/usergroup.get and media types with mediatype.get,
	// which requires the Super admin user type.
	'api_url' => 'http://localhost/zabbix/api_jsonrpc.php',
	'api_token' => '',
	'verify_ssl' => true,
	'timeout' => 30,

	// Timezone of schedule times, e.g. "Europe/Moscow". Set it explicitly: the frontend and the cron job have
	// different PHP default timezones. Empty = UTC (a warning is shown on the "Schedules and delivery" tab).
	'timezone' => '',

	// Language of the module interface and of the reports (e-mail, PDF, CSV, Telegram):
	// "ru" (default), "en", or "auto" (follows the Zabbix user language in the web interface, English in cron).
	'language' => 'ru',

	// Schedules, delivery state and logs. Must be writable by the web server user and must NOT be
	// reachable over HTTP (empty = <module>/data, protected only by .htaccess on Apache).
	'data_dir' => '/var/lib/zabbix-custom-reports',
	'log_file' => '',
	'log_level' => 'info',

	// Link to the frontend in HTML reports, e.g. "https://zabbix.example.com/zabbix".
	'frontend_url' => '',

	'problem_limit' => 1000,

	// Events read for period analytics ("Period analytics" section), per period.
	'analytics_limit' => 10000,

	// Target times by severity in seconds (dashboard panel "Response and resolution times"): "ack" - an open problem
	// should be acknowledged within it, "resolve" - resolved within it. Severities: 5 Disaster, 4 High, 3 Average,
	// 2 Warning, 1 Information, 0 Not classified; a severity left out is not checked.
	'response_targets' => [
		5 => ['ack' => 900, 'resolve' => 4 * 3600],
		4 => ['ack' => 1800, 'resolve' => 8 * 3600],
		3 => ['ack' => 4 * 3600, 'resolve' => 3 * 86400],
		2 => ['ack' => 86400, 'resolve' => 7 * 86400]
	],

	// PDF reports: "auto" uses headless Chrome/Chromium (google-chrome, chromium) and falls back to wkhtmltopdf;
	// set an explicit binary if it is not in a standard location. "none" sends HTML instead of PDF.
	'pdf_converter' => 'auto',
	'chrome_binary' => '',
	'wkhtmltopdf_binary' => '',
	'pdf_timeout' => 60,
	'grace_period' => 3600,

	// AlertScriptsPath from zabbix_server.conf (for media types of type "Script").
	'alert_scripts_path' => '/usr/lib/zabbix/alertscripts',

	// Media types of type "Script" (e.g. a Telegram script): {ALERT.MESSAGE} gets the short summary of the report,
	// split into several calls if it is longer than script_message_max_length characters. script_message_format:
	// "text", or "html" if the script sends with parse_mode=HTML (tables then come as copyable <pre> blocks).
	// The report file (HTML/PDF/CSV, depending on the format of the schedule) is passed as {REPORT.FILE} in the media
	// type parameters and as the environment variables CR_REPORT_FILE, CR_REPORT_FILENAME, CR_REPORT_MIME.
	'script_message_max_length' => 4000,
	'script_message_format' => 'text',

	// Retries of a failed delivery: attempts after the first one and the pause before the first retry (seconds,
	// doubled for every next one). Wrong addresses and configuration errors (SMTP 5xx, Telegram 4xx) are not retried.
	// "Run now" / "Test" in the web interface retry once after 3 s at most.
	'send_retries' => 2,
	'send_retry_delay' => 15,

	// Telegram alert scripts with the bot token inside the script: give the module the same token, keyed by the
	// script name (or the media type name / ID). The message still goes through the script, and the module sends the
	// report file (HTML/PDF/CSV) to the same chat as a document. With 'messages' => true the module sends the
	// messages through the Bot API too (copyable tables, long reports in several messages); the script is not run.
	// The chat ID is the "Send to" of the user media.
	//   'script_telegram' => [
	//       'zbx_telegram_simple_a35.py' => '123456789:AAE...',
	//       'zbx_telegram_simple_b12.py' => ['token' => '987654321:AAF...', 'messages' => true]
	//   ],
	'script_telegram' => [],

	// Report scripts (ExternalScripts from zabbix_server.conf): executable files of this directory can be the data
	// source of a report. They run as the web server user (tab "Scripts", "Send now") and as the cron user
	// (schedules). Output: JSON, CSV or plain text (see README, "Reports from external scripts").
	'external_scripts_path' => '/usr/lib/zabbix/externalscripts',
	'script_timeout' => 60,
	'script_max_rows' => 5000,
	'script_max_output' => 10485760,

	// The Zabbix API does not return media type passwords. The CLI sender reads them from the database
	// with the credentials of the frontend config file; or set them explicitly by mediatypeid:
	// 'smtp_passwords' => ['1' => 'secret'],
	'smtp_passwords' => [],
	'db_fallback' => true,
	'zabbix_web_conf' => '/etc/zabbix/web/zabbix.conf.php',

	// Monitoring of the delivery by Zabbix itself: import zabbix/zbx_template_custom_reports.yaml, link it to a host
	// (e.g. "Zabbix server") and put the technical name of that host here. The API token user needs read-write
	// access to that host. Empty = do not send the status.
	'zabbix_status_host' => '',

	'telegram_api_url' => 'https://api.telegram.org',
	'telegram_max_length' => 4096,
	'proxy' => '',

	// PHP CLI binary for the cron job created from the module UI; empty = autodetect (/usr/bin/php).
	'php_binary' => '',

	// "crontab" command used by the "Create cron job" button; empty = autodetect (/usr/bin/crontab).
	'crontab_binary' => ''
];
