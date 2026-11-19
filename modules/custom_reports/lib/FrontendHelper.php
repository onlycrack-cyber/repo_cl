<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

use CLink;
use CList;
use CSpan;
use CProfile;
use CSeverityHelper;
use CTag;
use CUrl;
use CWebUser;

/**
 * Helpers that depend on the Zabbix frontend (never loaded by the CLI sender).
 */
class FrontendHelper {

	/**
	 * CSeverityHelper::getSeverities() with the default (English) severity names translated; custom names
	 * from Administration -> General are kept as they are.
	 */
	public static function getSeverities(): array {
		$severities = CSeverityHelper::getSeverities();

		foreach ($severities as &$severity) {
			$default = Severity::NAMES[(int) $severity['value']] ?? null;

			if ($default !== null && ($severity['label'] === $default || $severity['label'] === _($default))) {
				$severity['label'] = I18n::t($default);
			}
		}
		unset($severity);

		return $severities;
	}

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
		CProfile::update(self::PROFILE_PREFIX.'analytics', $filter['analytics'], PROFILE_TYPE_STR);
	}

	/**
	 * Saves the sort column of the problem table (kept separately from the filter, survives "Reset").
	 */
	public static function updateSort(string $sortfield, string $sortorder): void {
		CProfile::update('web.customreports.sort', $sortfield, PROFILE_TYPE_STR);
		CProfile::update('web.customreports.sortorder', $sortorder, PROFILE_TYPE_STR);
	}

	public static function resetFilter(): void {
		foreach (['groupids', 'hostids', 'severities', 'period', 'show_suppressed', 'problem_name', 'acknowledged',
				'analytics'] as $key) {
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
			// 24 hours by default: the dashboard is opened often, 7/30 days read much more history.
			'analytics' => CProfile::get(self::PROFILE_PREFIX.'analytics', PeriodAnalytics::PERIOD_24H),
			'sortfield' => CProfile::get('web.customreports.sort', ReportBuilder::SORT_SEVERITY),
			'sortorder' => CProfile::get('web.customreports.sortorder', ReportBuilder::SORT_DESC)
		]);
	}

	private const CHANGES_PREFIX = 'web.customreports.changes.';

	/**
	 * Filter of the "Changes" tab (stored separately from the dashboard filter).
	 */
	public static function updateChangesFilter(array $filter): void {
		$filter = ChangeAnalytics::normalizeFilter($filter);

		CProfile::updateArray(self::CHANGES_PREFIX.'groupids', $filter['groupids'], PROFILE_TYPE_ID);
		CProfile::updateArray(self::CHANGES_PREFIX.'hostids', $filter['hostids'], PROFILE_TYPE_ID);
		CProfile::updateArray(self::CHANGES_PREFIX.'severities', $filter['severities'], PROFILE_TYPE_INT);

		foreach (['problem_name', 'period', 'date_from', 'date_to', 'grouping'] as $key) {
			CProfile::update(self::CHANGES_PREFIX.$key, $filter[$key], PROFILE_TYPE_STR);
		}
	}

	public static function resetChangesFilter(): void {
		foreach (['groupids', 'hostids', 'severities', 'problem_name', 'period', 'date_from', 'date_to',
				'grouping'] as $key) {
			CProfile::deleteIdx(self::CHANGES_PREFIX.$key);
		}
	}

	public static function getChangesFilter(): array {
		return ChangeAnalytics::normalizeFilter([
			'groupids' => CProfile::getArray(self::CHANGES_PREFIX.'groupids', []),
			'hostids' => CProfile::getArray(self::CHANGES_PREFIX.'hostids', []),
			'severities' => CProfile::getArray(self::CHANGES_PREFIX.'severities', []),
			'problem_name' => CProfile::get(self::CHANGES_PREFIX.'problem_name', ''),
			'period' => CProfile::get(self::CHANGES_PREFIX.'period', PeriodAnalytics::PERIOD_7D),
			'date_from' => CProfile::get(self::CHANGES_PREFIX.'date_from', ''),
			'date_to' => CProfile::get(self::CHANGES_PREFIX.'date_to', ''),
			'grouping' => CProfile::get(self::CHANGES_PREFIX.'grouping', ChangeAnalytics::GROUP_BY_GROUP)
		]);
	}

	/**
	 * Builds the "Changes" analysis for the filter with the permissions of the current user.
	 *
	 * @return array  ['changes' => array|null, 'meta' => severity names/colors, 'error' => string|null]
	 */
	public static function buildChanges(array $filter): array {
		$range = ChangeAnalytics::resolveRange($filter, time());
		$api = new FrontendApiClient();
		$meta = (new ReportBuilder($api))->getSeverityMeta();

		try {
			$data = (new ChangeAnalytics($api, (int) ModuleConfig::get()['analytics_limit']))
				->collect($filter, $range['from'], $range['till']);

			return ['changes' => ChangeAnalytics::compute($data, $filter['grouping']), 'meta' => $meta,
				'error' => $range['error']
			];
		}
		catch (ApiException $e) {
			return ['changes' => null, 'meta' => $meta, 'error' => $e->getMessage()];
		}
	}

	public static function createLogger(): Logger {
		$config = ModuleConfig::get();

		return new Logger($config['log_file'], $config['log_level']);
	}

	public static function createService(): ReportService {
		$config = ModuleConfig::get();
		$config['send_retries'] = min(1, (int) $config['send_retries']);
		$config['send_retry_delay'] = min(3, (int) $config['send_retry_delay']);

		return new ReportService(new FrontendApiClient(), self::createLogger(), $config,
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
	/**
	 * Active media types that the module can deliver through: [mediatypeid => "name (channel)"].
	 */
	public static function getSupportedMediaTypes(): array {
		$media_types = \API::MediaType()->get([
			'output' => 'extend',
			'filter' => ['status' => MEDIA_TYPE_STATUS_ACTIVE]
		]);

		$result = [];

		foreach ($media_types as $media_type) {
			$channel = Dispatcher::getChannel($media_type);

			if ($channel !== Dispatcher::CHANNEL_UNSUPPORTED) {
				$result[$media_type['mediatypeid']] = $media_type['name'].' ('.$channel.')';
			}
		}

		natcasesort($result);

		return $result;
	}

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
	 * Colored label of a run status (ScheduleHelper::STATUS_*); "ok"/"error" of older versions are the same values.
	 */
	public static function makeStatus(?string $status): CSpan {
		switch ($status) {
			case ScheduleHelper::STATUS_OK:
				return (new CSpan(cr_t('OK')))->addClass(ZBX_STYLE_GREEN);

			case ScheduleHelper::STATUS_PARTIAL:
				return (new CSpan(cr_t('Partially')))->addClass(ZBX_STYLE_ORANGE);

			case ScheduleHelper::STATUS_ERROR:
				return (new CSpan(cr_t('Error')))->addClass(ZBX_STYLE_RED);

			case ScheduleHelper::STATUS_EMPTY:
				return (new CSpan(cr_t('Nothing to send')))->addClass(ZBX_STYLE_GREY);

			default:
				return (new CSpan(cr_t('Never')))->addClass(ZBX_STYLE_GREY);
		}
	}

	public static function triggerLabel(string $trigger): string {
		switch ($trigger) {
			case ScheduleHelper::RUN_SCHEDULE:
				return cr_t('Scheduled');

			case ScheduleHelper::RUN_TEST:
				return cr_t('Test');

			case ScheduleHelper::RUN_RESEND:
				return cr_t('Resend');

			default:
				return cr_t('Send now');
		}
	}

	/**
	 * Tab navigation between the dashboard and the schedule list.
	 */
	public static function makeTabs(string $active, bool $show_schedules): CTag {
		$tabs = [
			'customreports.dashboard' => cr_t('Report dashboard'),
			'customreports.changes' => cr_t('Changes'),
			'customreports.schedule.list' => cr_t('Schedules and delivery'),
			'customreports.script' => cr_t('Scripts')
		];

		// Schedules and scripts: Super admins only.
		if (!$show_schedules) {
			unset($tabs['customreports.schedule.list'], $tabs['customreports.script']);
		}

		$list = (new CList())->addClass('cr-tabs');

		foreach ($tabs as $action => $label) {
			$link = new CLink($label, (new CUrl('zabbix.php'))->setArgument('action', $action)->getUrl());

			if ($action === $active) {
				$link->addClass('cr-tab-active');
			}

			$list->addItem($link);
		}

		return (new CTag('nav', true, $list))->setAttribute('aria-label', cr_t('Report sections'));
	}
}
