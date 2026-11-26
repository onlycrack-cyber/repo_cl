<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
**
** Short translation functions for views and controllers (see Modules\CustomReports\Lib\I18n).
*/


use Modules\CustomReports\Lib\I18n;

if (!function_exists('cr_t')) {
	function cr_t(string $text): string {
		return I18n::t($text);
	}

	function cr_s(string $format, ...$args): string {
		return I18n::s($format, ...$args);
	}

	function cr_n(string $singular, string $plural, int $count, ...$args): string {
		return I18n::n($singular, $plural, $count, ...$args);
	}
}
