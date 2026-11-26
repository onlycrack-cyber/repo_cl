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
use Modules\CustomReports\Lib\ChangeAnalytics;
use Modules\CustomReports\Lib\FrontendHelper;
use Modules\CustomReports\Lib\PdfConverter;
use Modules\CustomReports\Lib\ModuleConfig;

/**
 * "Changes" tab: the selected period compared with the previous one.
 */
class Changes extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'filter_groupids' =>		'array_id',
			'filter_hostids' =>			'array_id',
			'filter_severities' =>		'array',
			'filter_problem_name' =>	'string',
			'filter_show_suppressed' =>	'in 0,1',
			'filter_tags' =>			'array',
			'filter_evaltype' =>		'in 0,2',
			'filter_period' =>			'in '.implode(',', ChangeAnalytics::PERIODS),
			'filter_date_from' =>		'string',
			'filter_date_to' =>			'string',
			'filter_grouping' =>		'in '.implode(',', ChangeAnalytics::GROUPINGS),
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
			FrontendHelper::updateChangesFilter([
				'groupids' => $this->getInput('filter_groupids', []),
				'hostids' => $this->getInput('filter_hostids', []),
				'severities' => $this->getInput('filter_severities', []),
				'problem_name' => $this->getInput('filter_problem_name', ''),
				'show_suppressed' => $this->getInput('filter_show_suppressed', 0),
				'tags' => $this->getInput('filter_tags', []),
				'evaltype' => $this->getInput('filter_evaltype', 0),
				'period' => $this->getInput('filter_period', ''),
				'date_from' => $this->getInput('filter_date_from', ''),
				'date_to' => $this->getInput('filter_date_to', ''),
				'grouping' => $this->getInput('filter_grouping', '')
			]);
		}
		elseif ($this->hasInput('filter_rst')) {
			FrontendHelper::resetChangesFilter();
		}

		$filter = FrontendHelper::getChangesFilter();
		$result = FrontendHelper::buildChanges($filter);

		$groups = $filter['groupids']
			? API::HostGroup()->get(['output' => ['groupid', 'name'], 'groupids' => $filter['groupids']])
			: [];
		$hosts = $filter['hostids']
			? API::Host()->get(['output' => ['hostid', 'name'], 'hostids' => $filter['hostids']])
			: [];

		$response = new CControllerResponseData([
			'filter' => $filter,
			'filter_groups' => CArrayHelper::renameObjectsKeys($groups, ['groupid' => 'id']),
			'filter_hosts' => CArrayHelper::renameObjectsKeys($hosts, ['hostid' => 'id']),
			'changes' => $result['changes'],
			'meta' => $result['meta'],
			'error' => $result['error'],
			'pdf_available' => (new PdfConverter(ModuleConfig::get()))->isAvailable(),
			'is_super_admin' => $this->getUserType() == USER_TYPE_SUPER_ADMIN
		]);
		$response->setTitle(cr_t('Changes'));
		$this->setResponse($response);
	}
}
