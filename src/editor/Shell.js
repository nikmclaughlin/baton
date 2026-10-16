/**
 * Editor shell: owns the definition state (steps + initial_input) and the
 * node|stack view toggle. Both views render from the same state, so
 * switching is lossless. The chosen view is remembered per browser via
 * localStorage, overriding the server-provided default.
 *
 * Also owns workflow saving: modal "Save" buttons and the bottom form
 * submit both funnel into saveWorkflow(), which syncs the hidden fields,
 * POSTs to the REST endpoint, and shows feedback without a page reload.
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import {
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
	CheckboxControl,
	Snackbar,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import WorkflowEditor from './App';
import NodeView from './NodeView';
import { defaultStep, definitionFromSteps } from './utils';

function readInputValue( id ) {
	const el = document.getElementById( id );
	return el && 'value' in el ? el.value : '';
}

// Per-browser editor-view preference. Falls back to the server-provided
// default (the baton_editor_mode filter) when nothing is stored yet or
// storage is unavailable (privacy modes may throw on access).
const VIEW_STORAGE_KEY = 'batonEditorView';

function readStoredView() {
	try {
		const stored = window.localStorage.getItem( VIEW_STORAGE_KEY );
		return 'node' === stored || 'stack' === stored ? stored : null;
	} catch {
		return null;
	}
}

function writeStoredView( value ) {
	try {
		window.localStorage.setItem( VIEW_STORAGE_KEY, value );
	} catch {
		// Unwritable storage: the preference just won't persist.
	}
}

export default function EditorShell( {
	abilities,
	initialDefinition,
	initialLayout,
	initialSurfaces,
	initialMode = 'node',
} ) {
	const editorConfig = window.BatonEditor || {};
	const [ view, setView ] = useState( () => {
		const stored = readStoredView();
		if ( stored ) {
			return stored;
		}
		return 'stack' === initialMode ? 'stack' : 'node';
	} );
	const [ steps, setSteps ] = useState( () => {
		const s = initialDefinition?.steps;
		return s?.length
			? s.map( ( step ) => ( { ...step } ) )
			: [ defaultStep() ];
	} );
	const [ initialInput, setInitialInput ] = useState(
		initialDefinition?.initial_input &&
			typeof initialDefinition.initial_input === 'object'
			? initialDefinition.initial_input
			: {}
	);
	// External-execution surface map (B2): slugs of the surfaces this
	// workflow is opt-in exposed on. Today only 'rest' (core's abilities
	// run route); CLI/MCP join the map later.
	const [ surfaces, setSurfaces ] = useState( () =>
		Array.isArray( initialSurfaces )
			? initialSurfaces.filter( ( slug ) => typeof slug === 'string' )
			: []
	);
	const [ saving, setSaving ] = useState( false );
	const [ snackbar, setSnackbar ] = useState( null );
	const savedLayout =
		initialLayout && typeof initialLayout === 'object' ? initialLayout : {};

	// Last run report, delivered by the run panel (assets/admin-run.js
	// re-dispatches results as a window event so the bundled editor can
	// react without a shared build system). Held in the shell — not the
	// canvas — so badges survive view toggles.
	const [ runReport, setRunReport ] = useState( null );

	useEffect( () => {
		const handleRunReport = ( event ) =>
			setRunReport( event.detail || null );
		window.addEventListener( 'baton:run-report', handleRunReport );
		return () =>
			window.removeEventListener( 'baton:run-report', handleRunReport );
	}, [] );

	// The run endpoint executes the saved definition, so any step edit
	// after a run invalidates the badge positions.
	useEffect( () => {
		setRunReport( null );
	}, [ steps ] );

	const savingRef = useRef( false );
	const pendingSaveRef = useRef( null );

	const syncHiddenField = useCallback( () => {
		const hidden = document.getElementById( 'workflow_definition' );
		if ( ! hidden ) {
			return;
		}
		hidden.value = JSON.stringify(
			definitionFromSteps( steps, initialInput )
		);
	}, [ steps, initialInput ] );

	// B2: the surface map rides the save contract as its own hidden field,
	// seeded server-side and kept in sync so both save paths see it.
	const syncSurfacesField = useCallback( () => {
		const hidden = document.getElementById( 'baton_external_surfaces' );
		if ( ! hidden ) {
			return;
		}
		hidden.value = JSON.stringify( surfaces );
	}, [ surfaces ] );

	useEffect( () => {
		syncHiddenField();
		window.BatonEditorSync = syncHiddenField;
		return () => {
			delete window.BatonEditorSync;
		};
	}, [ syncHiddenField ] );

	useEffect( () => {
		syncSurfacesField();
	}, [ syncSurfacesField ] );

	const toggleRestSurface = useCallback( ( checked ) => {
		setSurfaces( ( prev ) => {
			const base = Array.isArray( prev ) ? prev : [];
			if ( checked && ! base.includes( 'rest' ) ) {
				return [ ...base, 'rest' ];
			}
			return base.filter( ( slug ) => slug !== 'rest' );
		} );
	}, [] );

	/**
	 * Save the workflow via the REST endpoint. When a modal is committing
	 * changes, pass closeModal; it runs after the modal's state update has
	 * flushed, so the serialized definition includes it.
	 *
	 * @param {{closeModal?: Function}} [options]
	 * @return {Promise<boolean>} Resolves true when saved.
	 */
	const saveWorkflow = useCallback(
		( { closeModal } = {} ) => {
			return new Promise( ( resolve ) => {
				if ( closeModal ) {
					pendingSaveRef.current = closeModal;
				}
				if ( savingRef.current ) {
					resolve( false );
					return;
				}

				const restRoot = editorConfig.restRoot;
				const restNonce = editorConfig.restNonce;
				if ( ! restRoot || ! restNonce ) {
					setSnackbar( {
						kind: 'error',
						text: __(
							'Save is unavailable in this editor session.',
							'baton'
						),
					} );
					resolve( false );
					return;
				}

				savingRef.current = true;
				setSaving( true );
				setSnackbar( null );

				// Modal commits are React state updates; yield a tick so they
				// (and the hidden-field sync effects) land before serializing.
				window.setTimeout( () => {
					const submitButton = document.getElementById( 'submit' );
					if ( submitButton ) {
						submitButton.classList.add( 'is-busy' );
						submitButton.disabled = true;
					}

					const title = readInputValue( 'workflow_title' ).trim();

					const finish = () => {
						savingRef.current = false;
						setSaving( false );
						if ( submitButton ) {
							submitButton.classList.remove( 'is-busy' );
							submitButton.disabled = false;
						}
					};

					if ( ! title ) {
						finish();
						setSnackbar( {
							kind: 'error',
							text: __(
								'Title is required — add one at the top, then save.',
								'baton'
							),
						} );
						resolve( false );
						return;
					}

					const definition = readInputValue( 'workflow_definition' );
					const layout = readInputValue( 'baton_editor_layout' );
					const surfacesJson = readInputValue(
						'baton_external_surfaces'
					);
					const excerpt = readInputValue( 'workflow_excerpt' );
					const workflowIdInput =
						document.getElementById( 'workflow_id' );
					const workflowId = workflowIdInput
						? parseInt( workflowIdInput.value, 10 ) || 0
						: parseInt( editorConfig.postId, 10 ) || 0;

					const payload = {
						workflow_title: title,
						workflow_excerpt: excerpt,
						// The hidden fields hold serialized JSON; the endpoint
						// accepts both the string and a decoded object.
						workflow_definition: definition,
						baton_editor_layout: layout,
						// B2: the (JSON) surface map; absence would keep the
						// stored map, and the field is always synced here.
						external_surfaces: surfacesJson,
						workflow_id: workflowId,
					};

					window
						.fetch( restRoot, {
							method: 'POST',
							headers: {
								'Content-Type': 'application/json',
								'X-WP-Nonce': restNonce,
							},
							body: JSON.stringify( payload ),
							credentials: 'same-origin',
						} )
						.then( ( response ) =>
							response.json().then( ( result ) => ( {
								ok: response.ok,
								result,
							} ) )
						)
						.then( ( { ok, result } ) => {
							finish();
							if ( ok ) {
								const newId = parseInt( result?.post_id, 10 );
								if ( workflowIdInput && newId > 0 ) {
									workflowIdInput.value = String( newId );
								}
								setSnackbar( {
									kind: 'success',
									text:
										result?.message ||
										__( 'Workflow saved.', 'baton' ),
								} );
								resolve( true );
							} else {
								setSnackbar( {
									kind: 'error',
									text:
										result?.message ||
										result?.data?.message ||
										__( 'Save failed.', 'baton' ),
								} );
								resolve( false );
							}
						} )
						.catch( () => {
							finish();
							setSnackbar( {
								kind: 'error',
								text: __( 'Save failed.', 'baton' ),
							} );
							resolve( false );
						} );
				}, 0 );
			} );
		},
		[ editorConfig.restRoot, editorConfig.restNonce, editorConfig.postId ]
	);

	const finishModalSave = useCallback( ( saved ) => {
		if ( ! saved ) {
			return;
		}
		const closeModal = pendingSaveRef.current;
		pendingSaveRef.current = null;
		if ( closeModal ) {
			window.requestAnimationFrame( () => closeModal() );
		}
	}, [] );

	const handleModalSave = useCallback(
		( closeModal ) => {
			saveWorkflow( { closeModal } ).then( finishModalSave );
		},
		[ saveWorkflow, finishModalSave ]
	);

	useEffect( () => {
		window.BatonEditorSave = saveWorkflow;
		return () => {
			delete window.BatonEditorSave;
		};
	}, [ saveWorkflow ] );

	// Intercept the classic form POST so saving happens over AJAX with no
	// page reload; the form POST remains the non-JS fallback.
	useEffect( () => {
		const form = document.getElementById( 'baton-form' );
		if ( ! form ) {
			return undefined;
		}
		const handleSubmit = ( event ) => {
			event.preventDefault();
			saveWorkflow();
		};
		form.addEventListener( 'submit', handleSubmit );
		return () => form.removeEventListener( 'submit', handleSubmit );
	}, [ saveWorkflow ] );

	useEffect( () => {
		if ( ! snackbar ) {
			return undefined;
		}
		const timeout = window.setTimeout( () => setSnackbar( null ), 6000 );
		return () => window.clearTimeout( timeout );
	}, [ snackbar ] );

	return (
		<div className="baton-editor baton-editor--shell">
			<div className="baton-editor__toolbar baton-editor__toolbar--view">
				<CheckboxControl
					className="baton-editor__expose-rest"
					label={ __( 'Expose externally (REST)', 'baton' ) }
					help={ __(
						'Serve this workflow over the core Abilities REST API for external tooling (admins only; runs are logged below).',
						'baton'
					) }
					checked={ surfaces.includes( 'rest' ) }
					onChange={ toggleRestSurface }
				/>
				<ToggleGroupControl
					label={ __( 'Editor view', 'baton' ) }
					hideLabelFromVision
					value={ view }
					onChange={ ( next ) => {
						setView( next );
						writeStoredView( next );
					} }
					className="baton-editor__view-toggle"
				>
					<ToggleGroupControlOption
						value="node"
						label={ __( 'Node canvas', 'baton' ) }
					/>
					<ToggleGroupControlOption
						value="stack"
						label={ __( 'Stack', 'baton' ) }
					/>
				</ToggleGroupControl>
			</div>

			{ 'node' === view ? (
				<NodeView
					abilities={ abilities }
					steps={ steps }
					savedLayout={ savedLayout }
					initialInput={ initialInput }
					saving={ saving }
					runReport={ runReport }
					onSave={ handleModalSave }
					onUpdateStep={ ( index, step ) =>
						setSteps( ( prev ) =>
							prev.map( ( s, i ) =>
								i === index ? { ...s, ...step } : s
							)
						)
					}
					onAddStep={ () =>
						setSteps( ( prev ) => [ ...prev, defaultStep() ] )
					}
					onInsertStep={ ( index ) =>
						setSteps( ( prev ) => [
							...prev.slice( 0, index ),
							defaultStep(),
							...prev.slice( index ),
						] )
					}
					onRemoveStep={ ( index ) =>
						setSteps( ( prev ) => {
							if ( prev.length <= 1 ) {
								return [ defaultStep() ];
							}
							return prev.filter( ( _, i ) => i !== index );
						} )
					}
					onInitialInputChange={ setInitialInput }
				/>
			) : (
				<WorkflowEditor
					abilities={ abilities }
					steps={ steps }
					setSteps={ setSteps }
					initialInput={ initialInput }
					saving={ saving }
					onSave={ handleModalSave }
					onInitialInputChange={ setInitialInput }
				/>
			) }

			{ snackbar && (
				<div className="baton-snackbar-zone">
					<Snackbar
						explicitDismiss={ 'error' === snackbar.kind }
						onDismiss={ () => setSnackbar( null ) }
						className={
							'error' === snackbar.kind
								? 'baton-snackbar--error'
								: 'baton-snackbar--success'
						}
					>
						{ snackbar.text }
					</Snackbar>
				</div>
			) }
		</div>
	);
}
