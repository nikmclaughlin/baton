/**
 * Baton — admin Command Palette bridge.
 *
 * Registers one "Run workflow: {title}" command per published workflow on
 * the core command palette (wp.commands). Deliberately NOT part of the
 * React editor build: a tiny always-on script for admins.
 *
 * Tolerance contract: wherever wp.commands is absent (older cores, builds
 * without the palette, or pages where the palette machinery never loads)
 * this stays a silent no-op.
 */
( function () {
	'use strict';

	const cfg = window.BatonPalette || {};
	const REST_PATH = cfg.restPath || '';
	const EDIT_URL = cfg.editUrl || '';
	const RUN_LABEL = ( cfg.strings && cfg.strings.run ) || 'Run workflow: %s';

	function registerFor( items ) {
		if ( ! Array.isArray( items ) || ! items.length ) {
			return;
		}

		const dispatch = window.wp.data.dispatch( window.wp.commands.store );

		items.forEach( function ( item ) {
			const id = parseInt( item && item.id, 10 );
			const title = item && typeof item.title === 'string' ? item.title : '';

			if ( ! id ) {
				return;
			}

			dispatch.registerCommand( {
				name: 'baton/run-workflow-' + id,
				label: RUN_LABEL.replace( '%s', title ),
				keywords: [ title.toLowerCase(), 'baton', 'workflow' ],
				category: 'action',
				callback: function ( options ) {
					if ( options && typeof options.close === 'function' ) {
						options.close();
					}

					// The workflow CPT has no core post.php screen, so
					// commands open Baton's own edit screen
					// (tools.php?page=baton&action=edit&workflow_id={id});
					// its run panel picks up ?baton_run=palette and
					// auto-triggers the run (trigger "palette").
					window.location.href =
						EDIT_URL.replace( '%d', String( id ) ) +
						'&baton_run=palette';
				},
			} );
		} );
	}

	function boot() {
		// Gate on the core palette being present; retry briefly because the
		// palette machinery can load after this script on some screens.
		if (
			! window.wp ||
			! window.wp.commands ||
			! window.wp.commands.store ||
			! window.wp.data ||
			! window.wp.data.dispatch
		) {
			if ( boot.attempts < 5 ) {
				boot.attempts += 1;
				window.setTimeout( boot, 50 * boot.attempts );
			}
			return;
		}

		if ( ! REST_PATH ) {
			return;
		}

		window.wp
			.apiFetch( { path: REST_PATH } )
			.then( registerFor )
			.catch( function () {
				// Silent: the palette simply offers no Baton commands.
			} );
	}

	boot.attempts = 0;
	boot();
} )();