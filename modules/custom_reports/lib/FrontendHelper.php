<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

use CLink;
use CList;
use CProfile;
use CTag;
use CUrl;
use CWebUser;

/**
 * Helpers that depend on the Zabbix frontend (never loaded by the CLI sender).
 */
class FrontendHelper {

	private const PROFILE_PREFIX = 'web.customreports.filter.';

	/**
	 * Saves the dashboard filter into the user profile.
	 */
	public static function updateFilter(array $filter): void {
		$filter = ReportBuilder::normalizeFilter($filter);

		CProfile::updateArray(self::PROFILE_PREFIX.'groupids', $filter['groupids'], PROFILE_TYPE_ID);
		CProfile::updateArray(self::PROFILE_PREFIX.'hostids', $filter['hostids'], PROFILE_TYPE_ID);
		CProfile::updateArray(self::PROFILE_PREFIX.'severities', $filter['severities'], PROFILE_TYPE_INT);
		CProfile::update(self::PROFILE_PREFIX.'period', $filter['period'], PROFILE_TYPE_STR);
		CProfile::update(self::PROFILE_PREFIX.'show_suppressed', $filter['show_suppressed'], PROFILE_TYPE_INT);
		CProfile::update(self::PROFILE_PREFIX.'problem_name', $filter['problem_name'], PROFILE_TYPE_STR);
		CProfile::update(self::PROFILE_PREFIX.'acknowledged', $filter['acknowledged'], PROFILE_TYPE_INT);
	}

	/**
	 * Saves the sort column of the problem table (kept separately from the filter, survives "Reset").
	 */
	public static function updateSort(string $sortfield, string $sortorder): void {
		CProfile::update('web.customreports.sort', $sortfield, PROFILE_TYPE_STR);
		CProfile::update('web.customreports.sortorder', $sortorder, PROFILE_TYPE_STR);
	}

	public static function resetFilter(): void {
		foreach (['groupids', 'hostids', 'severities', 'period', 'show_suppressed', 'problem_name', 'acknowledged'] as $key) {
			CProfile::deleteIdx(self::PROFILE_PREFIX.$key);
		}
	}

	public static function getFilter(): array {
		return ReportBuilder::normalizeFilter([
			'groupids' => CProfile::getArray(self::PROFILE_PREFIX.'groupids', []),
			'hostids' => CProfile::getArray(self::PROFILE_PREFIX.'hostids', []),
			'severities' => CProfile::getArray(self::PROFILE_PREFIX.'severities', []),
			'period' => CProfile::get(self::PROFILE_PREFIX.'period', Period::ALL),
			'show_suppressed' => CProfile::get(self::PROFILE_PREFIX.'show_suppressed', 0),
			'problem_name' => CProfile::get(self::PROFILE_PREFIX.'problem_name', ''),
			'acknowledged' => CProfile::get(self::PROFILE_PREFIX.'acknowledged', ReportBuilder::ACK_ALL),
			'sortfield' => CProfile::get('web.customreports.sort', ReportBuilder::SORT_SEVERITY),
			'sortorder' => CProfile::get('web.customreports.sortorder', ReportBuilder::SORT_DESC)
		]);
	}

	public static function createLogger(): Logger {
		$config = ModuleConfig::get();

		return new Logger($config['log_file'], $config['log_level']);
	}

	public static function createService(): ReportService {
		return new ReportService(new FrontendApiClient(), self::createLogger(), ModuleConfig::get(),
			[self::class, 'getMediaTypePassword']
		);
	}

	/**
	 * The API never returns media type passwords; the frontend reads it from its own database connection.
	 */
	public static function getMediaTypePassword(string $mediatypeid): ?string {
		$row = DBfetch(DBselect('SELECT mt.passwd FROM media_type mt WHERE mt.mediatypeid='.zbx_dbstr($mediatypeid)));

		return $row ? (string) $row['passwd'] : null;
	}

	/**
	 * Active media of the current user: mediatypeid => sendto[].
	 */
	public static function getCurrentUserMedia(): array {
		$result = [];
		$medias = DBselect(
			'SELECT m.mediatypeid,m.sendto'.
			' FROM media m'.
			' WHERE m.userid='.zbx_dbstr(CWebUser::$data['userid']).
				' AND m.active='.MEDIA_STATUS_ACTIVE
		);

		while ($media = DBfetch($medias)) {
			foreach (RecipientResolver::normalizeSendto($media['sendto']) as $sendto) {
				$result[$media['mediatypeid']][] = $sendto;
			}
		}

		return $result;
	}

	/**
	 * Tab navigation between the dashboard and the schedule list.
	 */
	public static function makeTabs(string $active, bool $show_schedules): CTag {
		$tabs = [
			'customreports.dashboard' => _('Report dashboard'),
			'customreports.schedule.list' => _('Schedules and delivery')
		];

		if (!$show_schedules) {
			unset($tabs['customreports.schedule.list']);
		}

		$list = (new CList())->addClass('cr-tabs');

		foreach ($tabs as $action => $label) {
			$link = new CLink($label, (new CUrl('zabbix.php'))->setArgument('action', $action)->getUrl());

			if ($action === $active) {
				$link->addClass('cr-tab-active');
			}

			$list->addItem($link);
		}

		return (new CTag('nav', true, $list))->setAttribute('aria-label', _('Report sections'));
	}
}
