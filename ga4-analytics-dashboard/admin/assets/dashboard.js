(function () {
	'use strict';

	var charts = {};
	var palette = ['#6366f1', '#06b6d4', '#f59e0b', '#ec4899', '#10b981', '#8b5cf6', '#ef4444', '#64748b'];
	// Clean, modern light-gray basemap with English place labels.
	var TILES = {
		base: 'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Light_Gray_Base/MapServer/tile/{z}/{y}/{x}',
		labels: 'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Light_Gray_Reference/MapServer/tile/{z}/{y}/{x}',
		opts: { maxNativeZoom: 16, maxZoom: 19, attribution: 'Tiles &copy; Esri' }
	};
	var loaded = { overview: false, audience: false };
	var current = 'overview';
	var rtTimer = null;
	var visTimer = null;
	var map = null, mapLayer = null;

	function el(id) { return document.getElementById(id); }

	function post(action, extra) {
		var body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', GA4DASH.nonce);
		Object.keys(extra || {}).forEach(function (k) { body.set(k, extra[k]); });
		return fetch(GA4DASH.ajax, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); });
	}

	function num(n) { return Math.round(n).toLocaleString(); }

	function duration(sec) {
		sec = Math.round(sec || 0);
		var m = Math.floor(sec / 60), s = sec % 60;
		return m + 'm ' + (s < 10 ? '0' : '') + s + 's';
	}

	function formatMetric(key, v) {
		if (key === 'averageSessionDuration') return duration(v);
		if (key === 'engagementRate') return (v * 100).toFixed(1) + '%';
		return num(v);
	}

	function escapeHtml(s) {
		return s.replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function destroy(name) {
		if (charts[name]) { charts[name].destroy(); delete charts[name]; }
	}

	function show(sel, on) {
		var e = typeof sel === 'string' ? el(sel) : sel;
		if (e) e.hidden = !on;
	}

	function loadingFor(view, on) {
		var e = document.querySelector('[data-loading="' + view + '"]');
		if (e) e.hidden = !on;
	}

	function fail(msg) {
		var box = el('ga4dash-error');
		box.querySelector('p').textContent = msg || 'Could not load data.';
		box.hidden = false;
	}

	function clearError() { el('ga4dash-error').hidden = true; }

	function rangeParams() {
		var range = el('ga4dash-range');
		var params = { range: range.value };
		if (range.value === 'custom') {
			params.start = el('ga4dash-start').value;
			params.end = el('ga4dash-end').value;
		}
		return params;
	}

	/* ---------- charts ---------- */

	function renderCards(summary) {
		document.querySelectorAll('.ga4dash-card').forEach(function (card) {
			var key = card.getAttribute('data-metric');
			var m = summary[key] || { current: 0, change: null };
			card.querySelector('.ga4dash-card-value').textContent = formatMetric(key, m.current);
			var badge = card.querySelector('.ga4dash-card-change');
			if (m.change === null || m.change === undefined) {
				badge.textContent = '';
				badge.className = 'ga4dash-card-change';
			} else {
				var up = m.change >= 0;
				badge.textContent = (up ? '▲ ' : '▼ ') + Math.abs(m.change) + '%';
				badge.className = 'ga4dash-card-change ' + (up ? 'is-up' : 'is-down');
			}
		});
	}

	function renderTimeseries(ts) {
		var ctx = el('ga4dash-timeseries');
		if (!ctx) return;
		destroy('ts');
		charts.ts = new Chart(ctx, {
			type: 'line',
			data: {
				labels: ts.labels,
				datasets: [
					{ label: 'Sessions', data: ts.series.sessions, borderColor: palette[0], backgroundColor: 'rgba(79,70,229,.12)', fill: true, tension: 0.3, pointRadius: 0, borderWidth: 2 },
					{ label: 'Visitors', data: ts.series.totalUsers, borderColor: palette[1], backgroundColor: 'transparent', tension: 0.3, pointRadius: 0, borderWidth: 2 },
					{ label: 'Page views', data: ts.series.screenPageViews, borderColor: palette[2], backgroundColor: 'transparent', tension: 0.3, pointRadius: 0, borderWidth: 2 }
				]
			},
			options: {
				responsive: true, maintainAspectRatio: false,
				interaction: { mode: 'index', intersect: false },
				plugins: { legend: { position: 'bottom' } },
				scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } }
			}
		});
	}

	function renderDoughnut(name, canvasId, rows, labelKey, valueKey) {
		var ctx = el(canvasId);
		if (!ctx) return;
		destroy(name);
		var labels = rows.map(function (r) { return r[labelKey] || '(not set)'; });
		var values = rows.map(function (r) { return r[valueKey]; });
		charts[name] = new Chart(ctx, {
			type: 'doughnut',
			data: { labels: labels, datasets: [{ data: values, backgroundColor: palette, borderWidth: 0 }] },
			options: { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'right' } } }
		});
	}

	function renderBar(name, canvasId, rows, labelKey, valueKey, label) {
		var ctx = el(canvasId);
		if (!ctx) return;
		destroy(name);
		var labels = rows.map(function (r) { return r[labelKey] || '(not set)'; });
		var values = rows.map(function (r) { return r[valueKey]; });
		charts[name] = new Chart(ctx, {
			type: 'bar',
			data: { labels: labels, datasets: [{ label: label || 'Users', data: values, backgroundColor: palette[0], borderRadius: 5, maxBarThickness: 34 }] },
			options: {
				responsive: true, maintainAspectRatio: false,
				plugins: { legend: { display: false } },
				scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } }
			}
		});
	}

	function renderNewRetTrend(trend) {
		var ctx = el('ga4dash-newret-trend');
		if (!ctx) return;
		destroy('newrettrend');
		trend = trend || { labels: [], new: [], returning: [] };
		charts.newrettrend = new Chart(ctx, {
			type: 'bar',
			data: {
				labels: trend.labels,
				datasets: [
					{ label: 'New', data: trend.new, backgroundColor: palette[0], stack: 'u', maxBarThickness: 26 },
					{ label: 'Returning', data: trend.returning, backgroundColor: palette[1], stack: 'u', maxBarThickness: 26 }
				]
			},
			options: {
				responsive: true, maintainAspectRatio: false,
				interaction: { mode: 'index', intersect: false },
				plugins: { legend: { position: 'bottom' } },
				scales: {
					x: { stacked: true, grid: { display: false } },
					y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } }
				}
			}
		});
	}

	function renderSpark(rows) {
		var ctx = el('ga4dash-rt-minutes');
		if (!ctx) return;
		destroy('rtmin');
		// rows run oldest -> now (30 bars). Bar at index i is (30 - i) minutes ago;
		// the last bar is the current minute. Mark the axis like GA4 does.
		var marks = { 0: '30 min', 5: '25', 10: '20', 15: '15', 20: '10', 25: '5', 29: '1 min' };
		charts.rtmin = new Chart(ctx, {
			type: 'bar',
			data: {
				labels: rows.map(function (_, i) { return i; }),
				datasets: [{ data: rows, backgroundColor: '#10b981', borderRadius: 1, barPercentage: 0.72, categoryPercentage: 1 }]
			},
			options: {
				responsive: true, maintainAspectRatio: false,
				plugins: {
					legend: { display: false },
					tooltip: {
						callbacks: {
							title: function (items) {
								var i = items[0].dataIndex;
								var ago = 30 - i;
								return ago <= 1 ? 'This minute' : ago + ' min ago';
							},
							label: function (c) { return c.parsed.y + ' active'; }
						}
					}
				},
				scales: {
					y: { display: false, beginAtZero: true },
					x: {
						grid: { display: false },
						border: { display: false },
						ticks: {
							autoSkip: false, maxRotation: 0, padding: 2,
							font: { size: 10 }, color: '#6b7280',
							callback: function (val, i) { return marks[i] || ''; }
						}
					}
				}
			}
		});
	}

	function renderTable(id, rows, cols) {
		var tbody = document.querySelector('#' + id + ' tbody');
		if (!tbody) return;
		if (!rows || !rows.length) { tbody.innerHTML = '<tr><td class="ga4dash-empty">No data for this period.</td></tr>'; return; }
		tbody.innerHTML = rows.map(function (r) {
			return '<tr>' + cols.map(function (c) {
				var v = r[c.key];
				if (c.format === 'num') v = num(v);
				var val = v === '' || v === undefined || v === null ? '(not set)' : v;
				return '<td class="' + (c.cls || '') + '"' + (c.title ? ' title="' + String(val).replace(/"/g, '&quot;') + '"' : '') + '>' + escapeHtml(String(val)) + '</td>';
			}).join('') + '</tr>';
		}).join('');
	}

	/* ---------- loaders ---------- */

	function loadOverview() {
		loadingFor('overview', true);
		clearError();
		post('ga4dash_data', rangeParams()).then(function (res) {
			loadingFor('overview', false);
			if (!res || !res.success) { fail(res && res.data ? res.data.message : null); return; }
			loaded.overview = true;
			var d = res.data;
			el('ga4dash-period').textContent = 'Showing ' + d.range.start + ' to ' + d.range.end + ' (compared with ' + d.range.previous.start + ' to ' + d.range.previous.end + ')';
			renderCards(d.summary);
			renderTimeseries(d.timeseries);
			renderDoughnut('sources', 'ga4dash-sources', d.sources, 'label', 'sessions');
			renderDoughnut('devices', 'ga4dash-devices', d.devices, 'label', 'sessions');
			renderTable('ga4dash-top-pages', d.topPages, [
				{ key: 'path', cls: 'ga4dash-path', title: true },
				{ key: 'views', cls: 'ga4dash-n', format: 'num' }
			]);
			renderTable('ga4dash-countries', d.countries, [
				{ key: 'label' },
				{ key: 'users', cls: 'ga4dash-n', format: 'num' }
			]);
			renderTable('ga4dash-referrers', d.referrers, [
				{ key: 'label', cls: 'ga4dash-path', title: true },
				{ key: 'sessions', cls: 'ga4dash-n', format: 'num' }
			]);
		}).catch(function () { loadingFor('overview', false); fail('Network error while loading data.'); });
	}

	function loadAudience() {
		loadingFor('audience', true);
		clearError();
		post('ga4dash_audience', rangeParams()).then(function (res) {
			loadingFor('audience', false);
			if (!res || !res.success) { fail(res && res.data ? res.data.message : null); return; }
			loaded.audience = true;
			var d = res.data;
			el('ga4dash-aud-period').textContent = 'Showing ' + d.range.start + ' to ' + d.range.end;
			var demo = (d.age && d.age.length) || (d.gender && d.gender.length) || (d.interests && d.interests.length);
			show('ga4dash-aud-note', !demo);
			renderNewRetTrend(d.newReturningTrend);
			renderDoughnut('newret', 'ga4dash-newret', d.newReturning, 'label', 'users');
			renderBar('age', 'ga4dash-age', d.age, 'label', 'users', 'Users');
			renderDoughnut('gender', 'ga4dash-gender', d.gender, 'label', 'users');
			renderBar('language', 'ga4dash-language', d.languages, 'label', 'users', 'Users');
			renderTable('ga4dash-aud-cities', d.cities, [
				{ key: 'label' },
				{ key: 'country' },
				{ key: 'users', cls: 'ga4dash-n', format: 'num' }
			]);
			renderTable('ga4dash-interests', d.interests, [
				{ key: 'label', cls: 'ga4dash-path', title: true },
				{ key: 'users', cls: 'ga4dash-n', format: 'num' }
			]);
		}).catch(function () { loadingFor('audience', false); fail('Network error while loading data.'); });
	}

	function loadRealtime(silent) {
		if (!silent) loadingFor('realtime', true);
		post('ga4dash_realtime', {}).then(function (res) {
			loadingFor('realtime', false);
			if (!res || !res.success) { if (!silent) fail(res && res.data ? res.data.message : null); return; }
			clearError();
			var d = res.data;
			el('ga4dash-rt-active').textContent = num(d.active);
			renderSpark(d.perMinute || []);
			renderTable('ga4dash-rt-countries', d.countries, [
				{ key: 'label' },
				{ key: 'active', cls: 'ga4dash-n', format: 'num' }
			]);
			renderTable('ga4dash-rt-cities', d.cities, [
				{ key: 'label' },
				{ key: 'active', cls: 'ga4dash-n', format: 'num' }
			]);
			renderTable('ga4dash-rt-pages', d.pages, [
				{ key: 'label', cls: 'ga4dash-path', title: true },
				{ key: 'active', cls: 'ga4dash-n', format: 'num' }
			]);
			renderDoughnut('rtdev', 'ga4dash-rt-devices', d.devices, 'label', 'active');
		}).catch(function () { loadingFor('realtime', false); if (!silent) fail('Network error while loading realtime.'); });
	}

	function startRealtime() {
		stopRealtime();
		loadRealtime(false);
		rtTimer = setInterval(function () { loadRealtime(true); }, 20000);
	}

	function stopRealtime() {
		if (rtTimer) { clearInterval(rtTimer); rtTimer = null; }
	}

	/* ---------- visitors ---------- */

	function ensureMap() {
		if (map || typeof L === 'undefined') return;
		map = L.map('ga4dash-map', { worldCopyJump: true, minZoom: 1, scrollWheelZoom: false }).setView([25, 5], 2);
		L.tileLayer(TILES.base, TILES.opts).addTo(map);
		L.tileLayer(TILES.labels, { maxNativeZoom: 16, maxZoom: 19 }).addTo(map);
		mapLayer = L.layerGroup().addTo(map);
	}

	function renderMap(points) {
		ensureMap();
		if (!map || !mapLayer) return;
		mapLayer.clearLayers();
		(points || []).forEach(function (p) {
			if (p.lat == null || p.lng == null) return;
			var icon = L.divIcon({
				className: 'ga4dash-pin',
				html: '<span class="ga4dash-pin-ring"></span><span class="ga4dash-pin-core"></span>',
				iconSize: [18, 18], iconAnchor: [9, 9]
			});
			var loc = (p.city || '') + (p.city && p.country ? ', ' : '') + (p.country || '');
			var html = '<div class="ga4dash-pop"><div class="ga4dash-pop-ip"><span class="ga4dash-live-dot"></span>' + escapeHtml(p.ip) + '</div>' +
				(loc ? '<div class="ga4dash-pop-loc">' + escapeHtml(loc) + '</div>' : '') +
				(p.last ? '<div class="ga4dash-pop-time">active ' + escapeHtml(p.last) + '</div>' : '') + '</div>';
			L.marker([p.lat, p.lng], { icon: icon }).addTo(mapLayer).bindPopup(html);
		});
		setTimeout(function () { if (map) map.invalidateSize(); }, 60);
	}

	function renderRules(id, rows, list) {
		var tbody = document.querySelector('#' + id + ' tbody');
		if (!tbody) return;
		if (!rows || !rows.length) { tbody.innerHTML = '<tr><td class="ga4dash-empty">None yet.</td></tr>'; return; }
		tbody.innerHTML = rows.map(function (r) {
			return '<tr><td class="ga4dash-path">' + escapeHtml(r.ip) + '</td>' +
				'<td><button type="button" class="button-link ga4dash-rule-remove" data-list="' + list + '" data-ip="' + escapeHtml(r.ip) + '">Remove</button></td></tr>';
		}).join('');
	}

	function renderVisitors(d) {
		var a = el('ga4dash-vis-active');
		if (a) a.textContent = num(d.active || 0);
		var w = el('ga4dash-vis-window');
		if (w && d.window) w.textContent = 'live, last ' + d.window + ' minutes';

		var tbody = document.querySelector('#ga4dash-visitors tbody');
		if (tbody) {
			if (!d.recent || !d.recent.length) {
				tbody.innerHTML = '<tr><td colspan="6" class="ga4dash-empty">No visitor activity recorded yet.</td></tr>';
			} else {
				tbody.innerHTML = d.recent.map(function (r) {
					var dot = r.active ? '<span class="ga4dash-row-live" title="active now"></span>' : '';
					var ago = r.ago ? '<span class="ga4dash-ago">' + escapeHtml(r.ago) + '</span>' : '';
					return '<tr>' +
						'<td class="ga4dash-path">' + dot + escapeHtml(r.ip) + '</td>' +
						'<td>' + escapeHtml(r.location || '') + '</td>' +
						'<td class="ga4dash-path" title="' + escapeHtml(r.path || '') + '">' + escapeHtml(r.path || '') + '</td>' +
						'<td class="ga4dash-n">' + num(r.count || 0) + '</td>' +
						'<td class="ga4dash-when">' + escapeHtml(r.last || '') + ago + '</td>' +
						'<td class="ga4dash-vis-actions">' +
							'<button type="button" class="button-link ga4dash-rule-add" data-list="block" data-ip="' + escapeHtml(r.ip) + '">Block</button> ' +
							'<button type="button" class="button-link ga4dash-rule-add" data-list="allow" data-ip="' + escapeHtml(r.ip) + '">Hide</button>' +
						'</td></tr>';
				}).join('');
			}
		}
		renderMap(d.points);
		renderRules('ga4dash-blocklist', d.block, 'block');
		renderRules('ga4dash-allowlist', d.allow, 'allow');
	}

	function loadVisitors(silent) {
		if (!silent) loadingFor('visitors', true);
		post('ga4dash_visitors', {}).then(function (res) {
			loadingFor('visitors', false);
			if (!res || !res.success) { if (!silent) fail(res && res.data ? res.data.message : null); return; }
			clearError();
			renderVisitors(res.data);
		}).catch(function () { loadingFor('visitors', false); if (!silent) fail('Network error while loading visitors.'); });
	}

	function ipRule(op, list, ip) {
		if (!ip) return;
		post('ga4dash_ip_rule', { op: op, list: list, ip: ip }).then(function (res) {
			if (res && res.success) loadVisitors(true);
			else fail(res && res.data ? res.data.message : 'Could not update the list.');
		}).catch(function () { fail('Network error while updating the list.'); });
	}

	function startVisitors() {
		stopVisitors();
		loadVisitors(false);
		visTimer = setInterval(function () { loadVisitors(true); }, 20000);
	}

	function stopVisitors() {
		if (visTimer) { clearInterval(visTimer); visTimer = null; }
	}

	function initVisitorActions() {
		var view = document.querySelector('[data-view="visitors"]');
		if (!view) return;
		view.addEventListener('click', function (e) {
			var t = e.target;
			if (t.classList.contains('ga4dash-rule-add')) ipRule('add', t.getAttribute('data-list'), t.getAttribute('data-ip'));
			else if (t.classList.contains('ga4dash-rule-remove')) ipRule('remove', t.getAttribute('data-list'), t.getAttribute('data-ip'));
			else if (t.hasAttribute('data-add')) {
				var list = t.getAttribute('data-add');
				var input = el(list === 'block' ? 'ga4dash-add-block' : 'ga4dash-add-allow');
				if (input && input.value.trim()) { ipRule('add', list, input.value.trim()); input.value = ''; }
			}
		});
	}

	/* ---------- tabs & range ---------- */

	function activate(tab) {
		current = tab;
		document.querySelectorAll('.ga4dash-tab').forEach(function (b) {
			b.classList.toggle('is-active', b.getAttribute('data-tab') === tab);
		});
		document.querySelectorAll('.ga4dash-view').forEach(function (v) {
			v.classList.toggle('is-active', v.getAttribute('data-view') === tab);
		});
		// The date range applies to Overview and Audience only.
		show('ga4dash-rangebar', tab !== 'realtime' && tab !== 'visitors');

		if (tab !== 'realtime') stopRealtime();
		if (tab !== 'visitors') stopVisitors();

		if (tab === 'realtime') { startRealtime(); return; }
		if (tab === 'visitors') { startVisitors(); return; }
		if (tab === 'overview' && !loaded.overview) loadOverview();
		if (tab === 'audience' && !loaded.audience) loadAudience();
	}

	function reloadCurrent() {
		if (current === 'overview') loadOverview();
		else if (current === 'audience') loadAudience();
		else if (current === 'visitors') loadVisitors(false);
		else loadRealtime(false);
	}

	function initTabs() {
		document.querySelectorAll('.ga4dash-tab').forEach(function (btn) {
			btn.addEventListener('click', function () { activate(btn.getAttribute('data-tab')); });
		});
	}

	function initRange() {
		var range = el('ga4dash-range');
		if (!range) return;
		range.addEventListener('change', function () {
			show('ga4dash-custom', range.value === 'custom');
			if (range.value !== 'custom') {
				loaded.overview = false; loaded.audience = false;
				reloadCurrent();
			}
		});
		var refresh = el('ga4dash-refresh');
		if (refresh) refresh.addEventListener('click', function () {
			loaded.overview = false; loaded.audience = false;
			reloadCurrent();
		});
		var apply = el('ga4dash-apply');
		if (apply) apply.addEventListener('click', function () {
			if (!el('ga4dash-start').value || !el('ga4dash-end').value) return;
			loaded.overview = false; loaded.audience = false;
			reloadCurrent();
		});
	}

	function initTest() {
		var btn = el('ga4dash-test');
		if (!btn) return;
		btn.addEventListener('click', function () {
			var out = el('ga4dash-test-result');
			out.textContent = 'Testing…';
			out.className = 'ga4dash-test-result';
			post('ga4dash_test', {}).then(function (res) {
				if (res && res.success) {
					out.textContent = res.data.message;
					out.className = 'ga4dash-test-result is-ok';
				} else {
					out.textContent = res && res.data ? res.data.message : 'Test failed.';
					out.className = 'ga4dash-test-result is-bad';
				}
			}).catch(function () {
				out.textContent = 'Network error.';
				out.className = 'ga4dash-test-result is-bad';
			});
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		if (document.getElementById('ga4dash-tabs')) {
			initTabs();
			initRange();
			initVisitorActions();
			activate('overview');
		}
		initTest();
	});

	window.addEventListener('beforeunload', function () { stopRealtime(); stopVisitors(); });
})();
