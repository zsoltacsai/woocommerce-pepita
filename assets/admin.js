/**
 * WP Pepita Sync admin behaviour.
 *
 * Progressive enhancement only: every action also works without JavaScript
 * through a nonce-protected admin-post URL or a normal form submit.
 */
( function ( $ ) {
	'use strict';

	var settings = window.wpsPepita || {};

	function t( key ) {
		return ( settings.strings && settings.strings[ key ] ) || key;
	}

	function post( action, data ) {
		return $.ajax( {
			url: settings.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: $.extend( { action: action, nonce: settings.nonce }, data || {} )
		} );
	}

	/* ---------------------------------------------------------------
	 * Copy to clipboard
	 * ------------------------------------------------------------ */
	$( document ).on( 'click', '.wps-copy', function () {
		var input = this;

		if ( ! navigator.clipboard ) {
			input.select();
			return;
		}

		navigator.clipboard.writeText( input.value ).then( function () {
			$( input ).addClass( 'is-copied' );
			window.setTimeout( function () {
				$( input ).removeClass( 'is-copied' );
			}, 1500 );
		} );
	} );

	/* ---------------------------------------------------------------
	 * Confirmation links
	 * ------------------------------------------------------------ */
	$( document ).on( 'click', '.wps-confirm', function ( event ) {
		var message = $( this ).data( 'confirm' ) || t( 'confirm' );

		if ( ! window.confirm( message ) ) {
			event.preventDefault();
		}
	} );

	/* ---------------------------------------------------------------
	 * Bulk inclusion
	 * ------------------------------------------------------------ */
	$( document ).on( 'change', '#wps-select-all', function () {
		$( '.wps-row-check' ).prop( 'checked', this.checked );
	} );

	$( document ).on( 'click', '#wps-bulk-apply', function () {
		var ids = $( '.wps-row-check:checked' ).map( function () {
			return this.value;
		} ).get();

		if ( ! ids.length ) {
			window.alert( 'Nincs kijelölt termék.' );
			return;
		}

		var button = $( this ).prop( 'disabled', true ).text( t( 'working' ) );
		var mode = $( '#wps-bulk-mode' ).val();

		post( 'wps_pepita_bulk_inclusion', { mode: mode, ids: ids } )
			.done( function ( response ) {
				if ( response && response.success ) {
					window.alert( response.data.message );
					window.location.reload();
					return;
				}

				window.alert( ( response && response.data && response.data.message ) || t( 'error' ) );
			} )
			.fail( function ( xhr ) {
				var message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
				window.alert( message || t( 'error' ) );
			} )
			.always( function () {
				button.prop( 'disabled', false ).text( 'Alkalmazás a kijelöltekre' );
			} );
	} );

	/* ---------------------------------------------------------------
	 * Category rules
	 * ------------------------------------------------------------ */
	$( document ).on( 'click', '.wps-cat-save', function () {
		var row = $( this ).closest( '.wps-category-row' );
		var button = $( this ).prop( 'disabled', true ).text( t( 'working' ) );

		post( 'wps_pepita_category_rule', {
			term_id: row.data( 'term-id' ),
			include: row.find( '.wps-cat-include' ).val(),
			include_children: row.find( '.wps-cat-children' ).is( ':checked' ) ? 1 : 0,
			gtin_required: row.find( '.wps-cat-gtin' ).is( ':checked' ) ? 1 : 0,
			price_adjustment_type: row.find( '.wps-cat-price-type' ).val(),
			price_adjustment_value: row.find( '.wps-cat-price-value' ).val(),
			shipping_delay: row.find( '.wps-cat-delay' ).val()
		} )
			.done( function ( response ) {
				if ( response && response.success ) {
					button.text( t( 'saved' ) );
					window.setTimeout( function () {
						button.prop( 'disabled', false ).text( 'Mentés' );
					}, 1500 );
					return;
				}

				window.alert( ( response && response.data && response.data.message ) || t( 'error' ) );
				button.prop( 'disabled', false ).text( 'Mentés' );
			} )
			.fail( function () {
				window.alert( t( 'error' ) );
				button.prop( 'disabled', false ).text( 'Mentés' );
			} );
	} );

	/* ---------------------------------------------------------------
	 * Product metabox validation
	 * ------------------------------------------------------------ */
	$( document ).on( 'click', '.wps-validate-product', function () {
		var box = $( this ).closest( '.wps-metabox' );
		var result = box.find( '.wps-validation-result' );
		var button = $( this ).prop( 'disabled', true ).text( t( 'working' ) );

		result.html( '' );

		post( 'wps_pepita_product_preview', { product_id: box.data( 'product-id' ) } )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					result.html( '<p class="is-error">' + t( 'error' ) + '</p>' );
					return;
				}

				var data = response.data;
				var html = '';

				if ( data.valid ) {
					html += '<p class="is-ok">A termék megfelel a Pepita feed követelményeinek.</p>';
				}

				if ( data.errors && data.errors.length ) {
					html += '<ul class="is-error">';
					data.errors.forEach( function ( error ) {
						html += '<li>' + $( '<div>' ).text( error.message ).html() + '</li>';
					} );
					html += '</ul>';
				}

				if ( data.warnings && data.warnings.length ) {
					html += '<ul class="is-warning">';
					data.warnings.forEach( function ( warning ) {
						html += '<li>' + $( '<div>' ).text( warning.message ).html() + '</li>';
					} );
					html += '</ul>';
				}

				result.html( html );
			} )
			.fail( function () {
				result.html( '<p class="is-error">' + t( 'error' ) + '</p>' );
			} )
			.always( function () {
				button.prop( 'disabled', false ).text( 'Feed adatok ellenőrzése' );
			} );
	} );

	/* ---------------------------------------------------------------
	 * Chunked feed generation
	 * ------------------------------------------------------------ */
	function progressBox() {
		var box = $( '.wps-progress' );

		if ( ! box.length ) {
			box = $( '<div class="wps-progress"><div class="wps-progress__bar"><div class="wps-progress__fill"></div></div><div class="wps-progress__label"></div></div>' );
			$( '.wps-wrap h1' ).after( box );
		}

		return box;
	}

	function setProgress( percent, label ) {
		var box = progressBox();

		box.addClass( 'is-active' );
		box.find( '.wps-progress__fill' ).css( 'width', Math.max( 0, Math.min( 100, percent ) ) + '%' );
		box.find( '.wps-progress__label' ).text( label || '' );
	}

	function runGeneration( type, attempt ) {
		attempt = attempt || 0;

		if ( attempt > 500 ) {
			setProgress( 100, t( 'error' ) );
			return;
		}

		post( 'wps_pepita_generate', { type: type } )
			.done( function ( response ) {
				var data = response && response.data ? response.data : {};
				var status = data.status || 'error';

				if ( status === 'running' ) {
					var percent = data.progress || 0;
					var counts = data.counts || {};
					setProgress(
						percent,
						'Generálás: ' + percent + '% – feldolgozva ' + ( counts.processed || 0 ) + ' / ' + ( data.planned || 0 ) + ' termék'
					);
					window.setTimeout( function () {
						runGeneration( type, attempt + 1 );
					}, 400 );
					return;
				}

				if ( status === 'completed' ) {
					var doneCounts = data.counts || {};
					setProgress(
						100,
						'Kész: ' + ( doneCounts.exported || 0 ) + ' termék exportálva, ' + ( doneCounts.invalid || 0 ) + ' kihagyva.'
					);
					window.setTimeout( function () {
						window.location.reload();
					}, 1200 );
					return;
				}

				if ( status === 'skipped' ) {
					setProgress( 100, data.message || 'A generálás már fut.' );
					return;
				}

				setProgress( 100, data.message || t( 'error' ) );
			} )
			.fail( function ( xhr ) {
				var message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
				setProgress( 100, message || t( 'error' ) );
			} );
	}

	$( document ).on( 'click', '.wps-generate', function ( event ) {
		event.preventDefault();

		var type = $( this ).data( 'type' ) || 'full';

		setProgress( 0, type === 'stock' ? t( 'generateStock' ) : t( 'generateFull' ) );
		runGeneration( type, 0 );
	} );

	/* ---------------------------------------------------------------
	 * Settings: conditional fields
	 * ------------------------------------------------------------ */
	$( document ).on( 'change', 'select[name="wps[brand_source]"]', function () {
		var meta = $( this ).val() === 'meta';
		$( 'input[name="wps[brand_meta_key]"]' ).closest( 'p' ).toggle( meta );
	} ).trigger( 'change' );
} )( jQuery );
