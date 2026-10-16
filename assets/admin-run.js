( function () {
	'use strict';

	const cfg = window.Baton || {};
	const strings = cfg.strings || {};

	function parseJson( text, fallback ) {
		if ( ! text || ! text.trim() ) {
			return fallback;
		}
		try {
			return JSON.parse( text );
		} catch ( e ) {
			return null;
		}
	}

	function stringifyJson( value ) {
		return JSON.stringify( value, null, 2 );
	}

	function escapeHtml( text ) {
		const div = document.createElement( 'div' );
		div.textContent = text;
		return div.innerHTML;
	}

	function syncDefinition() {
		if ( typeof window.BatonEditorSync === 'function' ) {
			window.BatonEditorSync();
		}
	}

	var RunPanel = {
		init() {
			const btn = document.getElementById( 'aw-run-workflow' );
			if ( ! btn ) {
				return;
			}

			btn.addEventListener( 'click', this.run.bind( this ) );

			if ( window.location.hash === '#aw-run-panel' ) {
				const panel = document.getElementById( 'aw-run-panel' );
				if ( panel ) {
					panel.scrollIntoView( { behavior: 'smooth' } );
				}
			}
		},

		run() {
			const btn = document.getElementById( 'aw-run-workflow' );
			const results = document.getElementById( 'aw-run-results' );
			const initialInput = document.getElementById(
				'aw-run-initial-input'
			);

			if ( ! btn || ! results ) {
				return;
			}

			syncDefinition();

			const hidden = document.getElementById( 'workflow_definition' );
			const definition = parseJson( hidden ? hidden.value : '', {} );
			if (
				! definition ||
				! definition.steps ||
				! definition.steps.length
			) {
				window.alert(
					strings.noSteps || 'Add at least one step before running.'
				);
				return;
			}

			const initial = parseJson(
				initialInput ? initialInput.value : '',
				{}
			);
			if (
				initialInput &&
				initialInput.value.trim() &&
				initial === null
			) {
				window.alert( strings.invalidJson || 'Invalid JSON.' );
				return;
			}

			btn.disabled = true;
			btn.textContent = strings.running || 'Running…';
			results.hidden = false;
			results.innerHTML =
				'<p class="aw-run-status">' +
				escapeHtml( strings.running || 'Running…' ) +
				'</p>';

			const payload = {
				initial_input: initialInput ? initialInput.value : '{}',
			};

			fetch( cfg.runUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.nonce,
				},
				body: JSON.stringify( payload ),
				credentials: 'same-origin',
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( result ) {
					btn.disabled = false;
					btn.textContent =
						btn.getAttribute( 'data-label' ) || 'Run Workflow';
					RunPanel.renderResults( result );
				} )
				.catch( function ( err ) {
					btn.disabled = false;
					btn.textContent =
						btn.getAttribute( 'data-label' ) || 'Run Workflow';
					results.innerHTML =
						'<div class="notice notice-error"><p>' +
						escapeHtml( String( err ) ) +
						'</p></div>';
				} );
		},

		renderResults( result ) {
			const results = document.getElementById( 'aw-run-results' );
			if ( ! results ) {
				return;
			}

			const report = result && typeof result === 'object' ? result : {};

			// Bridge to the editor build: the bundled editor listens for
			// this event to map step results onto its canvas nodes.
			window.dispatchEvent(
				new CustomEvent( 'baton:run-report', { detail: report } )
			);

			let html = '';

			window.__batonLastReport = report;
			window.BatonCopyTrace = function ( btn ) {
				const postField = document.getElementById( 'post_ID' );
				const trace = {
					site: window.location.origin,
					workflow_id: postField ? postField.value : null,
					generated_at: new Date().toISOString(),
					purpose: 'Baton run trace for troubleshooting',
					report: window.__batonLastReport || {},
				};
				const text = JSON.stringify( trace, null, 2 );
				const done = function () {
					const label = btn.textContent;
					btn.textContent = strings.copyTraceDone || 'Copied ✓';
					setTimeout( function () {
						btn.textContent = label;
					}, 2000 );
				};
				const fallbackCopy = function ( t ) {
					const ta = document.createElement( 'textarea' );
					ta.value = t;
					document.body.appendChild( ta );
					ta.select();
					try {
						document.execCommand( 'copy' );
					} catch ( e ) {}
					document.body.removeChild( ta );
				};
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard
						.writeText( text )
						.then( done )
						.catch( function () {
							fallbackCopy( text );
							done();
						} );
				} else {
					fallbackCopy( text );
					done();
				}
			};

			if ( report.success ) {
				html +=
					'<div class="notice notice-success"><p>' +
					escapeHtml(
						strings.runSuccess || 'Workflow completed successfully.'
					) +
					'</p></div>';
			} else {
				html +=
					'<div class="notice notice-error"><p>' +
					escapeHtml(
						report.error ||
							report.message ||
							strings.runFailed ||
							'Workflow failed.'
					) +
					'</p></div>';
				html +=
					'<p><button type="button" class="button button-secondary" onclick="window.BatonCopyTrace( this )">' +
					( strings.copyTrace || 'Copy trace' ) +
					'</button></p>';
			}

			if ( report.steps && report.steps.length ) {
				html += '<div class="aw-run-steps">';
				report.steps.forEach( function ( step, i ) {
					const statusClass = step.success
						? 'aw-step-ok'
						: 'aw-step-fail';
					const loopInfo = step.loop
						? ' &mdash; ' +
						  ( step.loop.iterations || 0 ) +
						  ' iteration' +
						  ( step.loop.iterations === 1 ? '' : 's' )
						: '';
					html +=
						'<details class="aw-run-step ' +
						statusClass +
						'" open>';
					html +=
						'<summary><strong>Step ' +
						( i + 1 ) +
						':</strong> ' +
						escapeHtml( step.ability || '' ) +
						loopInfo +
						( step.success ? ' ✓' : ' ✗' ) +
						'</summary>';
					if ( step.warnings && step.warnings.length ) {
						html +=
							'<p class="aw-warning">' +
							escapeHtml( step.warnings.join( ' ' ) ) +
							'</p>';
					}
					if ( step.loop && step.loop.iterations > 0 ) {
						html +=
							'<h4>Input (resolved before loop)</h4><pre class="aw-result-pre">' +
							escapeHtml( stringifyJson( step.input ) ) +
							'</pre>';
						html +=
							'<h4>Output (' +
							step.loop.iterations +
							' iterations)</h4>';
						if (
							Array.isArray( step.output ) &&
							step.output.length > 1
						) {
							step.output.forEach( function ( out, j ) {
								html +=
									'<details class="aw-run-iteration"><summary>Iteration ' +
									( j + 1 ) +
									'</summary><pre class="aw-result-pre">' +
									escapeHtml( stringifyJson( out ) ) +
									'</pre></details>';
							} );
						} else if ( Array.isArray( step.output ) ) {
							html +=
								'<pre class="aw-result-pre">' +
								escapeHtml( stringifyJson( step.output[ 0 ] || {} ) ) +
								'</pre>';
						} else {
							html +=
								'<pre class="aw-result-pre">' +
								escapeHtml( stringifyJson( step.output ) ) +
								'</pre>';
						}
					} else {
						html +=
							'<h4>Input</h4><pre class="aw-result-pre">' +
							escapeHtml( stringifyJson( step.input ) ) +
							'</pre>';
						if ( step.success ) {
							html +=
								'<h4>Output</h4><pre class="aw-result-pre">' +
								escapeHtml( stringifyJson( step.output ) ) +
								'</pre>';
						} else if ( step.error ) {
							html +=
								'<h4>Error</h4><p class="aw-error">' +
								escapeHtml( step.error ) +
								'</p>';
						}
					}
					html += '</details>';
				} );
				html += '</div>';
			}

			results.innerHTML = html;
		},
	};

	document.addEventListener( 'DOMContentLoaded', function () {
		RunPanel.init();

		const copyBtn = document.getElementById( 'baton-copy-ability-slug' );
		const slugEl = document.getElementById( 'baton-ability-slug' );
		if ( copyBtn && slugEl ) {
			copyBtn.addEventListener( 'click', function () {
				const text = slugEl.textContent || '';
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( text );
				}
			} );
		}
	} );
} )();
