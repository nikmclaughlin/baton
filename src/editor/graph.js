/**
 * Definition ↔ graph adapters for the node canvas.
 *
 * The definition remains the single source of truth (steps are a linear
 * chain for the sequential runner). graph.js converts to/from React Flow's
 * { nodes, edges } shape and computes fallback auto-layout, so NodeView
 * stays presentational. Pure functions; unit-testable without WP.
 */

import { getAbility } from './utils';

/** Max chars for a stable step id (mirrors PHP sanitize_definition cap). */
export const STEP_ID_MAX = 40;

/**
 * Normalize a stable step id: lowercase, [-_a-z0-9], capped at 40 chars.
 * Mirrors PHP's sanitize_key() + substr() whitelist.
 *
 * @param {string} raw Raw id string.
 * @return {string} Empty string if unusable.
 */
export function sanitizeStepId( raw ) {
	if ( typeof raw !== 'string' ) {
		return '';
	}
	return raw
		.toLowerCase()
		.replace( /[^-_a-z0-9]/g, '' )
		.slice( 0, STEP_ID_MAX );
}

/**
 * Node id for a step, stable when the step carries an `id`, index-based
 * otherwise. Layout keys match this scheme ('input' for the input node).
 *
 * @param {Object} step  Step object.
 * @param {number} index Step index (fallback identity).
 * @return {string} e.g. 'input', 'step-0', or a stable id like 'fetch'.
 */
export function stepNodeId( step, index ) {
	const stable = sanitizeStepId( step?.id );
	return stable || `step-${ index }`;
}

/**
 * Compute vertical auto-layout for a linear chain (Phases 1–3).
 * Fixed x, spaced y; used when saved layout is missing or doesn't cover
 * the definition's nodes.
 *
 * @param {number} stepCount Number of ability steps.
 * @return {Object} Map of node id to { x, y }.
 */
export function autoLayout( stepCount ) {
	const layout = { input: { x: 40, y: 20 } };
	for ( let i = 0; i < stepCount; i++ ) {
		layout[ `step-${ i }` ] = { x: 40, y: 120 * ( i + 1 ) };
	}
	return layout;
}

/**
 * Resolve node positions: saved layout for ids present in the definition,
 * auto-layout fallback for the rest.
 *
 * Valid saved ids mirror PHP prune_layout(): 'input', each step's stable
 * id, and each step's 'step-N' index fallback (pre-stable-id definitions
 * and duplicate-id collision fallbacks both keep working).
 *
 * @param {object[]} steps         Definition steps.
 * @param {Object}   [savedLayout] Saved _baton_editor_layout map.
 * @return {Object} Map of node id to { x, y }.
 */
export function resolveLayout( steps, savedLayout ) {
	const positions = autoLayout( steps.length );

	if ( ! savedLayout || typeof savedLayout !== 'object' ) {
		return positions;
	}

	const validIds = new Set( [ 'input' ] );
	steps.forEach( ( step, index ) => {
		const stable = sanitizeStepId( step?.id );
		if ( stable ) {
			validIds.add( stable );
		}
		validIds.add( `step-${ index }` );
	} );

	Object.keys( savedLayout ).forEach( ( id ) => {
		const position = savedLayout[ id ];
		if (
			validIds.has( id ) &&
			position &&
			Number.isFinite( position.x ) &&
			Number.isFinite( position.y )
		) {
			positions[ id ] = { x: position.x, y: position.y };
		}
	} );

	return positions;
}

/**
 * Convert definition steps to React Flow nodes + edges (linear chain:
 * input → step-0 → step-1 → ...). Edges always follow definition order,
 * never layout.
 *
 * @param {object[]} steps     Definition steps.
 * @param {object[]} abilities Ability catalog (for labels).
 * @param {Object}   layout    Resolved positions (resolveLayout output).
 * @return {{nodes: object[], edges: object[]}}
 */
export function stepsToGraph( steps, abilities, layout ) {
	const nodes = [
		{
			id: 'input',
			type: 'inputNode',
			position: layout.input,
			data: {},
		},
	];
	const edges = [];
	const usedIds = new Set( [ 'input' ] );

	steps.forEach( ( step, index ) => {
		const stableId = sanitizeStepId( step.id );
		const nodeId =
			stableId && ! usedIds.has( stableId )
				? stableId
				: `step-${ index }`;
		usedIds.add( nodeId );
		const sourceId =
			index === 0 ? 'input' : stepNodeId( steps[ index - 1 ], index - 1 );
		const ability = getAbility( abilities, step.ability );
		nodes.push( {
			id: nodeId,
			type: 'abilityNode',
			position: layout[ nodeId ] || { x: 40, y: 120 * ( index + 1 ) },
			data: {
				label: ability?.label || `Step ${ index + 1 }`,
				slug: step.ability,
			},
		} );
		edges.push( {
			id: `edge-${ sourceId }-to-${ nodeId }`,
			source: sourceId,
			target: nodeId,
			type: 'smoothstep',
		} );
	} );

	return { nodes, edges };
}

/**
 * Convert React Flow node positions back to the layout meta shape
 * ({ nodeId: { x, y } }). Positions must be finite numbers.
 *
 * @param {object[]} nodes React Flow nodes.
 * @return {Object} Layout map ready for _baton_editor_layout.
 */
export function graphToLayout( nodes ) {
	const layout = {};
	( nodes || [] ).forEach( ( node ) => {
		if (
			node?.position &&
			Number.isFinite( node.position.x ) &&
			Number.isFinite( node.position.y )
		) {
			layout[ node.id ] = {
				x: Math.round( node.position.x * 100 ) / 100,
				y: Math.round( node.position.y * 100 ) / 100,
			};
		}
	} );
	return layout;
}
