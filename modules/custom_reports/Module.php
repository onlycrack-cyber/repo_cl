<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
**
** This program is free software: you can redistribute it and/or modify it under the terms of
** the GNU Affero General Public License as published by the Free Software Foundation, version 3.
*/


namespace Modules\CustomReports;

use APP;
use CMenuItem;
use Modules\CustomReports\Lib\I18n;
use Zabbix\Core\CModule;

require_once __DIR__.'/lib/i18n_functions.php';

/**
 * Module entry point: registers the menu item under "Reports".
 */
class Module extends CModule {

	public function init(): void {
		APP::Component()->get('menu.main')
			->findOrAdd(_('Reports'))
			->getSubmenu()
			->add(
				(new CMenuItem(I18n::t('Custom Problem Reports')))
					->setAction('customreports.dashboard')
					->setAliases([
						'customreports.changes',
						'customreports.schedule.list',
						'customreports.schedule.edit',
						'customreports.schedule.history'
					])
			);
	}
}
