/**
 * Email-confirmation modal logic.
 *
 * Flow on every page load:
 *
 *  1. No edX session detected (edxloggedin cookie absent or false)
 *       → delete stale `edxemailverified` cookie (prevent false positives)
 *       → done, no modal.
 *
 *  2. edX session present + `edxemailverified=true` cookie present
 *       → email already confirmed in a prior check → skip API → no modal.
 *
 *  3. edX session present, no verified cookie
 *       → AJAX → WP proxy → /api/learner_home/init
 *       → isNeeded === false  → email confirmed → set `edxemailverified` cookie → no modal.
 *       → isNeeded === true   → email not confirmed → show modal (no cookie set,
 *                               API is called again on the next page load).
 */
( function ( $ ) {
	'use strict';

	var cfg         = window.tutorSsoEmailConfirm || {};
	var COOKIE_NAME = cfg.cookieName || 'edxemailverified';
	var COOKIE_TTL  = cfg.cookieTtl  || 30 * 24 * 3600; // seconds

	// ── Cookie helpers ────────────────────────────────────────────────────────

	function getCookie( name ) {
		var re    = new RegExp( '(?:^|; )' + name.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) + '=([^;]*)' );
		var match = document.cookie.match( re );
		return match ? decodeURIComponent( match[1] ) : null;
	}

	function setCookie( name, value, seconds ) {
		var d = new Date();
		d.setTime( d.getTime() + seconds * 1000 );
		document.cookie =
			name + '=' + encodeURIComponent( value ) +
			'; expires=' + d.toUTCString() +
			'; path=/; SameSite=Lax';
	}

	function deleteCookie( name ) {
		document.cookie =
			name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; SameSite=Lax';
	}

	// ── Session detection ─────────────────────────────────────────────────────

	function hasEdxSession() {
		return !! getCookie( 'edxloggedin' );
	}

	// ── Modal helpers ─────────────────────────────────────────────────────────

	function showModal() {
		var overlay = document.getElementById( 'tutor-sso-ecm-overlay' );
		if ( ! overlay ) {
			return;
		}
		overlay.setAttribute( 'aria-hidden', 'false' );
		overlay.classList.add( 'is-visible' );
	}

	function hideModal() {
		var overlay = document.getElementById( 'tutor-sso-ecm-overlay' );
		if ( ! overlay ) {
			return;
		}
		overlay.setAttribute( 'aria-hidden', 'true' );
		overlay.classList.remove( 'is-visible' );
	}

	// ── Main ──────────────────────────────────────────────────────────────────

	function init() {
		// Bind close button, confirm button, and backdrop click.
		$( document ).on( 'click', '.tutor-sso-ecm-close', hideModal );
		$( document ).on( 'click', '.tutor-sso-ecm-confirm', hideModal );
		$( document ).on( 'click', '#tutor-sso-ecm-overlay', function ( e ) {
			if ( e.target === this ) {
				hideModal();
			}
		} );
		$( document ).on( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) {
				hideModal();
			}
		} );

		// Step 1 — no edX session.
		if ( ! hasEdxSession() ) {
			// Clear stale verified cookie so a future session starts fresh.
			deleteCookie( COOKIE_NAME );
			return;
		}

		// Step 2 — email already confirmed in a previous check.
		if ( getCookie( COOKIE_NAME ) === 'true' ) {
			return;
		}

		// Step 3 — unknown state; ask the WP AJAX proxy.
		$.ajax( {
			url:      cfg.ajaxurl,
			method:   'POST',
			dataType: 'json',
			data: {
				action: 'tutor_sso_email_confirm',
				nonce:  cfg.nonce,
			},
			success: function ( response ) {
				if ( ! response || ! response.success ) {
					return;
				}

				if ( response.data.isNeeded ) {
					// Email not yet confirmed → show the notice modal.
					// No cookie is set; the API will be called again on the next page.
					showModal();
				} else {
					// Email confirmed → cache so we never hit the API again
					// for this browser/session.
					setCookie( COOKIE_NAME, 'true', COOKIE_TTL );
				}
			},
		} );
	}

	$( document ).ready( init );

} )( jQuery );
