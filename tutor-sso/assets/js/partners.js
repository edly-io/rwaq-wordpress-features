/**
 * Partners catalog behaviour: search, sort, infinite scroll.
 *
 * Every one of those is a single request to the same AJAX endpoint, which in
 * turn is one request to the LMS organizations API — search, sort and paging
 * travel together as query args, exactly as the courses catalog does.
 * Nothing is filtered or sorted client-side, so results always reflect the whole
 * remote catalog rather than the page in the DOM.
 *
 * Two request modes:
 *   reload  a search / sort change — resets to page 1 and replaces the grid,
 *           behind the dimming overlay.
 *   append  infinite scroll — fetches the next page and appends, behind the
 *           bottom spinner.
 *
 * Vanilla JS, no dependencies. Class names match
 * includes/partners/partners-catalog.php and assets/css/partners.css.
 */
(function () {
	'use strict';

	var CFG = window.tutorSsoPartners || {};
	var I18N = CFG.i18n || {};

	/**
	 * Wire one catalog instance.
	 *
	 * @param {Element} root The .rwaq-partners wrapper.
	 */
	function initCatalog(root) {
		var grid = root.querySelector('.rwaq-partners__grid');
		var status = root.querySelector('.rwaq-partners__status');
		var overlay = root.querySelector('.rwaq-partners__overlay');
		var loader = root.querySelector('.rwaq-partners__loader');
		var countEl = root.querySelector('[data-result-count]');
		var searchInput = root.querySelector('.rwaq-partners__search-input');

		if (!grid) {
			return;
		}

		var perPage = parseInt(root.getAttribute('data-per-page'), 10) || 24;
		var state = {
			page: parseInt(root.getAttribute('data-page'), 10) || 1,
			hasMore: root.getAttribute('data-has-more') === 'true',
			search: '',
			ordering: root.getAttribute('data-default-sort') || '',
			loading: false
		};

		/** Build the query string for the current state. */
		function query(page) {
			var params = new URLSearchParams();
			params.set('action', 'tutor_sso_load_partners');
			params.set('nonce', CFG.nonce || '');
			params.set('page', String(page));
			params.set('per_page', String(perPage));
			if (state.search) { params.set('search', state.search); }
			if (state.ordering) { params.set('ordering', state.ordering); }
			return params.toString();
		}

		function setBusy(busy, mode) {
			state.loading = busy;
			grid.setAttribute('aria-busy', busy ? 'true' : 'false');

			if (mode === 'append') {
				if (loader) { loader.hidden = !busy; }
			} else if (overlay) {
				overlay.hidden = !busy;
			}
		}

		/**
		 * Fetch a page and either replace or append the grid.
		 *
		 * @param {number} page
		 * @param {string} mode 'reload' | 'append'
		 * @return {Promise} Settles when the grid has been updated.
		 */
		function load(page, mode) {
			if (state.loading || !CFG.ajaxurl) {
				return Promise.resolve();
			}

			setBusy(true, mode);

			return fetch(CFG.ajaxurl + '?' + query(page), {
				credentials: 'same-origin',
				headers: { 'Accept': 'application/json' }
			})
				.then(function (res) { return res.json(); })
				.then(function (payload) {
					if (!payload || !payload.success || !payload.data) {
						throw new Error((payload && payload.data && payload.data.message) || 'request failed');
					}

					var data = payload.data;

					if (mode === 'append') {
						grid.insertAdjacentHTML('beforeend', data.html || '');
					} else {
						grid.innerHTML = data.html || '';
					}

					state.page = data.page || page;
					state.hasMore = !!data.has_more;
					root.setAttribute('data-page', String(state.page));
					root.setAttribute('data-has-more', state.hasMore ? 'true' : 'false');

					if (countEl && data.countText) {
						countEl.textContent = data.countText;
					}

					if (status) {
						status.textContent = grid.children.length ? '' : (I18N.noResults || '');
					}
				})
				.catch(function () {
					if (status) { status.textContent = I18N.error || ''; }
					// Do not keep retrying a failing endpoint on every scroll.
					state.hasMore = false;
					root.setAttribute('data-has-more', 'false');
				})
				.then(function () {
					setBusy(false, mode);
				});
		}

		function reload() {
			state.page = 1;
			load(1, 'reload');
		}

		// ── Search ────────────────────────────────────────────────────────────
		if (searchInput) {
			var searchTimer = null;
			searchInput.addEventListener('input', function () {
				clearTimeout(searchTimer);
				searchTimer = setTimeout(function () {
					var value = searchInput.value.trim();
					if (value === state.search) {
						return;
					}
					state.search = value;
					reload();
				}, 350);
			});

			// Enter should not submit a surrounding form.
			searchInput.addEventListener('keydown', function (event) {
				if (event.key === 'Enter') {
					event.preventDefault();
					clearTimeout(searchTimer);
					state.search = searchInput.value.trim();
					reload();
				}
			});
		}

		/**
		 * Wire one pill + menu pair, driven by its hidden <select> so the options
		 * live in the markup and stay available without JS.
		 *
		 * @param {Element} wrap .rwaq-partners__sort
		 * @param {string}  key  state key to write
		 */
		function initPill(wrap, key) {
			if (!wrap) { return; }

			var select = wrap.querySelector('select');
			var button = wrap.querySelector('.rwaq-partners__pill');
			var menu = wrap.querySelector('.rwaq-partners__menu');
			var valueEl = wrap.querySelector('.rwaq-partners__pill-value');

			if (!select || !button || !menu) { return; }

			// Build the menu from the select's options.
			Array.prototype.forEach.call(select.options, function (opt) {
				var item = document.createElement('div');
				item.className = 'rwaq-partners__option';
				item.setAttribute('role', 'option');
				item.dataset.value = opt.value;
				item.textContent = opt.textContent;
				if (opt.value === select.value) {
					item.classList.add('is-selected');
					item.setAttribute('aria-selected', 'true');
				}
				menu.appendChild(item);
			});

			function close() {
				wrap.classList.remove('is-open');
				button.setAttribute('aria-expanded', 'false');
			}

			button.addEventListener('click', function (event) {
				event.stopPropagation();
				var open = !wrap.classList.contains('is-open');

				// Only one menu open at a time.
				root.querySelectorAll('.is-open').forEach(function (el) {
					el.classList.remove('is-open');
					var b = el.querySelector('.rwaq-partners__pill');
					if (b) { b.setAttribute('aria-expanded', 'false'); }
				});

				wrap.classList.toggle('is-open', open);
				button.setAttribute('aria-expanded', open ? 'true' : 'false');
			});

			menu.addEventListener('click', function (event) {
				var item = event.target.closest('.rwaq-partners__option');
				if (!item) { return; }

				select.value = item.dataset.value;

				menu.querySelectorAll('.rwaq-partners__option').forEach(function (el) {
					var on = el === item;
					el.classList.toggle('is-selected', on);
					el.setAttribute('aria-selected', on ? 'true' : 'false');
				});

				if (valueEl) {
					valueEl.textContent = item.textContent;
				}

				close();

				if (state[key] !== select.value) {
					state[key] = select.value;
					reload();
				}
			});

			// Keyboard: Escape closes.
			wrap.addEventListener('keydown', function (event) {
				if (event.key === 'Escape') { close(); }
			});
		}

		initPill(root.querySelector('.rwaq-partners__sort'), 'ordering');

		// Clicking anywhere else closes any open menu.
		document.addEventListener('click', function (event) {
			if (!root.contains(event.target)) {
				root.querySelectorAll('.is-open').forEach(function (el) {
					el.classList.remove('is-open');
				});
			}
		});

		// ── Infinite scroll ───────────────────────────────────────────────────
		// A dedicated 1px sentinel after the grid is what gets observed. Two
		// things it must not be: the [hidden] loader (display:none has no box, so
		// it never intersects) or an ancestor of the grid (already intersecting on
		// load, and its intersection never changes — so the observer would fire
		// once immediately and then stay silent forever).
		var sentinel = root.querySelector('.rwaq-partners__sentinel');
		var observer = null;

		if (sentinel && 'IntersectionObserver' in window) {
			// Appending can leave the sentinel still on screen — on a tall viewport,
			// or when a page of cards is shorter than the gap. No intersection
			// *change* means no new callback, so after each append the position is
			// re-checked directly and the next page pulled if it is still in range.
			var MARGIN = 400;

			function inRange() {
				var r = sentinel.getBoundingClientRect();
				return r.top <= (window.innerHeight || 0) + MARGIN;
			}

			function pump() {
				if (!state.hasMore || state.loading || !inRange()) {
					return;
				}

				load(state.page + 1, 'append').then(pump);
			}

			// The reference is held deliberately: an IntersectionObserver created
			// inline, with nothing pointing at it, has been observed to stop firing
			// after its first callback.
			observer = new IntersectionObserver(function () {
				pump();
			}, { rootMargin: MARGIN + 'px 0px' });
			observer.observe(sentinel);

			// Belt and braces. The observer only reports intersection *changes*, and
			// a sentinel that appends its way just past the margin and back can miss
			// the transition — which showed up in testing as loading stalling after
			// one extra page. A throttled scroll/resize check has no such dependency,
			// and pump()'s own guards make the duplicate calls free.
			var pumpTimer = null;
			function schedulePump() {
				if (pumpTimer) { return; }
				pumpTimer = setTimeout(function () {
					pumpTimer = null;
					pump();
				}, 150);
			}

			window.addEventListener('scroll', schedulePump, { passive: true });
			window.addEventListener('resize', schedulePump);
		}
	}

	function init() {
		Array.prototype.forEach.call(document.querySelectorAll('.rwaq-partners'), initCatalog);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
