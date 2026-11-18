<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


/**
 * Raw body of an exported report; headers are sent by layout.customreports.download.
 *
 * @var CView $this
 * @var array $data
 */

echo $data['content'];
