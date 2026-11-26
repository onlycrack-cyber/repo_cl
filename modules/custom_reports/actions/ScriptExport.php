<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Actions;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use Exception;
use Modules\CustomReports\Lib\ModuleConfig;
use Modules\CustomReports\Lib\PdfConverter;
use Modules\CustomReports\Lib\ReportRenderer;
use Modules\CustomReports\Lib\ScriptReport;
use Modules\CustomReports\Lib\ScriptReportRenderer;

/**
 * Export of a script report ("Scripts" tab): runs the script again and returns CSV, HTML, PDF or printable HTML.
 * POST with the CSRF token of this action (checked by CController).
 */
class ScriptExport extends CController {

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'script' =>			'required|string',
			'script_params' =>	'string',
			'script_format' =>	'in '.implode(',', ScriptReport::FORMATS),
			'format' =>			'required|in csv,html,pdf,print'
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
		$result = Script::runScript([
			'script' => $this->getInput('script'),
			'script_params' => ScriptReport::normalizeParams($this->getInput('script_params', '')),
			'script_format' => $this->getInput('script_format', ScriptReport::FORMAT_AUTO)
		]);

		if ($result['report'] === null) {
			error($result['error']);
			$this->setResponse(new CControllerResponseFatal());

			return;
		}

		$report = $result['report'];
		$title = $report['title'] !== '' ? $report['title'] : $report['script'];
		$format = $this->getInput('format');
		$name = ReportRenderer::fileName($title, $format === 'csv' ? 'csv' : ($format === 'pdf' ? 'pdf' : 'html'),
			$report['generated']
		);

		if ($format === 'csv') {
			$data = ['main_block' => ScriptReportRenderer::renderCsv($report),
				'content_type' => 'text/csv; charset=UTF-8', 'disposition' => 'attachment'
			];
		}
		elseif ($format === 'pdf') {
			try {
				$pdf = (new PdfConverter(ModuleConfig::get()))->convert(ScriptReportRenderer::renderHtml($report, $title));
			}
			catch (Exception $e) {
				error($e->getMessage());
				$this->setResponse(new CControllerResponseFatal());

				return;
			}

			$data = ['main_block' => $pdf, 'content_type' => 'application/pdf', 'disposition' => 'attachment'];
		}
		else {
			$data = [
				'main_block' => ScriptReportRenderer::renderHtml($report, $title, ['print' => $format === 'print']),
				'content_type' => 'text/html; charset=UTF-8',
				'disposition' => $format === 'print' ? 'inline' : 'attachment'
			];
		}

		// No view: the controller data goes to the layout as is.
		$response = new CControllerResponseData($data + ['filename' => $name]);
		$response->setFileName($name);
		$this->setResponse($response);
	}
}
