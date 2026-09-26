/**
 * Editor shell: owns the definition state (steps) and the node|stack view
 * toggle. Both views render from the same state, so switching is lossless.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import {
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import WorkflowEditor from './App';
import NodeView from './NodeView';
import { defaultStep, definitionFromSteps } from './utils';

export default function EditorShell( {
	abilities,
	initialDefinition,
	initialMode = 'node',
} ) {
	const [ view, setView ] = useState(
		'stack' === initialMode ? 'stack' : 'node'
	);
	const [ steps, setSteps ] = useState( () => {
		const s = initialDefinition?.steps;
		return s?.length
			? s.map( ( step ) => ( { ...step } ) )
			: [ defaultStep() ];
	} );

	const syncHiddenField = useCallback( () => {
		const hidden = document.getElementById( 'workflow_definition' );
		if ( ! hidden ) {
			return;
		}
		const initial =
			initialDefinition?.initial_input &&
			typeof initialDefinition.initial_input === 'object'
				? initialDefinition.initial_input
				: {};
		hidden.value = JSON.stringify( definitionFromSteps( steps, initial ) );
	}, [ steps, initialDefinition ] );

	useEffect( () => {
		syncHiddenField();
		window.BatonEditorSync = syncHiddenField;
		return () => {
			delete window.BatonEditorSync;
		};
	}, [ syncHiddenField ] );

	return (
		<div className="baton-editor baton-editor--shell">
			<div className="baton-editor__toolbar baton-editor__toolbar--view">
				<ToggleGroupControl
					label={ __( 'Editor view', 'baton' ) }
					hideLabelFromVision
					value={ view }
					onChange={ ( next ) => setView( next ) }
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
				<NodeView abilities={ abilities } steps={ steps } />
			) : (
				<WorkflowEditor
					abilities={ abilities }
					steps={ steps }
					setSteps={ setSteps }
				/>
			) }
		</div>
	);
}
