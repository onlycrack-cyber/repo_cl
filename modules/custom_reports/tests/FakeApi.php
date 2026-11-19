<?php declare(strict_types = 0);

namespace Modules\CustomReports\Tests;

use Modules\CustomReports\Lib\ApiClientInterface;
use Modules\CustomReports\Lib\ApiException;

/**
 * Zabbix API for tests: a handler per method (callable(array $params) or a fixed result); calls are recorded.
 * A method without a handler fails like an API without permissions.
 */
class FakeApi implements ApiClientInterface {

	/** @var array  method => callable|array */
	private array $handlers;

	/** @var array  list of [method, params] */
	public array $calls = [];

	public function __construct(array $handlers = []) {
		$this->handlers = $handlers;
	}

	public function on(string $method, $handler): self {
		$this->handlers[$method] = $handler;

		return $this;
	}

	public function call(string $method, array $params) {
		$this->calls[] = [$method, $params];

		if (!array_key_exists($method, $this->handlers)) {
			throw new ApiException(sprintf('No permissions to call "%s".', $method));
		}

		$handler = $this->handlers[$method];

		return is_callable($handler) ? $handler($params) : $handler;
	}

	/**
	 * Parameters of the calls of a method.
	 */
	public function paramsOf(string $method): array {
		return array_values(array_map(static function (array $call): array {
			return $call[1];
		}, array_filter($this->calls, static function (array $call) use ($method): bool {
			return $call[0] === $method;
		})));
	}
}
