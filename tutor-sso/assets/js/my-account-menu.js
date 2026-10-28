/* global tutorSsoAccountMenu */
/**
 * My Account navigation: a collapsible menu on small screens.
 *
 * At 900px and below the navigation card sits above the content as a long
 * bare list. This adds a toggle button at the top of the card — a caption
 * ("قائمة الحساب") over the current page's name and icon — and the list opens
 * under it on tap. Desktop is untouched: the button is hidden there and the
 * list always shows (assets/css/my-account.css, "Navigation toggle").
 *
 * The collapsing CSS only applies once this script has marked the nav with
 * `rwaq-acc-nav--js`, so if the script fails to load the full list still shows.
 */
( function () {
	'use strict';

	var cfg = window.tutorSsoAccountMenu || {};
	var labels = cfg.i18n || {};

	function init() {
		var nav = document.querySelector( '.woocommerce-account .woocommerce-MyAccount-navigation' );
		var list = nav ? nav.querySelector( 'ul' ) : null;

		if ( ! list || nav.classList.contains( 'rwaq-acc-nav--js' ) ) {
			return;
		}

		if ( ! list.id ) {
			list.id = 'rwaq-acc-nav-list';
		}

		var activeLink = list.querySelector( 'li.is-active a' ) || list.querySelector( 'li a' );
		var currentLabel = activeLink ? activeLink.textContent.trim() : '';

		var toggle = document.createElement( 'button' );
		toggle.type = 'button';
		toggle.className = 'rwaq-acc-nav-toggle';
		toggle.setAttribute( 'aria-expanded', 'false' );
		toggle.setAttribute( 'aria-controls', list.id );

		// Reuse the current page's own menu icon, which the stylesheet defines
		// per endpoint as --rwaq-acc-icon on its link. No icon found, no badge:
		// a mask with nothing to mask would paint a solid square.
		var icon = null;
		var iconUrl = activeLink && window.getComputedStyle
			? window.getComputedStyle( activeLink ).getPropertyValue( '--rwaq-acc-icon' ).trim()
			: '';

		if ( iconUrl ) {
			icon = document.createElement( 'span' );
			icon.className = 'rwaq-acc-nav-toggle__icon';
			icon.setAttribute( 'aria-hidden', 'true' );
			icon.style.setProperty( '--rwaq-acc-icon', iconUrl );
		}

		var text = document.createElement( 'span' );
		text.className = 'rwaq-acc-nav-toggle__text';

		var caption = document.createElement( 'span' );
		caption.className = 'rwaq-acc-nav-toggle__caption';
		caption.textContent = labels.title || '';

		var current = document.createElement( 'span' );
		current.className = 'rwaq-acc-nav-toggle__current';
		current.textContent = currentLabel;

		var chevron = document.createElement( 'span' );
		chevron.className = 'rwaq-acc-nav-toggle__chevron';
		chevron.setAttribute( 'aria-hidden', 'true' );

		text.appendChild( caption );
		text.appendChild( current );
		if ( icon ) {
			toggle.appendChild( icon );
		}
		toggle.appendChild( text );
		toggle.appendChild( chevron );

		nav.insertBefore( toggle, list );
		nav.classList.add( 'rwaq-acc-nav--js' );

		function setOpen( open ) {
			nav.classList.toggle( 'is-open', open );
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}

		toggle.addEventListener( 'click', function () {
			setOpen( ! nav.classList.contains( 'is-open' ) );
		} );

		// Esc closes it from anywhere inside the menu and returns focus to the
		// button, so keyboard users are not left in a list that just vanished.
		nav.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && nav.classList.contains( 'is-open' ) ) {
				setOpen( false );
				toggle.focus();
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
