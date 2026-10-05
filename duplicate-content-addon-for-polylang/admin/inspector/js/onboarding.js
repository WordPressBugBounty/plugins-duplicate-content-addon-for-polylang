(function () {
	'use strict';

	var root = document.getElementById('dupcap-onboarding');
	if (!root || typeof dupcapOnboarding === 'undefined') {
		return;
	}

	var slides = Array.prototype.slice.call(root.querySelectorAll('.dupcap-onboarding__slide'));
	var prevBtn = root.querySelector('[data-dupcap-onboarding-prev]');
	var nextBtn = root.querySelector('[data-dupcap-onboarding-next]');
	var finishBtn = root.querySelector('[data-dupcap-onboarding-finish]');
	var toolChecks = Array.prototype.slice.call(root.querySelectorAll('[data-dupcap-tool]'));
	var addonChecks = Array.prototype.slice.call(root.querySelectorAll('[data-dupcap-addon]'));
	var footerNotes = Array.prototype.slice.call(root.querySelectorAll('[data-dupcap-footer-note]'));
	var installing = false;
	var optinCheck = root.querySelector('[data-dupcap-optin]');
	var optinTermsBtn = root.querySelector('[data-dupcap-optin-terms]');
	var optinDetails = root.querySelector('[data-dupcap-optin-details]');
	var optinSaved = false;
	var dismissing = false;
	var path = [];
	var pathPos = 0;
	var isOverlay = root.classList.contains('is-overlay') || !!(dupcapOnboarding && dupcapOnboarding.isOverlay);

	if (isOverlay && root.parentNode !== document.body) {
		document.body.appendChild(root);
	}

	function selectedTools() {
		var selected = {};
		for (var i = 0; i < toolChecks.length; i++) {
			if (toolChecks[i].checked) {
				selected[toolChecks[i].getAttribute('data-dupcap-tool')] = true;
			}
		}
		return selected;
	}

	function isStepEnabled(el, tools) {
		var requires = el.getAttribute('data-requires');
		if (!requires) {
			return true;
		}
		return !!tools[requires];
	}

	function rebuildPath() {
		var tools = selectedTools();
		path = [];
		for (var i = 0; i < slides.length; i++) {
			if (isStepEnabled(slides[i], tools)) {
				path.push(i);
			}
		}
		if (!path.length) {
			path = [0];
		}

		updateDoneCopy(tools);
		syncFeatureCards(tools);
	}

	function getDoneCopyMap() {
		return (dupcapOnboarding && dupcapOnboarding.doneCopy) || {};
	}

	function getDoneSlide() {
		return root.querySelector('.dupcap-onboarding__slide[data-step-id="done"]');
	}

	function updateDoneCopy(tools) {
		tools = tools || selectedTools();
		var copyMap = getDoneCopyMap();
		var hasDup = !!tools.duplicate;
		var hasIns = !!tools.inspector;
		var key = 'both';

		if (hasDup && hasIns) {
			key = 'both';
		} else if (hasDup) {
			key = 'duplicate';
		} else if (hasIns) {
			key = 'inspector';
		} else {
			return;
		}

		var copy = copyMap[key];
		if (!copy || typeof copy !== 'object') {
			return;
		}

		root.setAttribute('data-done-variant', key);

		var slide = getDoneSlide();
		var doneTitle = slide ? slide.querySelector('.dupcap-onboarding__title') : null;
		var doneDesc = slide ? slide.querySelector('.dupcap-onboarding__desc') : null;
		var doneList = slide ? slide.querySelector('.dupcap-onboarding__list') : null;

		if (doneTitle && copy.title) {
			doneTitle.textContent = copy.title;
		}
		if (doneDesc && copy.description) {
			doneDesc.innerHTML = copy.description;
		}
		if (doneList) {
			var bullets = copy.bullets || [];
			var keys = Object.keys(bullets);
			if (!keys.length) {
				doneList.innerHTML = '';
				doneList.hidden = true;
			} else {
				var html = '';
				for (var i = 0; i < keys.length; i++) {
					html += '<li>' + escapeHtml(bullets[keys[i]]) + '</li>';
				}
				doneList.innerHTML = html;
				doneList.hidden = false;
			}
		}
		var doneTags = slide ? slide.querySelector('.dupcap-onboarding__done-tags') : null;
		if (doneTags && copy.tags && copy.tags.length) {
			var tagsHtml = '';
			for (var t = 0; t < copy.tags.length; t++) {
				var tag = copy.tags[t];
				var dotClass = tag.dot === 'blue' ? 'is-blue' : 'is-green';
				tagsHtml += '<span class="dupcap-onboarding__done-tag">' +
					'<span class="dupcap-onboarding__done-dot ' + dotClass + '"></span>' +
					escapeHtml(tag.label) +
					'</span>';
			}
			doneTags.innerHTML = tagsHtml;
		}

		if (finishBtn) {
			var text = copy.finish || dupcapOnboarding.finishText || '';
			finishBtn.innerHTML = '<span>' + escapeHtml(text) + '</span><span class="dupcap-btn-arrow" aria-hidden="true">&rarr;</span>';
		}
	}

	function escapeHtml(text) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(String(text || '')));
		return div.innerHTML;
	}

	function syncFeatureCards(tools) {
		for (var i = 0; i < toolChecks.length; i++) {
			var check = toolChecks[i];
			var card = check.closest('.dupcap-onboarding__feature');
			if (!card) {
				continue;
			}
			var tool = check.getAttribute('data-dupcap-tool');
			card.classList.toggle('is-checked', !!tools[tool]);
		}
	}

	function showPathIndex(pos) {
		if (pos < 0 || pos >= path.length) {
			return;
		}
		pathPos = pos;
		var currentIndex = path[pathPos];

		for (var i = 0; i < slides.length; i++) {
			var active = i === currentIndex;
			slides[i].classList.toggle('is-active', active);
			if (active) {
				slides[i].removeAttribute('hidden');
			} else {
				slides[i].setAttribute('hidden', '');
			}
		}

		if (prevBtn) {
			var stepIdForPrev = slides[currentIndex] ? slides[currentIndex].getAttribute('data-step-id') : '';
			// Done screen matches the clean finish layout — primary CTA only.
			if (pathPos === 0 || stepIdForPrev === 'done') {
				prevBtn.setAttribute('hidden', '');
			} else {
				prevBtn.removeAttribute('hidden');
			}
		}

		var isLast = pathPos === path.length - 1;
		if (nextBtn) {
			if (isLast) {
				nextBtn.setAttribute('hidden', '');
			} else {
				nextBtn.removeAttribute('hidden');
			}
		}
		if (finishBtn) {
			if (isLast) {
				finishBtn.removeAttribute('hidden');
			} else {
				finishBtn.setAttribute('hidden', '');
			}
		}

		// The footer note belongs to one step, not to the wizard.
		var stepId = slides[currentIndex] ? slides[currentIndex].getAttribute('data-step-id') : '';
		for (var f = 0; f < footerNotes.length; f++) {
			footerNotes[f].hidden = footerNotes[f].getAttribute('data-dupcap-footer-note') !== stepId;
		}

		// Refresh Done copy whenever this step is shown (matches current checkboxes).
		updateDoneCopy(selectedTools());
	}

	// The toolkit has to keep at least one of its own tools. Rather than
	// refusing on Continue, the last one still ticked stops being clickable and
	// says why, so the limit is visible before it is hit.
	function syncCoreLock() {
		var ticked = toolChecks.filter(function (input) {
			return input.checked;
		});
		var lockLast = ticked.length === 1;

		toolChecks.forEach(function (input) {
			var row = featureRow(input);
			var mark = row ? row.querySelector('.dupcap-onboarding__feature-mark') : null;
			var lock = lockLast && input.checked;

			input.disabled = lock;

			if (row) {
				row.classList.toggle('is-locked', lock);
			}
			if (mark) {
				if (lock) {
					mark.setAttribute('data-dupcap-tooltip', dupcapOnboarding.requiredToolText || 'At least one included tool is required.');
					mark.removeAttribute('title');
				} else {
					mark.removeAttribute('data-dupcap-tooltip');
					mark.removeAttribute('title');
					mark.classList.remove('is-tooltip-active');
				}
			}
		});
	}

	function resolveFinishUrl() {
		var tools = selectedTools();
		var urls = dupcapOnboarding.finishUrls || {};
		var hasDup = !!tools.duplicate;
		var hasIns = !!tools.inspector;
		if (hasDup && hasIns && urls.both) {
			return urls.both;
		}
		if (hasIns && urls.inspector) {
			return urls.inspector;
		}
		if (hasDup && urls.duplicate) {
			return urls.duplicate;
		}
		return (urls && urls.duplicate) || dupcapOnboarding.finishUrl || (window.ajaxurl ? 'admin.php?page=toolkit-for-polylang' : '/');
	}

	function staysOnDashboard(finishUrl) {
		if (!isOverlay || !finishUrl) {
			return false;
		}
		var inspector = (dupcapOnboarding.finishUrls && dupcapOnboarding.finishUrls.inspector) || dupcapOnboarding.finishUrl || '';
		if (!inspector) {
			return false;
		}
		return finishUrl.indexOf('page=') !== -1 && inspector.indexOf('page=') !== -1 &&
			finishUrl.replace(/#.*$/, '').split('page=')[1].split('&')[0] === inspector.replace(/#.*$/, '').split('page=')[1].split('&')[0];
	}

	function closeOverlay() {
		document.body.classList.remove('dupcap-onboarding-overlay');
		if (root.parentNode) {
			root.parentNode.removeChild(root);
		}
	}

	// Drop the forced-replay flag before any reload: left in place,
	// ?dupcap_onboarding=1 would reopen the wizard on the very page load that is
	// meant to show the finished dashboard.
	function getCleanUrl() {
		var cleanUrl = window.location.href;
		try {
			var url = new URL(window.location.href);
			url.searchParams.delete('dupcap_onboarding');
			cleanUrl = url.toString();
			if (window.history && window.history.replaceState) {
				window.history.replaceState({}, document.title, cleanUrl);
			}
		} catch (err) {
			cleanUrl = window.location.href.replace(/([?&])dupcap_onboarding=[^&]*(&|$)/, function(m, p1, p2) {
				return (p1 === '?' ? '?' : (p2 === '&' ? '&' : ''));
			}).replace(/\?&/, '?').replace(/\?$/, '');
		}
		return cleanUrl;
	}

	// isSkip: the X and Escape only dismiss setup. They must never follow the
	// tool-based destination - closing the wizard with just Duplicate Content
	// selected used to throw the user onto edit.php.
	function completeAndLeave(isSkip) {
		if (dismissing) {
			return;
		}
		dismissing = true;

		var body = new FormData();
		body.append('action', 'dupcap_onboarding_dismiss');
		body.append('nonce', dupcapOnboarding.nonce);
		body.append('tools', JSON.stringify(Object.keys(selectedTools())));
		var finishUrl = isSkip ? '' : resolveFinishUrl();
		var stay = isSkip || staysOnDashboard(finishUrl);

		var go = function () {
			if (stay) {
				// Take the overlay down first so it closes even if what follows
				// is slow.
				closeOverlay();
				var cleanUrl = getCleanUrl();

				// Finishing only sends the user to the dashboard; no scan is
				// started here. The page behind the overlay was rendered before
				// the wizard saved, so it navigates to pick up the saved tools.
				window.location.href = cleanUrl;
				return;
			}
			window.location.href = finishUrl;
		};

		if (window.fetch) {
			fetch(dupcapOnboarding.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			})
				.then(function (response) {
					return response.json();
				})
				.then(function () {
					go();
				})
				.catch(function () {
					go();
				});
		} else {
			go();
		}
	}

	// The welcome step owns the opt-in answer, so Continue is what records it -
	// consent (and the first usage snapshot) must not wait for Finish, which the
	// user may never reach.
	function saveOptin() {
		if (optinSaved || !optinCheck || !window.fetch) {
			return;
		}
		optinSaved = true;

		var body = new FormData();
		body.append('action', 'dupcap_onboarding_optin');
		body.append('nonce', dupcapOnboarding.nonce);
		body.append('opt_in', optinCheck.checked ? 'yes' : 'no');
		body.append('tools', JSON.stringify(Object.keys(selectedTools())));

		fetch(dupcapOnboarding.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		}).catch(function () {
			// A failed save must not block the wizard; Finish saves the tools again.
			optinSaved = false;
		});
	}


	// ---- Optional add-on plugins -------------------------------------------
	//
	// Continue installs every ticked add-on before moving on. Each row reports
	// its own progress, and a failure is shown on that row rather than blocking
	// setup: the user can always add the plugin later.

	function featureRow(input) {
		return input.closest('.dupcap-onboarding__feature');
	}

	function setNote(input, text, state) {
		var key = input.getAttribute('data-dupcap-addon') || '';
		var note = root.querySelector('[data-dupcap-feature-note="' + key + '"]');
		var row = featureRow(input);

		if (note) {
			note.textContent = text || '';
		}
		if (row) {
			row.classList.remove('is-busy', 'is-done', 'is-error');
			if (state) {
				row.classList.add('is-' + state);
			}
		}
	}

	function lockRow(input) {
		var row = featureRow(input);
		var mark = row ? row.querySelector('.dupcap-onboarding__feature-mark') : null;

		input.disabled = true;
		if (row) {
			row.classList.add('is-locked');
		}
		// Same reason the server puts on an add-on that was already active.
		if (mark && !mark.getAttribute('data-dupcap-tooltip')) {
			mark.setAttribute('data-dupcap-tooltip', (dupcapOnboarding && dupcapOnboarding.activeAddonText) || '');
		}
	}

	function setInstalling(state, statusText) {
		installing = state;
		if (nextBtn) {
			nextBtn.disabled = state;
			if (state) {
				nextBtn.classList.add('is-loading');
				var text = statusText || (dupcapOnboarding && dupcapOnboarding.installingPluginsText) || 'Installing plugins…';
				nextBtn.innerHTML = '<svg class="dupcap-spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.3"></circle><path d="M12 3a9 9 0 0 1 9 9" stroke="currentColor" stroke-linecap="round"></path></svg><span>' + escapeHtml(text) + '</span>';
			} else {
				nextBtn.classList.remove('is-loading');
				nextBtn.textContent = (dupcapOnboarding && dupcapOnboarding.continueText) || 'Continue';
			}
		}
		if (prevBtn) {
			prevBtn.disabled = state;
		}
	}

	function pendingAddons() {
		return addonChecks.filter(function (input) {
			return input.checked && !input.disabled && input.getAttribute('data-plugin-action');
		});
	}

	function installAddon(input) {
		var action = input.getAttribute('data-plugin-action') || 'install';
		var slug = input.getAttribute('data-plugin-slug') || '';
		var body = new FormData();
		var row = featureRow(input);

		body.append('action', 'dupcap_install_plugin');
		body.append('_wpnonce', dupcapOnboarding.installNonce || '');
		body.append('slug', slug);
		body.append('plugin_action', action);

		if (row) {
			row.classList.add('is-busy');
		}

		return fetch(dupcapOnboarding.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				var data = (payload && payload.data) || {};

				if (row) {
					row.classList.remove('is-busy');
				}

				// The handler answers success even when the install worked but
				// activation did not, so the error field decides, not the status.
				if (payload && payload.success && !data.errorMessage) {
					lockRow(input);
					return;
				}

				setNote(
					input,
					data.errorMessage || dupcapOnboarding.installErrorText || 'Could not be installed.',
					'error'
				);
			})
			.catch(function () {
				if (row) {
					row.classList.remove('is-busy');
				}
				setNote(input, dupcapOnboarding.installErrorText || 'Could not be installed.', 'error');
			});
	}

	function installAddons() {
		var queue = pendingAddons();

		if (!queue.length || !window.fetch) {
			return Promise.resolve();
		}

		var hasInstall = queue.some(function (input) {
			return input.getAttribute('data-plugin-action') === 'install';
		});
		var defaultStatusText = hasInstall
			? ((dupcapOnboarding && dupcapOnboarding.installingPluginsText) || 'Installing plugins…')
			: ((dupcapOnboarding && dupcapOnboarding.activatingPluginsText) || 'Activating plugins…');

		setInstalling(true, defaultStatusText);

		// One at a time: two Plugin_Upgrader runs in parallel fight over the
		// same filesystem lock and the second one fails for no good reason.
		return queue
			.reduce(function (chain, input) {
				return chain.then(function () {
					var action = input.getAttribute('data-plugin-action');
					var currentText = action === 'activate'
						? ((dupcapOnboarding && dupcapOnboarding.activatingPluginsText) || 'Activating plugins…')
						: ((dupcapOnboarding && dupcapOnboarding.installingPluginsText) || 'Installing plugins…');
					setInstalling(true, currentText);
					return installAddon(input);
				});
			}, Promise.resolve())
			.then(function () {
				setInstalling(false);
			});
	}

	function advance(currentIndex) {
		rebuildPath();
		var newPos = path.indexOf(currentIndex);
		if (newPos < 0) {
			newPos = 0;
		}
		if (newPos < path.length - 1) {
			showPathIndex(newPos + 1);
		} else {
			showPathIndex(newPos);
		}
	}

	function goNext() {
		var currentIndex = path[pathPos];
		var stepId = slides[currentIndex] ? slides[currentIndex].getAttribute('data-step-id') : '';

		if (installing) {
			return;
		}

		// The features step owns the opt-in answer and the add-on installs, and
		// both have to finish before the Done step claims setup is complete.
		if (stepId === 'features') {
			saveOptin();
			installAddons().then(function () {
				advance(currentIndex);
			});
			return;
		}

		advance(currentIndex);
	}

	function goPrev() {
		if (installing) {
			return;
		}
		rebuildPath();
		var currentIndex = path[pathPos];
		var newPos = path.indexOf(currentIndex);
		if (newPos < 0) {
			newPos = 0;
		}
		if (newPos > 0) {
			showPathIndex(newPos - 1);
		} else {
			showPathIndex(0);
		}
	}

	rebuildPath();
	showPathIndex(0);

	if (optinCheck) {
		optinCheck.addEventListener('change', function () {
			optinSaved = false;
			var row = featureRow(optinCheck);
			if (row) {
				row.classList.toggle('is-checked', optinCheck.checked);
			}
		});
	}

	if (optinTermsBtn && optinDetails) {
		optinTermsBtn.addEventListener('click', function () {
			var show = optinDetails.hidden;
			optinDetails.hidden = !show;
			optinTermsBtn.setAttribute('aria-expanded', show ? 'true' : 'false');
			optinTermsBtn.textContent = show ? (dupcapOnboarding.hideTermsText || 'Hide terms') : (dupcapOnboarding.seeTermsText || 'See terms');
		});
	}

	// Ticking a row only repaints it; what it means is read on Continue.
	for (var t = 0; t < toolChecks.length; t++) {
		toolChecks[t].addEventListener('change', function () {
			var row = featureRow(this);
			if (row) {
				row.classList.toggle('is-checked', this.checked);
			}
			syncCoreLock();
			updateDoneCopy(selectedTools());
		});
	}

	// A saved answer can already be down to one tool, so lock on load too.
	syncCoreLock();

	for (var a = 0; a < addonChecks.length; a++) {
		addonChecks[a].addEventListener('change', function () {
			var row = featureRow(this);
			if (row) {
				row.classList.toggle('is-checked', this.checked);
			}
		});
	}

	root.addEventListener('click', function (event) {
		var target = event.target;
		if (!target) {
			return;
		}

		var lockedRow = target.closest('.dupcap-onboarding__feature.is-locked');
		if (lockedRow) {
			var tooltipMark = lockedRow.querySelector('.dupcap-onboarding__feature-mark[data-dupcap-tooltip]');
			if (tooltipMark) {
				tooltipMark.classList.add('is-tooltip-active');
				setTimeout(function () {
					tooltipMark.classList.remove('is-tooltip-active');
				}, 2200);
			}
		}

		if (target.closest('[data-dupcap-onboarding-skip]')) {
			completeAndLeave(true);
			return;
		}
		if (target.closest('[data-dupcap-onboarding-prev]')) {
			goPrev();
			return;
		}
		if (target.closest('[data-dupcap-onboarding-next]')) {
			goNext();
			return;
		}
		if (target.closest('[data-dupcap-onboarding-finish]')) {
			completeAndLeave(false);
		}
	});

	document.addEventListener('keydown', function (event) {
		if (installing) {
			return;
		}
		if (event.key === 'Escape') {
			completeAndLeave(true);
		} else if (event.key === 'ArrowRight') {
			goNext();
		} else if (event.key === 'ArrowLeft') {
			goPrev();
		}
	});
})();
