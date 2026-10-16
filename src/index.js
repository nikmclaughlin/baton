import { createRoot } from '@wordpress/element';
import EditorShell from './editor/Shell';

document.addEventListener( 'DOMContentLoaded', () => {
	const rootEl = document.getElementById( 'baton-editor-root' );
	if ( ! rootEl ) {
		return;
	}

	const readJsonElement = ( id, fallback ) => {
		const el = document.getElementById( id );
		if ( ! el ) {
			return fallback;
		}
		try {
			return JSON.parse( el.textContent || 'null' ) ?? fallback;
		} catch {
			return fallback;
		}
	};

	const abilities = readJsonElement( 'baton-abilities-data', [] );
	const definition = readJsonElement( 'baton-definition-data', {
		initial_input: {},
		steps: [],
	} );
	const layout = readJsonElement( 'baton-layout-data', {} );
	const surfaces = readJsonElement( 'baton-surfaces-data', [] );
	const mode = window.BatonEditor?.editorMode === 'stack' ? 'stack' : 'node';

	const root = createRoot( rootEl );
	root.render(
		<EditorShell
			abilities={ abilities }
			initialDefinition={ definition }
			initialLayout={ layout }
			initialSurfaces={ Array.isArray( surfaces ) ? surfaces : [] }
			initialMode={ mode }
		/>
	);
} );
