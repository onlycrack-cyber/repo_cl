<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

use RuntimeException;

/**
 * Converts an HTML report into PDF with an external renderer:
 * headless Chrome/Chromium (preferred, the same browser zabbix_web_service uses) or wkhtmltopdf.
 *
 * Config keys: pdf_converter ("auto", "chrome", "wkhtmltopdf", "none"), chrome_binary, wkhtmltopdf_binary,
 * pdf_timeout.
 */
class PdfConverter {

	private const CHROME_PATHS = ['/usr/bin/google-chrome', '/usr/bin/google-chrome-stable', '/usr/bin/chromium',
		'/usr/bin/chromium-browser', '/snap/bin/chromium'
	];
	private const WKHTMLTOPDF_PATHS = ['/usr/bin/wkhtmltopdf', '/usr/local/bin/wkhtmltopdf'];

	private array $config;

	public function __construct(array $config) {
		$this->config = $config;
	}

	/**
	 * @return array|null  [type, binary] of the converter to use, null if none is available.
	 */
	public function detect(): ?array {
		$mode = $this->config['pdf_converter'] ?? 'auto';

		if ($mode === 'none') {
			return null;
		}

		if ($mode === 'auto' || $mode === 'chrome') {
			$binary = self::findBinary($this->config['chrome_binary'] ?? '', self::CHROME_PATHS);

			if ($binary !== null) {
				return ['chrome', $binary];
			}
		}

		if ($mode === 'auto' || $mode === 'wkhtmltopdf') {
			$binary = self::findBinary($this->config['wkhtmltopdf_binary'] ?? '', self::WKHTMLTOPDF_PATHS);

			if ($binary !== null) {
				return ['wkhtmltopdf', $binary];
			}
		}

		return null;
	}

	public function isAvailable(): bool {
		return $this->detect() !== null;
	}

	/**
	 * @throws RuntimeException
	 */
	public function convert(string $html): string {
		$converter = $this->detect();

		if ($converter === null) {
			throw new RuntimeException('No PDF converter: install Chromium/Google Chrome or wkhtmltopdf, '.
				'or set "chrome_binary" / "wkhtmltopdf_binary" in conf/config.php.'
			);
		}

		[$type, $binary] = $converter;
		$timeout = (int) ($this->config['pdf_timeout'] ?? 60);
		$work_dir = rtrim(sys_get_temp_dir(), '/').'/crpdf_'.bin2hex(random_bytes(8));

		if (!@mkdir($work_dir, 0700)) {
			throw new RuntimeException('Cannot create a temporary directory for PDF conversion.');
		}

		$html_file = $work_dir.'/report.html';
		$pdf_file = $work_dir.'/report.pdf';

		try {
			file_put_contents($html_file, $html);

			if ($type === 'chrome') {
				$command = [$binary, '--headless', '--disable-gpu', '--disable-extensions',
					'--disable-background-networking', '--no-first-run', '--user-data-dir='.$work_dir.'/profile',
					'--no-pdf-header-footer', '--print-to-pdf-no-header', '--print-to-pdf='.$pdf_file,
					'file://'.$html_file
				];

				// Chrome refuses to run as root with the sandbox enabled.
				if (function_exists('posix_geteuid') && posix_geteuid() == 0) {
					array_splice($command, 1, 0, ['--no-sandbox']);
				}

				$env = ['HOME' => $work_dir, 'PATH' => '/usr/local/bin:/usr/bin:/bin'];
			}
			else {
				$command = [$binary, '--quiet', '--encoding', 'utf-8', '--orientation', 'Landscape', $html_file,
					$pdf_file
				];
				$env = ['HOME' => $work_dir, 'PATH' => '/usr/local/bin:/usr/bin:/bin'];
			}

			[$exit_code, $output] = ProcessRunner::run($command, $timeout, $env);
			$pdf = is_file($pdf_file) ? (string) file_get_contents($pdf_file) : '';

			if (strncmp($pdf, '%PDF', 4) != 0) {
				throw new RuntimeException(sprintf('PDF conversion with %s failed (exit code %d): %s', $type,
					$exit_code, trim(substr($output, -500))
				));
			}

			return $pdf;
		}
		finally {
			self::removeDir($work_dir);
		}
	}

	private static function findBinary(string $configured, array $paths): ?string {
		foreach ($configured !== '' ? [$configured] : $paths as $path) {
			if (is_file($path) && is_executable($path)) {
				return $path;
			}
		}

		return null;
	}

	private static function removeDir(string $dir): void {
		if (!is_dir($dir) || strpos(basename($dir), 'crpdf_') !== 0) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($items as $item) {
			$item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
		}

		@rmdir($dir);
	}
}
