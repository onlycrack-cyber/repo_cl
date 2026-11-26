<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Actions;

/**
 * "Test to me": builds the report of a schedule with all its settings (filter, format, split, period analytics)
 * and delivers it only to the current user's own media of the schedule's media type.
 * The run is added to the delivery history, but does not change the status of the schedule.
 */
class ScheduleTest extends ScheduleRun {

	protected function isTest(): bool {
		return true;
	}
}
