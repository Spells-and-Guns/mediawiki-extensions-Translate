/*!
 * Entity selector for Special:ExportTranslations that allows users to load
 * messages from typing in a group name.
 * @author Eugene Wang'ombe
 * @license GPL-2.0-or-later, CC-BY-SA-3.0
 */

( function () {
	'use strict';

	function activateEntitySelector( $group ) {
		// hide the message group selector
		var $groupContainer = $( '.message-group-selector' );

		// Change the label, and update the for attribute, and remove the click handler
		// which causes the entity selector to become un-responsive when triggered
		$groupContainer
			.attr( 'for', 'mw-entity-selector-input' )
			.off( 'click' );

		// Determine what value was set, and set it on the entity selector
		var selectedGroup = $group.find( 'select option:selected' ).text();

		// load the entity selector and set the value
		var entitySelector = getEntitySelector( onEntitySelect );
		entitySelector.setValue( selectedGroup );

		$group.addClass( 'hidden' );
		$group.after( entitySelector.$element );
	}

	function onEntitySelect( selectedItem ) {
		$( 'select[name="group"]' ).val( selectedItem.data );
	}

	function onSubmit() {
		var selectedGroupName = $( 'select[name="group"]' ).find( 'option:selected' ).text();
		var currentVal = $( '.tes-entity-selector' ).find( 'input[type="text"]' ).val();

		// Check if the user has selected an invalid entity.
		if ( currentVal !== selectedGroupName ) {
			mw.notify(
				mw.msg( 'translate-mgs-invalid-group', currentVal ),
				{
					type: 'error',
					tag: 'invalid-selection'
				}
			);
			return false;
		}
	}

	function getEntitySelector( onSelect ) {
		var EntitySelector = require( '../ext.translate.special.languagestats/entity.selector.js' );
		return new EntitySelector( {
			onSelect: onSelect,
			entityType: [ 'groups' ],
			inputId: 'mw-entity-selector-input'
		} );
	}

	$( function () {
		activateEntitySelector( $( '#group' ) );

		$( '#mw-export-message-group-form' ).on( 'submit', onSubmit );
	} );
}() );
