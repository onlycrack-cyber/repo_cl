<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS: unit tests (no Zabbix needed).
*/

// Module classes: Modules\CustomReports\Lib\Transport\X -> lib/transport/X.php.
spl_autoload_register(static function (string $class): void {
	$prefix = 'Modules\\CustomReports\\';

	if (strncmp($class, $prefix, strlen($prefix)) != 0) {
		return;
	}

	$parts = explode('\\', substr($class, strlen($prefix)));
	$name = array_pop($parts);
	$file = dirname(__DIR__).'/'.($parts ? strtolower(implode('/', $parts)).'/' : '').$name.'.php';

	if (is_file($file)) {
		require $file;
	}
});

require __DIR__.'/FakeApi.php';
require __DIR__.'/TestCase.php';

date_default_timezone_set('UTC');

// Texts of the reports in English: the tests compare them.
Modules\CustomReports\Lib\I18n::setLang('en');
