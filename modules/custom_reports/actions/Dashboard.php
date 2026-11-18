<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Actions;

use API;
use CArrayHelper;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CSettingsHelper;
use Exception;
use Modules\CustomReports\Lib\Dispatcher;
use Modules\CustomReports\Lib\FrontendApiClient;
use Modules\CustomReports\Lib\FrontendHelper;
use Modules\CustomReports\Lib\ModuleConfig;
use Modules\CustomReports\Lib\PdfConverter;
use Modules\CustomReports\Lib\Period;
use Modules\CustomReports\Lib\PeriodAnalytics;
use Modules\CustomReports\Lib\ReportBuilder;

/**
 * Tab 1: interactive report dashboard.
 */
class Dashboard extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'filter_groupids' =>		'array_id',
			'filter_hostids' =>			'array_id',
			'filter_severities' =>		'array',
			'filter_period' =>			'in '.implode(',', array_keys(Period::LABELS)),
			'filter_show_suppressed' =>	'in 0,1',
			'filter_problem_name' =>	'string',
			'filter_acknowledged' =>	'in '.ReportBuilder::ACK_ALL.','.ReportBuilder::ACK_NO.','.ReportBuilder::ACK_YES,
			'filter_analytics' =>		'in '.implode(',', [PeriodAnalytics::PERIOD_NONE, PeriodAnalytics::PERIOD_24H,
				PeriodAnalytics::PERIOD_7D, PeriodAnalytics::PERIOD_30D
			]),
			'sort' =>					'in '.implode(',', ReportBuilder::SORT_FIELDS),
			'sortorder' =>				'in '.ReportBuilder::SORT_ASC.','.ReportBuilder::SORT_DESC,
			'filter_set' =>				'in 1',
			'filter_rst' =>				'in 1'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		if ($this->hasInput('filter_set')) {
			FrontendHelper::updateFilter([
				'groupids' => $this->getInput('filter_groupids', []),
				'hostids' => $this->getInput('filter_hostids', []),
				'severities' => $this->getInput('filter_severities', []),
				'period' => $this->getInput('filter_period', Period::ALL),
				'show_suppressed' => $this->getInput('filter_show_suppressed', 0),
				'problem_name' => $this->getInput('filter_problem_name', ''),
				'acknowledged' => $this->getInput('filter_acknowledged', ReportBuilder::ACK_ALL),
				'analytics' => $this->getInput('filter_analytics', PeriodAnalytics::PERIOD_7D)
			]);
		}
		elseif ($this->hasInput('filter_rst')) {
			FrontendHelper::resetFilter();
		}

		if ($this->hasInput('sort')) {
			FrontendHelper::updateSort($this->getInput('sort'), $this->getInput('sortorder', ReportBuilder::SORT_DESC));
		}

		$filter = FrontendHelper::getFilter();
		$limit = (int) CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT);
		$error = null;

		// Problems are read with the permissions of the current user.
		try {
			$report = (new ReportBuilder(new FrontendApiClient(), $limit))->build($filter);
		}
		catch (Exception $e) {
			$report = ReportBuilder::emptyReport($filter);
			$error = $e->getMessage();
		}

		$groups = $filter['groupids']
			? API::HostGroup()->get([
				'output' => ['groupid', 'name'],
				'groupids' => $filter['groupids']
			])
			: [];

		$hosts = $filter['hostids']
			? API::Host()->get([
				'output' => ['hostid', 'name'],
				'hostids' => $filter['hostids']
			])
			: [];

		$can_send = $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN;

		$data = [
			'filter' => $filter,
			'filter_groups' => CArrayHelper::renameObjectsKeys($groups, ['groupid' => 'id']),
			'filter_hosts' => CArrayHelper::renameObjectsKeys($hosts, ['hostid' => 'id']),
			'report' => $report,
			'error' => $error,
			'can_send' => $can_send,
			'send_media_types' => $can_send ? $this->getSendMediaTypes() : [],
			'is_super_admin' => $this->getUserType() == USER_TYPE_SUPER_ADMIN,
			'pdf_available' => (new PdfConverter(ModuleConfig::get()))->isAvailable()
		];

		$response = new CControllerResponseData($data);
		$response->setTitle(_('Custom problem reports'));
		$this->setResponse($response);
	}

	/**
	 * Media types usable for a test delivery: active, supported, and the current user has an active media of them.
	 */
	private function getSendMediaTypes(): array {
		$user_media = FrontendHelper::getCurrentUserMedia();

		if (!$user_media) {
			return [];
		}

		$media_types = API::MediaType()->get([
			'output' => 'extend',
			'mediatypeids' => array_keys($user_media),
			'filter' => ['status' => MEDIA_TYPE_STATUS_ACTIVE]
		]);

		$result = [];

		foreach ($media_types as $media_type) {
			if (Dispatcher::getChannel($media_type) !== Dispatcher::CHANNEL_UNSUPPORTED) {
				$result[$media_type['mediatypeid']] = $media_type['name'];
			}
		}

		natcasesort($result);

		return $result;
	}
}
