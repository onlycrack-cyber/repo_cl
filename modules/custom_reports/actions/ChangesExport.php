<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Actions;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use Exception;
use Modules\CustomReports\Lib\ChangeRenderer;
use Modules\CustomReports\Lib\FrontendHelper;
use Modules\CustomReports\Lib\ModuleConfig;
use Modules\CustomReports\Lib\PdfConverter;
use Modules\CustomReports\Lib\ReportRenderer;

/**
 * Export of the "Changes" tab with its current filter: CSV, HTML, PDF or printable HTML.
 */
class ChangesExport extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput(['format' => 'required|in csv,html,pdf,print']);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$result = FrontendHelper::buildChanges(FrontendHelper::getChangesFilter());

		if ($result['changes'] === null) {
			error($result['error']);
			$this->setResponse(new CControllerResponseFatal());

			return;
		}

		$changes = $result['changes'];
		$title = cr_t('Changes');
		$format = $this->getInput('format');
		$name = ReportRenderer::fileName('problem_changes', $format === 'csv' ? 'csv' : ($format === 'pdf' ? 'pdf' : 'html'),
			time()
		);

		if ($format === 'csv') {
			$data = ['main_block' => ChangeRenderer::renderCsv($changes, $result['meta']),
				'content_type' => 'text/csv; charset=UTF-8', 'disposition' => 'attachment'
			];
		}
		elseif ($format === 'pdf') {
			try {
				$pdf = (new PdfConverter(ModuleConfig::get()))->convert(
					ChangeRenderer::renderHtml($changes, $result['meta'], $title)
				);
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
				'main_block' => ChangeRenderer::renderHtml($changes, $result['meta'], $title, $format === 'print'),
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
