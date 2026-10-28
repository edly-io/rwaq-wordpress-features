/**
 * Make the price fields reachable for the Subscription product type.
 *
 * WooCommerce decides whether to show a Product data tab by looking at its
 * panel: if every child `.options_group` is display:none, the tab is hidden.
 * The price fields live in a group the template marks
 * `show_if_simple show_if_external`, and no group in the General panel mentions
 * a custom type — so for a Subscription every child is hidden and the General
 * tab disappears, taking the price with it.
 *
 * Those classes are hardcoded in a WooCommerce template with no filter, so they
 * are added here instead, then the type change is re-triggered so WooCommerce
 * recalculates visibility with them in place. It only ever hides a tab, never
 * re-shows one, so the second pass is what brings General back.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var showClass = 'show_if_' + tutorSsoSubscriptionAdmin.productType;
		var $pricing  = $( '#general_product_data .options_group.pricing' );

		$pricing.addClass( showClass );

		// Since the cost-of-goods feature, the price inputs — and with them the
		// billing-period field, which hooks the same action — sit one level
		// deeper, in a span carrying the same simple/external gate. Matched by
		// the price input it contains so the separate cost-of-goods span, which
		// means nothing for a plan, stays hidden.
		$pricing.children( 'span' ).has( '#_regular_price' ).addClass( showClass );

		// The tax group, which the same template marks for simple/external only.
		$( '#_tax_status' ).closest( '.options_group' ).addClass( showClass );

		// WooCommerce works visibility out on ready and on type change; ask it
		// again now the classes are present.
		$( 'select#product-type' ).trigger( 'change' );
	} );
}( jQuery ) );
