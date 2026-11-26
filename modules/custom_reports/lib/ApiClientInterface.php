<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

/**
 * Abstraction over the Zabbix API.
 *
 * FrontendApiClient calls API::*() classes in the context of the logged-in user (permissions of
 * that user are applied), JsonRpcApiClient calls api_jsonrpc.php with an API token (CLI sender).
 */
interface ApiClientInterface {

	/**
	 * @param string $method  API method, e.g. "problem.get".
	 * @param array  $params
	 *
	 * @throws ApiException
	 *
	 * @return mixed
	 */
	public function call(string $method, array $params);
}
