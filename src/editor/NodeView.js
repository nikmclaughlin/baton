/**
 * Node-view spike: renders the current definition as a read-only canvas.
 * Positions and styling are spike-grade; layout persistence lands in Phase 1.
 */
import { useMemo } from '@wordpress/element';
import {
	Background,
	Controls,
	Handle,
	Position,
	ReactFlow,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import { getAbility } from './utils';

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

export default function NodeView( { abilities, steps } ) {
	const nodes = useMemo( () => {
		const list = [
			{
				id: 'input',
				type: 'inputNode',
				position: { x: 40, y: 20 },
				data: {},
			},
		];
		steps.forEach( ( step, index ) => {
			const ability = getAbility( abilities, step.ability );
			list.push( {
				id: `step-${ index }`,
				type: 'abilityNode',
				position: { x: 40, y: 120 * ( index + 1 ) },
				data: {
					label: ability?.label || `Step ${ index + 1 }`,
					slug: step.ability,
				},
			} );
		} );
		return list;
	}, [ abilities, steps ] );

	const edges = useMemo( () => {
		const list = [];
		steps.forEach( ( _, index ) => {
			const source = index === 0 ? 'input' : `step-${ index - 1 }`;
			list.push( {
				id: `edge-${ source }-step-${ index }`,
				source,
				target: `step-${ index }`,
				type: 'smoothstep',
			} );
		} );
		return list;
	}, [ steps ] );

	return (
		<div className="baton-editor__node-canvas">
			<ReactFlow
				nodes={ nodes }
				edges={ edges }
				nodeTypes={ nodeTypes }
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
