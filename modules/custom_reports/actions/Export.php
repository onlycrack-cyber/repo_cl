<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Actions;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CSettingsHelper;
use Exception;
use Modules\CustomReports\Lib\FrontendApiClient;
use Modules\CustomReports\Lib\FrontendHelper;
use Modules\CustomReports\Lib\ModuleConfig;
use Modules\CustomReports\Lib\ReportBuilder;
use Modules\CustomReports\Lib\ReportRenderer;

/**
 * Export of the current dashboard report: CSV, HTML or printable HTML ("Save as PDF").
 */
class Export extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput(['format' => 'required|in csv,html,pdf']);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$limit = (int) CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT);

		try {
			$report = (new ReportBuilder(new FrontendApiClient(), $limit))->build(FrontendHelper::getFilter());
		}
		catch (Exception $e) {
			error($e->getMessage());
			$this->setResponse(new CControllerResponseFatal());

			return;
		}

		$title = _('Problem report');
		$format = $this->getInput('format');
		$options = ['frontend_url' => ModuleConfig::get()['frontend_url']];

		switch ($format) {
			case 'csv':
				$data = [
					'content' => ReportRenderer::renderCsv($report),
					'content_type' => 'text/csv; charset=UTF-8',
					'disposition' => 'attachment',
					'filename' => ReportRenderer::fileName('problem_report', 'csv', $report['generated'])
				];
				break;

			case 'html':
				$data = [
					'content' => ReportRenderer::renderHtml($report, $title, $options),
					'content_type' => 'text/html; charset=UTF-8',
					'disposition' => 'attachment',
					'filename' => ReportRenderer::fileName('problem_report', 'html', $report['generated'])
				];
				break;

			default:
				$data = [
					'content' => ReportRenderer::renderHtml($report, $title, $options + ['print' => true]),
					'content_type' => 'text/html; charset=UTF-8',
					'disposition' => 'inline',
					'filename' => ReportRenderer::fileName('problem_report', 'html', $report['generated'])
				];
		}

		$response = new CControllerResponseData($data);
		$response->setFileName($data['filename']);
		$this->setResponse($response);
	}
}
