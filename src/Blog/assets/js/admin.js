/* global jQuery, TheblogAdmin */
( function ( $ ) {
	'use strict';

	$( function () {
		var $form           = $( '#theblog-add-content-form' );
		if ( ! $form.length ) {
			return;
		}

		var $sourceRadios   = $( 'input[name="content_source_ui"]' );
		var $sourceHidden   = $( '#theblog-content-source' );
		var $topicPanel     = $( '#theblog-mode-topic' );
		var $productPanel   = $( '#theblog-mode-product' );
		var $productFields  = $( '.theblog-product-fields' );
		var $urlInput       = $( '#theblog-product-url-input' );
		var $analyzeBtn     = $( '#theblog-analyze-product' );
		var $spinner        = $( '#theblog-analyze-spinner' );
		var $resultBox      = $( '#theblog-analyze-result' );
		var $errorBox       = $( '#theblog-analyze-error' );
		var $submitBtn      = $( '#theblog-add-to-queue' );

		var productAnalyzed = false;

		function setMode( mode ) {
			$sourceHidden.val( mode );
			if ( 'product_url' === mode ) {
				$topicPanel.hide();
				$productPanel.show();
			} else {
				$productPanel.hide();
				$topicPanel.show();
			}
		}

		$sourceRadios.on( 'change', function () {
			setMode( $( this ).val() );
		} );

		function resetAnalysis() {
			productAnalyzed = false;
			$productFields.hide();
			$resultBox.hide().empty();
			$( '#theblog-product-id' ).val( '' );
			$( '#theblog-product-title' ).val( '' );
			$( '#theblog-product-url' ).val( '' );
			$( '#theblog-secondary-keywords' ).val( '' );
		}

		$urlInput.on( 'input', resetAnalysis );

		$analyzeBtn.on( 'click', function () {
			var url = $.trim( $urlInput.val() );
			$errorBox.hide().empty();
			resetAnalysis();

			if ( ! url ) {
				$errorBox.text( TheblogAdmin.i18n.enterUrl ).show();
				return;
			}

			$analyzeBtn.prop( 'disabled', true ).text( TheblogAdmin.i18n.analyzing );
			$spinner.addClass( 'is-active' );

			$.post( TheblogAdmin.ajaxUrl, {
				action: 'theblog_analyze_product',
				nonce: TheblogAdmin.analyzeNonce,
				product_url: url
			} ).done( function ( response ) {
				if ( response && response.success ) {
					var data = response.data;

					$( '#theblog-product-id' ).val( data.product_id );
					$( '#theblog-product-title' ).val( data.product_title );
					$( '#theblog-product-url' ).val( data.product_url );
					$( '#theblog-primary-keyword' ).val( data.primary_keyword );
					$( '#theblog-secondary-keywords' ).val( JSON.stringify( data.secondary_keywords || [] ) );

					var secondaryText = ( data.secondary_keywords || [] ).join( ', ' );
					$( '#theblog-secondary-keywords-display' ).text(
						secondaryText ? 'Secondary keywords: ' + secondaryText : ''
					);

					var html = '<strong>' + TheblogAdmin.i18n.productFound + '</strong><br>';
					if ( data.image_url ) {
						html += '<img src="' + data.image_url + '" alt="" class="theblog-analyze-thumb" />';
					}
					html += '<span class="theblog-analyze-title">' + escapeHtml( data.product_title ) + '</span>';
					$resultBox.html( html ).show();

					$productFields.show();
					productAnalyzed = true;
				} else {
					var message = ( response && response.data && response.data.message ) || TheblogAdmin.i18n.genericError;
					$errorBox.text( message ).show();
				}
			} ).fail( function () {
				$errorBox.text( TheblogAdmin.i18n.genericError ).show();
			} ).always( function () {
				$analyzeBtn.prop( 'disabled', false ).text( TheblogAdmin.i18n.analyzeProduct );
				$spinner.removeClass( 'is-active' );
			} );
		} );

		$form.on( 'submit', function ( e ) {
			if ( 'product_url' === $sourceHidden.val() && ! productAnalyzed ) {
				e.preventDefault();
				$errorBox.text( TheblogAdmin.i18n.enterUrl ).show();
			}
		} );

		function escapeHtml( text ) {
			return $( '<div>' ).text( text || '' ).html();
		}
	} );
} )( jQuery );
