(function () {
	'use strict';

	var contentTypeFilter = 'all';
	var elementorTypeFilter = 'all';
	// Severity pills (All / Critical / Warning / Notice / Passed): one per tab.
	var sectionSeverity = { urls: 'all', content: 'all', elementor: 'all', site: 'all' };
	var FILTER_STORE = 'dupcapDashboardFilters';
	var contentSearch = '';
	var contentSearchRaw = '';
	var activeTab = 'overview';
	var TAB_HASH_PREFIX = 'tab-';

	function qs(selector, context) {
		return (context || document).querySelector(selector);
	}

	function qsa(selector, context) {
		return Array.prototype.slice.call((context || document).querySelectorAll(selector));
	}

	// Press the button whose value matches in one filter set. Falls back to
	// "all" when that button is gone or disabled after a re-render.
	function syncFilterState(selector, key, value) {
		var buttons = qsa(selector);
		var exists = buttons.some(function (button) {
			return (button.dataset[key] || 'all') === value && !button.disabled;
		});
		var current = exists ? value : 'all';

		buttons.forEach(function (button) {
			var active = (button.dataset[key] || 'all') === current;
			button.classList.toggle('is-active', active);
			button.setAttribute('aria-pressed', active ? 'true' : 'false');
		});

		return current;
	}

	// Severity pills work the same in every tab. Info cards (e.g. URLs to
	// Info rows (manual checks the user has to verify by hand) count as warnings.
	// Severity "All" still shows the Ignored panel; type-pill totals do not
	// count ignored rows (same as passed checks).
	function severityMatches(key, severity) {
		var filter = sectionSeverity[key] || 'all';
		if (filter === 'all') {
			return true;
		}
		if (filter === 'ignored') {
			return severity === 'ignored';
		}
		// A Notice is its own level once the issue layer is on. Before that it had
		// no pill of its own, so it had to answer to the Warning filter or it
		// could never be shown at all.
		if (severity === 'info' && !hasNoticeLevel()) {
			severity = 'warn';
		}
		return severity === filter;
	}

	function hasNoticeLevel() {
		return !!(window.dupcapDashboard && window.dupcapDashboard.noticeLevel);
	}

	// Filter choices are a working preference, not data: browser storage is the
	// right home for them, and a browser that refuses it just means the filters
	// reset on reload.
	function saveFilters() {
		try {
			window.localStorage.setItem(FILTER_STORE, JSON.stringify({ severity: sectionSeverity }));
		} catch (e) {
			// Private windows and blocked site data both land here.
		}
	}

	function loadFilters() {
		var raw;
		var saved;
		var keys = ['urls', 'content', 'elementor', 'site'];
		try {
			raw = window.localStorage.getItem(FILTER_STORE);
		} catch (e) {
			return;
		}
		if (!raw) {
			return;
		}
		try {
			saved = JSON.parse(raw);
		} catch (e) {
			return;
		}
		if (!saved || typeof saved !== 'object') {
			return;
		}
		keys.forEach(function (key) {
			if (saved.severity && typeof saved.severity[key] === 'string') {
				sectionSeverity[key] = saved.severity[key];
			}
		});
	}

	function severitySelector(key) {
		return '[data-dupcap-tab-panel="' + key + '"] [data-dupcap-filter-group="severity"]';
	}

	// "Nothing matches these filters" is true but unhelpful when the user just
	// asked for one severity. Name the thing that is not there instead.
	function severityEmptyMessage(key) {
		var filter = sectionSeverity[key] || 'all';
		var map = (window.dupcapDashboard && window.dupcapDashboard.severityEmptyText) || {};

		return 'all' === filter ? '' : (map[filter] || '');
	}

	function setFilterEmpty(panel, show, message) {
		var empty = panel ? qs('[data-dupcap-filter-empty]', panel) : null;
		if (empty) {
			if (!empty.dataset.dupcapEmptyDefault) {
				empty.dataset.dupcapEmptyDefault = empty.textContent;
			}
			empty.textContent = message || empty.dataset.dupcapEmptyDefault || empty.textContent;
			empty.classList.toggle('dupcap-hidden', !show);
		}
	}

	// URLs & Routing and Site setup: severity is the only filter. Cards,
	// passed checks and the critical pointer all carry data-dupcap-severity.
	function applySeverityPanel(key) {
		var panel = qs('[data-dupcap-tab-panel="' + key + '"]');
		var items;
		var shown = 0;

		if (!panel) {
			return;
		}

		items = qsa('[data-dupcap-severity]', panel);
		items.forEach(function (item) {
			var ok = severityMatches(key, item.getAttribute('data-dupcap-severity'));
			item.classList.toggle('dupcap-hidden', !ok);
			if (ok) {
				shown += 1;
				if (item.getAttribute('data-dupcap-severity') === 'ignored' && (sectionSeverity[key] || 'all') === 'ignored') {
					item.open = true;
				}
			}
		});
		setFilterEmpty(panel, items.length > 0 && shown === 0, severityEmptyMessage(key));
	}

	function contentTypeMatches(type) {
		if (contentTypeFilter === 'all') {
			return true;
		}
		if (contentTypeFilter === 'custom_post') {
			return type !== '' && type !== 'page' && type !== 'post';
		}
		return type === contentTypeFilter;
	}

	function contentSearchMatches(row) {
		var text;

		if (!contentSearch) {
			return true;
		}
		text = (row.getAttribute('data-dupcap-title') || row.textContent || '').toLowerCase();
		return text.indexOf(contentSearch) !== -1;
	}

	// A row with no post type (e.g. "No language" rows) only matches while
	// the type filter is on "All".
	function contentRowMatches(row) {
		if (!contentTypeMatches(row.getAttribute('data-dupcap-content-type') || '')) {
			return false;
		}
		return contentSearchMatches(row);
	}

	// A type chip says how many rows picking it would show, so it counts under
	// the severity and the search but not under the type choice itself -
	// otherwise every chip but the active one reads 0. Severity is read from
	// the card because the row carries none.
	function syncContentTypeCounts() {
		var host = qs('#dupcap-content-cards');
		var bar = qs('[data-dupcap-content-filters]');
		var counts = {};
		var total = 0;

		if (!host || !bar) {
			return;
		}

		qsa('[data-dupcap-card]', host).forEach(function (card) {
			if (!severityMatches('content', card.getAttribute('data-dupcap-severity'))) {
				return;
			}
			qsa('[data-dupcap-paginate] > *', card).forEach(function (row) {
				var type;

				if (!contentSearchMatches(row)) {
					return;
				}
				total += 1;
				type = row.getAttribute('data-dupcap-content-type') || '';
				if (type) {
					counts[type] = (counts[type] || 0) + 1;
				}
			});
		});

		qsa('button[data-dupcap-filter-group="type"]', bar).forEach(function (button) {
			var value = button.dataset.dupcapFilterValue || 'all';
			var countEl = qs('.dupcap-filter-count', button);

			if (countEl) {
				countEl.textContent = String(value === 'all' ? total : (counts[value] || 0));
			}
		});
	}

	// Card heading while a filter is active. The wording comes from data
	// attributes so it stays translatable, and the number is the count of rows
	// on screen - never rows x languages.
	function updateCardHeading(card, shown, filtered) {
		var titleEl = qs('[data-dupcap-card-title]', card);
		var base;
		var count;
		var template;

		if (!titleEl || !titleEl.dataset.dupcapCountMany) {
			return;
		}

		base = titleEl.dataset.dupcapCardTitle || '';
		count = filtered ? String(shown) : (titleEl.dataset.dupcapCardCount || '');
		template = count === '1'
			? (titleEl.dataset.dupcapCountOne || titleEl.dataset.dupcapCountMany)
			: titleEl.dataset.dupcapCountMany;

		if (base && count) {
			titleEl.textContent = template.replace('%1$s', base).replace('%2$s', count);
		}
	}

	// While filtering, the card summary counts what is on screen; with no
	// filter it goes back to the sentence the server rendered.
	function updateCardSummary(card, shown, total, filtered) {
		var summaryEl = qs('[data-dupcap-card-summary]', card);
		var template;

		if (!summaryEl) {
			return;
		}

		if (typeof summaryEl.dataset.dupcapSummaryDefault !== 'string') {
			summaryEl.dataset.dupcapSummaryDefault = summaryEl.textContent;
		}

		template = summaryEl.dataset.dupcapSummaryFiltered || '';
		summaryEl.textContent = (filtered && template)
			? template.replace('%1$s', String(shown)).replace('%2$s', String(total))
			: summaryEl.dataset.dupcapSummaryDefault;
	}

	// Content tab: severity picks cards; post type and title search pick rows.
	// A card stays visible only while it still has a matching row. Passed
	// checks and the critical pointer follow severity only.
	function applyContentFilters() {
		var panel = qs('[data-dupcap-tab-panel="content"]');
		var host = qs('#dupcap-content-cards');
		var status = qs('[data-dupcap-content-filter-status]');
		var narrowing = contentTypeFilter !== 'all' || contentSearch !== '';
		var cards = host ? qsa('[data-dupcap-card]', host) : [];
		var others;
		var visible = 0;
		var matchedRows = 0;
	
		if (!panel) {
			return;
		}
	
		cards.forEach(function (card) {
			var severityOk = severityMatches('content', card.getAttribute('data-dupcap-severity'));
			var rows = qsa('[data-dupcap-paginate] > *', card);
			var matched = 0;
			var show;

			rows.forEach(function (row) {
				var ok = severityOk && contentRowMatches(row);
				row.classList.toggle('dupcap-hidden', !ok);
				if (ok) {
					matched += 1;
				}
			});
	
			show = severityOk && (rows.length ? matched > 0 : !narrowing);
			card.classList.toggle('dupcap-hidden', !show);
			if (show) {
				visible += 1;
				matchedRows += rows.length ? matched : 1;
			}
	
			updateCardHeading(card, matched, narrowing);
			updateCardSummary(card, matched, rows.length, narrowing && rows.length > 0);
		});
	
		others = qsa('[data-dupcap-severity]', panel).filter(function (item) {
			return !host || !host.contains(item);
		});
		others.forEach(function (item) {
			var severityOk = severityMatches('content', item.getAttribute('data-dupcap-severity'));
			var rows;
			var matched = 0;
			var show;

			if (item.getAttribute('data-dupcap-severity') === 'ignored') {
				rows = qsa('[data-dupcap-paginate] > *', item);
				rows.forEach(function (row) {
					var ok = severityOk && contentRowMatches(row);
					row.classList.toggle('dupcap-hidden', !ok);
					if (ok) {
						matched += 1;
					}
				});
				show = severityOk && (rows.length ? matched > 0 : true);
				item.classList.toggle('dupcap-hidden', !show);
				if (show) {
					visible += 1;
					matchedRows += matched;
					if ((sectionSeverity.content || 'all') === 'ignored') {
						item.open = true;
					}
				}
				return;
			}

			item.classList.toggle('dupcap-hidden', !severityOk);
			if (severityOk) {
				visible += 1;
			}
		});
	
		// With a type or a search narrowing too, the generic wording is the honest one.
		setFilterEmpty(panel, cards.length + others.length > 0 && visible === 0, narrowing ? '' : severityEmptyMessage('content'));
	
		if (status) {
			status.textContent = narrowing
				? (status.getAttribute('data-template') || '%s').replace('%s', String(matchedRows))
				: '';
		}

		syncContentTypeCounts();
	}

	function bindContentFilters(context) {
		qsa('[data-dupcap-content-filters]', context).forEach(function (bar) {
			var input = qs('[data-dupcap-content-search]', bar);

			if (bar.dataset.dupcapFiltersBound) {
				return;
			}
			bar.dataset.dupcapFiltersBound = '1';

			bar.addEventListener('click', function (event) {
				var button = event.target.closest('button[data-dupcap-filter-group="type"]');

				if (!button || button.disabled) {
					return;
				}

				contentTypeFilter = syncFilterState('[data-dupcap-filter-group="type"]', 'dupcapFilterValue', button.dataset.dupcapFilterValue || 'all');
				applyFilters();
			});

			if (input) {
				input.addEventListener('input', function () {
					contentSearchRaw = input.value;
					contentSearch = input.value.trim().toLowerCase();
					applyFilters();
				});
			}
		});
	}

	// Re-apply the user's Content filters to freshly rendered markup.
	function restoreContentFilters() {
		var bar = qs('[data-dupcap-content-filters]');
		var input;

		if (!bar) {
			return;
		}

		contentTypeFilter = syncFilterState('[data-dupcap-filter-group="type"]', 'dupcapFilterValue', contentTypeFilter);
		input = qs('[data-dupcap-content-search]', bar);
		if (input) {
			input.value = contentSearchRaw;
		}
	}

	function applyFilters() {
		applyContentFilters();
		applySeverityPanel('urls');
		applySeverityPanel('site');
		// A filter changed which rows count, so every list starts again at page 1.
		refreshPagination(true);
	}

	// One delegated handler per severity pill group; the tab it sits in
	// decides which value it sets.
	function bindSeverityFilters(context) {
		qsa('[data-dupcap-severity-filters]', context).forEach(function (group) {
			var panel = group.closest('[data-dupcap-tab-panel]');
			var key = panel ? panel.getAttribute('data-dupcap-tab-panel') : '';

			if (!key || group.dataset.dupcapSeverityBound) {
				return;
			}
			group.dataset.dupcapSeverityBound = '1';

			group.addEventListener('click', function (event) {
				var button = event.target.closest('button[data-dupcap-filter-group="severity"]');

				if (!button || button.disabled) {
					return;
				}

				sectionSeverity[key] = syncFilterState(severitySelector(key), 'dupcapFilterValue', button.dataset.dupcapFilterValue || 'all');
				saveFilters();
				if (key === 'elementor') {
					applyElementorTypeFilters();
				} else {
					applyFilters();
				}
			});
		});
	}

	function bindPassedSearch(context) {
		qsa('[data-dupcap-passed-search]', context).forEach(function (input) {
			input.addEventListener('input', function () {
				var search = input.value.trim().toLowerCase();
				qsa('[data-dupcap-pass-text]', input.closest('.dupcap-passed-panel')).forEach(function (card) {
					card.classList.toggle('dupcap-hidden', search && card.dataset.dupcapPassText.indexOf(search) === -1);
				});
			});
		});
	}

	var completeTimer = null;
	var rescanInFlight = false;

	function setRescanButtonState(button, state) {
		var cfg = window.dupcapDashboard || {};
		var label;
		var spinner;
		var defaultText;
		var text;

		if (!button) {
			return;
		}

		label = qs('.dupcap-rescan-label', button);
		defaultText = button.dataset.defaultLabel || cfg.defaultText || 'Scan Website';
		button.dataset.defaultLabel = defaultText;

		if (state === 'loading') {
			text = cfg.loadingText || 'Scanning...';
			button.classList.add('is-loading');
			button.classList.remove('is-complete');
			button.classList.remove('dupcap-rescan-button--prompt');
			if (button.parentElement && button.parentElement.classList.contains('dupcap-scan-cta')) {
				button.parentElement.classList.remove('is-prompt');
			}
			button.disabled = true;
			button.setAttribute('aria-busy', 'true');

			spinner = qs('.dupcap-rescan-spinner', button);
			if (!spinner) {
				spinner = document.createElement('span');
				spinner.className = 'dashicons dashicons-update dupcap-rescan-spinner';
				spinner.setAttribute('aria-hidden', 'true');
				button.insertBefore(spinner, button.firstChild);
			}
		} else if (state === 'complete') {
			text = cfg.completeText || 'Scan Complete';
			button.classList.remove('is-loading');
			button.classList.add('is-complete');
			button.disabled = true;
			button.setAttribute('aria-busy', 'false');

			spinner = qs('.dupcap-rescan-spinner', button);
			if (spinner && spinner.parentNode) {
				spinner.parentNode.removeChild(spinner);
			}
		} else {
			text = defaultText;
			button.classList.remove('is-loading');
			button.classList.remove('is-complete');
			button.disabled = false;
			button.setAttribute('aria-busy', 'false');

			spinner = qs('.dupcap-rescan-spinner', button);
			if (spinner && spinner.parentNode) {
				spinner.parentNode.removeChild(spinner);
			}
		}

		if (label) {
			label.textContent = text;
		}
	}

	function showCompleteThenReset() {
		var button = qs('[data-dupcap-rescan]');
		var cfg = window.dupcapDashboard || {};

		if (completeTimer) {
			window.clearTimeout(completeTimer);
			completeTimer = null;
		}

		if (!button) {
			return;
		}

		button.dataset.defaultLabel = cfg.defaultText || button.dataset.defaultLabel || 'Scan Website';
		setRescanButtonState(button, 'complete');

		completeTimer = window.setTimeout(function () {
			completeTimer = null;
			setRescanButtonState(qs('[data-dupcap-rescan]'), 'idle');
		}, 3000);
	}

	// Stable id for a details card so open state can survive fragment refresh
	// after Ignore / rescan in every tab (Content, Elementor, Site setup, URLs).
	function cardOpenKey(card) {
		var panel;
		var tab;
		var id;
		var group;
		var bucketTitle;
		var cardTitle;

		if (!card) {
			return '';
		}

		panel = card.closest('[data-dupcap-tab-panel]');
		tab = panel ? (panel.getAttribute('data-dupcap-tab-panel') || '') : '';

		id = card.getAttribute('data-dupcap-card-id');
		if (id) {
			return tab + '::' + id;
		}

		group = card.getAttribute('data-dupcap-group');
		if (group) {
			return tab + '::group:' + group;
		}

		bucketTitle = qs('[data-dupcap-bucket-title]', card);
		if (bucketTitle && bucketTitle.getAttribute('data-dupcap-bucket-title')) {
			return tab + '::bucket:' + (card.getAttribute('data-dupcap-bucket-severity') || '') + ':' + bucketTitle.getAttribute('data-dupcap-bucket-title');
		}

		cardTitle = qs('[data-dupcap-card-title]', card);
		if (cardTitle && cardTitle.getAttribute('data-dupcap-card-title')) {
			return tab + '::title:' + cardTitle.getAttribute('data-dupcap-card-title');
		}

		return '';
	}

	function collectOpenCardState(root) {
		var state = [];

		qsa('details[data-dupcap-card][open]', root).forEach(function (card) {
			var key = cardOpenKey(card);
			var pages;

			if (!key) {
				return;
			}

			pages = qsa('[data-dupcap-paginate]', card).map(function (list) {
				return parseInt(list.getAttribute('data-dupcap-page'), 10) || 1;
			});

			state.push({ key: key, pages: pages });
		});

		return state;
	}

	function restoreOpenCards(state, root) {
		if (!state || !state.length) {
			return;
		}

		// Active-tab cards last so named accordion exclusivity keeps the one
		// the user was looking at open.
		state.slice().sort(function (a, b) {
			var aActive = a.key.indexOf((activeTab || '') + '::') === 0 ? 1 : 0;
			var bActive = b.key.indexOf((activeTab || '') + '::') === 0 ? 1 : 0;
			return aActive - bActive;
		}).forEach(function (entry) {
			qsa('details[data-dupcap-card]', root).forEach(function (card) {
				var lists;

				if (cardOpenKey(card) !== entry.key) {
					return;
				}

				card.open = true;

				lists = qsa('[data-dupcap-paginate]', card);
				(entry.pages || []).forEach(function (page, index) {
					if (lists[index] && page > 1) {
						renderPage(lists[index], page);
					}
				});
			});
		});
	}

	// Exclusive accordion behavior: opening one card closes others in the same panel
	document.addEventListener('toggle', function (e) {
		var target = e.target;
		var panel;
		if (!target || target.tagName !== 'DETAILS' || !target.hasAttribute('data-dupcap-card') || !target.open) {
			return;
		}
		panel = target.closest('#dupcap-content-cards, #dupcap-problem-cards, #dupcap-url-cards, [data-dupcap-tab-panel], .dupcap-grouped-section');
		if (!panel) {
			return;
		}
		qsa('details[data-dupcap-card][open]', panel).forEach(function (card) {
			if (card !== target) {
				card.open = false;
			}
		});
	}, true);

	function refreshDashboardFragments(payload) {
		var header = qs('#dupcap-dashboard-header');
		var notices = qs('#dupcap-dashboard-notices');
		var body = qs('#dupcap-dashboard-body');
		var openState = body ? collectOpenCardState(body) : [];
	
		if (!payload || !payload.success) {
			throw new Error(payload && payload.data && payload.data.message ? payload.data.message : window.dupcapDashboard.defaultError);
		}
	
		if (header && payload.data.header) {
			header.innerHTML = payload.data.header;
		}
	
		if (notices && typeof payload.data.notices === 'string') {
			notices.innerHTML = payload.data.notices;
		}
	
		if (body && payload.data.body) {
			body.innerHTML = payload.data.body;
		}
	
		bindDashboard(document);
		// Keep the user's filters across the refresh (e.g. after Ignore); a value
		// whose button is gone or disabled falls back to "All".
		restoreContentFilters();
		Object.keys(sectionSeverity).forEach(function (key) {
			sectionSeverity[key] = syncFilterState(severitySelector(key), 'dupcapFilterValue', sectionSeverity[key]);
		});
		applyFilters();
		elementorTypeFilter = syncFilterState('[data-dupcap-elementor-type-filter]', 'dupcapElementorTypeFilter', elementorTypeFilter);
		applyElementorTypeFilters();
		// The server always renders Overview as active; keep the user's tab.
		activateTab(activeTab);
		// Ignore / rescan replace the body HTML; reopen cards in every section.
		restoreOpenCards(openState, body || document);
	}

	function runRescan(button, showOverlay) {
		var data;
		var cfg = window.dupcapDashboard || {};
	
		// button.disabled: the server disables Scan Website while content has no
		// language. Programmatic callers (e.g. after a plugin install) must respect
		// that too, or the block only holds for real clicks.
		if (rescanInFlight || !button || button.disabled || !cfg.ajaxUrl) {
			if (showOverlay) {
				hideScanOverlay();
			}
			return;
		}
	
		rescanInFlight = true;
	
		if (completeTimer) {
			window.clearTimeout(completeTimer);
			completeTimer = null;
		}
	
		if (showOverlay) {
			showScanOverlay();
		}
	
		setRescanButtonState(button, 'loading');
	
		data = new window.FormData();
		data.append('action', 'dupcap_rescan_dashboard');
		data.append('pll_ajax_backend', '1');
		data.append('nonce', cfg.nonce);
	
		window.fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		})
			.then(function (response) {
				if (!response.ok) {
					throw new Error(cfg.defaultError);
				}
				return response.json();
			})
			.then(function (payload) {
				refreshDashboardFragments(payload);
				showCompleteThenReset();
			})
			.catch(function () {
				setRescanButtonState(qs('[data-dupcap-rescan]'), 'idle');
			})
			.then(function () {
				rescanInFlight = false;
				if (showOverlay) {
					hideScanOverlay();
				}
			});
	}

	function bindRescan(context) {
		qsa('[data-dupcap-rescan]', context).forEach(function (button) {
			button.addEventListener('click', function () {
				runRescan(button, true);
			});
		});
	}

	// Ignore and Restore are the same request with a different action.
	function bindIgnoreAction(context, attr, datasetKey, action, busyKey, busyFallback) {
		qsa('[' + attr + ']', context).forEach(function (button) {
			button.addEventListener('click', function () {
				var cfg = window.dupcapDashboard || {};
				var defaultLabel = button.textContent;
				var data;

				if (!cfg.ajaxUrl || !button.dataset[datasetKey]) {
					return;
				}

				button.disabled = true;
				button.textContent = cfg[busyKey] || busyFallback;

				data = new window.FormData();
				data.append('action', action);
				data.append('pll_ajax_backend', '1');
				data.append('nonce', cfg.nonce);
				data.append('ignore_key', button.dataset[datasetKey]);

				window.fetch(cfg.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: data
				})
					.then(function (response) {
						return response.json();
					})
					.then(refreshDashboardFragments)
					.catch(function () {
						button.disabled = false;
						button.textContent = defaultLabel;
					});
			});
		});
	}

	function bindIgnore(context) {
		bindIgnoreAction(context, 'data-dupcap-ignore', 'dupcapIgnore', 'dupcap_ignore_issue', 'ignoringText', 'Ignoring...');
	}

	function bindUnignore(context) {
		bindIgnoreAction(context, 'data-dupcap-unignore', 'dupcapUnignore', 'dupcap_unignore_issue', 'restoringText', 'Restoring...');
	}

	// One chip can stand for several slugs ("single single-post single-page").
	function elementorTypeMatches(type) {
		return elementorTypeFilter === 'all' || elementorTypeFilter.split(/\s+/).indexOf(type) !== -1;
	}

	function cardMatchesElementorType(card) {
		if (elementorTypeFilter === 'all') {
			return true;
		}
		var typesStr = card.dataset.dupcapElementorDocumentTypes;
		if (!typesStr) {
			return false;
		}
		return typesStr.split(/\s+/).some(elementorTypeMatches);
	}

	function applyElementorTypeFilters() {
		var section = qs('.dupcap-elementor-insights');
		if (!section) {
			return;
		}

		qsa('[data-dupcap-document-type]', section).forEach(function (item) {
			var matches = elementorTypeMatches(item.dataset.dupcapDocumentType || '');
			item.classList.toggle('dupcap-hidden', !matches);
		});

		qsa('.dupcap-elementor-bucket', section).forEach(function (bucket) {
			var items = qsa('[data-dupcap-document-type]', bucket);
			var visible = 0;
			items.forEach(function (item) {
				if (!item.classList.contains('dupcap-hidden')) {
					visible += 1;
				}
			});
			var severityOk = severityMatches('elementor', bucket.getAttribute('data-dupcap-bucket-severity'));
			var hideBucket = !severityOk || (elementorTypeFilter !== 'all' && visible === 0);
			bucket.classList.toggle('dupcap-hidden', hideBucket);

			var titleEl = qs('[data-dupcap-bucket-title]', bucket);
			var total = parseInt(bucket.dataset.dupcapBucketTotal, 10);
			var count;
			if (isNaN(total)) {
				total = items.length;
			}
			count = elementorTypeFilter === 'all' ? total : visible;

			if (titleEl) {
				var title = titleEl.dataset.dupcapBucketTitle || '';
				titleEl.textContent = title + ' (' + count + ')';
			}
		});

		qsa('.dupcap-elementor-template-groups [data-dupcap-document-type]', section).forEach(function (row) {
			row.classList.toggle('dupcap-hidden', !elementorTypeMatches(row.dataset.dupcapDocumentType || ''));
		});

		qsa('.dupcap-elementor-template-groups [data-dupcap-card]', section).forEach(function (card) {
			var rows = qsa('[data-dupcap-document-type]', card);
			var typeOk = rows.length
				? rows.some(function (row) { return elementorTypeMatches(row.dataset.dupcapDocumentType || ''); })
				: cardMatchesElementorType(card);
			var severityOk = severityMatches('elementor', card.dataset.dupcapSeverity);
			card.classList.toggle('dupcap-hidden', !typeOk || !severityOk);

			var shownRows = rows.filter(function (row) { return !row.classList.contains('dupcap-hidden'); }).length;
			var typeFiltered = elementorTypeFilter !== 'all';

			updateCardHeading(card, shownRows, typeFiltered);
			updateCardSummary(card, shownRows, rows.length, typeFiltered && rows.length > 0);
		});

		var panel = section.closest('[data-dupcap-tab-panel]') || section;
		var typeFiltering = elementorTypeFilter !== 'all';
		var visiblePasses = 0;

		qsa('.dupcap-pass-card', section).forEach(function (card) {
			var type = card.dataset.dupcapDocumentType || '';
			var typeOk = !typeFiltering || (type && elementorTypeMatches(type));
			card.classList.toggle('dupcap-hidden', !typeOk);
			if (typeOk) {
				visiblePasses += 1;
			}
		});

		qsa('.dupcap-passed-panel, .dupcap-critical-pointer', panel).forEach(function (item) {
			var severity = item.getAttribute('data-dupcap-severity');
			var severityOk = severityMatches('elementor', severity);
			var typeOk = true;
			if (severity === 'ignored') {
				typeOk = true;
				if (severityOk && (sectionSeverity.elementor || 'all') === 'ignored') {
					item.open = true;
				}
			} else if (typeFiltering && item.classList.contains('dupcap-passed-panel')) {
				typeOk = visiblePasses > 0;
			} else if (typeFiltering) {
				typeOk = false;
			}
			item.classList.toggle('dupcap-hidden', !severityOk || !typeOk);
		});

		var passedTitle = qs('.dupcap-passed-panel:not(.dupcap-ignored-panel) strong', section);
		if (passedTitle && typeFiltering) {
			if (!passedTitle.dataset.dupcapPassedLabel) {
				passedTitle.dataset.dupcapPassedLabel = passedTitle.textContent;
			}
			passedTitle.textContent = passedTitle.dataset.dupcapPassedLabel.replace(/\(\d+\)/, '(' + visiblePasses + ')');
		} else if (passedTitle && passedTitle.dataset.dupcapPassedLabel) {
			passedTitle.textContent = passedTitle.dataset.dupcapPassedLabel;
		}

		var anyBucket = qsa('.dupcap-elementor-bucket:not(.dupcap-hidden)', section).length > 0;
		var anyCard = qsa('.dupcap-elementor-template-groups [data-dupcap-card]:not(.dupcap-hidden)', section).length > 0;
		var anyPassed = qsa('.dupcap-passed-panel:not(.dupcap-hidden):not(.dupcap-ignored-panel)', panel).length > 0;
		var anyIgnored = qsa('.dupcap-ignored-panel:not(.dupcap-hidden)', panel).length > 0;
		var filtering = typeFiltering || sectionSeverity.elementor !== 'all';
		var emptyMessage = '';
		if (typeFiltering && !anyBucket && !anyCard && !anyIgnored) {
			var activeType = qs('[data-dupcap-elementor-type-filter].is-active', section);
			var typeLabel = activeType && activeType.childNodes[0] ? activeType.childNodes[0].textContent.trim() : '';
			var typeEmpty = (window.dupcapDashboard && window.dupcapDashboard.elementorTypeEmptyText) || 'No issues found for %s.';
			emptyMessage = typeLabel ? typeEmpty.replace('%s', typeLabel) : typeEmpty.replace('%s', '');
		}
		if ('' === emptyMessage && !typeFiltering) {
			emptyMessage = severityEmptyMessage('elementor');
		}
		setFilterEmpty(panel, filtering && !anyBucket && !anyCard && !anyPassed && !anyIgnored, emptyMessage);

		syncActiveTypeChipCount(section);
		refreshPagination(true, section);
	}

	// Same rule as the Content chips: count from the data under the severity
	// filter only. Counting visible items made every chip but the active one
	// read 0, which is why only the active one used to be refreshed.
	function syncActiveTypeChipCount(section) {
		var counts = {};
		var total = 0;

		qsa('.dupcap-elementor-bucket, .dupcap-elementor-template-groups [data-dupcap-card]', section).forEach(function (host) {
			var severity = host.classList.contains('dupcap-elementor-bucket')
				? host.getAttribute('data-dupcap-bucket-severity')
				: host.getAttribute('data-dupcap-severity');

			if (!severityMatches('elementor', severity)) {
				return;
			}

			qsa('[data-dupcap-document-type]', host).forEach(function (item) {
				var type = item.dataset.dupcapDocumentType || '';

				total += 1;
				if (type) {
					counts[type] = (counts[type] || 0) + 1;
				}
			});
		});

		qsa('[data-dupcap-elementor-type-filter]', section).forEach(function (button) {
			var filter = button.dataset.dupcapElementorTypeFilter || 'all';
			var countEl = qs('.dupcap-filter-count', button);
			var count = 0;

			if (!countEl) {
				return;
			}

			if (filter === 'all') {
				count = total;
			} else {
				filter.split(/\s+/).forEach(function (slug) {
					count += counts[slug] || 0;
				});
			}

			countEl.textContent = String(count);
		});
	}

	function bindElementorTypeFilters(context) {
		qsa('[data-dupcap-elementor-type-filter]', context).forEach(function (button) {
			button.addEventListener('click', function () {
				elementorTypeFilter = button.dataset.dupcapElementorTypeFilter || 'all';
				qsa('[data-dupcap-elementor-type-filter]', context).forEach(function (btn) {
					var active = btn === button;
					btn.classList.toggle('is-active', active);
					btn.setAttribute('aria-pressed', active ? 'true' : 'false');
				});
				applyElementorTypeFilters();
			});
		});
	}

	function setRescanIssueStatus(button, resolved, message) {
		setIssueCheckState({ rescanButton: button }, resolved ? 'resolved' : 'present', message);
	}
	
	// One-row rescans share the request and the result handling; only the
	// action and the fields that identify the row differ.
	function bindRowRescan(context, attr, boundKey, action, fields) {
		qsa('[' + attr + ']', context).forEach(function (button) {
			if (button.dataset[boundKey]) {
				return;
			}
			button.dataset[boundKey] = '1';
			button.addEventListener('click', function () {
				var cfg = window.dupcapDashboard || {};
				var data;
	
				if (!cfg.ajaxUrl || button.disabled) {
					return;
				}
	
				button.disabled = true;
				button.classList.add('is-loading');
	
				data = new window.FormData();
				data.append('action', action);
				data.append('pll_ajax_backend', '1');
				data.append('nonce', cfg.nonce);
				fields(button).forEach(function (pair) {
					data.append(pair[0], pair[1]);
				});
	
				window.fetch(cfg.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: data
				})
					.then(function (response) {
						return response.json();
					})
					.then(function (payload) {
						var resolved;
						var message;
	
						if (!payload || !payload.success) {
							button.disabled = false;
							button.classList.remove('is-loading');
							return;
						}
	
						resolved = !!(payload.data && payload.data.resolved);
						message = payload.data && payload.data.message;
	
						if (resolved) {
							// Issue is gone: refresh the whole dashboard so the
							// card/bucket/counts update to reflect it.
							refreshDashboardFragments(payload);
						} else {
							// Issue still present: leave the card alone, just
							// show an inline warning and re-enable the button.
							setRescanIssueStatus(button, false, message);
							button.disabled = false;
							button.classList.remove('is-loading');
						}
					})
					.catch(function () {
						button.disabled = false;
						button.classList.remove('is-loading');
					});
			});
		});
	}

	function bindRescanIssue(context) {
		bindRowRescan(context, 'data-dupcap-rescan-issue', 'dupcapRescanBound', 'dupcap_rescan_issue', function (button) {
			return [
				['template_id', button.dataset.templateId || ''],
				['ignore_key', button.dataset.ignoreKey || '']
			];
		});
	}

	function bindRescanLibraryIssue(context) {
		bindRowRescan(context, 'data-dupcap-rescan-library-issue', 'dupcapLibraryRescanBound', 'dupcap_rescan_elementor_library_issue', function (button) {
			return [
				['template_type', button.getAttribute('data-template-type') || ''],
				['language', button.getAttribute('data-language') || '']
			];
		});
	}

	function getContentRescanTargets(button) {
		var wrap = button.closest('.dupcap-content-actions') || button.parentElement;
		var kind = button.getAttribute('data-kind') || 'content';
		var postId = button.getAttribute('data-post-id') || '';
		var defaultGap = button.getAttribute('data-gap-type') || 'missing';
		var targets = [];
		var seen = {};

		if (wrap) {
			qsa('[data-dupcap-track-fix]', wrap).forEach(function (link) {
				var language = link.getAttribute('data-language') || '';
				var gapType = link.getAttribute('data-gap-type') || defaultGap;
				var key;

				if (!language) {
					return;
				}

				key = language + ':' + gapType;
				if (seen[key]) {
					return;
				}
				seen[key] = true;
				targets.push({
					type: kind === 'homepage' ? 'homepage' : 'content',
					postId: link.getAttribute('data-post-id') || postId,
					language: language,
					gapType: gapType
				});
			});
		}

		if (!targets.length) {
			(button.getAttribute('data-languages') || '').split(',').forEach(function (slug) {
				var language = String(slug || '').trim();
				var key;

				if (!language) {
					return;
				}

				key = language + ':' + defaultGap;
				if (seen[key]) {
					return;
				}
				seen[key] = true;
				targets.push({
					type: kind === 'homepage' ? 'homepage' : 'content',
					postId: postId,
					language: language,
					gapType: defaultGap
				});
			});
		}

		return targets;
	}

	function bindRescanContentIssue(context) {
		qsa('[data-dupcap-rescan-content-issue]', context).forEach(function (button) {
			if (button.dataset.dupcapContentRescanBound) {
				return;
			}
			button.dataset.dupcapContentRescanBound = '1';
			button.addEventListener('click', function () {
				var cfg = window.dupcapDashboard || {};
				var targets;
				var chain;
				var anyResolved = false;
				var resolvedPayload = null;
				var stillMessage = '';

				if (!cfg.ajaxUrl || button.disabled) {
					return;
				}

				targets = getContentRescanTargets(button);
				if (!targets.length) {
					return;
				}

				button.disabled = true;
				button.classList.add('is-loading');

				chain = Promise.resolve();
				targets.forEach(function (item) {
					chain = chain.then(function () {
						return rescanPendingItem(item).then(function (result) {
							if (!result) {
								return;
							}
							if (result.resolved) {
								anyResolved = true;
								resolvedPayload = result.payload;
							} else {
								stillMessage = (result.payload.data && result.payload.data.message) || stillMessage;
							}
						});
					});
				});

				chain.then(function () {
					if (anyResolved && resolvedPayload) {
						refreshDashboardFragments(resolvedPayload);
						return;
					}

					setRescanIssueStatus(
						button,
						false,
						stillMessage || (cfg.issueStillPresentText || 'This issue is still present.')
					);
					button.disabled = false;
					button.classList.remove('is-loading');
				}).catch(function () {
					button.disabled = false;
					button.classList.remove('is-loading');
				});
			});
		});
	}

	function bindRescanContentDetail(context) {
		qsa('[data-dupcap-rescan-content-detail]', context).forEach(function (button) {
			if (button.dataset.dupcapContentDetailRescanBound) {
				return;
			}
			button.dataset.dupcapContentDetailRescanBound = '1';
			button.addEventListener('click', function () {
				var cfg = window.dupcapDashboard || {};
				var kind = button.getAttribute('data-kind') || '';

				if (!cfg.ajaxUrl || button.disabled || (kind !== 'identical' && kind !== 'link' && kind !== 'menu_dead')) {
					return;
				}

				button.disabled = true;
				button.classList.add('is-loading');

				rescanPendingItem({
					type: kind,
					ignoreKey: button.getAttribute('data-ignore-key') || '',
					url: button.getAttribute('data-url') || '',
					postId: button.getAttribute('data-post-id') || '',
					translationId: button.getAttribute('data-translation-id') || '',
					language: button.getAttribute('data-language') || '',
					field: button.getAttribute('data-field') || ''
				}).then(function (result) {
					if (!result) {
						button.disabled = false;
						button.classList.remove('is-loading');
						return;
					}

					if (result.resolved) {
						refreshDashboardFragments(result.payload);
						return;
					}

					setRescanIssueStatus(
						button,
						false,
						(result.payload.data && result.payload.data.message) || (cfg.issueStillPresentText || 'This issue is still present.')
					);
					button.disabled = false;
					button.classList.remove('is-loading');
				}).catch(function () {
					button.disabled = false;
					button.classList.remove('is-loading');
				});
			});
		});
	}

	var PENDING_RESCAN_KEY = 'dupcap_pending_issue_rescans';
	var PENDING_RESCAN_ARM_KEY = 'dupcap_pending_rescan_armed';

	function readPendingRescans() {
		try {
			var list = JSON.parse(window.localStorage.getItem(PENDING_RESCAN_KEY) || '[]');
			return Object.prototype.toString.call(list) === '[object Array]' ? list : [];
		} catch (e) {
			return [];
		}
	}

	function writePendingRescans(list) {
		try {
			if (!list || !list.length) {
				window.localStorage.removeItem(PENDING_RESCAN_KEY);
				return;
			}
			window.localStorage.setItem(PENDING_RESCAN_KEY, JSON.stringify(list));
		} catch (e) {
			// Ignore storage failures.
		}
	}

	function pendingIssueKey(item) {
		if (!item || !item.type) {
			return '';
		}
		if (item.type === 'elementor') {
			return 'elementor:' + (item.templateId || '') + ':' + (item.ignoreKey || '');
		}
		if (item.type === 'elementor_library') {
			return 'elementor_library:' + (item.templateType || '') + ':' + (item.language || '');
		}
		if (item.type === 'identical' || item.type === 'link') {
			return item.type + ':' + (item.ignoreKey || '');
		}
		if (item.type === 'menu_dead') {
			return 'menu_dead:' + (item.ignoreKey || '');
		}
		return item.type + ':' + (item.postId || '') + ':' + (item.language || '') + ':' + (item.gapType || 'missing');
	}

	function queuePendingRescan(item) {
		var key = pendingIssueKey(item);
		var list;
		if (!key) {
			return;
		}
		list = readPendingRescans().filter(function (row) {
			return pendingIssueKey(row) !== key;
		});
		list.push(item);
		writePendingRescans(list);
		try {
			window.sessionStorage.setItem(PENDING_RESCAN_ARM_KEY, '1');
		} catch (e) {
			// Ignore storage failures.
		}
	}

	function bindTrackFixLinks(context) {
		qsa('[data-dupcap-track-fix]', context).forEach(function (link) {
			if (link.dataset.dupcapTrackBound) {
				return;
			}
			link.dataset.dupcapTrackBound = '1';
			// pointerdown runs before the new tab opens, so the pending
			// issue is stored even if the click handler is interrupted.
			link.addEventListener('pointerdown', function () {
				var type = link.getAttribute('data-dupcap-track-fix') || '';
				if (type === 'elementor') {
					queuePendingRescan({
						type: 'elementor',
						templateId: link.getAttribute('data-template-id') || '',
						ignoreKey: link.getAttribute('data-ignore-key') || ''
					});
					return;
				}
				if (type === 'elementor_library' && link.getAttribute('data-language')) {
					queuePendingRescan({
						type: 'elementor_library',
						templateType: link.getAttribute('data-template-type') || '',
						language: link.getAttribute('data-language') || '',
						ignoreKey: link.getAttribute('data-ignore-key') || ''
					});
					return;
				}
				if (type === 'content' || type === 'homepage') {
					var langs = (link.getAttribute('data-languages') || link.getAttribute('data-language') || '')
						.split(',')
						.map(function (lang) { return lang.trim(); })
						.filter(Boolean);
					if (!langs.length) {
						return;
					}
					langs.forEach(function (lang) {
						queuePendingRescan({
							type: type,
							postId: link.getAttribute('data-post-id') || '',
							language: lang,
							gapType: link.getAttribute('data-gap-type') || 'missing'
						});
					});
					return;
				}
				if ((type === 'identical' || type === 'link') && link.getAttribute('data-ignore-key')) {
					queuePendingRescan({
						type: type,
						ignoreKey: link.getAttribute('data-ignore-key') || '',
						postId: link.getAttribute('data-post-id') || '',
						translationId: link.getAttribute('data-translation-id') || '',
						language: link.getAttribute('data-language') || '',
						field: link.getAttribute('data-field') || ''
					});
				}
			});
		});
	}

	var pendingRescanBusy = false;

	// Locate the fix link the user clicked (and the Elementor Rescan button when present).
	function findPendingIssueTargets(item) {
		var link = null;
		var rescanButton = null;

		if (!item || !item.type) {
			return { link: null, rescanButton: null };
		}

		qsa('[data-dupcap-track-fix]').forEach(function (el) {
			if (link || (el.getAttribute('data-dupcap-track-fix') || '') !== item.type) {
				return;
			}

			if (item.type === 'elementor') {
				if (
					(el.getAttribute('data-template-id') || '') === String(item.templateId || '')
					&& (el.getAttribute('data-ignore-key') || '') === String(item.ignoreKey || '')
				) {
					link = el;
				}
				return;
			}

			if (item.type === 'elementor_library') {
				if (
					(el.getAttribute('data-template-type') || '') === String(item.templateType || '')
					&& (el.getAttribute('data-language') || '') === String(item.language || '')
				) {
					link = el;
				}
				return;
			}

			if (item.type === 'identical' || item.type === 'link') {
				if ((el.getAttribute('data-ignore-key') || '') === String(item.ignoreKey || '')) {
					link = el;
				}
				return;
			}

			if (
				(el.getAttribute('data-post-id') || '') === String(item.postId || '')
				&& (el.getAttribute('data-language') || '') === String(item.language || '')
				&& (el.getAttribute('data-gap-type') || 'missing') === String(item.gapType || 'missing')
			) {
				link = el;
			}
		});

		if (item.type === 'elementor' && item.ignoreKey) {
			qsa('[data-dupcap-rescan-issue]').forEach(function (button) {
				if (rescanButton) {
					return;
				}
				if (
					(button.getAttribute('data-template-id') || '') === String(item.templateId || '')
					&& (button.getAttribute('data-ignore-key') || '') === String(item.ignoreKey || '')
				) {
					rescanButton = button;
				}
			});
		}

		if (item.type === 'elementor_library') {
			qsa('[data-dupcap-rescan-library-issue]').forEach(function (button) {
				if (rescanButton) {
					return;
				}
				if (
					(button.getAttribute('data-template-type') || '') === String(item.templateType || '')
					&& (button.getAttribute('data-language') || '') === String(item.language || '')
				) {
					rescanButton = button;
				}
			});
		}

		if ((item.type === 'identical' || item.type === 'link') && item.ignoreKey) {
			qsa('[data-dupcap-rescan-content-detail]').forEach(function (button) {
				if (rescanButton) {
					return;
				}
				if (
					(button.getAttribute('data-kind') || '') === String(item.type || '')
					&& (button.getAttribute('data-ignore-key') || '') === String(item.ignoreKey || '')
				) {
					rescanButton = button;
				}
			});
		}

		if ((item.type === 'content' || item.type === 'homepage') && !rescanButton) {
			qsa('[data-dupcap-rescan-content-issue]').forEach(function (button) {
				var languages;
				var kind;

				if (rescanButton) {
					return;
				}

				kind = button.getAttribute('data-kind') || 'content';
				if ((item.type === 'homepage' && kind !== 'homepage') || (item.type === 'content' && kind === 'homepage')) {
					return;
				}

				if (item.type === 'content' && (button.getAttribute('data-post-id') || '') !== String(item.postId || '')) {
					return;
				}

				languages = (button.getAttribute('data-languages') || '').split(',').map(function (slug) {
					return String(slug || '').trim();
				});

				if (languages.indexOf(String(item.language || '')) !== -1) {
					rescanButton = button;
				}
			});
		}

		return { link: link, rescanButton: rescanButton };
	}

	function revealIssueRow(row) {
		var hidden;
		var panel;

		if (!row) {
			return;
		}

		panel = row.closest('[data-dupcap-tab-panel]');
		if (panel && panel.hidden) {
			activateTab(panel.dataset.dupcapTabPanel);
		}

		showItemPage(row);

		hidden = row.closest('.dupcap-elementor-example-extra[hidden]');
		if (hidden) {
			hidden.removeAttribute('hidden');
		}

		if (row.scrollIntoView) {
			row.scrollIntoView({ block: 'center', behavior: 'smooth' });
		}
	}

	function setIssueCheckState(targets, state, message) {
		var link = targets && targets.link;
		var rescanButton = targets && targets.rescanButton;
		var anchor = link || rescanButton;
		var row;
		var actionsWrap;
		var status;
		var host;
		var icon;
		var cfg = window.dupcapDashboard || {};

		if (!anchor) {
			return;
		}

		row = anchor.closest('.dupcap-elementor-example-card, .dupcap-content-pending-table tr, .dupcap-detail-row')
			|| anchor.parentElement;
		if (!row) {
			return;
		}

		row.classList.remove('dupcap-issue-checking', 'dupcap-issue-resolved', 'dupcap-issue-present');

		if (link) {
			link.classList.remove('dupcap-fix-link-checking', 'dupcap-fix-link-resolved', 'dupcap-fix-link-present');
		}

		if (rescanButton) {
			if (state === 'checking') {
				rescanButton.disabled = true;
				rescanButton.classList.add('is-loading');
			} else {
				rescanButton.disabled = false;
				rescanButton.classList.remove('is-loading');
			}
		}

		if (state === 'checking') {
			revealIssueRow(row);
		}

		actionsWrap = qs('.dupcap-elementor-example-actions, .dupcap-detail-row-actions, .dupcap-content-actions', row)
			|| (rescanButton && rescanButton.parentNode)
			|| (link && link.parentNode)
			|| row;

		// Clean up any existing status elements in this row so only one element ever exists.
		qsa('[data-dupcap-issue-check-status], [data-dupcap-rescan-status], .dupcap-issue-check-status, .dupcap-rescan-status', row).forEach(function (el) {
			if (status && el !== status) {
				if (el.parentNode) {
					el.parentNode.removeChild(el);
				}
			} else {
				status = el;
			}
		});

		if (!status) {
			status = document.createElement('span');
			status.setAttribute('data-dupcap-issue-check-status', '');
			status.setAttribute('role', 'status');
			status.setAttribute('aria-live', 'polite');
		}

		if (actionsWrap) {
			actionsWrap.appendChild(status);
		} else {
			row.appendChild(status);
		}

		status.className = 'dupcap-issue-check-status is-' + state;
		status.textContent = '';
		icon = document.createElement('span');
		icon.className = 'dashicons dashicons-'
			+ (state === 'checking' ? 'update' : (state === 'resolved' ? 'yes-alt' : 'warning'));
		icon.setAttribute('aria-hidden', 'true');
		status.appendChild(icon);
		status.appendChild(
			document.createTextNode(
				' '
				+ (
					message
					|| (state === 'checking' ? (cfg.checkingIssueText || 'Checking…') : '')
				)
			)
		);

		if (status.dupcapHideTimer) {
			window.clearTimeout(status.dupcapHideTimer);
			status.dupcapHideTimer = null;
		}

		if (state === 'present' || state === 'resolved') {
			status.dupcapHideTimer = window.setTimeout(function () {
				if (status.parentNode) {
					status.parentNode.removeChild(status);
				}
				row.classList.remove('dupcap-issue-checking', 'dupcap-issue-resolved', 'dupcap-issue-present');
				if (link) {
					link.classList.remove('dupcap-fix-link-checking', 'dupcap-fix-link-resolved', 'dupcap-fix-link-present');
				}
				status.dupcapHideTimer = null;
			}, 3000);
		}
	}

	function rescanPendingItem(item) {
		var cfg = window.dupcapDashboard || {};
		var data = new window.FormData();

		if (!cfg.ajaxUrl || !cfg.nonce || !item) {
			return Promise.resolve(null);
		}

		data.append('nonce', cfg.nonce);
		data.append('pll_ajax_backend', '1');
		if (item.type === 'elementor') {
			if (!item.templateId || !item.ignoreKey) {
				return Promise.resolve(null);
			}
			data.append('action', 'dupcap_rescan_issue');
			data.append('template_id', item.templateId);
			data.append('ignore_key', item.ignoreKey);
		} else if (item.type === 'elementor_library') {
			if (!item.templateType || !item.language) {
				return Promise.resolve(null);
			}
			data.append('action', 'dupcap_rescan_elementor_library_issue');
			data.append('template_type', item.templateType);
			data.append('language', item.language);
		} else if (item.type === 'content' || item.type === 'homepage') {
			data.append('action', 'dupcap_rescan_content_issue');
			data.append('kind', item.type === 'homepage' ? 'homepage' : 'content');
			data.append('post_id', item.postId || '');
			data.append('language', item.language || '');
			data.append('gap_type', item.gapType || 'missing');
		} else if (item.type === 'identical' || item.type === 'link') {
			if (!item.ignoreKey) {
				return Promise.resolve(null);
			}
			data.append('action', 'dupcap_rescan_content_issue');
			data.append('kind', item.type);
			data.append('ignore_key', item.ignoreKey);
			data.append('post_id', item.postId || '');
			data.append('translation_id', item.translationId || '');
			data.append('language', item.language || '');
			data.append('field', item.field || '');
		} else if (item.type === 'menu_dead') {
			if (!item.ignoreKey) {
				return Promise.resolve(null);
			}
			data.append('action', 'dupcap_rescan_content_issue');
			data.append('kind', 'menu_dead');
			data.append('ignore_key', item.ignoreKey);
			data.append('url', item.url || '');
		} else {
			return Promise.resolve(null);
		}

		return window.fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		})
			.then(function (response) { return response.json(); })
			.then(function (payload) {
				if (!payload || !payload.success) {
					return null;
				}
				return {
					resolved: !!(payload.data && payload.data.resolved),
					payload: payload
				};
			})
			.catch(function () { return null; });
	}

	function processPendingRescans() {
		var cfg = window.dupcapDashboard || {};
		var pending = readPendingRescans();
		var chain = Promise.resolve();
		var resolvedPayload = null;
		var unchecked = [];

		try {
			if (window.sessionStorage.getItem(PENDING_RESCAN_ARM_KEY) !== '1') {
				return;
			}
			window.sessionStorage.removeItem(PENDING_RESCAN_ARM_KEY);
		} catch (e) {
			// If sessionStorage is unavailable, fall through and process.
		}

		if (pendingRescanBusy || !pending.length || !cfg.ajaxUrl) {
			return;
		}

		pendingRescanBusy = true;

		pending.forEach(function (item) {
			chain = chain.then(function () {
				var targets = findPendingIssueTargets(item);

				setIssueCheckState(targets, 'checking', cfg.checkingIssueText || 'Checking…');

				return rescanPendingItem(item).then(function (result) {
					if (!result) {
						unchecked.push(item);
						setIssueCheckState(targets, 'present', cfg.checkFailedText || 'Could not check');
						return;
					}

					setIssueCheckState(
						targets,
						result.resolved ? 'resolved' : 'present',
						(result.payload.data && result.payload.data.message) || ''
					);

					if (result.resolved && result.payload.data && result.payload.data.body) {
						resolvedPayload = result.payload;
					}
				});
			});
		});

		chain.then(function () {
			writePendingRescans(unchecked);
			pendingRescanBusy = false;

			if (!resolvedPayload) {
				return;
			}

			window.setTimeout(function () {
				refreshDashboardFragments(resolvedPayload);
			}, 1100);
		}).catch(function () {
			pendingRescanBusy = false;
		});
	}

	function showCopiedFeedback(button) {
		var textEl = qs('.dupcap-copy-text', button);
		var originalText = textEl ? textEl.textContent : '';
		button.classList.add('is-copied');
		if (textEl) {
			textEl.textContent = (window.dupcapDashboard && window.dupcapDashboard.copiedText) || 'Copied!';
		}
		window.setTimeout(function () {
			button.classList.remove('is-copied');
			if (textEl) {
				textEl.textContent = originalText;
			}
		}, 2000);
	}

	function bindCopyButtons(context) {
		qsa('[data-dupcap-copy]', context).forEach(function (button) {
			button.addEventListener('click', function (e) {
				e.preventDefault();
				e.stopPropagation();
				var text = button.dataset.dupcapCopy;
				if (!text) {
					return;
				}

				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(function () {
						showCopiedFeedback(button);
					}).catch(function () {
						// Fallback if clipboard API fails
						fallbackCopy(text, button);
					});
				} else {
					fallbackCopy(text, button);
				}
			});
		});
	}

	function fallbackCopy(text, button) {
		var input = document.createElement('input');
		input.value = text;
		input.style.position = 'fixed';
		input.style.opacity = '0';
		document.body.appendChild(input);
		input.select();
		try {
			document.execCommand('copy');
			showCopiedFeedback(button);
		} catch (err) {
			// ignore
		}
		document.body.removeChild(input);
	}





	function showScanOverlay() {
		var overlay = qs('[data-dupcap-scan-overlay]');
		var panel = qs('[data-dupcap-scan-panel]');
		if (overlay) {
			overlay.hidden = false;
			overlay.classList.add('is-visible');
			overlay.setAttribute('aria-hidden', 'false');
		}
		if (panel) {
			panel.hidden = false;
			panel.classList.add('is-visible');
			panel.setAttribute('aria-hidden', 'false');
		}
	}
	
	function hideScanOverlay() {
		var overlay = qs('[data-dupcap-scan-overlay]');
		var panel = qs('[data-dupcap-scan-panel]');
		if (overlay) {
			overlay.classList.remove('is-visible');
			overlay.hidden = true;
			overlay.setAttribute('aria-hidden', 'true');
		}
		if (panel) {
			panel.classList.remove('is-visible');
			panel.hidden = true;
			panel.setAttribute('aria-hidden', 'true');
		}
	}

	function pluginActionErrorMessage(payload, fallback) {
		var data = payload && payload.data;
		var message = data && (data.errorMessage || data.message);
		if (Object.prototype.toString.call(message) === '[object Array]') {
			message = message.join(' ');
		}
		return message || fallback || 'Could not install the plugin. Please try again.';
	}

	function bindEnvironmentPluginInstall(context) {
		qsa('[data-dupcap-plugin-action]', context).forEach(function (button) {
			button.addEventListener('click', function (event) {
				event.preventDefault();
				runEnvironmentPluginAction(button);
			});
		});
	}

	var pendingAiTranslateUrl = '';

	function getAutopolyModal() {
		return qs('[data-dupcap-autopoly-modal]');
	}

	function getAutopolyProModal() {
		return qs('[data-dupcap-autopoly-pro-modal]');
	}

	function openAutopolyProModal() {
		var modal = getAutopolyProModal();
		if (!modal) {
			return;
		}
		modal.classList.add('active');
		modal.setAttribute('aria-hidden', 'false');
	}

	function closeAutopolyProModal() {
		var modal = getAutopolyProModal();
		if (!modal) {
			return;
		}
		modal.classList.remove('active');
		modal.setAttribute('aria-hidden', 'true');
	}

	function openAutopolyModal(redirectUrl) {
		var modal = getAutopolyModal();
		var msg;

		pendingAiTranslateUrl = redirectUrl || '';
		if (!modal) {
			return;
		}

		msg = qs('[data-dupcap-autopoly-msg]', modal);
		if (msg) {
			msg.hidden = true;
			msg.textContent = '';
		}

		modal.classList.add('active');
		modal.setAttribute('aria-hidden', 'false');
	}

	function closeAutopolyModal() {
		var modal = getAutopolyModal();
		if (!modal) {
			return;
		}
		modal.classList.remove('active');
		modal.setAttribute('aria-hidden', 'true');
	}

	function redirectAfterAutopoly() {
		var url = pendingAiTranslateUrl;
		pendingAiTranslateUrl = '';
		closeAutopolyModal();
		if (url) {
			window.location.assign(url);
		}
	}

	// Install or activate one plugin through dupcap_install_plugin.
	// Resolves 'activate-next' when an install left the plugin switched off and
	// 'done' when it is running; rejects with a message the caller can show.
	function requestPluginAction(slug, init, action, nonce) {
		var cfg = window.dupcapDashboard || {};
		var data = new window.FormData();

		data.append('action', 'dupcap_install_plugin');
		data.append('pll_ajax_backend', '1');
		data.append('slug', slug);
		data.append('init', init);
		data.append('plugin_action', action);
		data.append('_wpnonce', nonce);

		return window.fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		})
			.then(function (response) {
				return response.json().catch(function () {
					throw new Error(cfg.installErrorText || 'Could not install the plugin. Please try again.');
				});
			})
			.then(function (payload) {
				var activated = payload && payload.success && payload.data && payload.data.activated;

				if (action === 'install' && payload && payload.success && !activated) {
					return 'activate-next';
				}

				if (!payload || !payload.success) {
					throw new Error(pluginActionErrorMessage(payload, cfg.installErrorText));
				}

				return 'done';
			});
	}

	function runAutopolyInstall(button, forceAction) {
		var cfg = window.dupcapDashboard || {};
		var action = forceAction || button.getAttribute('data-action') || 'install';
		var slug = button.getAttribute('data-slug') || '';
		var init = button.getAttribute('data-init') || '';
		var nonce = button.getAttribute('data-nonce') || cfg.installNonce || '';
		var modal = getAutopolyModal();
		var messageEl = modal ? qs('[data-dupcap-autopoly-msg]', modal) : null;
		var labelEl = qs('.dupcap-btn-text', button) || button;

		if (button.disabled || !cfg.ajaxUrl || !slug || !nonce) {
			return;
		}

		if (!button.getAttribute('data-original-label')) {
			button.setAttribute('data-original-label', (labelEl.textContent || '').trim());
		}

		button.disabled = true;
		button.classList.add('is-busy');
		labelEl.textContent = action === 'activate'
			? (cfg.activatingText || 'Activating...')
			: (cfg.installingText || 'Installing...');

		if (messageEl) {
			messageEl.hidden = true;
			messageEl.textContent = '';
		}

		requestPluginAction(slug, init, action, nonce)
			.then(function (outcome) {
				if (outcome === 'activate-next') {
					button.disabled = false;
					button.classList.remove('is-busy');
					button.setAttribute('data-action', 'activate');
					runAutopolyInstall(button, 'activate');
					return;
				}

				if (cfg.autopoly) {
					cfg.autopoly.active = true;
					cfg.autopoly.action = 'none';
				}

				labelEl.textContent = cfg.activatedText || 'Active';
				redirectAfterAutopoly();
			})
			.catch(function (error) {
				button.disabled = false;
				button.classList.remove('is-busy');
				labelEl.textContent = button.getAttribute('data-original-label') || 'Install AutoPoly';
				if (messageEl) {
					messageEl.hidden = false;
					messageEl.textContent = (error && error.message) || cfg.installErrorText || 'Could not install the plugin. Please try again.';
				}
			});
	}

	function bindAutopolyAiTranslate() {
		document.addEventListener('click', function (event) {
			var link = event.target.closest('.dupcap-ai-translate-btn');
			var actionBtn;
			var closeBtn;
			var modal;
			var cfg;
			var autopoly;

			if (link) {
				cfg = window.dupcapDashboard || {};
				autopoly = cfg.autopoly || {};

				// Pro: allow normal navigation to the translation list.
				if (autopoly.is_pro) {
					return;
				}

				// Free only: Pro upsell is for re-translate after default-language
				// edits. First-time missing/draft translation stays on Free.
				if (autopoly.is_free) {
					if (link.getAttribute('data-gap-type') === 'source_updated') {
						event.preventDefault();
						if (getAutopolyProModal()) {
							openAutopolyProModal();
							return;
						}
						window.open(
							'https://coolplugins.net/product/autopoly-ai-translation-for-polylang/?utm_source=pdca_plugin&utm_medium=inside&utm_campaign=view_plugin&utm_content=inspector_ai_free',
							'_blank',
							'noopener,noreferrer'
						);
						return;
					}
					return;
				}

				// Not installed: show install/activate modal.
				event.preventDefault();

				if (
					(autopoly.action === 'install' && !autopoly.can_install) ||
					(autopoly.action === 'activate' && !autopoly.can_activate)
				) {
					window.alert(cfg.autopolyPermissionText || 'You do not have permission to install or activate plugins.');
					return;
				}

				if (!getAutopolyModal()) {
					window.open(link.href, '_blank', 'noopener,noreferrer');
					return;
				}

				openAutopolyModal(link.href);
				return;
			}

			actionBtn = event.target.closest('[data-dupcap-autopoly-action]');
			if (actionBtn) {
				event.preventDefault();
				runAutopolyInstall(actionBtn);
				return;
			}

			closeBtn = event.target.closest('[data-dupcap-autopoly-close]');
			if (closeBtn) {
				event.preventDefault();
				closeAutopolyModal();
				return;
			}

			closeBtn = event.target.closest('[data-dupcap-autopoly-pro-close]');
			if (closeBtn) {
				event.preventDefault();
				closeAutopolyProModal();
				return;
			}

			modal = getAutopolyProModal();
			if (modal && modal.classList.contains('active') && event.target === modal) {
				closeAutopolyProModal();
				return;
			}

			modal = getAutopolyModal();
			if (modal && modal.classList.contains('active') && event.target === modal) {
				closeAutopolyModal();
			}
		});

		document.addEventListener('keydown', function (event) {
			var modal;
			if (event.key !== 'Escape') {
				return;
			}
			modal = getAutopolyProModal();
			if (modal && modal.classList.contains('active')) {
				closeAutopolyProModal();
				return;
			}
			modal = getAutopolyModal();
			if (modal && modal.classList.contains('active')) {
				closeAutopolyModal();
			}
		});
	}

	function runEnvironmentPluginAction(button, forceAction) {
		var cfg = window.dupcapDashboard || {};
		var action = forceAction || button.getAttribute('data-dupcap-plugin-action') || 'install';
		var slug = button.getAttribute('data-slug') || '';
		var init = button.getAttribute('data-init') || '';
		var nonce = button.getAttribute('data-nonce') || cfg.installNonce || '';
		var row = button.closest('[data-dupcap-connector-row]');
		var actions = button.closest('.dupcap-elementor-example-actions');
		var messageEl = null;

		if (row) {
			messageEl = qs('.dupcap-environment-plugin-msg', row);
		} else if (actions) {
			messageEl = qs('.dupcap-environment-plugin-msg', actions);
		} else {
			messageEl = button.nextElementSibling;
			if (messageEl && !messageEl.classList.contains('dupcap-environment-plugin-msg')) {
				messageEl = null;
			}
		}

		if (button.disabled || !cfg.ajaxUrl || !slug || !nonce) {
			return;
		}

		if (!button.getAttribute('data-original-label')) {
			button.setAttribute('data-original-label', (button.textContent || '').trim());
		}

		button.disabled = true;
		button.classList.add('is-busy');
		button.textContent = action === 'activate'
			? (cfg.activatingText || 'Activating...')
			: (cfg.installingText || 'Installing...');

		if (messageEl) {
			messageEl.hidden = true;
			messageEl.textContent = '';
		}

		requestPluginAction(slug, init, action, nonce)
			.then(function (outcome) {
				if (outcome === 'activate-next') {
					button.disabled = false;
					button.classList.remove('is-busy');
					runEnvironmentPluginAction(button, 'activate');
					return;
				}

				markConnectorInstalled(button);
			})
			.catch(function (error) {
				button.disabled = false;
				button.classList.remove('is-busy');
				button.textContent = button.getAttribute('data-original-label') || 'Install';
				if (messageEl) {
					messageEl.hidden = false;
					messageEl.textContent = (error && error.message) || cfg.installErrorText || 'Could not install the plugin. Please try again.';
				}
			});
	}

	function markConnectorInstalled(clickedButton) {
		var cfg = window.dupcapDashboard || {};
		var slug = clickedButton ? (clickedButton.getAttribute('data-slug') || '') : '';
		var selector = slug
			? '[data-dupcap-plugin-action][data-slug="' + slug + '"]'
			: '[data-dupcap-plugin-action]';

		qsa(selector, document).forEach(function (button) {
			var status = document.createElement('span');
			status.className = 'dupcap-environment-plugin-status';
			status.textContent = cfg.activatedText || 'Active';
			button.replaceWith(status);
		});

		qsa('.dupcap-environment-plugin-msg', document).forEach(function (messageEl) {
			messageEl.hidden = true;
			messageEl.textContent = '';
		});

		// Refresh the report so CPEL-related warnings clear without a manual scan.
		var scanButton = qs('[data-dupcap-rescan]');
		if (scanButton && !rescanInFlight) {
			runRescan(scanButton, true);
		}
	}

	function bindAssignDefaultLang(context) {
		qsa('[data-dupcap-assign-default-lang]', context).forEach(function (button) {
			button.addEventListener('click', function (event) {
				event.preventDefault();
				runAssignDefaultLang(button);
			});
		});
	}

	function runAssignDefaultLang(button) {
		var cfg = window.dupcapDashboard || {};
		var notice = button.closest('.dupcap-nolang-notice');
		var messageEl = notice ? qs('.dupcap-nolang-notice-msg', notice) : null;
		var data;

		if (button.disabled || rescanInFlight || !cfg.ajaxUrl || !cfg.nonce) {
			return;
		}

		if (!button.getAttribute('data-original-label')) {
			button.setAttribute('data-original-label', (button.textContent || '').trim());
		}

		qsa('[data-dupcap-assign-default-lang]').forEach(function (other) {
			other.disabled = true;
		});
		button.textContent = cfg.assigningText || 'Assigning…';
		if (messageEl) {
			messageEl.hidden = true;
			messageEl.textContent = '';
		}

		rescanInFlight = true;
		showScanOverlay();

		data = new window.FormData();
		data.append('action', 'dupcap_assign_default_lang');
		data.append('pll_ajax_backend', '1');
		data.append('nonce', cfg.nonce);

		window.fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		})
			.then(function (response) {
				return response.json().then(function (payload) {
					return payload;
				}).catch(function () {
					throw new Error(cfg.assignErrorText || 'Could not assign the default language. Please try again.');
				});
			})
			.then(function (payload) {
				if (!payload || !payload.success) {
					throw new Error(
						(payload && payload.data && payload.data.message) ||
						cfg.assignErrorText ||
						'Could not assign the default language. Please try again.'
					);
				}
				refreshDashboardFragments(payload);
				showCompleteThenReset();
			})
			.catch(function (error) {
				button.textContent = button.getAttribute('data-original-label') || button.textContent;
				qsa('[data-dupcap-assign-default-lang]').forEach(function (other) {
					other.disabled = false;
				});
				if (messageEl) {
					messageEl.hidden = false;
					messageEl.textContent = (error && error.message) || cfg.assignErrorText || 'Could not assign the default language. Please try again.';
				}
			})
			.then(function () {
				rescanInFlight = false;
				hideScanOverlay();
			});
	}

	var routingInFlight = false;

	// Prefer the dedicated slot inside URLs & Routing; fall back to placing
	// the status line right under that section's heading.
	function setRoutingStatus(text, isError) {
		var section = qs('[data-dupcap-url-section]');
		var slot = qs('[data-dupcap-routing-status-slot]');
		var el = qs('[data-dupcap-routing-status]');

		if (!text) {
			if (el && el.parentNode) {
				el.parentNode.removeChild(el);
			}
			return;
		}

		if (!section && !slot) {
			return;
		}

		if (!el || (slot && !slot.contains(el)) || (!slot && section && !section.contains(el))) {
			if (el && el.parentNode) {
				el.parentNode.removeChild(el);
			}
			el = document.createElement('div');
			el.className = 'dupcap-routing-status';
			el.setAttribute('data-dupcap-routing-status', '');
			el.setAttribute('role', 'status');

			if (slot) {
				slot.appendChild(el);
			} else {
				var heading = qs('.dupcap-section-heading', section);
				if (heading && heading.nextSibling) {
					section.insertBefore(el, heading.nextSibling);
				} else if (heading) {
					heading.insertAdjacentElement('afterend', el);
				} else {
					section.insertBefore(el, section.firstChild);
				}
			}
		}

		el.classList.toggle('is-error', !!isError);
		el.textContent = text;
	}

	// Manual only. The URL sweep makes real HTTP requests to the site, so it is
	// never chained off the main scan.
	function runRoutingScan(button) {
		var cfg = window.dupcapDashboard || {};
		var originalLabel = button ? button.textContent : '';
		var data;

		// A full or section scan rewrites the saved report; running the sweep at
		// the same time would let one write drop the other's rows.
		if (routingInFlight || rescanInFlight || !cfg.ajaxUrl || !cfg.nonce) {
			return;
		}

		routingInFlight = true;
		setRoutingStatus(cfg.routingTestText || '', false);

		if (button) {
			button.disabled = true;
			button.textContent = cfg.routingTestText || originalLabel;
		}

		data = new window.FormData();
		data.append('action', 'dupcap_scan_routing');
		data.append('pll_ajax_backend', '1');
		data.append('nonce', cfg.nonce);
		// Explicit user action, so bypass the freshness window. The server still
		// applies its own rate limit.
		data.append('force', '1');

		window.fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		})
			.then(function (response) {
				if (!response.ok) {
					throw new Error(cfg.routingErrorText);
				}
				return response.json();
			})
			.then(function (payload) {
				var message = payload && payload.data ? payload.data.routing_message : '';
				var ran = payload && payload.data ? payload.data.routing_ran : false;

				refreshDashboardFragments(payload);

				// Rate limited or served from cache: say so, or the button looks broken.
				setRoutingStatus(ran ? '' : (message || ''), false);
			})
			.catch(function () {
				// A failed request means the server-side "could not be tested" card
				// was never rendered either, so say so rather than failing silently.
				setRoutingStatus(cfg.routingErrorText || '', true);
			})
			.then(function () {
				routingInFlight = false;
				// The button is usually replaced by the fragment refresh; restore it
				// only if this exact element is still on the page.
				if (button && document.body.contains(button)) {
					button.disabled = false;
					button.textContent = originalLabel;
				}
			});
	}

	var toastTimer = null;

	// Lives on <body>, outside the fragments, so a refresh cannot remove it.
	function getToast() {
		var toast = qs('[data-dupcap-toast]');
		if (!toast) {
			toast = document.createElement('div');
			toast.className = 'dupcap-toast dupcap-toast-resolved';
			toast.setAttribute('data-dupcap-toast', '');
			toast.setAttribute('role', 'status');
			toast.setAttribute('aria-live', 'polite');
			toast.hidden = true;
			document.body.appendChild(toast);
		}
		return toast;
	}

	function showToast(message) {
		var toast = getToast();

		if (!message) {
			return;
		}

		toast.textContent = message;
		toast.hidden = false;

		if (toastTimer) {
			window.clearTimeout(toastTimer);
		}
		toastTimer = window.setTimeout(function () {
			toastTimer = null;
			toast.hidden = true;
		}, 5000);
	}

	function setSectionScanError(section, message) {
		var el = qs('[data-dupcap-section-scan-error="' + section + '"]');
		if (!el) {
			return;
		}
		el.textContent = message || '';
		el.hidden = !message;
	}

	// Scan content / Scan Elementor / Scan site setup. Shares rescanInFlight
	// with Scan Website so two writes to the saved report never overlap.
	function runSectionScan(button) {
		var cfg = window.dupcapDashboard || {};
		var section = button.getAttribute('data-dupcap-section-scan') || '';
		var label = qs('.dupcap-section-scan-label', button);
		var originalLabel = label ? label.textContent : '';
		var freshButton;
		var data;

		if (rescanInFlight || routingInFlight || !section || !cfg.ajaxUrl || !cfg.nonce) {
			return;
		}

		rescanInFlight = true;
		setSectionScanError(section, '');
		qsa('[data-dupcap-section-scan]').forEach(function (other) {
			other.disabled = true;
		});
		button.classList.add('is-loading');
		button.setAttribute('aria-busy', 'true');
		if (label) {
			label.textContent = cfg.sectionScanningText || 'Scanning…';
		}

		data = new window.FormData();
		data.append('action', 'dupcap_scan_section');
		data.append('pll_ajax_backend', '1');
		data.append('nonce', cfg.nonce);
		data.append('section', section);

		window.fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		})
			.then(function (response) {
				// Error responses still carry a JSON message; a PHP fatal does not.
				return response.json().catch(function () {
					throw new Error(cfg.sectionScanErrorText || '');
				});
			})
			.then(function (payload) {
				if (!payload || !payload.success) {
					throw new Error((payload && payload.data && payload.data.message) || cfg.sectionScanErrorText || '');
				}
				refreshDashboardFragments(payload);
				showToast(payload.data.message || '');
				// The refresh replaced the button, which drops keyboard focus to
				// <body>; put it back on the new button for the same section.
				freshButton = qs('[data-dupcap-section-scan="' + section + '"]');
				if (freshButton) {
					freshButton.focus();
				}
			})
			.catch(function (error) {
				// No refresh happened, so this panel's error slot is still on the page.
				setSectionScanError(section, (error && error.message) || cfg.sectionScanErrorText || 'The scan could not finish.');
			})
			.then(function () {
				rescanInFlight = false;
				button.classList.remove('is-loading');
				button.removeAttribute('aria-busy');
				if (label) {
					label.textContent = originalLabel;
				}
				qsa('[data-dupcap-section-scan]').forEach(function (other) {
					other.disabled = false;
				});
			});
	}

	function bindSectionScan(context) {
		qsa('[data-dupcap-section-scan]', context).forEach(function (button) {
			if (button.dataset.dupcapSectionScanBound) {
				return;
			}
			button.dataset.dupcapSectionScanBound = '1';
			button.addEventListener('click', function () {
				runSectionScan(button);
			});
		});
	}

	function bindRoutingRescan(context) {
		qsa('[data-dupcap-routing-rescan]', context).forEach(function (button) {
			if (button.dataset.dupcapRoutingBound) {
				return;
			}
			button.dataset.dupcapRoutingBound = '1';
			button.addEventListener('click', function () {
				runRoutingScan(button);
			});
		});
	}

	// Detail list pagination. Any container marked data-dupcap-paginate in PHP
	// pages its direct children. Out-of-page items get their own class, so the
	// filters' dupcap-hidden class and pagination never overwrite each other.
	var PAGE_SIZE = 10;
	var pagerSeq = 0;

	function pagerText(key, fallback, values) {
		var cfg = window.dupcapDashboard || {};
		var text = cfg[key] || fallback;
		(values || []).forEach(function (value, i) {
			text = text.replace('%' + (i + 1) + '$s', String(value));
		});
		return values && values.length ? text.replace('%s', String(values[0])) : text;
	}

	// Items that match the active filters.
	function pagedItems(list) {
		return Array.prototype.filter.call(list.children, function (item) {
			return !item.classList.contains('dupcap-hidden');
		});
	}

	// First page, last page, and the neighbours of the current one; 0 = gap.
	function pageNumbers(current, total) {
		var out = [];
		var last = 0;
		var p;
		for (p = 1; p <= total; p++) {
			if (p === 1 || p === total || Math.abs(p - current) <= 1) {
				if (last && p - last > 1) {
					out.push(0);
				}
				out.push(p);
				last = p;
			}
		}
		return out;
	}

	// The footer goes after the whole table (and its scroll wrapper), not inside it.
	function pagerAnchor(list) {
		var table = list.tagName === 'TBODY' ? list.closest('table') : null;
		if (!table) {
			return list;
		}
		return table.parentElement && table.parentElement.classList.contains('dupcap-table-wrap') ? table.parentElement : table;
	}

	function findPager(list) {
		var next = pagerAnchor(list).nextElementSibling;
		return next && next.hasAttribute('data-dupcap-pager') ? next : null;
	}

	function getPager(list) {
		var anchor = pagerAnchor(list);
		var pager = findPager(list);

		if (pager) {
			return pager;
		}

		if (!list.id) {
			pagerSeq += 1;
			list.id = 'dupcap-paged-' + pagerSeq;
		}

		pager = document.createElement('nav');
		pager.className = 'dupcap-pager';
		pager.setAttribute('data-dupcap-pager', '');
		pager.setAttribute('data-dupcap-pager-for', list.id);
		pager.setAttribute('aria-label', pagerText('pagerNavLabel', 'Pagination'));
		pager.innerHTML = '<p class="dupcap-pager-summary" aria-live="polite"></p><div class="dupcap-pager-controls"></div>';
		anchor.parentNode.insertBefore(pager, anchor.nextSibling);

		pager.addEventListener('click', function (event) {
			var button = event.target.closest('button[data-dupcap-page]');
			if (!button || button.disabled) {
				return;
			}
			renderPage(list, parseInt(button.getAttribute('data-dupcap-page'), 10), button.getAttribute('data-dupcap-page-role'));
		});

		return pager;
	}

	function pagerButton(page, text, label, role, disabled, current) {
		var button = document.createElement('button');
		button.type = 'button';
		button.className = 'button button-small dupcap-pager-button' + (current ? ' is-current' : '');
		button.textContent = text;
		button.setAttribute('aria-label', label);
		button.setAttribute('data-dupcap-page', String(page));
		button.setAttribute('data-dupcap-page-role', role);
		button.disabled = !!disabled;
		if (current) {
			button.setAttribute('aria-current', 'page');
		}
		return button;
	}

	function renderPage(list, page, focusRole) {
		var items = pagedItems(list);
		var total = items.length;
		var pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
		var pager;
		var controls;
		var numbers;
		var indicator;
		var target;

		page = Math.min(Math.max(1, page || 1), pages);
		list.setAttribute('data-dupcap-page', String(page));

		Array.prototype.forEach.call(list.children, function (item) {
			item.classList.remove('dupcap-page-hidden');
		});

		if (total <= PAGE_SIZE) {
			pager = findPager(list);
			if (pager) {
				pager.hidden = true;
			}
			return;
		}

		items.forEach(function (item, index) {
			item.classList.toggle('dupcap-page-hidden', index < (page - 1) * PAGE_SIZE || index >= page * PAGE_SIZE);
		});

		pager = getPager(list);
		pager.hidden = false;
		qs('.dupcap-pager-summary', pager).textContent = pagerText(
			'pagerSummary',
			'Showing %1$s – %2$s of %3$s items',
			[(page - 1) * PAGE_SIZE + 1, Math.min(page * PAGE_SIZE, total), total]
		);

		controls = qs('.dupcap-pager-controls', pager);
		controls.textContent = '';
		controls.appendChild(pagerButton(page - 1, pagerText('pagerPrev', 'Previous'), pagerText('pagerPrevLabel', 'Previous page'), 'prev', page === 1, false));

		numbers = document.createElement('span');
		numbers.className = 'dupcap-pager-pages';
		pageNumbers(page, pages).forEach(function (number) {
			var gap;
			if (!number) {
				gap = document.createElement('span');
				gap.className = 'dupcap-pager-gap';
				gap.setAttribute('aria-hidden', 'true');
				gap.textContent = '…';
				numbers.appendChild(gap);
				return;
			}
			numbers.appendChild(pagerButton(number, String(number), pagerText('pagerPageLabel', 'Page %s', [number]), 'page', false, number === page));
		});
		controls.appendChild(numbers);

		indicator = document.createElement('span');
		indicator.className = 'dupcap-pager-indicator';
		indicator.textContent = pagerText('pagerIndicator', 'Page %1$s of %2$s', [page, pages]);
		controls.appendChild(indicator);

		controls.appendChild(pagerButton(page + 1, pagerText('pagerNext', 'Next'), pagerText('pagerNextLabel', 'Next page'), 'next', page === pages, false));

		// The buttons were rebuilt, so put keyboard focus back on the control the
		// user pressed; if it just became disabled, use the opposite arrow.
		if (focusRole) {
			target = focusRole === 'page'
				? qs('[aria-current="page"]', pager)
				: qs('[data-dupcap-page-role="' + focusRole + '"]', pager);
			if (target && target.disabled) {
				target = qs('[data-dupcap-page-role="' + (focusRole === 'next' ? 'prev' : 'next') + '"]', pager);
			}
			if (target) {
				target.focus();
			}
		}
	}

	// Prepare paginated list container.
	function preparePagedList(list) {
		if (list.hasAttribute('data-dupcap-pager-ready')) {
			return;
		}
		list.setAttribute('data-dupcap-pager-ready', '');
	}

	function refreshPagination(reset, scope) {
		qsa('[data-dupcap-paginate]', scope).forEach(function (list) {
			preparePagedList(list);
			renderPage(list, reset ? 1 : parseInt(list.getAttribute('data-dupcap-page'), 10) || 1);
		});
	}

	// Jump to the page that holds a row (used when a pending fix is re-checked).
	function showItemPage(row) {
		var list = row.closest('[data-dupcap-paginate]');
		var item = row;
		var index;

		if (!list) {
			return;
		}
		while (item && item.parentElement !== list) {
			item = item.parentElement;
		}
		index = item ? pagedItems(list).indexOf(item) : -1;
		if (index >= 0) {
			renderPage(list, Math.floor(index / PAGE_SIZE) + 1);
		}
	}

	// Report section tabs. The active tab lives in JS state and the URL hash,
	// so it survives the fragment refresh after every scan, ignore or rescan.
	function readTabFromHash() {
		var hash = (window.location.hash || '').replace(/^#/, '');
		return hash.indexOf(TAB_HASH_PREFIX) === 0 ? hash.slice(TAB_HASH_PREFIX.length) : '';
	}

	// When the nav is stuck to the top of the screen, jump back to the start
	// of the panels so a newly opened tab is not shown half-way down.
	function scrollToPanelsTop() {
		var nav = qs('.dupcap-section-nav');
		var stack = nav ? nav.parentNode : null;
		var stickyTop;
		var target;

		if (!stack) {
			return;
		}

		stickyTop = parseFloat(window.getComputedStyle(nav).top) || 0;
		target = stack.getBoundingClientRect().top + window.pageYOffset - stickyTop;
		if (window.pageYOffset > target) {
			window.scrollTo(0, target);
		}
	}

	function activateTab(key, options) {
		var opts = options || {};
		var panel = qs('[data-dupcap-tab-panel="' + key + '"]');

		// A tab can disappear between renders (e.g. Elementor was deactivated).
		if (!panel) {
			key = 'overview';
			panel = qs('[data-dupcap-tab-panel="overview"]');
		}
		if (!panel) {
			return;
		}

		activeTab = key;

		qsa('[data-dupcap-nav-tab]').forEach(function (tab) {
			var selected = tab.dataset.dupcapNavTab === key;
			tab.classList.toggle('is-active', selected);
			tab.setAttribute('aria-selected', selected ? 'true' : 'false');
			tab.tabIndex = selected ? 0 : -1;
			if (!selected) {
				return;
			}
			if (opts.focus) {
				tab.focus();
			}
			// Only when the user is using the nav: it is on screen then, so
			// this scrolls the tab strip sideways without moving the page.
			if ((opts.focus || opts.updateHash) && tab.scrollIntoView) {
				tab.scrollIntoView({ block: 'nearest', inline: 'nearest' });
			}
		});

		qsa('[data-dupcap-tab-panel]').forEach(function (item) {
			item.hidden = item !== panel;
		});

		if (opts.updateHash && window.history && window.history.replaceState) {
			window.history.replaceState(null, '', '#' + TAB_HASH_PREFIX + key);
		}

		if (opts.scroll) {
			scrollToPanelsTop();
		}
	}

	function bindSectionNav(context) {
		qsa('[data-dupcap-nav-tab]', context).forEach(function (tab) {
			if (tab.dataset.dupcapNavBound) {
				return;
			}
			tab.dataset.dupcapNavBound = '1';
			tab.addEventListener('click', function () {
				activateTab(tab.dataset.dupcapNavTab, { updateHash: true, scroll: true });
			});
			tab.addEventListener('keydown', function (event) {
				var tabs = qsa('[data-dupcap-nav-tab]');
				var index = tabs.indexOf(tab);
				var rtl = document.documentElement.getAttribute('dir') === 'rtl';
				var forward = rtl ? 'ArrowLeft' : 'ArrowRight';
				var back = rtl ? 'ArrowRight' : 'ArrowLeft';
				var next = -1;

				if (event.key === forward) {
					next = (index + 1) % tabs.length;
				} else if (event.key === back) {
					next = (index - 1 + tabs.length) % tabs.length;
				} else if (event.key === 'Home') {
					next = 0;
				} else if (event.key === 'End') {
					next = tabs.length - 1;
				}

				if (next < 0) {
					return;
				}
				event.preventDefault();
				activateTab(tabs[next].dataset.dupcapNavTab, { updateHash: true, focus: true });
			});
		});

		qsa('[data-dupcap-goto-tab]', context).forEach(function (button) {
			if (button.dataset.dupcapGotoBound) {
				return;
			}
			button.dataset.dupcapGotoBound = '1';
			button.addEventListener('click', function (event) {
				var target = button.dataset.dupcapGotoTarget
					? document.getElementById(button.dataset.dupcapGotoTarget)
					: null;

				event.preventDefault();
				// Scrolling the panel to the top first would fight the jump to
				// the section, so let the target win when there is one.
				activateTab(button.dataset.dupcapGotoTab, { updateHash: true, scroll: !target });

				if (target && target.scrollIntoView) {
					target.scrollIntoView({ block: 'start', behavior: 'smooth' });
				}
			});
		});
	}

	// Usage data sharing. This is the plugin's only saveable setting, so Save
	// stays disabled until the checkbox differs from the stored answer.
	function bindOptin(context) {
		qsa('[data-dupcap-optin-card]', context).forEach(function (card) {
			var input = qs('[data-dupcap-optin-input]', card);
			var saveBtn = qs('[data-dupcap-optin-save]', card);
			var termsBtn = qs('[data-dupcap-optin-terms]', card);
			var details = qs('[data-dupcap-optin-details]', card);
			var cfg = window.dupcapDashboard || {};

			if (termsBtn && details) {
				termsBtn.addEventListener('click', function () {
					var show = details.hidden;
					details.hidden = !show;
					termsBtn.setAttribute('aria-expanded', show ? 'true' : 'false');
					termsBtn.textContent = show ? (cfg.hideTermsText || 'Hide terms') : (cfg.seeTermsText || 'See terms');
				});
			}

			if (!input || !saveBtn) {
				return;
			}

			function savedValue() {
				return input.getAttribute('data-dupcap-optin-saved') === '1';
			}

			function syncSaveState() {
				saveBtn.disabled = input.checked === savedValue();
			}

			input.addEventListener('change', syncSaveState);

			saveBtn.addEventListener('click', function () {
				var data;
				var label = saveBtn.textContent;

				if (!cfg.ajaxUrl || !cfg.optinNonce) {
					return;
				}

				saveBtn.disabled = true;
				saveBtn.textContent = cfg.savingText || 'Saving...';

				data = new window.FormData();
				data.append('action', 'dupcap_save_optin');
				data.append('nonce', cfg.optinNonce);
				data.append('opt_in', input.checked ? 'yes' : 'no');

				window.fetch(cfg.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: data
				})
					.then(function (response) {
						return response.json();
					})
					.then(function (payload) {
						saveBtn.textContent = label;

						if (!payload || !payload.success) {
							syncSaveState();
							return;
						}

						input.setAttribute('data-dupcap-optin-saved', payload.data.enabled ? '1' : '0');
						syncSaveState();
					})
					.catch(function () {
						saveBtn.textContent = label;
						syncSaveState();
					});
			});

			syncSaveState();
		});
	}

	function bindDashboard(context) {
		bindSectionNav(context);
		bindSeverityFilters(context);
		bindContentFilters(context);
		bindElementorTypeFilters(context);
		bindPassedSearch(context);
		bindRescan(context);
		bindIgnore(context);
		bindUnignore(context);
		bindRescanIssue(context);
		bindRescanLibraryIssue(context);
		bindRescanContentIssue(context);
		bindRescanContentDetail(context);
		bindTrackFixLinks(context);
		bindCopyButtons(context);
		bindEnvironmentPluginInstall(context);
		bindAssignDefaultLang(context);
		bindRoutingRescan(context);
		bindSectionScan(context);
		bindOptin(context);
	}

	// Accordion behavior: opening one card automatically closes all other open cards in the same panel/group.
	document.addEventListener('toggle', function (event) {
		var target = event.target;
		if (target && target.tagName === 'DETAILS' && target.hasAttribute('data-dupcap-card') && target.open) {
			var panel = target.closest('[data-dupcap-tab-panel]') || target.closest('.dupcap-dashboard-body') || document;
			var name = target.getAttribute('name');
			// A card with no accordion name is outside the exclusive group (today
			// that means a Critical). Opening one must not close its siblings.
			if (!name) {
				return;
			}
			qsa('details[data-dupcap-card][open]', panel).forEach(function (card) {
				if (card !== target && card.getAttribute('name') === name) {
					card.removeAttribute('open');
				}
			});
		}
	}, true);

	document.addEventListener('DOMContentLoaded', function () {
		bindDashboard(document);
		bindAutopolyAiTranslate();
		// Restore the filters this user last chose, then press the matching pills.
		loadFilters();
		Object.keys(sectionSeverity).forEach(function (key) {
			sectionSeverity[key] = syncFilterState(severitySelector(key), 'dupcapFilterValue', sectionSeverity[key]);
		});
		activateTab(readTabFromHash() || 'overview');
		applyFilters();
		applyElementorTypeFilters();
		processPendingRescans();

		var cfg = window.dupcapDashboard || {};
		var scanButton = qs('[data-dupcap-rescan]');
		if (cfg.promptScan && scanButton) {
			scanButton.classList.add('dupcap-rescan-button--prompt');
			if (scanButton.parentElement && scanButton.parentElement.classList.contains('dupcap-scan-cta')) {
				scanButton.parentElement.classList.add('is-prompt');
			}
		}
	});

	// Fix links open in a new tab; when the user comes back to this tab the
	// pending check should run even without a full page reload.
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'visible') {
			processPendingRescans();
		}
	});

	window.addEventListener('pageshow', function () {
		processPendingRescans();
	});
})();
