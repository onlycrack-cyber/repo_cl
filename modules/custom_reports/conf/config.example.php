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

	// Timezone of schedule times (e.g. "Europe/Moscow"); empty = PHP default timezone.
	'timezone' => '',

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

	// PDF reports: "auto" uses headless Chrome/Chromium (google-chrome, chromium) and falls back to wkhtmltopdf;
	// set an explicit binary if it is not in a standard location. "none" sends HTML instead of PDF.
	'pdf_converter' => 'auto',
	'chrome_binary' => '',
	'wkhtmltopdf_binary' => '',
	'pdf_timeout' => 60,
	'grace_period' => 3600,

	// AlertScriptsPath from zabbix_server.conf (for media types of type "Script").
	'alert_scripts_path' => '/usr/lib/zabbix/alertscripts',

	// The Zabbix API does not return media type passwords. The CLI sender reads them from the database
	// with the credentials of the frontend config file; or set them explicitly by mediatypeid:
	// 'smtp_passwords' => ['1' => 'secret'],
	'smtp_passwords' => [],
	'db_fallback' => true,
	'zabbix_web_conf' => '/etc/zabbix/web/zabbix.conf.php',

	'telegram_api_url' => 'https://api.telegram.org',
	'telegram_max_length' => 4096,
	'proxy' => '',

	// PHP CLI binary for the cron job created from the module UI; empty = autodetect (/usr/bin/php).
	'php_binary' => '',

	// "crontab" command used by the "Create cron job" button; empty = autodetect (/usr/bin/crontab).
	'crontab_binary' => ''
];
