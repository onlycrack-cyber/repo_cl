<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


/**
 * Layout for file downloads (CSV, HTML, printable HTML). Used by an action without a view, so $data is the
 * controller data: main_block, content_type, disposition, filename.
 *
 * @var CView $this
 * @var array $data
 */

$filename = str_replace(['"', "\r", "\n", '/', '\\'], '', $data['filename'] ?? ($data['page']['file'] ?? 'report.txt'));

header('Content-Type: '.($data['content_type'] ?? 'application/octet-stream'));
header('Content-Disposition: '.(($data['disposition'] ?? '') === 'inline' ? 'inline' : 'attachment').
	'; filename="'.$filename.'"'
);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

echo $data['main_block'];
