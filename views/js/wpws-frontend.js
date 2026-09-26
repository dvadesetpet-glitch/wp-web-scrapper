/* global wpwsAjax, jQuery */
(function ( $ ) {
	'use strict';

	// Each placeholder carries only an opaque job ID. The server looks up the
	// URL, query and arguments itself, so nothing sensitive is in the page and
	// the endpoint cannot be pointed at arbitrary URLs.
	function loadPlaceholder( $el ) {
		var id = $el.data( 'wpws-id' );
		if ( ! id ) return;

		$el.addClass( 'wpws-loading' );

		$.post( wpwsAjax.ajaxurl, {
			action  : 'wpws_scrape',
			wpws_id : String( id ),
		} )
		.done( function ( response ) {
			if ( response && response.success && response.data && response.data.html !== undefined ) {
				$el.replaceWith( response.data.html );
			} else {
				$el.removeClass( 'wpws-loading' ).addClass( 'wpws-error' );
			}
		} )
		.fail( function () {
			$el.removeClass( 'wpws-loading' ).addClass( 'wpws-error' );
		} );
	}

	$( function () {
		$( '.wpws-ajax-placeholder' ).each( function () {
			loadPlaceholder( $( this ) );
		} );
	} );

}( jQuery ) );
