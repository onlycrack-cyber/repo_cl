<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

/**
 * Translation of the module texts: the web interface and the reports (e-mail, PDF, CSV, Telegram).
 *
 * Zabbix has no translation catalogs for modules, so the module keeps its own dictionaries in lib/lang/<lang>.php
 * (English source text => translation). Config "language": "ru" (default), "en", or "auto" (the language of the
 * Zabbix user in the web interface, English in the cron sender). Texts missing from the dictionary go through the
 * Zabbix translation (gettext), so words like "Filter" or "Hosts" still follow the Zabbix language.
 */
class I18n {

	private static ?string $lang = null;
	private static ?array $dictionary = null;

	public static function lang(): string {
		if (self::$lang === null) {
			$lang = (string) (ModuleConfig::get()['language'] ?? 'ru');

			if ($lang === 'auto') {
				$user_lang = class_exists('CWebUser', false) && isset(\CWebUser::$data['lang'])
					? (string) \CWebUser::$data['lang']
					: '';
				$lang = strncmp($user_lang, 'ru', 2) == 0 ? 'ru' : 'en';
			}

			self::$lang = in_array($lang, ['ru', 'en'], true) ? $lang : 'en';
		}

		return self::$lang;
	}

	/**
	 * For tests and for switching the language at runtime.
	 */
	public static function setLang(?string $lang): void {
		self::$lang = $lang;
		self::$dictionary = null;
	}

	private static function dictionary(): array {
		if (self::$dictionary === null) {
			$file = __DIR__.'/lang/'.self::lang().'.php';
			self::$dictionary = self::lang() !== 'en' && is_file($file) ? (array) include $file : [];
		}

		return self::$dictionary;
	}

	public static function t(string $text): string {
		$dictionary = self::dictionary();

		if (array_key_exists($text, $dictionary)) {
			return is_array($dictionary[$text]) ? $dictionary[$text][0] : $dictionary[$text];
		}

		return function_exists('_') && $text !== '' ? \_($text) : $text;
	}

	/**
	 * Translated format with arguments: s('Daily at %1$s', '09:00').
	 */
	public static function s(string $format, ...$args): string {
		return vsprintf(self::t($format), $args);
	}

	/**
	 * Plural forms: n('%1$s user', '%1$s users', $count, ...$args). The dictionary keeps the forms of the
	 * language under "singular|plural" (Russian: one, few, many).
	 */
	public static function n(string $singular, string $plural, int $count, ...$args): string {
		$dictionary = self::dictionary();
		$key = $singular.'|'.$plural;
		$args = $args ?: [$count];

		if (array_key_exists($key, $dictionary) && is_array($dictionary[$key])) {
			return vsprintf($dictionary[$key][self::pluralIndex($count)], $args);
		}

		return vsprintf($count == 1 ? $singular : $plural, $args);
	}

	private static function pluralIndex(int $count): int {
		if (self::lang() !== 'ru') {
			return $count == 1 ? 0 : 1;
		}

		$n = abs($count);

		if ($n % 10 == 1 && $n % 100 != 11) {
			return 0;
		}

		return ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 12 || $n % 100 > 14)) ? 1 : 2;
	}
}
