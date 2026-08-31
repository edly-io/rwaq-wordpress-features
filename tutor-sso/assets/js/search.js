/**
 * Search results page behaviour.
 *
 * Three pieces:
 *
 *   1. Type toggle — both grids are already in the page, so switching is a local
 *      show/hide. The toggles are real links, so they work without JS.
 *   2. Infinite scroll — each panel pages independently via AJAX.
 *   3. Sort menu open/close. Its options are links, so sorting works without JS.
 *
 * Class names match includes/search/search-page.php and assets/css/search.css.
 */
(function () {
	'use strict';

	/**
	 * Wire one search results block.
	 *
	 * @param {Element} root The .rwaq-search wrapper.
	 */
	function initSearch(root) {
		// ── Type toggle ───────────────────────────────────────────────────────
		var tabs = Array.prototype.slice.call(root.querySelectorAll('.rwaq-search__type'));
		var panels = Array.prototype.slice.call(root.querySelectorAll('.rwaq-search__panel'));

		if (tabs.length && panels.length) {
			tabs.forEach(function (tab) {
				tab.addEventListener('click', function (event) {
					var type = tab.dataset.type;
					if (!type) {
						return;
					}

					// Intercept only when the matching panel is present; otherwise
					// let the link navigate as it would without JS.
					var target = panels.filter(function (p) { return p.dataset.panel === type; })[0];
					if (!target) {
						return;
					}

					event.preventDefault();

					tabs.forEach(function (t) {
						var on = t === tab;
						t.classList.toggle('is-active', on);
						t.setAttribute('aria-selected', on ? 'true' : 'false');
					});

					panels.forEach(function (p) {
						p.hidden = p !== target;
					});

					root.setAttribute('data-active-type', type);

					// Keep the URL honest without reloading, so a copied link or a
					// refresh lands on the same type.
					if (window.history && window.history.replaceState) {
						try {
							var url = new URL(window.location.href);
							url.searchParams.set('type', type);
							window.history.replaceState({}, '', url);
						} catch (e) {
							// A malformed URL is not worth failing the toggle over.
						}
					}
				});
			});
		}

		// ── Infinite scroll, per panel ────────────────────────────────────────
		var CFG = window.tutorSsoSearch || {};
		var I18N = CFG.i18n || {};
		var MARGIN = 400;

		panels.forEach(function (panel) {
			var sentinel = panel.querySelector('.rwaq-search__sentinel');
			var loader = panel.querySelector('.rwaq-search__loader');
			var grid = panel.querySelector('.rwaq-search__grid');

			if (!sentinel || !grid || !CFG.ajaxurl) {
				return;
			}

			var loading = false;

			function inRange() {
				return sentinel.getBoundingClientRect().top <= (window.innerHeight || 0) + MARGIN;
			}

			// A hidden panel has no layout, so its sentinel never qualifies.
			function ready() {
				return !panel.hidden && !loading &&
					panel.dataset.hasMore === 'true' && inRange();
			}

			function load() {
				if (!ready()) {
					return Promise.resolve();
				}

				loading = true;
				if (loader) { loader.hidden = false; }

				var params = new URLSearchParams();
				params.set('action', 'tutor_sso_load_search');
				params.set('nonce', CFG.nonce || '');
				params.set('q', root.dataset.query || '');
				params.set('type', panel.dataset.panel);
				params.set('page', String(parseInt(panel.dataset.page, 10) + 1));
				params.set('per_page', root.dataset.perPage || '12');
				if (root.dataset.ordering) { params.set('ordering', root.dataset.ordering); }

				return fetch(CFG.ajaxurl + '?' + params.toString(), {
					credentials: 'same-origin',
					headers: { 'Accept': 'application/json' }
				})
					.then(function (res) { return res.json(); })
					.then(function (payload) {
						if (!payload || !payload.success || !payload.data) {
							throw new Error('request failed');
						}

						grid.insertAdjacentHTML('beforeend', payload.data.html || '');
						panel.dataset.page = String(payload.data.page);
						panel.dataset.hasMore = payload.data.has_more ? 'true' : 'false';
					})
					.catch(function () {
						// Stop retrying a failing endpoint on every scroll.
						panel.dataset.hasMore = 'false';
						var note = document.createElement('p');
						note.className = 'rwaq-search__empty rwaq-search__empty--error';
						note.textContent = I18N.error || '';
						panel.appendChild(note);
					})
					.then(function () {
						loading = false;
						if (loader) { loader.hidden = true; }
					});
			}

			// Appending can leave the sentinel still in view, and no intersection
			// change means no further callback — so re-check after each append.
			function pump() {
				load().then(function () {
					if (ready()) { pump(); }
				});
			}

			if ('IntersectionObserver' in window) {
				// Reference held: an inline observer has been seen to stop firing.
				panel._observer = new IntersectionObserver(function () {
					pump();
				}, { rootMargin: MARGIN + 'px 0px' });
				panel._observer.observe(sentinel);
			}

			// Scroll fallback: scrollTo clamped at the bottom fires no scroll
			// event, and the observer only reports intersection *changes*.
			var timer = null;
			window.addEventListener('scroll', function () {
				if (timer) { return; }
				timer = setTimeout(function () { timer = null; pump(); }, 150);
			}, { passive: true });

			panel._pump = pump;
		});

		// Switching type must let the newly shown panel fill the viewport.
		tabs.forEach(function (tab) {
			tab.addEventListener('click', function () {
				var target = panels.filter(function (p) { return !p.hidden; })[0];
				if (target && target._pump) { target._pump(); }
			});
		});

		// ── Sort menu ─────────────────────────────────────────────────────────
		var sort = root.querySelector('.rwaq-search__sort');

		if (sort) {
			var button = sort.querySelector('.rwaq-search__pill');

			if (button) {
				button.addEventListener('click', function (event) {
					event.stopPropagation();
					var open = !sort.classList.contains('is-open');
					sort.classList.toggle('is-open', open);
					button.setAttribute('aria-expanded', open ? 'true' : 'false');
				});

				sort.addEventListener('keydown', function (event) {
					if (event.key === 'Escape') {
						sort.classList.remove('is-open');
						button.setAttribute('aria-expanded', 'false');
					}
				});

				document.addEventListener('click', function (event) {
					if (!sort.contains(event.target)) {
						sort.classList.remove('is-open');
						button.setAttribute('aria-expanded', 'false');
					}
				});
			}
		}
	}

	function init() {
		Array.prototype.forEach.call(document.querySelectorAll('.rwaq-search'), initSearch);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
