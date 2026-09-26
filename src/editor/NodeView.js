/**
 * Node-canvas view: renders the definition as an interactive React Flow
 * canvas. Editing the chain (add/remove/reorder/edit steps) stays in the
 * Stack view for Phases 1–3; here nodes are draggable and positions sync
 * into the #baton_editor_layout hidden field for persistence.
 */
import { useCallback, useEffect, useMemo, useRef } from '@wordpress/element';
import {
	Background,
	Controls,
	Handle,
	Position,
	ReactFlow,
	useNodesState,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import { graphToLayout, resolveLayout, stepsToGraph } from './graph';

function InputNode() {
	return (
		<div className="baton-node baton-node--input">
			<Handle type="source" position={ Position.Bottom } />
			<strong>{ 'Workflow input' }</strong>
			<code>{ 'initial_input' }</code>
		</div>
	);
}

function AbilityNode( { data } ) {
	return (
		<div className="baton-node baton-node--ability">
			<Handle type="target" position={ Position.Top } />
			<strong>{ data.label }</strong>
			{ data.slug ? <code>{ data.slug }</code> : null }
			<Handle type="source" position={ Position.Bottom } />
		</div>
	);
}

const nodeTypes = {
	inputNode: InputNode,
	abilityNode: AbilityNode,
};

export default function NodeView( { abilities, steps, savedLayout } ) {
	const { nodes: initialNodes, edges } = useMemo(
		() =>
			stepsToGraph(
				steps,
				abilities,
				resolveLayout( steps, savedLayout )
			),
		[ steps, abilities, savedLayout ]
	);

	const [ nodes, , onNodesChange ] = useNodesState( initialNodes );
	const nodesRef = useRef( nodes );
	nodesRef.current = nodes;

	const hiddenLayoutRef = useRef( null );

	/**
	 * Write the current layout into the hidden field.
	 *
	 * @param {Object} [layoutOverride] Layout map to serialize instead of
	 *                                  the current nodes' positions (used on drag stop where React Flow's
	 *                                  args are fresher than state).
	 */
	const syncLayoutField = useCallback( ( layoutOverride ) => {
		if ( ! hiddenLayoutRef.current ) {
			hiddenLayoutRef.current = document.getElementById(
				'baton_editor_layout'
			);
		}
		if ( hiddenLayoutRef.current ) {
			hiddenLayoutRef.current.value = JSON.stringify(
				layoutOverride || graphToLayout( nodesRef.current )
			);
		}
	}, [] );

	/**
	 * Persist final positions on drag stop. Uses React Flow's callback
	 * args (fresher than state) merged with the rest of the layout.
	 */
	const onNodeDragStop = useCallback(
		( event, node, draggedNodes ) => {
			const positions = graphToLayout( nodesRef.current );
			( draggedNodes || [ node ] ).forEach( ( dragged ) => {
				if (
					dragged?.position &&
					Number.isFinite( dragged.position.x )
				) {
					positions[ dragged.id ] = {
						x: Math.round( dragged.position.x * 100 ) / 100,
						y: Math.round( dragged.position.y * 100 ) / 100,
					};
				}
			} );
			syncLayoutField( positions );
		},
		[ syncLayoutField ]
	);

	// Keep the hidden field current even when saving without dragging, so
	// a save can't wipe previously stored positions with an empty layout.
	useEffect( () => {
		syncLayoutField();
	}, [ nodes, syncLayoutField ] );

	return (
		<div className="baton-editor__node-canvas">
			<ReactFlow
				nodes={ nodes }
				edges={ edges }
				nodeTypes={ nodeTypes }
				onNodesChange={ onNodesChange }
				onNodeDragStop={ onNodeDragStop }
				fitView
				nodesConnectable={ false }
				edgesFocusable={ false }
			>
				<Background />
				<Controls />
			</ReactFlow>
		</div>
	);
}
