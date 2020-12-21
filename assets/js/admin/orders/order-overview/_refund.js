/* global eddAdminOrderOverview */

// Loads the modal when the refund button is clicked.
$(document.body).on('click', '.edd-refund-order', function (e) {
	e.preventDefault();
	var link     = $(this),
		postData = {
			action  : 'edd_generate_refund_form',
			order_id: $('input[name="edd_payment_id"]').val(),
		};

	$.ajax({
		type   : 'POST',
		data   : postData,
		url    : ajaxurl,
		success: function success(data) {
			let modal_content = '';
			if (data.success) {
				modal_content = data.html;
			} else {
				modal_content = data.message;
			}

			$('#edd-refund-order-dialog').dialog({
				position: { my: 'top center', at: 'center center-25%' },
				width    : '75%',
				modal    : true,
				resizable: false,
				draggable: false,
				classes: {
					'ui-dialog': 'edd-dialog',
				},
				closeText: eddAdminOrderOverview.i18n.closeText,
				open: function( event, ui ) {
					$(this).html( modal_content );
				},
				close: function( event, ui ) {
					$( this ).html( '' );
					if ( $( this ).hasClass( 'did-refund' ) ) {
						location.reload();
					}
				}
			});
			return false;
		}
	}).fail(function (data) {
		$('#edd-refund-order-dialog').dialog({
			position: { my: 'top center', at: 'center center-25%' },
			width    : '75%',
			modal    : true,
			resizable: false,
			draggable: false
		}).html(data.message);
		return false;
	});
});

$( document.body ).on( 'click', '.ui-widget-overlay', function ( e ) {
	$( '#edd-refund-order-dialog' ).dialog( 'close' );
} );

// Handles quantity changes, which includes items in the refund.
$( document.body ).on( 'change', '#edd-refund-order-dialog .edd-order-item-refund-input', function () {
	let parent = $( this ).parent().parent(),
		quantityField = parent.find( '.edd-order-item-refund-quantity' ),
		quantity = parseInt( quantityField.val() );

	if ( quantity > 0 ) {
		parent.addClass( 'refunded' );
	} else {
		parent.removeClass( 'refunded' );
	}

	// Only auto calculate subtotal / tax if we've adjusted the quantity.
	if ( $( this ).hasClass( 'edd-order-item-refund-quantity' ) ) {
		let subtotalField = parent.find( '.edd-order-item-refund-subtotal' ),
			taxField = parent.find( '.edd-order-item-refund-tax' ),
			originalSubtotal = parseFloat( subtotalField.data( 'original' ) ),
			originalTax = parseFloat( taxField.data( 'original' ) ),
			originalQuantity = parseInt( quantityField.data( 'original' ) ),
			calculatedSubtotal = ( originalSubtotal / originalQuantity ) * quantity,
			calculatedTax = ( originalTax / originalQuantity ) * quantity;

		// Guess the subtotal and tax for the selected quantity.
		subtotalField.val( parseFloat( calculatedSubtotal ).toFixed( edd_vars.currency_decimals ) );
		taxField.val( parseFloat( calculatedTax ).toFixed( edd_vars.currency_decimals ) );
	}

	recalculateRefundTotal();
} );

/**
 * Calculates all the final refund values.
 */
function recalculateRefundTotal() {
	let newSubtotal   = 0,
		newTax        = 0,
		newTotal      = 0,
		canRefund     = false,
		allInputBoxes = $( '#edd-refund-order-dialog .edd-order-item-refund-input' );

	// Set a readonly while we recalculate, to avoid race conditions in the browser.
	allInputBoxes.prop( 'readonly', true );
	$( '#edd-refund-submit-button-wrapper .spinner' ).css( 'visibility', 'visible' );

	// Loop over all order items.
	$( '#edd-refund-order-dialog .edd-order-item-refund-quantity' ).each( function() {
		let thisItemQuantity = parseInt( $( this ).val() );

		if ( ! thisItemQuantity ) {
			return;
		}

		let thisItemParent = $( this ).parent().parent();

		// Values for this item.
		let thisItemSubtotal = 0,
			thisItemTax      = 0,
			thisItemTotal    = 0;

		if ( thisItemQuantity ) {
			thisItemSubtotal = parseFloat( thisItemParent.find( '.edd-order-item-refund-subtotal' ).val() ),
			thisItemTax      = parseFloat( thisItemParent.find( '.edd-order-item-refund-tax' ).val() ),
			thisItemTotal    = parseFloat( thisItemSubtotal + thisItemTax );
		}

		thisItemParent.find( '.column-total span' ).text( thisItemTotal.toFixed( edd_vars.currency_decimals ) );

		newSubtotal += thisItemSubtotal;
		newTax      += thisItemTax;
		newTotal    += thisItemTotal;
	} );

	newSubtotal = parseFloat( newSubtotal ).toFixed( edd_vars.currency_decimals );
	newTax      = parseFloat( newTax ).toFixed( edd_vars.currency_decimals );
	newTotal    = parseFloat( newTotal ).toFixed( edd_vars.currency_decimals );

	if ( newTotal > 0 ) {
		canRefund = true;
	}

	$( '#edd-refund-submit-subtotal-amount' ).text( newSubtotal );
	$( '#edd-refund-submit-tax-amount' ).text( newTax );
	$( '#edd-refund-submit-total-amount' ).text( newTotal );

	$( '#edd-submit-refund-submit' ).attr( 'disabled', ! canRefund );

	// Remove the readonly.
	allInputBoxes.prop( 'readonly', false );
	$( '#edd-refund-submit-button-wrapper .spinner' ).css( 'visibility', 'hidden' );
}

// Process the refund form after the button is clicked.
$(document.body).on( 'click', '#edd-submit-refund-submit', function(e) {
	e.preventDefault();
	$('.edd-submit-refund-message').removeClass('success').removeClass('fail');
	$( this ).attr( 'disabled', false );
	$('#edd-refund-submit-button-wrapper .spinner').css('visibility', 'visible');
	$('#edd-submit-refund-status').hide();

	const refundForm = $( '#edd-submit-refund-form' );
	const refundData = refundForm.serialize();

	var postData = {
		action: 'edd_process_refund_form',
		data: refundData,
		order_id: $('input[name="edd_payment_id"]').val()
	};

	$.ajax({
		type   : 'POST',
		data   : postData,
		url    : ajaxurl,
		success: function success(response) {
			const message_target = $('.edd-submit-refund-message'),
				url_target     = $('.edd-submit-refund-url');

			if ( response.success ) {
				$('#edd-refund-order-dialog table').hide();
				$('#edd-refund-order-dialog .tablenav').hide();

				message_target.text(response.data.message).addClass('success');
				url_target.attr( 'href', response.data.refund_url ).show();

				$( '#edd-submit-refund-status' ).show();
				url_target.focus();
				$( '#edd-refund-order-dialog' ).addClass( 'did-refund' );
			} else {
				message_target.html(response.data).addClass('fail');
				url_target.hide();

				$('#edd-submit-refund-status').show();
				$( '#edd-submit-refund-submit' ).attr( 'disabled', false );
				$( '#edd-refund-submit-button-wrapper .spinner' ).css( 'visibility', 'hidden' );
			}
		}
	} ).fail( function ( data ) {
		const message_target = $('.edd-submit-refund-message'),
			url_target     = $('.edd-submit-refund-url'),
			json           = data.responseJSON;


		message_target.text(json.message).addClass('fail');
		url_target.hide();

		$( '#edd-submit-refund-status' ).show();
		$( '#edd-submit-refund-submit' ).attr( 'disabled', false );
		$( '#edd-refund-submit-button-wrapper .spinner' ).css( 'visibility', 'hidden' );
		return false;
	});
});
