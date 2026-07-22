/**
 * Program detail page — sticky tab behaviour.
 *
 * The tabs (نظرة عامة / الدورات) are plain in-page anchor links. The browser
 * scrolls to the section on click, but nothing moves the `--active` highlight.
 * This keeps the active tab in sync in two ways:
 *   1. Immediate feedback on click.
 *   2. Scroll-spy — as the reader scrolls, the tab for the section currently
 *      under the sticky header becomes active.
 *
 * Plain ES5, no dependencies. Enqueued whenever the [rwaq_program_detail]
 * shortcode renders (see program_detail_render()).
 */
( function () {
	'use strict';

	function initTabs( root ) {
		var tabs = Array.prototype.slice.call( root.querySelectorAll( '.rwaq-pd__tab' ) );
		if ( tabs.length < 2 ) {
			return; // Nothing to switch between.
		}

		var header = root.querySelector( '.rwaq-pd__header-bar' );

		// Pair each tab with the section it points at, in document order.
		var targets = [];
		tabs.forEach( function ( tab ) {
			var href = tab.getAttribute( 'href' ) || '';
			if ( href.charAt( 0 ) !== '#' ) {
				return;
			}
			var section = document.getElementById( href.slice( 1 ) );
			if ( section ) {
				targets.push( { tab: tab, section: section } );
			}
		} );
		if ( targets.length < 2 ) {
			return;
		}

		function setActive( tab ) {
			targets.forEach( function ( t ) {
				var isActive = t.tab === tab;
				t.tab.classList.toggle( 'rwaq-pd__tab--active', isActive );
				if ( isActive ) {
					t.tab.setAttribute( 'aria-current', 'true' );
				} else {
					t.tab.removeAttribute( 'aria-current' );
				}
			} );
		}

		// The last section whose top has scrolled just under the sticky header
		// wins; falls back to the first tab while still above every section.
		function currentTab() {
			var line = ( header ? header.offsetHeight : 0 ) + 2;
			var active = targets[ 0 ].tab;
			targets.forEach( function ( t ) {
				if ( t.section.getBoundingClientRect().top <= line ) {
					active = t.tab;
				}
			} );
			return active;
		}

		// While a click-driven scroll is settling, hold the clicked tab so the
		// spy doesn't briefly flip back to the section being scrolled past.
		var suppressSpy = false;
		var suppressTimer = null;

		var ticking = false;
		function onScroll() {
			if ( suppressSpy || ticking ) {
				return;
			}
			ticking = true;
			window.requestAnimationFrame( function () {
				setActive( currentTab() );
				ticking = false;
			} );
		}

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				setActive( tab );
				suppressSpy = true;
				window.clearTimeout( suppressTimer );
				suppressTimer = window.setTimeout( function () {
					suppressSpy = false;
					setActive( currentTab() );
				}, 700 );
			} );
		} );

		window.addEventListener( 'scroll', onScroll, { passive: true } );
		window.addEventListener( 'resize', onScroll );
		setActive( currentTab() );
	}

	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( '.rwaq-pd' ), initTabs );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
