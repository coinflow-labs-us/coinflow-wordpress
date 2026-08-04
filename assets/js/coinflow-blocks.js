/* global window */
( function () {
	var settings = window.wc.wcSettings.getSetting( 'coinflow_data', {} );
	var label = settings.title || 'Credit Card';

	var Content = function () {
		return window.wp.htmlEntities.decodeEntities( settings.description || '' );
	};

	window.wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'coinflow',
		paymentMethodId: 'coinflow',
		label: label,
		ariaLabel: label,
		canMakePayment: function () {
			return true;
		},
		content: window.wp.element.createElement( Content, null ),
		edit: window.wp.element.createElement( Content, null ),
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )();
