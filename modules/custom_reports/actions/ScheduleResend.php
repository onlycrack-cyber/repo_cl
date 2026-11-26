<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Actions;

/**
 * "Resend" in the delivery history: builds the report again and sends it only to the recipients (and report parts)
 * that failed in the chosen run. The resend is a run of its own in the history and sets the status of the schedule.
 */
class ScheduleResend extends ScheduleRun {

	protected function isResend(): bool {
		return true;
	}
}
