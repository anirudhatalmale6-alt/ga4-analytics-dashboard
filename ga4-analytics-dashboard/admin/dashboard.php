<?php
if (!defined('ABSPATH')) exit;
$configured = get_option(GA4DASH_OPT_SA, '') && get_option(GA4DASH_OPT_PROPERTY, '');
?>
<div class="wrap ga4dash">
	<div class="ga4dash-head">
		<h1>Analytics Dashboard</h1>
		<div class="ga4dash-controls" id="ga4dash-rangebar">
			<select id="ga4dash-range">
				<option value="today">Today</option>
				<option value="yesterday">Yesterday</option>
				<option value="7d">Last 7 days</option>
				<option value="28d" selected>Last 28 days</option>
				<option value="90d">Last 90 days</option>
				<option value="month">This month</option>
				<option value="year">This year</option>
				<option value="custom">Custom range</option>
			</select>
			<span id="ga4dash-custom" class="ga4dash-custom" hidden>
				<input type="date" id="ga4dash-start" />
				<span>to</span>
				<input type="date" id="ga4dash-end" />
				<button type="button" id="ga4dash-apply" class="button">Apply</button>
			</span>
			<button type="button" id="ga4dash-refresh" class="button">Refresh</button>
		</div>
	</div>

	<?php if (!$configured): ?>
		<div class="notice notice-warning"><p>Add your GA4 Property ID and service account key on the <a href="<?php echo esc_url(admin_url('admin.php?page=ga4-dashboard-settings')); ?>">Settings</a> tab to start pulling data.</p></div>
	<?php endif; ?>

	<nav class="ga4dash-tabs" id="ga4dash-tabs">
		<button type="button" class="ga4dash-tab is-active" data-tab="overview">Overview</button>
		<button type="button" class="ga4dash-tab" data-tab="realtime">Realtime <span class="ga4dash-live-dot"></span></button>
		<button type="button" class="ga4dash-tab" data-tab="audience">Audience</button>
		<button type="button" class="ga4dash-tab" data-tab="visitors">Visitors <span class="ga4dash-live-dot"></span></button>
	</nav>

	<div id="ga4dash-error" class="notice notice-error" hidden><p></p></div>

	<!-- OVERVIEW -->
	<section class="ga4dash-view is-active" data-view="overview">
		<div class="ga4dash-loading" data-loading="overview" hidden>Loading live data from Google Analytics…</div>
		<p class="ga4dash-period" id="ga4dash-period"></p>

		<div class="ga4dash-cards" id="ga4dash-cards">
			<?php
			$cards = [
				'totalUsers'             => 'Visitors',
				'newUsers'               => 'New visitors',
				'sessions'               => 'Sessions',
				'screenPageViews'        => 'Page views',
				'averageSessionDuration' => 'Avg. session',
				'engagementRate'         => 'Engagement rate',
			];
			foreach ($cards as $key => $label): ?>
				<div class="ga4dash-card" data-metric="<?php echo esc_attr($key); ?>">
					<div class="ga4dash-card-label"><?php echo esc_html($label); ?></div>
					<div class="ga4dash-card-value">—</div>
					<div class="ga4dash-card-change"></div>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="ga4dash-grid">
			<div class="ga4dash-panel ga4dash-wide">
				<h2>Traffic over time</h2>
				<div class="ga4dash-chart is-line"><canvas id="ga4dash-timeseries"></canvas></div>
			</div>
			<div class="ga4dash-panel">
				<h2>Traffic sources</h2>
				<div class="ga4dash-chart is-doughnut"><canvas id="ga4dash-sources"></canvas></div>
			</div>
			<div class="ga4dash-panel">
				<h2>Devices</h2>
				<div class="ga4dash-chart is-doughnut"><canvas id="ga4dash-devices"></canvas></div>
			</div>
			<div class="ga4dash-panel">
				<h2>Top pages</h2>
				<table class="ga4dash-table" id="ga4dash-top-pages"><tbody></tbody></table>
			</div>
			<div class="ga4dash-panel">
				<h2>Top countries</h2>
				<table class="ga4dash-table" id="ga4dash-countries"><tbody></tbody></table>
			</div>
			<div class="ga4dash-panel">
				<h2>Referrers</h2>
				<table class="ga4dash-table" id="ga4dash-referrers"><tbody></tbody></table>
			</div>
		</div>
	</section>

	<!-- REALTIME -->
	<section class="ga4dash-view" data-view="realtime">
		<div class="ga4dash-loading" data-loading="realtime" hidden>Loading realtime activity…</div>
		<div class="ga4dash-rt-head">
			<div class="ga4dash-rt-active">
				<div class="ga4dash-rt-num" id="ga4dash-rt-active">—</div>
				<div class="ga4dash-rt-label">Active users right now <span class="ga4dash-live-dot"></span></div>
			</div>
			<div class="ga4dash-rt-spark">
				<div class="ga4dash-rt-spark-label">Active users per minute (last 30 min)</div>
				<div class="ga4dash-chart is-spark"><canvas id="ga4dash-rt-minutes"></canvas></div>
			</div>
		</div>
		<div class="ga4dash-grid">
			<div class="ga4dash-panel">
				<h2>By country</h2>
				<table class="ga4dash-table" id="ga4dash-rt-countries"><tbody></tbody></table>
			</div>
			<div class="ga4dash-panel">
				<h2>By city</h2>
				<table class="ga4dash-table" id="ga4dash-rt-cities"><tbody></tbody></table>
			</div>
			<div class="ga4dash-panel">
				<h2>Active pages</h2>
				<table class="ga4dash-table" id="ga4dash-rt-pages"><tbody></tbody></table>
			</div>
			<div class="ga4dash-panel">
				<h2>Devices</h2>
				<div class="ga4dash-chart is-doughnut"><canvas id="ga4dash-rt-devices"></canvas></div>
			</div>
		</div>
	</section>

	<!-- AUDIENCE -->
	<section class="ga4dash-view" data-view="audience">
		<div class="ga4dash-loading" data-loading="audience" hidden>Loading audience breakdown…</div>
		<p class="ga4dash-period" id="ga4dash-aud-period"></p>
		<div class="ga4dash-note" id="ga4dash-aud-note" hidden>Age, Gender and Interests stay empty until Google Signals is turned on for this property (GA4 Admin, Data collection, Google signals data collection) and enough visitors have been recorded. Google also hides these breakdowns while traffic is below its minimum threshold.</div>
		<div class="ga4dash-grid">
			<div class="ga4dash-panel ga4dash-wide">
				<h2>New vs returning over time</h2>
				<div class="ga4dash-chart is-line"><canvas id="ga4dash-newret-trend"></canvas></div>
			</div>
			<div class="ga4dash-panel">
				<h2>New vs returning</h2>
				<div class="ga4dash-chart is-doughnut"><canvas id="ga4dash-newret"></canvas></div>
			</div>
			<div class="ga4dash-panel">
				<h2>Age</h2>
				<div class="ga4dash-chart is-bar"><canvas id="ga4dash-age"></canvas></div>
			</div>
			<div class="ga4dash-panel">
				<h2>Gender</h2>
				<div class="ga4dash-chart is-doughnut"><canvas id="ga4dash-gender"></canvas></div>
			</div>
			<div class="ga4dash-panel">
				<h2>Language</h2>
				<div class="ga4dash-chart is-bar"><canvas id="ga4dash-language"></canvas></div>
			</div>
			<div class="ga4dash-panel">
				<h2>Top cities</h2>
				<table class="ga4dash-table" id="ga4dash-aud-cities"><tbody></tbody></table>
			</div>
			<div class="ga4dash-panel">
				<h2>Interests</h2>
				<table class="ga4dash-table" id="ga4dash-interests"><tbody></tbody></table>
			</div>
		</div>
	</section>

	<!-- VISITORS -->
	<section class="ga4dash-view" data-view="visitors">
		<div class="ga4dash-loading" data-loading="visitors" hidden>Loading visitor activity…</div>
		<div class="ga4dash-vis-head">
			<div class="ga4dash-hero">
				<div class="ga4dash-hero-num" id="ga4dash-vis-active">—</div>
				<div class="ga4dash-hero-label">Active visitors right now <span class="ga4dash-live-dot"></span></div>
				<div class="ga4dash-hero-sub" id="ga4dash-vis-window">live, last 5 minutes</div>
			</div>
			<div class="ga4dash-panel ga4dash-map-panel">
				<div class="ga4dash-panel-head">
					<h2>Live visitor map <span class="ga4dash-live-dot"></span></h2>
					<span class="ga4dash-sub">active locations right now</span>
				</div>
				<div id="ga4dash-map" class="ga4dash-map"></div>
			</div>
		</div>
		<div class="ga4dash-grid">
			<div class="ga4dash-panel ga4dash-wide">
				<div class="ga4dash-panel-head">
					<h2>Recent visitors</h2>
					<span class="ga4dash-sub">last 24 hours, UK time</span>
				</div>
				<table class="ga4dash-table ga4dash-vis-table" id="ga4dash-visitors">
					<thead><tr><th>IP address</th><th>Location</th><th>Last page</th><th class="ga4dash-n">Visits</th><th>Last seen</th><th></th></tr></thead>
					<tbody></tbody>
				</table>
			</div>
			<div class="ga4dash-panel">
				<h2>Blocked IPs</h2>
				<div class="ga4dash-ipadd">
					<input type="text" id="ga4dash-add-block" placeholder="IP address" />
					<button type="button" class="button" data-add="block">Block</button>
				</div>
				<table class="ga4dash-table" id="ga4dash-blocklist"><tbody></tbody></table>
			</div>
			<div class="ga4dash-panel">
				<h2>Whitelisted (internal)</h2>
				<div class="ga4dash-ipadd">
					<input type="text" id="ga4dash-add-allow" placeholder="IP address" />
					<button type="button" class="button" data-add="allow">Whitelist</button>
				</div>
				<table class="ga4dash-table" id="ga4dash-allowlist"><tbody></tbody></table>
			</div>
		</div>
		<p class="ga4dash-note">The count and map show visitors active in the last 5 minutes, so they line up with the Realtime tab. Bots and automated checks are filtered out. The Recent visitors list below covers the last 24 hours. Blocked IPs are refused on the site itself; whitelisted IPs are treated as internal and kept out of this view.</p>
	</section>
</div>
