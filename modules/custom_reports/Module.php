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
use Zabbix\Core\CModule;

/**
 * Module entry point: registers the menu item under "Reports".
 */
class Module extends CModule {

	public function init(): void {
		APP::Component()->get('menu.main')
			->findOrAdd(_('Reports'))
			->getSubmenu()
			->add(
				(new CMenuItem(_('Custom Problem Reports')))
					->setAction('customreports.dashboard')
					->setAliases([
						'customreports.schedule.list',
						'customreports.schedule.edit',
						'customreports.schedule.history'
					])
			);
	}
}
