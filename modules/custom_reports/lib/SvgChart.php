<?php declare(strict_types = 0);
/*
** Custom Problem Reports module for Zabbix 7.0 LTS.
*/


namespace Modules\CustomReports\Lib;

/**
 * Inline SVG chart of the web interface: open problems as a line (top, own scale fitted to its range - a change from
 * 75 to 77 stays visible) and new / resolved problems as bars up / down from one axis (bottom, own scale).
 * Texts use currentColor, so the chart follows the light and the dark theme.
 */
class SvgChart {

	private const WIDTH = 1000;
	private const LEFT = 8;
	private const RIGHT = 56;

	// Top panel (open problems): y of its top and bottom.
	private const LINE_TOP = 22;
	private const LINE_BOTTOM = 122;

	// Bottom panel (new up, resolved down): the zero axis and the maximum bar height to each side.
	private const BARS_ZERO = 190;
	private const BARS_HALF = 46;

	private const HEIGHT = 262;

	public const COLOR_NEW = '4796c4';
	public const COLOR_RESOLVED = '59b36a';
	public const COLOR_OPEN = 'e9894f';
	public const COLOR_PREV = '9aa7b0';

	/**
	 * @param array $points  List of ['label' => axis label ('' - none), 'title' => tooltip, 'new' => int,
	 *                       'resolved' => int, 'open' => int, 'new_prev' => int|null].
	 * @param array $texts   'open', 'new', 'resolved', 'start' (labels inside the chart).
	 * @param int|null $open_start  Open problems at the start of the period: a dashed reference line.
	 */
	public static function flow(array $points, array $texts, ?int $open_start = null): string {
		$count = count($points);

		if ($count == 0) {
			return '';
		}

		$plot_width = self::WIDTH - self::LEFT - self::RIGHT;
		$slot = $plot_width / $count;
		$x = static function (int $index) use ($slot): float {
			return self::LEFT + $slot * ($index + 0.5);
		};

		// Line scale: the range of the open values (and of the start level), with some room.
		$values = array_column($points, 'open');

		if ($open_start !== null) {
			$values[] = $open_start;
		}

		[$min, $max] = [min($values), max($values)];
		$pad = max(1, (int) ceil(($max - $min) * 0.2));
		[$low, $high] = [max(0, $min - $pad), $max + $pad];
		$y_line = static function (float $value) use ($low, $high): float {
			return self::LINE_BOTTOM - ($value - $low) * (self::LINE_BOTTOM - self::LINE_TOP) / max(1, $high - $low);
		};

		// Bars scale: the biggest of new / resolved / previous period.
		$bar_max = 1;

		foreach ($points as $point) {
			$bar_max = max($bar_max, $point['new'], $point['resolved'], (int) ($point['new_prev'] ?? 0));
		}

		$h_bar = static function (int $value) use ($bar_max): float {
			return $value > 0 ? max(1.5, $value * self::BARS_HALF / $bar_max) : 0;
		};

		$e = static function (string $text): string {
			return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		};
		$f = static function (float $value): string {
			return number_format($value, 1, '.', '');
		};

		$svg = '<svg class="cr-svg-chart" viewBox="0 0 '.self::WIDTH.' '.self::HEIGHT.'" width="100%" role="img" '.
			'xmlns="http://www.w3.org/2000/svg" style="font-family:inherit;font-size:15px">';

		// Grid of the line panel: low, middle, high values on the right.
		foreach (array_unique([$low, (int) round(($low + $high) / 2), $high]) as $value) {
			$y = $y_line($value);
			$svg .= '<line x1="'.self::LEFT.'" x2="'.(self::WIDTH - self::RIGHT).'" y1="'.$f($y).'" y2="'.$f($y).'" '.
				'stroke="currentColor" stroke-opacity=".12"/>'.
				'<text x="'.(self::WIDTH - self::RIGHT + 6).'" y="'.$f($y + 4).'" fill="currentColor" fill-opacity=".6">'.
				$value.'</text>';
		}

		$svg .= '<text x="'.self::LEFT.'" y="12" fill="currentColor" fill-opacity=".75">'.$e($texts['open']).'</text>';

		// Level at the start of the period.
		if ($open_start !== null) {
			$y = $y_line($open_start);
			$svg .= '<line x1="'.self::LEFT.'" x2="'.(self::WIDTH - self::RIGHT).'" y1="'.$f($y).'" y2="'.$f($y).'" '.
				'stroke="currentColor" stroke-opacity=".45" stroke-dasharray="4 4"><title>'.
				$e($texts['start'].': '.$open_start).'</title></line>';
		}

		// Line and area of open problems.
		$path = '';
		$area = 'M'.$f($x(0)).' '.self::LINE_BOTTOM;

		foreach ($points as $index => $point) {
			$path .= ($index == 0 ? 'M' : 'L').$f($x($index)).' '.$f($y_line($point['open']));
			$area .= ' L'.$f($x($index)).' '.$f($y_line($point['open']));
		}

		$area .= ' L'.$f($x($count - 1)).' '.self::LINE_BOTTOM.' Z';
		$svg .= '<path d="'.$area.'" fill="#'.self::COLOR_OPEN.'" fill-opacity=".12"/>'.
			'<path d="'.$path.'" fill="none" stroke="#'.self::COLOR_OPEN.'" stroke-width="2.5" stroke-linejoin="round"/>';

		if ($count <= 48) {
			foreach ($points as $index => $point) {
				$svg .= '<circle cx="'.$f($x($index)).'" cy="'.$f($y_line($point['open'])).'" r="2.6" fill="#'.
					self::COLOR_OPEN.'"/>';
			}
		}

		// Values at the start, the end, the minimum and the maximum of the line.
		$marks = [0 => true, $count - 1 => true];
		$marks[array_search($min, array_column($points, 'open'), true)] = true;
		$marks[array_search($max, array_column($points, 'open'), true)] = true;

		foreach (array_keys($marks) as $index) {
			if ($index === false || !isset($points[$index])) {
				continue;
			}

			$anchor = $index == 0 ? 'start' : ($index == $count - 1 ? 'end' : 'middle');
			$svg .= '<text x="'.$f($x($index)).'" y="'.$f($y_line($points[$index]['open']) - 7).'" text-anchor="'.$anchor.
				'" font-weight="bold" fill="currentColor">'.$points[$index]['open'].'</text>';
		}

		// Bars panel: new up, resolved down, previous period as a tick.
		$zero = self::BARS_ZERO;
		$svg .= '<text x="'.self::LEFT.'" y="'.($zero - self::BARS_HALF - 8).'" fill="currentColor" fill-opacity=".75">'.
			$e($texts['new'].' ↑ / '.$texts['resolved'].' ↓').'</text>'.
			'<line x1="'.self::LEFT.'" x2="'.(self::WIDTH - self::RIGHT).'" y1="'.$zero.'" y2="'.$zero.'" '.
			'stroke="currentColor" stroke-opacity=".35"/>'.
			'<text x="'.(self::WIDTH - self::RIGHT + 6).'" y="'.($zero - self::BARS_HALF + 4).'" fill="currentColor" '.
			'fill-opacity=".6">'.$bar_max.'</text>'.
			'<text x="'.(self::WIDTH - self::RIGHT + 6).'" y="'.($zero + 4).'" fill="currentColor" fill-opacity=".6">0</text>'.
			'<text x="'.(self::WIDTH - self::RIGHT + 6).'" y="'.($zero + self::BARS_HALF + 4).'" fill="currentColor" '.
			'fill-opacity=".6">'.$bar_max.'</text>';

		$bar_width = max(1.5, min(28, $slot * 0.6));

		foreach ($points as $index => $point) {
			$cx = $x($index);
			$left = $cx - $bar_width / 2;

			if ($point['new'] > 0) {
				$h = $h_bar($point['new']);
				$svg .= '<rect x="'.$f($left).'" y="'.$f($zero - $h).'" width="'.$f($bar_width).'" height="'.$f($h).'" fill="#'.
					self::COLOR_NEW.'"/>';
			}

			if ($point['resolved'] > 0) {
				$h = $h_bar($point['resolved']);
				$svg .= '<rect x="'.$f($left).'" y="'.$zero.'" width="'.$f($bar_width).'" height="'.$f($h).'" fill="#'.
					self::COLOR_RESOLVED.'"/>';
			}

			if (($point['new_prev'] ?? null) !== null && $point['new_prev'] > 0) {
				$y = $zero - $h_bar((int) $point['new_prev']);
				$svg .= '<line x1="'.$f($left - 2).'" x2="'.$f($left + $bar_width + 2).'" y1="'.$f($y).'" y2="'.$f($y).'" '.
					'stroke="#'.self::COLOR_PREV.'" stroke-width="2" stroke-dasharray="3 2"/>';
			}

			if ($point['label'] !== '') {
				$svg .= '<text x="'.$f($cx).'" y="'.(self::HEIGHT - 6).'" text-anchor="middle" fill="currentColor" '.
					'fill-opacity=".7" font-size="14">'.$e($point['label']).'</text>';
			}

			// Hover area of the whole interval with the numbers.
			$svg .= '<rect x="'.$f(self::LEFT + $slot * $index).'" y="0" width="'.$f($slot).'" height="'.self::HEIGHT.'" '.
				'fill="transparent" class="cr-svg-hover"><title>'.$e($point['title']).'</title></rect>';
		}

		return $svg.'</svg>';
	}
}
