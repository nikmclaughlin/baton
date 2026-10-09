/**
 * Node-canvas view (Phase 2): full editing on the canvas.
 *
 * The definition (steps + initial_input) stays the source of truth and is
 * owned by the shell; NodeView derives nodes/edges from it and edits it
 * through callbacks. Node positions live in a local layout map synced to
 * the #baton_editor_layout hidden field. Editing the chain (ability,
 * static input, loop) reuses the same components as the stack view;
 * data filters are edited through the mapping edges.
 */
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
	Background,
	BaseEdge,
	Controls,
	EdgeLabelRenderer,
	Handle,
	Position,
	ReactFlow,
	getSmoothStepPath,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import {
	DataFilterPanel,
	InitialInputPanel,
	IoChip,
	IoDetailPanel,
	LoopPanel,
	AbilitySelect,
} from './components';
import { applyAbilityChange, connectorSummary, getAbility } from './utils';
import { resolveLayout, runStepForIndex, stepsToGraph } from './graph';

function RunBadge( { runStep } ) {
	const warnings = Array.isArray( runStep.warnings ) ? runStep.warnings : [];
	let state = 'error';
	if ( runStep.success ) {
		state = warnings.length ? 'warn' : 'ok';
	}
	const glyph = { ok: '✓', warn: '!', error: '✗' }[ state ];
	let title = runStep.error || '';
	if ( ! title && warnings.length ) {
		title = warnings.join( ' ' );
	}

	return (
		<span
			className={ `baton-node__run-badge baton-run-badge--${ state }` }
			title={ title }
		>
			{ glyph }
		</span>
	);
}

function InputNode( { data } ) {
	return (
		<div className="baton-node baton-node--input">
			<Handle type="source" position={ Position.Bottom } />
			<strong>{ __( 'Workflow input', 'baton' ) }</strong>
			<code>{ 'initial_input' }</code>
			<div className="baton-node__body nodrag nowheel">
				<button
					type="button"
					className="baton-node__edit-input"
					onClick={ data.onEditInput }
				>
					{ __( 'Edit initial input', 'baton' ) }
				</button>
			</div>
		</div>
	);
}

function AbilityNode( { data } ) {
	const { step, ability, stepIndex } = data;
	const inputSummary =
		ability?.input_summary?.summary || __( 'No input', 'baton' );
	const outputSummary =
		ability?.output_summary?.summary || __( 'Unknown', 'baton' );

	return (
		<div className="baton-node baton-node--ability">
			<Handle type="target" position={ Position.Top } />
			<div className="baton-node__header">
				<span className="baton-node__kind">
					{ __( 'Ability step', 'baton' ) }
				</span>
				<strong>
					{ `${ __( 'Step', 'baton' ) } ${ stepIndex + 1 }` }
					{ data.runStep && <RunBadge runStep={ data.runStep } /> }
				</strong>
				<button
					type="button"
					className="baton-node__remove"
					onClick={ data.onRemove }
				>
					{ __( 'Remove', 'baton' ) }
				</button>
			</div>
			<div className="baton-node__body nodrag nowheel">
				<AbilitySelect
					value={ step.ability }
					abilities={ data.abilities }
					onChange={ data.onAbilityChange }
				/>
				<div className="baton-io-row">
					<IoChip
						label={ __( 'Input schema', 'baton' ) }
						sublabel={ __( 'declared input', 'baton' ) }
						summary={ inputSummary }
						isSingleValue={
							ability?.input_summary?.kind === 'single_value'
						}
						onClick={ () => data.onIoClick( 'input' ) }
					/>
					<IoChip
						label={ __( 'Output schema', 'baton' ) }
						sublabel={ __( 'declared output', 'baton' ) }
						summary={ outputSummary }
						isSingleValue={
							ability?.output_summary?.kind === 'single_value'
						}
						onClick={ () => data.onIoClick( 'output' ) }
					/>
				</div>
				{ ability && (
					<LoopPanel
						step={ step }
						ability={ ability }
						onToggle={ data.onLoopToggle }
						onFieldChange={ data.onLoopFieldChange }
					/>
				) }
			</div>
			<Handle type="source" position={ Position.Bottom } />
		</div>
	);
}

const nodeTypes = {
	inputNode: InputNode,
	abilityNode: AbilityNode,
};

function MappingEdge( {
	id,
	sourceX,
	sourceY,
	targetX,
	targetY,
	sourcePosition,
	targetPosition,
	markerEnd,
	data,
} ) {
	const [ path, labelX, labelY ] = getSmoothStepPath( {
		sourceX,
		sourceY,
		sourcePosition,
		targetX,
		targetY,
		targetPosition,
	} );

	return (
		<>
			<BaseEdge id={ id } path={ path } markerEnd={ markerEnd } />
			<EdgeLabelRenderer>
				<button
					type="button"
					className={ `baton-edge-chip${
						data?.summary ? '' : ' baton-edge-chip--empty'
					}` }
					style={ {
						transform: `translate(-50%, -50%) translate(${ labelX }px, ${ labelY }px)`,
					} }
					onClick={ data?.onConfigure }
				>
					{ data?.summary || __( '+ Data filter', 'baton' ) }
				</button>
			</EdgeLabelRenderer>
		</>
	);
}

const edgeTypes = {
	mappingEdge: MappingEdge,
};

/**
 * Drop target resolution: insertion index = number of step nodes above
 * the drop position (vertical-chain convention). 0 inserts before the
 * first step, steps.length appends.
 *
 * @param {{x: number, y: number}} flowPos Drop point in flow coordinates.
 * @param {object[]}               nodes   Canvas nodes.
 * @return {number} Insertion index.
 */
function insertionIndexForDrop( flowPos, nodes ) {
	return nodes.filter(
		( node ) => 'abilityNode' === node.type && flowPos.y > node.position.y
	).length;
}

export default function NodeView( {
	abilities,
	steps,
	savedLayout,
	initialInput,
	runReport,
	onUpdateStep,
	onAddStep,
	onInsertStep,
	onRemoveStep,
	onInitialInputChange,
	onSave,
	saving,
} ) {
	const [ positions, setPositions ] = useState( () =>
		resolveLayout( steps, savedLayout )
	);
	const [ filterIndex, setFilterIndex ] = useState( null );
	const [ ioPanel, setIoPanel ] = useState( null );
	const [ inputPanelOpen, setInputPanelOpen ] = useState( false );
	const [ rfInstance, setRfInstance ] = useState( null );
	const hiddenLayoutRef = useRef( null );

	const { nodes, edges } = useMemo( () => {
		const graph = stepsToGraph( steps, abilities, positions );
		graph.nodes = graph.nodes.map( ( node ) => {
			if ( 'abilityNode' !== node.type ) {
				return {
					...node,
					data: { onEditInput: () => setInputPanelOpen( true ) },
				};
			}
			const stepIndex = node.data.stepIndex;
			const step = steps[ stepIndex ];
			const ability = getAbility( abilities, step.ability );
			return {
				...node,
				data: {
					...node.data,
					step,
					ability,
					abilities,
					runStep: runStepForIndex( runReport, stepIndex ),
					onAbilityChange: ( slug ) =>
						onUpdateStep(
							stepIndex,
							applyAbilityChange( step, slug, abilities )
						),
					onRemove: () => {
						setFilterIndex( null );
						setIoPanel( null );
						onRemoveStep( stepIndex );
					},
					onIoClick: ( kind ) => setIoPanel( { stepIndex, kind } ),
					onLoopToggle: ( enabled ) =>
						onUpdateStep( stepIndex, {
							loop: enabled ? { field: '' } : null,
						} ),
					onLoopFieldChange: ( field ) =>
						onUpdateStep( stepIndex, { loop: { field } } ),
				},
			};
		} );
		graph.edges = graph.edges.map( ( edge ) => ( {
			...edge,
			data: {
				...edge.data,
				summary: connectorSummary(
					steps[ edge.data.downstreamIndex ]?.input_mappings,
					getAbility(
						abilities,
						steps[ edge.data.downstreamIndex ]?.ability
					)
				),
				onConfigure: () => setFilterIndex( edge.data.downstreamIndex ),
			},
		} ) );
		return graph;
	}, [ steps, abilities, positions, runReport, onUpdateStep, onRemoveStep ] );

	// Position changes (dragging) update the layout map only; the hidden
	// field is serialized from the rendered nodes so ids stay valid.
	const onNodesChange = useCallback( ( changes ) => {
		const positionChanges = changes.filter(
			( change ) => 'position' === change.type && change.position
		);
		if ( ! positionChanges.length ) {
			return;
		}
		setPositions( ( prev ) => {
			const next = { ...prev };
			positionChanges.forEach( ( change ) => {
				next[ change.id ] = {
					x: change.position.x,
					y: change.position.y,
				};
			} );
			return next;
		} );
	}, [] );

	useEffect( () => {
		if ( ! hiddenLayoutRef.current ) {
			hiddenLayoutRef.current = document.getElementById(
				'baton_editor_layout'
			);
		}
		if ( hiddenLayoutRef.current ) {
			hiddenLayoutRef.current.value = JSON.stringify( positions );
		}
	}, [ positions ] );

	const onDragOver = useCallback( ( event ) => {
		event.preventDefault();
		event.dataTransfer.dropEffect = 'move';
	}, [] );

	const onDrop = useCallback(
		( event ) => {
			event.preventDefault();
			if (
				! rfInstance ||
				! event.dataTransfer.getData( 'application/baton-step' )
			) {
				return;
			}
			const flowPosition = rfInstance.screenToFlowPosition( {
				x: event.clientX,
				y: event.clientY,
			} );
			onInsertStep( insertionIndexForDrop( flowPosition, nodes ) );
		},
		[ rfInstance, nodes, onInsertStep ]
	);

	return (
		<div className="baton-editor__node-view">
			<div className="baton-editor__toolbar">
				<Button variant="primary" onClick={ onAddStep }>
					{ __( 'Add step', 'baton' ) }
				</Button>
				<div
					className="baton-editor__drag-chip"
					draggable
					onDragStart={ ( event ) =>
						event.dataTransfer.setData(
							'application/baton-step',
							'step'
						)
					}
				>
					{ __( 'Drag to place a new step', 'baton' ) }
				</div>
			</div>
			<div className="baton-editor__node-canvas">
				<ReactFlow
					nodes={ nodes }
					edges={ edges }
					nodeTypes={ nodeTypes }
					edgeTypes={ edgeTypes }
					onNodesChange={ onNodesChange }
					onInit={ setRfInstance }
					onDrop={ onDrop }
					onDragOver={ onDragOver }
					fitView
					nodesConnectable={ false }
					edgesFocusable={ false }
					deleteKeyCode={ null }
				>
					<Background />
					<Controls />
				</ReactFlow>
			</div>

			{ filterIndex !== null && (
				<DataFilterPanel
					key={ `filter-panel-${ filterIndex }` }
					stepIndex={ filterIndex }
					steps={ steps }
					abilities={ abilities }
					onClose={ () => setFilterIndex( null ) }
					onChangeMappings={ ( stepIndex, mappings ) =>
						onUpdateStep( stepIndex, { input_mappings: mappings } )
					}
					onSave={ onSave }
					saving={ saving }
				/>
			) }

			<IoDetailPanel
				panel={ ioPanel }
				abilities={ abilities }
				steps={ steps }
				onClose={ () => setIoPanel( null ) }
				onInputChange={ ( stepIndex, input ) =>
					onUpdateStep( stepIndex, { input } )
				}
				onSave={ onSave }
				saving={ saving }
			/>

			<InitialInputPanel
				open={ inputPanelOpen }
				initialInput={ initialInput }
				onClose={ () => setInputPanelOpen( false ) }
				onChange={ onInitialInputChange }
				onSave={ onSave }
				saving={ saving }
			/>
		</div>
	);
}
