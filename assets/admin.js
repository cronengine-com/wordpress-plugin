/* CronEngine admin page: ask before disconnecting. */
( function () {
	document.querySelectorAll( 'form[data-cronengine-confirm]' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			if ( ! window.confirm( form.getAttribute( 'data-cronengine-confirm' ) ) ) {
				event.preventDefault();
			}
		} );
	} );
} )();
