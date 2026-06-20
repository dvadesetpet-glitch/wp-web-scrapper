/* global wpwsAjax, jQuery */
(function ( $ ) {
	'use strict';

	function loadPlaceholder( $el ) {
		var url   = $el.data( 'wpws-url' );
		var query = $el.data( 'wpws-query' );
		var args  = $el.data( 'wpws-args' );
		var nonce = $el.data( 'wpws-nonce' );

		if ( ! url ) return;

		$el.addClass( 'wpws-loading' );

		$.post( wpwsAjax.ajaxurl, {
			action     : 'wpws_scrape',
			nonce      : nonce || wpwsAjax.nonce,
			wpws_url   : url,
			wpws_query : query || '',
			wpws_args  : typeof args === 'object' ? JSON.stringify( args ) : ( args || '{}' ),
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
