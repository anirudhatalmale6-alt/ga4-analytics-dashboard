<?php
// Builds the report requests and reshapes GA4 responses into a compact payload.
if (!defined('ABSPATH')) exit;

class GA4_Reports {
	private $client;

	public function __construct(GA4_Client $client) {
		$this->client = $client;
	}

	public function fetch($preset, $start = '', $end = '') {
		$range = $this->resolve_range($preset, $start, $end);
		$prev  = $this->previous_range($range);
		$cur   = ['startDate' => $range['start'], 'endDate' => $range['end']];
		$prv   = ['startDate' => $prev['start'], 'endDate' => $prev['end']];
		$single = ($range['start'] === $range['end']);
		$ts_dim = $single ? 'dateHour' : 'date';

		$batch1 = [
			[
				'dateRanges' => [$cur, $prv],
				'metrics'    => self::metrics(['totalUsers', 'newUsers', 'sessions', 'screenPageViews', 'averageSessionDuration', 'engagementRate']),
			],
			[
				'dateRanges' => [$cur],
				'dimensions' => self::dims([$ts_dim]),
				'metrics'    => self::metrics(['sessions', 'totalUsers', 'screenPageViews']),
				'orderBys'   => [['dimension' => ['dimensionName' => $ts_dim]]],
				'limit'      => 100000,
			],
			[
				'dateRanges' => [$cur],
				'dimensions' => self::dims(['pagePath', 'pageTitle']),
				'metrics'    => self::metrics(['screenPageViews', 'totalUsers']),
				'dimensionFilter' => self::exclude_preview(),
				'orderBys'   => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
				'limit'      => 10,
			],
			[
				'dateRanges' => [$cur],
				'dimensions' => self::dims(['sessionDefaultChannelGroup']),
				'metrics'    => self::metrics(['sessions']),
				'orderBys'   => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
				'limit'      => 10,
			],
			[
				'dateRanges' => [$cur],
				'dimensions' => self::dims(['deviceCategory']),
				'metrics'    => self::metrics(['sessions']),
				'orderBys'   => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
			],
		];

		$batch2 = [
			[
				'dateRanges' => [$cur],
				'dimensions' => self::dims(['country']),
				'metrics'    => self::metrics(['totalUsers', 'sessions']),
				'orderBys'   => [['metric' => ['metricName' => 'totalUsers'], 'desc' => true]],
				'limit'      => 10,
			],
			[
				'dateRanges' => [$cur],
				'dimensions' => self::dims(['sessionSource']),
				'metrics'    => self::metrics(['sessions']),
				'dimensionFilter' => ['filter' => ['fieldName' => 'sessionMedium', 'stringFilter' => ['value' => 'referral']]],
				'orderBys'   => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
				'limit'      => 10,
			],
		];

		$r1 = $this->client->batch_run_reports($batch1);
		if (is_wp_error($r1)) return $r1;
		$r2 = $this->client->batch_run_reports($batch2);
		if (is_wp_error($r2)) return $r2;

		return [
			'range'      => ['start' => $range['start'], 'end' => $range['end'], 'previous' => $prev],
			'summary'    => $this->shape_summary(isset($r1[0]) ? $r1[0] : null),
			'timeseries' => $this->shape_timeseries(isset($r1[1]) ? $r1[1] : null, $single),
			'topPages'   => $this->shape_rows(isset($r1[2]) ? $r1[2] : null, ['path', 'title'], ['views', 'users']),
			'sources'    => $this->shape_rows(isset($r1[3]) ? $r1[3] : null, ['label'], ['sessions']),
			'devices'    => $this->shape_rows(isset($r1[4]) ? $r1[4] : null, ['label'], ['sessions']),
			'countries'  => $this->shape_rows(isset($r2[0]) ? $r2[0] : null, ['label'], ['users', 'sessions']),
			'referrers'  => $this->shape_rows(isset($r2[1]) ? $r2[1] : null, ['label'], ['sessions']),
		];
	}

	public function realtime() {
		$c = $this->client;

		$total = $c->run_realtime_report([
			'metrics' => self::metrics(['activeUsers']),
		]);
		if (is_wp_error($total)) return $total;

		$minutes = $c->run_realtime_report([
			'dimensions' => self::dims(['minutesAgo']),
			'metrics'    => self::metrics(['activeUsers']),
			'limit'      => 30,
		]);
		if (is_wp_error($minutes)) return $minutes;

		$countries = $c->run_realtime_report([
			'dimensions' => self::dims(['country']),
			'metrics'    => self::metrics(['activeUsers']),
			'orderBys'   => [['metric' => ['metricName' => 'activeUsers'], 'desc' => true]],
			'limit'      => 10,
		]);
		if (is_wp_error($countries)) return $countries;

		$cities = $c->run_realtime_report([
			'dimensions' => self::dims(['city']),
			'metrics'    => self::metrics(['activeUsers']),
			'orderBys'   => [['metric' => ['metricName' => 'activeUsers'], 'desc' => true]],
			'limit'      => 10,
		]);
		if (is_wp_error($cities)) return $cities;

		$pages = $c->run_realtime_report([
			'dimensions' => self::dims(['unifiedScreenName']),
			'metrics'    => self::metrics(['activeUsers', 'screenPageViews']),
			'orderBys'   => [['metric' => ['metricName' => 'activeUsers'], 'desc' => true]],
			'limit'      => 10,
		]);
		if (is_wp_error($pages)) return $pages;

		$devices = $c->run_realtime_report([
			'dimensions' => self::dims(['deviceCategory']),
			'metrics'    => self::metrics(['activeUsers']),
		]);
		if (is_wp_error($devices)) return $devices;

		$active = 0;
		if (!empty($total['rows'][0]['metricValues'][0]['value'])) {
			$active = (int) $total['rows'][0]['metricValues'][0]['value'];
		}

		return [
			'active'    => $active,
			'perMinute' => $this->shape_minutes($minutes),
			'countries' => $this->shape_rows($countries, ['label'], ['active']),
			'cities'    => $this->shape_rows($cities, ['label'], ['active']),
			'pages'     => $this->shape_rows($pages, ['label'], ['active', 'views']),
			'devices'   => $this->shape_rows($devices, ['label'], ['active']),
		];
	}

	public function audience($preset, $start = '', $end = '') {
		$range = $this->resolve_range($preset, $start, $end);
		$prev  = $this->previous_range($range);
		$cur   = ['startDate' => $range['start'], 'endDate' => $range['end']];

		$batch = [
			[
				'dateRanges' => [$cur],
				'dimensions' => self::dims(['newVsReturning']),
				'metrics'    => self::metrics(['totalUsers', 'sessions']),
			],
			[
				'dateRanges' => [$cur],
				'dimensions' => self::dims(['city', 'country']),
				'metrics'    => self::metrics(['totalUsers', 'newUsers', 'sessions']),
				'orderBys'   => [['metric' => ['metricName' => 'totalUsers'], 'desc' => true]],
				'limit'      => 12,
			],
			[
				'dateRanges' => [$cur],
				'dimensions' => self::dims(['language']),
				'metrics'    => self::metrics(['totalUsers']),
				'orderBys'   => [['metric' => ['metricName' => 'totalUsers'], 'desc' => true]],
				'limit'      => 10,
			],
			[
				'dateRanges' => [$cur],
				'dimensions' => self::dims(['userAgeBracket']),
				'metrics'    => self::metrics(['totalUsers']),
				'orderBys'   => [['dimension' => ['dimensionName' => 'userAgeBracket']]],
			],
			[
				'dateRanges' => [$cur],
				'dimensions' => self::dims(['userGender']),
				'metrics'    => self::metrics(['totalUsers']),
			],
			[
				'dateRanges' => [$cur],
				'dimensions' => self::dims(['brandingInterest']),
				'metrics'    => self::metrics(['totalUsers']),
				'orderBys'   => [['metric' => ['metricName' => 'totalUsers'], 'desc' => true]],
				'limit'      => 10,
			],
			[
				'dateRanges' => [$cur],
				'dimensions' => self::dims(['date', 'newVsReturning']),
				'metrics'    => self::metrics(['totalUsers']),
				'orderBys'   => [['dimension' => ['dimensionName' => 'date']]],
				'limit'      => 100000,
			],
		];

		// GA4 batchRunReports allows at most 5 requests per call, so split into two.
		$rA = $this->client->batch_run_reports(array_slice($batch, 0, 5));
		if (is_wp_error($rA)) return $rA;
		$rB = $this->client->batch_run_reports(array_slice($batch, 5));
		if (is_wp_error($rB)) return $rB;
		$r = array_merge($rA, $rB);

		return [
			'range'             => ['start' => $range['start'], 'end' => $range['end']],
			'newReturning'      => $this->shape_rows(isset($r[0]) ? $r[0] : null, ['label'], ['users', 'sessions']),
			'cities'            => $this->shape_rows(isset($r[1]) ? $r[1] : null, ['label', 'country'], ['users', 'newUsers', 'sessions']),
			'languages'         => $this->shape_rows(isset($r[2]) ? $r[2] : null, ['label'], ['users']),
			'age'               => $this->shape_rows(isset($r[3]) ? $r[3] : null, ['label'], ['users']),
			'gender'            => $this->shape_rows(isset($r[4]) ? $r[4] : null, ['label'], ['users']),
			'interests'         => $this->shape_rows(isset($r[5]) ? $r[5] : null, ['label'], ['users']),
			'newReturningTrend' => $this->shape_newret_trend(isset($r[6]) ? $r[6] : null),
		];
	}

	// Pivot [date, newVsReturning] rows into a per-day split for the trend chart.
	private function shape_newret_trend($report) {
		$days = [];
		if (!empty($report['rows'])) {
			foreach ($report['rows'] as $row) {
				$date = isset($row['dimensionValues'][0]['value']) ? $row['dimensionValues'][0]['value'] : '';
				$type = isset($row['dimensionValues'][1]['value']) ? strtolower($row['dimensionValues'][1]['value']) : '';
				$val  = isset($row['metricValues'][0]['value']) ? (float) $row['metricValues'][0]['value'] : 0.0;
				if ($date === '') continue;
				if (!isset($days[$date])) $days[$date] = ['new' => 0.0, 'returning' => 0.0];
				if ($type === 'new') $days[$date]['new'] += $val;
				elseif ($type === 'returning') $days[$date]['returning'] += $val;
			}
		}
		ksort($days);
		$labels = $new = $ret = [];
		foreach ($days as $d => $v) {
			$labels[] = $this->format_day($d);
			$new[] = $v['new'];
			$ret[] = $v['returning'];
		}
		return ['labels' => $labels, 'new' => $new, 'returning' => $ret];
	}

	private function shape_minutes($report) {
		$buckets = array_fill(0, 30, 0);
		if (!empty($report['rows'])) {
			foreach ($report['rows'] as $row) {
				$m = isset($row['dimensionValues'][0]['value']) ? (int) $row['dimensionValues'][0]['value'] : -1;
				if ($m >= 0 && $m < 30) {
					$buckets[$m] = isset($row['metricValues'][0]['value']) ? (int) $row['metricValues'][0]['value'] : 0;
				}
			}
		}
		// minutesAgo counts backwards; reverse so the chart runs oldest -> now (left -> right).
		return array_reverse($buckets);
	}

	private function shape_summary($report) {
		$keys = ['totalUsers', 'newUsers', 'sessions', 'screenPageViews', 'averageSessionDuration', 'engagementRate'];
		$out = [];
		foreach ($keys as $k) $out[$k] = ['current' => 0.0, 'previous' => 0.0, 'change' => null];
		if (empty($report['rows'])) return $out;

		foreach ($report['rows'] as $row) {
			$which = isset($row['dimensionValues'][0]['value']) ? $row['dimensionValues'][0]['value'] : 'date_range_0';
			$slot = ($which === 'date_range_1') ? 'previous' : 'current';
			foreach ($keys as $i => $k) {
				$out[$k][$slot] = isset($row['metricValues'][$i]['value']) ? (float) $row['metricValues'][$i]['value'] : 0.0;
			}
		}
		foreach ($keys as $k) {
			$p = $out[$k]['previous'];
			$out[$k]['change'] = ($p > 0) ? round((($out[$k]['current'] - $p) / $p) * 100, 1) : null;
		}
		return $out;
	}

	private function shape_timeseries($report, $single) {
		$labels = [];
		$series = ['sessions' => [], 'totalUsers' => [], 'screenPageViews' => []];
		if (empty($report['rows'])) return ['labels' => $labels, 'series' => $series];

		foreach ($report['rows'] as $row) {
			$raw = isset($row['dimensionValues'][0]['value']) ? $row['dimensionValues'][0]['value'] : '';
			$labels[] = $single ? $this->format_hour($raw) : $this->format_day($raw);
			$vals = array_values($series);
			$i = 0;
			foreach ($series as $key => $_) {
				$series[$key][] = isset($row['metricValues'][$i]['value']) ? (float) $row['metricValues'][$i]['value'] : 0.0;
				$i++;
			}
		}
		return ['labels' => $labels, 'series' => $series];
	}

	private function shape_rows($report, $dim_keys, $metric_keys) {
		$rows = [];
		if (empty($report['rows'])) return $rows;
		foreach ($report['rows'] as $row) {
			$item = [];
			foreach ($dim_keys as $i => $k) {
				$item[$k] = isset($row['dimensionValues'][$i]['value']) ? $row['dimensionValues'][$i]['value'] : '';
			}
			foreach ($metric_keys as $i => $k) {
				$item[$k] = isset($row['metricValues'][$i]['value']) ? (float) $row['metricValues'][$i]['value'] : 0.0;
			}
			$rows[] = $item;
		}
		return $rows;
	}

	private function format_day($ymd) {
		if (strlen($ymd) !== 8) return $ymd;
		return substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2);
	}

	private function format_hour($ymdh) {
		if (strlen($ymdh) !== 10) return $ymdh;
		return substr($ymdh, 8, 2) . ':00';
	}

	public function resolve_range($preset, $start = '', $end = '') {
		$tz = wp_timezone();
		$today = new DateTimeImmutable('now', $tz);
		$fmt = 'Y-m-d';

		switch ($preset) {
			case 'today':     $s = $today; $e = $today; break;
			case 'yesterday': $s = $today->modify('-1 day'); $e = $s; break;
			case '7d':        $s = $today->modify('-6 days'); $e = $today; break;
			case '90d':       $s = $today->modify('-89 days'); $e = $today; break;
			case 'month':     $s = $today->modify('first day of this month'); $e = $today; break;
			case 'year':      $s = $today->setDate((int) $today->format('Y'), 1, 1); $e = $today; break;
			case 'custom':
				$s = DateTimeImmutable::createFromFormat($fmt, $start, $tz);
				$e = DateTimeImmutable::createFromFormat($fmt, $end, $tz);
				if (!$s) $s = $today->modify('-27 days');
				if (!$e) $e = $today;
				if ($e < $s) { $tmp = $s; $s = $e; $e = $tmp; }
				break;
			case '28d':
			default:          $s = $today->modify('-27 days'); $e = $today; break;
		}
		return ['start' => $s->format($fmt), 'end' => $e->format($fmt), '_s' => $s, '_e' => $e];
	}

	private function previous_range($range) {
		$s = $range['_s'];
		$e = $range['_e'];
		$days = $s->diff($e)->days + 1;
		$pe = $s->modify('-1 day');
		$ps = $pe->modify('-' . ($days - 1) . ' days');
		return ['start' => $ps->format('Y-m-d'), 'end' => $pe->format('Y-m-d')];
	}

	private static function metrics($names) {
		$out = [];
		foreach ($names as $n) $out[] = ['name' => $n];
		return $out;
	}

	private static function dims($names) {
		$out = [];
		foreach ($names as $n) $out[] = ['name' => $n];
		return $out;
	}

	// Keep blog draft previews (served under /preview/<id>) out of the Top pages table.
	private static function exclude_preview() {
		return [
			'notExpression' => [
				'filter' => [
					'fieldName'    => 'pagePath',
					'stringFilter' => ['matchType' => 'BEGINS_WITH', 'value' => '/preview'],
				],
			],
		];
	}
}
