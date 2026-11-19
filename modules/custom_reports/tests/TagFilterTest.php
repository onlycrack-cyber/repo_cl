<?php declare(strict_types = 0);

namespace Modules\CustomReports\Tests;

use Modules\CustomReports\Lib\ChangeAnalytics;
use Modules\CustomReports\Lib\ReportBuilder;
use Modules\CustomReports\Lib\ScheduleHelper;
use Modules\CustomReports\Lib\TagFilter;

class TagFilterTest extends TestCase {

	public function testNormalize(): void {
		$this->assertSame([
			['tag' => 'service', 'operator' => TagFilter::OPERATOR_EQUAL, 'value' => 'db'],
			['tag' => 'team', 'operator' => TagFilter::OPERATOR_EXISTS, 'value' => '']
		], TagFilter::normalize([
			['tag' => ' service ', 'operator' => '1', 'value' => 'db'],
			['tag' => '', 'operator' => 0, 'value' => 'no tag name'],
			['tag' => 'team', 'operator' => 4, 'value' => 'ignored for exists'],
			['tag' => 'bad', 'operator' => 99, 'value' => 'x'],
			'garbage'
		]));

		$this->assertSame([], TagFilter::normalize('garbage'));
		$this->assertSame(TagFilter::EVAL_OR, TagFilter::normalizeEvaltype('2'));
		$this->assertSame(TagFilter::EVAL_AND_OR, TagFilter::normalizeEvaltype(7));
	}

	public function testApiParams(): void {
		$this->assertSame([], TagFilter::apiParams(['tags' => []]));
		$this->assertSame(['tags' => [['tag' => 'a', 'operator' => 0, 'value' => 'b']], 'evaltype' => 2],
			TagFilter::apiParams(['tags' => [['tag' => 'a', 'operator' => 0, 'value' => 'b']], 'evaltype' => 2])
		);
	}

	public function testDescribe(): void {
		$tags = [['tag' => 'service', 'operator' => 1, 'value' => 'db'], ['tag' => 'team', 'operator' => 4, 'value' => '']];

		$this->assertSame('service equals "db", team exists', TagFilter::describe($tags, TagFilter::EVAL_AND_OR));
		$this->assertSame('service equals "db", team exists (any of them)', TagFilter::describe($tags, TagFilter::EVAL_OR));
	}

	public function testTagsGoToProblemGetAndToTheReportHeader(): void {
		$api = $this->api();
		$report = (new ReportBuilder($api))->build([
			'tags' => [['tag' => 'service', 'operator' => 1, 'value' => 'db']],
			'evaltype' => 2,
			'analytics' => 'none'
		], self::NOW);

		$params = $api->paramsOf('problem.get')[0];
		$this->assertSame([['tag' => 'service', 'operator' => 1, 'value' => 'db']], $params['tags']);
		$this->assertSame(2, $params['evaltype']);
		$this->assertSame('service equals "db"', $report['filter_info']['tags']);

		$api = $this->api();
		(new ReportBuilder($api))->build(['analytics' => 'none'], self::NOW);
		$this->assertArrayNotHasKey('tags', $api->paramsOf('problem.get')[0], 'no tags - no tag filter');
	}

	public function testTagsGoToTheEventHistory(): void {
		$api = $this->api();
		(new ChangeAnalytics($api))->collect(ChangeAnalytics::normalizeFilter([
			'tags' => [['tag' => 'team', 'operator' => 4]]
		]), self::NOW - 86400, self::NOW);

		$this->assertSame([['tag' => 'team', 'operator' => 4, 'value' => '']], $api->paramsOf('event.get')[0]['tags']);
	}

	public function testSchedulesKeepTags(): void {
		$schedule = ScheduleHelper::normalize(['tags' => [['tag' => 'team', 'operator' => 1, 'value' => 'web']], 'evaltype' => 2]);

		$this->assertSame([['tag' => 'team', 'operator' => 1, 'value' => 'web']], $schedule['tags']);
		$this->assertSame(2, $schedule['evaltype']);
		$this->assertSame([], ScheduleHelper::normalize([])['tags']);
	}
}
