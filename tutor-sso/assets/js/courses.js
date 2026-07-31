/* global jQuery, tutorSsoCourses */
/**
 * Courses catalog: infinite scroll + AJAX search, sort and an organization
 * filter dropdown.
 *
 * Each `.rwaq-courses` block is self-contained; per-instance config lives in
 * data-* attributes, shared config (ajaxurl / nonce / i18n) in tutorSsoCourses.
 *
 * Organization filter: a top-toolbar dropdown of organization checkboxes with an
 * "All" reset row and an in-dropdown search box to filter a long org list.
 * Selections are staged and only take effect on "Apply" (تطبيق); applied
 * selections render as removable chips; "clear all" (chip row or dropdown)
 * resets everything. Search & sort apply instantly; the grid paginates on scroll.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.tutorSsoCourses || {};
	var i18n = cfg.i18n || {};
	var icons = cfg.icons || {};

	var CHIP_X_SVG = icons.removeChip || '';

	$( '.rwaq-courses' ).each( function () {
		initCatalog( $( this ) );
	} );

	function initCatalog( $root ) {
		var $grid = $root.find( '.rwaq-courses__grid' ).first();
		var $loader = $root.find( '.rwaq-courses__loader' ).first();
		var $overlay = $root.find( '.rwaq-courses__overlay' ).first();
		var $status = $root.find( '.rwaq-courses__status' ).first();
		var $sentinel = $root.find( '.rwaq-courses__sentinel' ).first();
		var $search = $root.find( '.rwaq-courses__search-input' ).first();
		var $resultCount = $root.find( '[data-result-count]' ).first();

		// Sort (custom dropdown backed by a hidden native <select>).
		var $sort = $root.find( '.rwaq-courses__sort-select' ).first();
		var $sortWrap = $root.find( '.rwaq-courses__sort' ).first();
		var $sortTrigger = $root.find( '.rwaq-courses__sort-trigger' ).first();
		var $sortValue = $root.find( '.rwaq-courses__sort-value' ).first();
		var $sortMenu = $root.find( '.rwaq-courses__sort-menu' ).first();

		// Organization filter dropdown.
		var $filter = $root.find( '.rwaq-courses__filter' ).first();
		var $filterTrigger = $filter.find( '.rwaq-courses__filter-trigger' ).first();
		var $filterValue = $filter.find( '.rwaq-courses__filter-value' ).first();
		var $filterMenu = $filter.find( '.rwaq-courses__filter-menu' ).first();
		var $filterAll = $filterMenu.find( '.rwaq-courses__filter-input[data-role="all"]' ).first();
		var $filterAllOption = $filterMenu.find( '.rwaq-courses__filter-option--all' ).first();
		var $filterSearch = $filterMenu.find( '.rwaq-courses__filter-search-input' ).first();
		var $filterEmpty = $filterMenu.find( '.rwaq-courses__filter-empty' ).first();

		// Active-filter chips + "clear all".
		var $chips = $root.find( '.rwaq-courses__chips' ).first();
		var $clearAll = $root.find( '.rwaq-courses__clear-all' ).first();

		var state = {
			page: parseInt( $root.data( 'page' ), 10 ) || 1,
			perPage: parseInt( $root.data( 'per-page' ), 10 ) || 9,
			search: '',
			ordering: $sort.length ? $sort.val() : ( $root.data( 'default-sort' ) || '' ),
			orgs: [], // applied organization slugs
			hasMore: toBool( $root.data( 'has-more' ) ),
			loading: false
		};

		// ── Filter helpers ───────────────────────────────────────────────────────
		function orgInputs() {
			return $filterMenu.find( '.rwaq-courses__filter-input[data-role="org"]' );
		}

		function orgOptions() {
			return $filterMenu.find( '.rwaq-courses__filter-option[data-label]' );
		}

		function anySpecificChecked() {
			return orgInputs().filter( ':checked' ).length > 0;
		}

		function labelForInput( $input ) {
			return $.trim( $input.closest( '.rwaq-courses__filter-option' ).find( '.rwaq-courses__filter-label' ).text() );
		}

		// Reflect the applied selection back onto the checkboxes (on open + after
		// apply / clear / chip removal), discarding un-applied staged edits.
		function syncCheckboxesToApplied() {
			orgInputs().each( function () {
				$( this ).prop( 'checked', state.orgs.indexOf( String( $( this ).val() ) ) !== -1 );
			} );
			$filterAll.prop( 'checked', 0 === state.orgs.length );
		}

		// Reset the in-dropdown org search to show the full list.
		function resetFilterSearch() {
			if ( $filterSearch.length ) {
				$filterSearch.val( '' );
			}
			orgOptions().prop( 'hidden', false );
			$filterAllOption.prop( 'hidden', false );
			if ( $filterEmpty.length ) {
				$filterEmpty.prop( 'hidden', true );
			}
		}

		function appliedLabels() {
			var labels = [];
			orgInputs().each( function () {
				if ( state.orgs.indexOf( String( $( this ).val() ) ) !== -1 ) {
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

			state.orgs.forEach( function ( slug ) {
				var $input = orgInputs().filter( function () {
					return String( $( this ).val() ) === String( slug );
				} );
				var label = $input.length ? labelForInput( $input ) : slug;
				var $chip = $( '<button type="button" class="rwaq-courses__chip"></button>' )
					.attr( 'data-value', slug )
					.attr( 'aria-label', ( i18n.removeFilter || 'Remove' ) + ': ' + label );
				$( '<span></span>' ).text( label ).appendTo( $chip );
				$( '<span class="rwaq-courses__chip-x" aria-hidden="true"></span>' ).html( CHIP_X_SVG ).appendTo( $chip );
				$chips.append( $chip );
			} );

			$clearAll.prop( 'hidden', 0 === state.orgs.length );
		}

		function commitPending() {
			state.orgs = [];
			orgInputs().filter( ':checked' ).each( function () {
				state.orgs.push( String( $( this ).val() ) );
			} );
		}

		function reflectApplied() {
			renderChips();
			updateTriggerLabel();
		}

		function clearAllFilters() {
			state.orgs = [];
			syncCheckboxesToApplied();
			reflectApplied();
			load( true );
		}

		function closeFilter() {
			$filter.removeClass( 'is-open' );
			$filterTrigger.attr( 'aria-expanded', 'false' );
		}

		// ── Fetch a page. reset=true → page 1 (search / sort / filter change);
		//    reset=false → append the next page (scroll). ──
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

			$.ajax( {
				url: cfg.ajaxurl,
				type: 'GET',
				dataType: 'json',
				data: {
					action: 'tutor_sso_load_courses',
					nonce: cfg.nonce,
					page: nextPage,
					per_page: state.perPage,
					search: state.search,
					ordering: state.ordering,
					org: state.orgs
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
			$sortMenu.find( '.rwaq-courses__sort-option' ).each( function () {
				$( this ).toggleClass( 'is-selected', String( $( this ).data( 'value' ) ) === String( current ) );
			} );
		}

		function closeSort() {
			$sortWrap.removeClass( 'is-open' );
			$sortTrigger.attr( 'aria-expanded', 'false' );
		}

		if ( $sortMenu.length && $sortTrigger.length ) {
			$sort.find( 'option' ).each( function () {
				$( '<div class="rwaq-courses__sort-option" role="option"></div>' )
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

			$sortMenu.on( 'click', '.rwaq-courses__sort-option', function () {
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

		// ── Organization filter dropdown ─────────────────────────────────────────
		if ( $filterTrigger.length && $filterMenu.length ) {
			$filterTrigger.on( 'click', function ( e ) {
				e.stopPropagation();
				closeSort();
				var open = $filter.toggleClass( 'is-open' ).hasClass( 'is-open' );
				$filterTrigger.attr( 'aria-expanded', open ? 'true' : 'false' );
				if ( open ) {
					syncCheckboxesToApplied();
					resetFilterSearch();
				}
			} );

			// Keep clicks inside the menu from bubbling to the document (close) handler.
			$filterMenu.on( 'click', function ( e ) {
				e.stopPropagation();
			} );

			// In-dropdown search: filter the org options by label.
			if ( $filterSearch.length ) {
				$filterSearch.on( 'input', function () {
					var q = $.trim( $( this ).val() ).toLowerCase();
					var anyVisible = false;

					orgOptions().each( function () {
						var label = String( $( this ).attr( 'data-label' ) || '' ).toLowerCase();
						var match = '' === q || label.indexOf( q ) !== -1;
						$( this ).prop( 'hidden', ! match );
						if ( match ) {
							anyVisible = true;
						}
					} );

					// Hide the "All" reset row while actively searching.
					$filterAllOption.prop( 'hidden', '' !== q );

					if ( $filterEmpty.length ) {
						$filterEmpty.prop( 'hidden', anyVisible );
					}
				} );
			}

			// "All" ⇄ specific mutual exclusivity (staged, not yet applied).
			$filterMenu.on( 'change', '.rwaq-courses__filter-input', function () {
				var role = $( this ).data( 'role' );
				if ( 'all' === role ) {
					if ( $( this ).prop( 'checked' ) ) {
						orgInputs().prop( 'checked', false );
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

			$filter.find( '.rwaq-courses__filter-apply' ).on( 'click', function () {
				commitPending();
				reflectApplied();
				closeFilter();
				load( true );
			} );

			$filter.find( '.rwaq-courses__filter-clear' ).on( 'click', function () {
				clearAllFilters();
				closeFilter();
			} );
		}

		// ── Remove a single filter via its chip ──────────────────────────────────
		$chips.on( 'click', '.rwaq-courses__chip', function () {
			var value = String( $( this ).data( 'value' ) );
			state.orgs = state.orgs.filter( function ( slug ) {
				return slug !== value;
			} );
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
