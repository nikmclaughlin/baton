/**
 * Node-canvas view: renders the definition as an interactive React Flow
 * canvas. Editing the chain (add/remove/reorder/edit steps) stays in the
 * Stack view for Phases 1–3; here nodes are draggable and positions sync
 * into the #baton_editor_layout hidden field for persistence.
 */
import { useCallback, useMemo } from '@wordpress/element';
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

	const onNodeDragStop = useCallback( () => {
		const hidden = document.getElementById( 'baton_editor_layout' );
		if ( ! hidden ) {
			return;
		}
		hidden.value = JSON.stringify( graphToLayout( nodes ) );
	}, [ nodes ] );

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
