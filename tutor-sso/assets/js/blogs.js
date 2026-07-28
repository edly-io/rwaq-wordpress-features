/* global jQuery, tutorSsoBlogs */
/**
 * Blogs listing: infinite scroll + AJAX search, sort and a category filter.
 *
 * WordPress-posts counterpart of programs.js. Each `.rwaq-blogs` block is
 * self-contained; per-instance config lives in data-* attributes, shared config
 * (ajaxurl / nonce / i18n) in tutorSsoBlogs.
 *
 * Filtering: a single top-toolbar dropdown of category checkboxes plus an "All"
 * reset row and a "Featured" (مميز) pseudo-option mapping to the ACF is_featured
 * flag. Selections are staged and only take effect on "Apply" (تطبيق). Applied
 * selections render as removable chips; "clear all" (chip row or dropdown)
 * resets everything. Search & sort apply instantly; the grid paginates on scroll.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.tutorSsoBlogs || {};
	var i18n = cfg.i18n || {};
	var icons = cfg.icons || {};

	// Remove ("×") icon used on active-filter chips (sourced from blogs_icon()).
	var CHIP_X_SVG = icons.removeChip || '';

	$( '.rwaq-blogs' ).each( function () {
		initListing( $( this ) );
	} );

	function initListing( $root ) {
		var $grid = $root.find( '.rwaq-blogs__grid' ).first();
		var $loader = $root.find( '.rwaq-blogs__loader' ).first();
		var $overlay = $root.find( '.rwaq-blogs__overlay' ).first();
		var $status = $root.find( '.rwaq-blogs__status' ).first();
		var $sentinel = $root.find( '.rwaq-blogs__sentinel' ).first();
		var $search = $root.find( '.rwaq-blogs__search-input' ).first();
		var $resultCount = $root.find( '[data-result-count]' ).first();

		// Sort (custom dropdown backed by a hidden native <select>).
		var $sort = $root.find( '.rwaq-blogs__sort-select' ).first();
		var $sortWrap = $root.find( '.rwaq-blogs__sort' ).first();
		var $sortTrigger = $root.find( '.rwaq-blogs__sort-trigger' ).first();
		var $sortValue = $root.find( '.rwaq-blogs__sort-value' ).first();
		var $sortMenu = $root.find( '.rwaq-blogs__sort-menu' ).first();

		// Category filter dropdown.
		var $filter = $root.find( '.rwaq-blogs__filter' ).first();
		var $filterTrigger = $filter.find( '.rwaq-blogs__filter-trigger' ).first();
		var $filterValue = $filter.find( '.rwaq-blogs__filter-value' ).first();
		var $filterMenu = $filter.find( '.rwaq-blogs__filter-menu' ).first();
		var $filterAll = $filterMenu.find( '.rwaq-blogs__filter-input[data-role="all"]' ).first();

		// Active-filter chips + "clear all".
		var $chips = $root.find( '.rwaq-blogs__chips' ).first();
		var $clearAll = $root.find( '.rwaq-blogs__clear-all' ).first();

		var state = {
			page: parseInt( $root.data( 'page' ), 10 ) || 1, // Last page in the grid.
			perPage: parseInt( $root.data( 'per-page' ), 10 ) || 9,
			postType: $root.data( 'post-type' ) || 'post',
			badgeTax: $root.data( 'badge-tax' ) || '',
			taxonomy: String( $root.data( 'taxonomy' ) || '' ),
			search: '',
			ordering: $sort.length ? $sort.val() : ( $root.data( 'default-sort' ) || '' ),
			categories: [], // applied category slugs
			featured: false, // applied featured flag
			hasMore: toBool( $root.data( 'has-more' ) ),
			loading: false
		};

		// ── Filter helpers ───────────────────────────────────────────────────────
		function termInputs() {
			return $filterMenu.find( '.rwaq-blogs__filter-input[data-role="term"]' );
		}

		function featuredInput() {
			return $filterMenu.find( '.rwaq-blogs__filter-input[data-role="featured"]' );
		}

		function anySpecificChecked() {
			return $filterMenu
				.find( '.rwaq-blogs__filter-input[data-role="term"]:checked, .rwaq-blogs__filter-input[data-role="featured"]:checked' )
				.length > 0;
		}

		// Reflect the applied selection back onto the dropdown checkboxes (used on
		// open and after apply / clear / chip removal), so staged edits that were
		// never applied are discarded.
		function syncCheckboxesToApplied() {
			featuredInput().prop( 'checked', state.featured );
			termInputs().each( function () {
				$( this ).prop( 'checked', state.categories.indexOf( String( $( this ).val() ) ) !== -1 );
			} );
			$filterAll.prop( 'checked', ! ( state.featured || state.categories.length ) );
		}

		function labelForInput( $input ) {
			return $.trim( $input.closest( '.rwaq-blogs__filter-option' ).find( '.rwaq-blogs__filter-label' ).text() );
		}

		// Applied option labels in dropdown order (featured before terms).
		function appliedLabels() {
			var labels = [];
			$filterMenu.find( '.rwaq-blogs__filter-input' ).each( function () {
				var role = $( this ).data( 'role' );
				var value = String( $( this ).val() );
				var on = ( 'featured' === role && state.featured ) ||
					( 'term' === role && state.categories.indexOf( value ) !== -1 );
				if ( on ) {
					labels.push( labelForInput( $( this ) ) );
				}
			} );
			return labels;
		}

		// Trigger value: "All" (none), the single label, or "First +N".
		function updateTriggerLabel() {
			var labels = appliedLabels();
			var allLabel = $filterValue.data( 'all-label' ) || '';
			if ( ! labels.length ) {
				$filterValue.text( allLabel );
			} else if ( 1 === labels.length ) {
				$filterValue.text( labels[ 0 ] );
			} else {
				$filterValue.text( labels[ 0 ] + ' +' + ( labels.length - 1 ) );
			}
		}

		function renderChips() {
			$chips.empty();

			var items = [];
			if ( state.featured ) {
				items.push( { role: 'featured', value: 'featured', label: labelForInput( featuredInput() ) } );
			}
			state.categories.forEach( function ( slug ) {
				var $input = termInputs().filter( function () {
					return String( $( this ).val() ) === String( slug );
				} );
				items.push( { role: 'term', value: slug, label: $input.length ? labelForInput( $input ) : slug } );
			} );

			items.forEach( function ( it ) {
				var $chip = $( '<button type="button" class="rwaq-blogs__chip"></button>' )
					.attr( 'data-role', it.role )
					.attr( 'data-value', it.value )
					.attr( 'aria-label', ( i18n.removeFilter || 'Remove' ) + ': ' + it.label );
				$( '<span></span>' ).text( it.label ).appendTo( $chip );
				$( '<span class="rwaq-blogs__chip-x" aria-hidden="true"></span>' ).html( CHIP_X_SVG ).appendTo( $chip );
				$chips.append( $chip );
			} );

			$clearAll.prop( 'hidden', 0 === items.length );
		}

		// Read the staged checkbox state into the applied state.
		function commitPending() {
			state.featured = featuredInput().prop( 'checked' );
			state.categories = [];
			termInputs().filter( ':checked' ).each( function () {
				state.categories.push( String( $( this ).val() ) );
			} );
		}

		function reflectApplied() {
			renderChips();
			updateTriggerLabel();
		}

		function clearAllFilters() {
			state.featured = false;
			state.categories = [];
			syncCheckboxesToApplied();
			reflectApplied();
			load( true );
		}

		function closeFilter() {
			$filter.removeClass( 'is-open' );
			$filterTrigger.attr( 'aria-expanded', 'false' );
		}

		// ── Fetch a page. reset=true clears the grid and loads page 1 (search /
		//    sort / filter change); reset=false appends the next page (scroll).
		function load( reset ) {
			if ( state.loading ) {
				return;
			}
			if ( ! reset && ! state.hasMore ) {
				return;
			}

			state.loading = true;
			$grid.attr( 'aria-busy', 'true' );
			if ( reset ) {
				$overlay.prop( 'hidden', false );
			} else {
				$loader.prop( 'hidden', false );
			}
			$status.text( '' );

			var nextPage = reset ? 1 : state.page + 1;

			var tax = {};
			if ( state.taxonomy && state.categories.length ) {
				tax[ state.taxonomy ] = state.categories;
			}

			$.ajax( {
				url: cfg.ajaxurl,
				type: 'GET',
				dataType: 'json',
				data: {
					action: 'tutor_sso_load_blogs',
					nonce: cfg.nonce,
					page: nextPage,
					per_page: state.perPage,
					search: state.search,
					ordering: state.ordering,
					post_type: state.postType,
					badge_tax: state.badgeTax,
					featured: state.featured ? 1 : 0,
					tax: tax
				}
			} )
				.done( function ( response ) {
					if ( ! response || ! response.success ) {
						$status.text( errorText( response ) );
						return;
					}

					var data = response.data || {};

					if ( reset ) {
						$grid.empty();
					}

					if ( data.html ) {
						$grid.append( data.html );
					}

					state.page = data.page || nextPage;
					state.hasMore = !! data.has_more;
					updateCounts( data.count );

					if ( reset && ! data.html ) {
						$status.text( i18n.noResults || '' );
					} else {
						$status.text( '' );
					}
				} )
				.fail( function ( jqXHR ) {
					$status.text( errorText( jqXHR.responseJSON ) );
				} )
				.always( function () {
					state.loading = false;
					$grid.attr( 'aria-busy', 'false' );
					$loader.prop( 'hidden', true );
					$overlay.prop( 'hidden', true );
				} );
		}

		function updateCounts( count ) {
			if ( typeof count === 'undefined' || count === null ) {
				return;
			}
			if ( $resultCount.length && i18n.countLabel ) {
				$resultCount.text( i18n.countLabel.replace( '%s', count ) );
			}
		}

		// ── Infinite scroll ─────────────────────────────────────────────────────
		if ( 'IntersectionObserver' in window && $sentinel.length ) {
			var observer = new window.IntersectionObserver( function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting ) {
						load( false );
					}
				} );
			}, { rootMargin: '200px' } );

			observer.observe( $sentinel.get( 0 ) );
		}

		// ── Search (debounced) ───────────────────────────────────────────────────
		var searchTimer = null;
		$search.on( 'input', function () {
			var value = $.trim( $( this ).val() );
			window.clearTimeout( searchTimer );
			searchTimer = window.setTimeout( function () {
				if ( value === state.search ) {
					return;
				}
				state.search = value;
				load( true );
			}, 350 );
		} );

		// ── Sort (custom dropdown backed by the hidden native <select>) ───────────
		function renderSortUI() {
			var current = $sort.val();
			if ( $sortValue.length ) {
				$sortValue.text( $sort.find( 'option:selected' ).text() );
			}
			$sortMenu.find( '.rwaq-blogs__sort-option' ).each( function () {
				$( this ).toggleClass( 'is-selected', String( $( this ).data( 'value' ) ) === String( current ) );
			} );
		}

		function closeSort() {
			$sortWrap.removeClass( 'is-open' );
			$sortTrigger.attr( 'aria-expanded', 'false' );
		}

		if ( $sortMenu.length && $sortTrigger.length ) {
			$sort.find( 'option' ).each( function () {
				$( '<div class="rwaq-blogs__sort-option" role="option"></div>' )
					.attr( 'data-value', $( this ).val() )
					.text( $( this ).text() )
					.appendTo( $sortMenu );
			} );

			$sortTrigger.on( 'click', function ( e ) {
				e.stopPropagation();
				closeFilter();
				var open = $sortWrap.toggleClass( 'is-open' ).hasClass( 'is-open' );
				$sortTrigger.attr( 'aria-expanded', open ? 'true' : 'false' );
			} );

			$sortMenu.on( 'click', '.rwaq-blogs__sort-option', function () {
				var value = String( $( this ).data( 'value' ) );
				if ( $sort.val() !== value ) {
					$sort.val( value ).trigger( 'change' );
				}
				closeSort();
			} );
		}

		$sort.on( 'change', function () {
			state.ordering = $( this ).val();
			renderSortUI();
			load( true );
		} );

		renderSortUI();

		// ── Category filter dropdown ─────────────────────────────────────────────
		if ( $filterTrigger.length && $filterMenu.length ) {
			$filterTrigger.on( 'click', function ( e ) {
				e.stopPropagation();
				closeSort();
				var open = $filter.toggleClass( 'is-open' ).hasClass( 'is-open' );
				$filterTrigger.attr( 'aria-expanded', open ? 'true' : 'false' );
				if ( open ) {
					syncCheckboxesToApplied();
				}
			} );

			// Keep clicks inside the menu from bubbling to the document (close) handler.
			$filterMenu.on( 'click', function ( e ) {
				e.stopPropagation();
			} );

			// "All" ⇄ specific mutual exclusivity (staged, not yet applied).
			$filterMenu.on( 'change', '.rwaq-blogs__filter-input', function () {
				var role = $( this ).data( 'role' );
				if ( 'all' === role ) {
					if ( $( this ).prop( 'checked' ) ) {
						featuredInput().prop( 'checked', false );
						termInputs().prop( 'checked', false );
					} else if ( ! anySpecificChecked() ) {
						$( this ).prop( 'checked', true ); // Can't leave nothing selected.
					}
				} else {
					$filterAll.prop( 'checked', false );
					if ( ! anySpecificChecked() ) {
						$filterAll.prop( 'checked', true );
					}
				}
			} );

			$filter.find( '.rwaq-blogs__filter-apply' ).on( 'click', function () {
				commitPending();
				reflectApplied();
				closeFilter();
				load( true );
			} );

			$filter.find( '.rwaq-blogs__filter-clear' ).on( 'click', function () {
				clearAllFilters();
				closeFilter();
			} );
		}

		// ── Remove a single filter via its chip ──────────────────────────────────
		$chips.on( 'click', '.rwaq-blogs__chip', function () {
			var role = $( this ).data( 'role' );
			var value = String( $( this ).data( 'value' ) );
			if ( 'featured' === role ) {
				state.featured = false;
			} else {
				state.categories = state.categories.filter( function ( slug ) {
					return slug !== value;
				} );
			}
			syncCheckboxesToApplied();
			reflectApplied();
			load( true );
		} );

		// ── Clear all (chip row) ─────────────────────────────────────────────────
		$clearAll.on( 'click', clearAllFilters );

		// ── Close dropdowns on outside click ─────────────────────────────────────
		$( document ).on( 'click', function ( e ) {
			if ( ! $( e.target ).closest( $sortWrap ).length ) {
				closeSort();
			}
			if ( ! $( e.target ).closest( $filter ).length ) {
				closeFilter();
			}
		} );
	}

	function toBool( value ) {
		return value === true || value === 'true' || value === 1 || value === '1';
	}

	function errorText( data ) {
		if ( data && data.data && data.data.message ) {
			return data.data.message;
		}
		return i18n.error || 'Something went wrong. Please try again.';
	}
} )( jQuery );
