<?php declare(strict_types = 0);

namespace Modules\CustomReports\Tests;

use Modules\CustomReports\Lib\I18n;
use PHPUnit\Framework\TestCase as BaseTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every text of the module passed to cr_t / cr_s / cr_n / I18n::t / s / n as a literal has a Russian translation.
 */
class TranslationTest extends BaseTestCase {

	public function testEveryTextIsTranslated(): void {
		$dir = dirname(__DIR__);
		$dictionary = include $dir.'/lib/lang/ru.php';
		$missing = [];

		foreach (self::keys($dir) as $key => $where) {
			if (!array_key_exists($key, $dictionary)) {
				$missing[] = $key.' ('.$where.')';
			}
		}

		$this->assertSame([], $missing, 'Add these texts to lib/lang/ru.php');
	}

	public function testPluralForms(): void {
		foreach (include dirname(__DIR__).'/lib/lang/ru.php' as $key => $value) {
			if (is_array($value)) {
				$this->assertCount(3, $value, 'Russian plural needs 3 forms: '.$key);
			}
		}

		I18n::setLang('ru');

		try {
			$this->assertSame('Нерешённых проблем: 19, открыты весь период: 6',
				I18n::s('Unresolved problems: %1$s, open the whole period: %2$s', 19, 6)
			);
		}
		finally {
			I18n::setLang('en');
		}
	}

	/**
	 * @return array  text => "file:line"
	 */
	private static function keys(string $dir): array {
		$keys = [];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));

		foreach ($files as $file) {
			$path = (string) $file;

			if (substr($path, -4) !== '.php' || preg_match('#/(lang|tests|vendor)/#', $path)) {
				continue;
			}

			$tokens = token_get_all(file_get_contents($path));
			$count = count($tokens);

			for ($i = 0; $i < $count; $i++) {
				if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING) {
					continue;
				}

				$name = $tokens[$i][1];
				$static = $i > 1 && is_array($tokens[$i - 1]) && $tokens[$i - 1][0] === T_DOUBLE_COLON
					&& is_array($tokens[$i - 2]) && $tokens[$i - 2][1] === 'I18n';

				if (!in_array($name, ['cr_t', 'cr_s', 'cr_n'], true) && !($static && in_array($name, ['t', 's', 'n'], true))) {
					continue;
				}

				$j = $i + 1;

				while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
					$j++;
				}

				if ($tokens[$j] !== '(') {
					continue;
				}

				// Constant string arguments (concatenation of literals); null - built at runtime.
				$args = [];
				$current = '';
				$constant = true;
				$depth = 0;

				for ($j++; $j < $count; $j++) {
					$token = $tokens[$j];

					if ($token === '(' || $token === '[') {
						$depth++;
					}

					if ($token === ')' || $token === ']') {
						if ($depth == 0) {
							$args[] = $constant ? $current : null;
							break;
						}

						$depth--;
					}

					if ($token === ',' && $depth == 0) {
						$args[] = $constant ? $current : null;
						$current = '';
						$constant = true;

						if (count($args) >= 2) {
							break;
						}

						continue;
					}

					if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
						$literal = substr($token[1], 1, -1);
						// Single quotes: only \' and \\ are escapes.
						$current .= $token[1][0] === "'"
							? str_replace(["\\'", '\\\\'], ["'", '\\'], $literal)
							: stripcslashes($literal);
					}
					elseif (!(is_array($token) && $token[0] === T_WHITESPACE) && $token !== '.') {
						$constant = false;
					}
				}

				$plural = $name === 'cr_n' || $name === 'n';
				$key = $plural
					? (($args[0] ?? null) !== null && ($args[1] ?? null) !== null ? $args[0].'|'.$args[1] : null)
					: ($args[0] ?? null);

				if ($key !== null) {
					$keys[$key] = basename($path).':'.$tokens[$i][2];
				}
			}
		}

		return $keys;
	}
}
