/* global jQuery, tutorSsoCourses */
/**
 * Courses catalog: infinite scroll + AJAX search, sort and sidebar filters.
 *
 * Each `.rwaq-courses` block is self-contained; per-instance config lives in
 * data-* attributes, shared config (ajaxurl / nonce / i18n) in tutorSsoCourses.
 *
 * Filters: organization (multi-select checkboxes) in the sidebar, matching the
 * programs catalog, plus an in-group search box that narrows a long
 * organization list client-side. Any change re-queries page 1 via AJAX
 * immediately; active selections render as removable chips and "clear all"
 * resets them. Catalog search and sort apply instantly too; the grid paginates
 * on scroll.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.tutorSsoCourses || {};
	var i18n = cfg.i18n || {};
	var icons = cfg.icons || {};

	// Remove ("×") icon used on active-filter chips. Sourced from courses_icon()
	// in PHP (see courses_enqueue_assets) and localized onto tutorSsoCourses.
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
		var $chips = $root.find( '.rwaq-courses__chips' ).first();

		// Sort (custom dropdown backed by a hidden native <select>).
		var $sort = $root.find( '.rwaq-courses__sort-select' ).first();
		var $sortWrap = $root.find( '.rwaq-courses__sort' ).first();
		var $sortTrigger = $root.find( '.rwaq-courses__sort-trigger' ).first();
		var $sortValue = $root.find( '.rwaq-courses__sort-value' ).first();
		var $sortMenu = $root.find( '.rwaq-courses__sort-menu' ).first();

		var state = {
			page: parseInt( $root.data( 'page' ), 10 ) || 1, // Last page in the grid.
			perPage: parseInt( $root.data( 'per-page' ), 10 ) || 8,
			search: '',
			ordering: $sort.length ? $sort.val() : ( $root.data( 'default-sort' ) || '' ),
			filters: { org: [] },
			hasMore: toBool( $root.data( 'has-more' ) ),
			loading: false
		};

		// ── Filter helpers ───────────────────────────────────────────────────────
		function groupInputs( group ) {
			return $root.find(
				'.rwaq-courses__filter-group[data-filter="' + group + '"] .rwaq-courses__filter-input'
			);
		}

		function readFilters() {
			var f = { org: [] };
			$root.find( '.rwaq-courses__filter-group' ).each( function () {
				var group = $( this ).data( 'filter' );
				$( this ).find( '.rwaq-courses__filter-input:checked' ).each( function () {
					if ( f[ group ] ) {
						f[ group ].push( $( this ).val() );
					}
				} );
			} );
			return f;
		}

		function labelFor( group, value ) {
			var label = value;
			groupInputs( group ).each( function () {
				if ( String( $( this ).val() ) === String( value ) ) {
					var text = $( this ).siblings( '.rwaq-courses__filter-label' ).text();
					if ( text ) {
						label = $.trim( text );
					}
				}
			} );
			return label;
		}

		function renderChips() {
			$chips.empty();

			state.filters.org.forEach( function ( value ) {
				var label = labelFor( 'org', value );
				var $chip = $( '<button type="button" class="rwaq-courses__chip"></button>' )
					.attr( 'data-group', 'org' )
					.attr( 'data-value', value )
					.attr( 'aria-label', ( i18n.removeFilter || 'Remove' ) + ': ' + label );
				$( '<span></span>' ).text( label ).appendTo( $chip );
				$( '<span class="rwaq-courses__chip-x" aria-hidden="true"></span>' ).html( CHIP_X_SVG ).appendTo( $chip );
				$chips.append( $chip );
			} );
		}

		// Update the filtered "found N" line. The header badge stays at the
		// unfiltered catalog total (rendered server-side).
		function updateCounts( count ) {
			if ( typeof count === 'undefined' || count === null ) {
				return;
			}
			if ( $resultCount.length && i18n.countLabel ) {
				$resultCount.text( i18n.countLabel.replace( '%s', count ) );
			}
		}

		function applyFilters() {
			state.filters = readFilters();
			renderChips();
			load( true );
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
					org: state.filters.org
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

			$( document ).on( 'click', function ( e ) {
				if ( ! $( e.target ).closest( $sortWrap ).length ) {
					closeSort();
				}
			} );
		}

		// The native select stays the single source of truth for the chosen sort.
		$sort.on( 'change', function () {
			state.ordering = $( this ).val();
			renderSortUI();
			load( true );
		} );

		renderSortUI();

		// ── Collapsible filter groups ────────────────────────────────────────────
		function toggleGroup( $title ) {
			var collapsed = $title
				.closest( '.rwaq-courses__filter-group' )
				.toggleClass( 'is-collapsed' )
				.hasClass( 'is-collapsed' );
			$title.attr( 'aria-expanded', collapsed ? 'false' : 'true' );
		}

		$root.on( 'click', '.rwaq-courses__filter-title', function () {
			toggleGroup( $( this ) );
		} );

		$root.on( 'keydown', '.rwaq-courses__filter-title', function ( e ) {
			if ( e.key === 'Enter' || e.key === ' ' || e.which === 13 || e.which === 32 ) {
				e.preventDefault();
				toggleGroup( $( this ) );
			}
		} );

		// ── Filters (checkboxes) ─────────────────────────────────────────────────
		$root.on( 'change', '.rwaq-courses__filter-input', function () {
			applyFilters();
		} );

		// ── Remove a single filter via its chip ──────────────────────────────────
		$chips.on( 'click', '.rwaq-courses__chip', function () {
			var group = $( this ).data( 'group' );
			var value = String( $( this ).data( 'value' ) );
			groupInputs( group ).each( function () {
				if ( String( $( this ).val() ) === value ) {
					$( this ).prop( 'checked', false );
				}
			} );
			applyFilters();
		} );

		// ── Clear all filters ────────────────────────────────────────────────────
		$root.find( '.rwaq-courses__clear' ).on( 'click', function () {
			$root.find( '.rwaq-courses__filter-input' ).prop( 'checked', false );
			applyFilters();
		} );

		// ── "Show more / less" organizations toggle. The collapsed label
		//    ("عرض N المزيد") is dynamic, so it is kept on the button's
		//    data-more-text and restored when collapsing.
		$root.on( 'click', '.rwaq-courses__show-more', function () {
			var $btn = $( this );
			var expanded = $btn.attr( 'aria-expanded' ) === 'true';
			$btn.closest( '.rwaq-courses__filter-group' )
				.find( '.rwaq-courses__filter-option--overflow' )
				.prop( 'hidden', expanded );
			$btn.attr( 'aria-expanded', expanded ? 'false' : 'true' )
				.text( expanded ? ( $btn.data( 'more-text' ) || '' ) : ( i18n.showLess || '' ) );
		} );

		// ── In-group search: narrow a long option list by label. Matching uses
		//    the `is-filtered-out` class so it composes with the `hidden`
		//    attribute that "show more" manages. While a query is active every
		//    match is shown (overflow included) and "show more" steps aside;
		//    clearing the box restores whichever state that toggle was in.
		$root.on( 'input', '.rwaq-courses__filter-search-input', function () {
			var $group = $( this ).closest( '.rwaq-courses__filter-group' );
			var $options = $group.find( '.rwaq-courses__filter-option' );
			var $overflow = $group.find( '.rwaq-courses__filter-option--overflow' );
			var $showMore = $group.find( '.rwaq-courses__show-more' );
			var $empty = $group.find( '.rwaq-courses__filter-empty' );
			var query = $.trim( $( this ).val() ).toLowerCase();
			var matches = 0;

			$options.each( function () {
				var label = String( $( this ).attr( 'data-label' ) || '' ).toLowerCase();
				var match = '' === query || label.indexOf( query ) !== -1;
				$( this ).toggleClass( 'is-filtered-out', ! match );
				if ( match ) {
					matches++;
				}
			} );

			if ( '' === query ) {
				$overflow.prop( 'hidden', $showMore.attr( 'aria-expanded' ) !== 'true' );
			} else {
				$overflow.prop( 'hidden', false );
			}

			$showMore.prop( 'hidden', '' !== query );
			$empty.prop( 'hidden', matches > 0 );
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
