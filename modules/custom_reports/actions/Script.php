<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Actions;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CCsrfTokenHelper;
use Exception;
use Modules\CustomReports\Lib\ModuleConfig;
use Modules\CustomReports\Lib\ScriptReport;

/**
 * "Scripts" tab: runs an external script of "external_scripts_path" and shows its output as a report.
 *
 * Only Super admins: the script runs on the frontend server as the web server user. Running needs a POST with the
 * CSRF token; a GET only shows the form.
 */
class Script extends CController {

	protected function init(): void {
		// The form is shown on GET; the CSRF token is checked in doAction() when the script is run.
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'script' =>				'string',
			'script_params' =>		'string',
			'script_format' =>		'in '.implode(',', ScriptReport::FORMATS),
			'run' =>				'in 1',
			CCsrfTokenHelper::CSRF_TOKEN_NAME => 'string'
		]);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction(): void {
		$config = ModuleConfig::get();
		$scripts = ScriptReport::listScripts($config);
		$script = $this->getInput('script', '');

		$data = [
			'scripts' => $scripts,
			'scripts_path' => (string) $config['external_scripts_path'],
			'user' => ScriptReport::currentUser(),
			'script' => $script !== '' ? $script : (string) reset($scripts),
			'script_params' => ScriptReport::normalizeParams($this->getInput('script_params', '')),
			'script_format' => $this->getInput('script_format', ScriptReport::FORMAT_AUTO),
			'report' => null,
			'error' => null
		];

		// A link (GET) never runs a script: it only fills the form.
		if ($this->hasInput('run') && $_SERVER['REQUEST_METHOD'] === 'POST') {
			if (!CCsrfTokenHelper::check($this->getInput(CCsrfTokenHelper::CSRF_TOKEN_NAME, ''), 'customreports.script')) {
				$data['error'] = _('Operation cannot be performed due to unauthorized request.');
			}
			else {
				$data = array_replace($data, self::runScript($data));
			}
		}

		$response = new CControllerResponseData($data);
		$response->setTitle(cr_t('Scripts'));
		$this->setResponse($response);
	}

	/**
	 * @return array  ['report' => array|null, 'error' => string|null]
	 */
	public static function runScript(array $data): array {
		try {
			$report = ScriptReport::build(ModuleConfig::get(), $data['script'], $data['script_params'],
				$data['script_format'], ScriptReport::macros(time(), 86400)
			);

			return ['report' => $report, 'error' => null];
		}
		catch (Exception $e) {
			return ['report' => null, 'error' => $e->getMessage()];
		}
	}
}
