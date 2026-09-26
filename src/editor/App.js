import { useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
	applyAbilityChange,
	defaultStep,
	getAbility,
	mappingRowLabel,
} from './utils';
import {
	AbilitySelect,
	IoChip,
	LoopPanel,
	DataFilterPanel,
	IoDetailPanel,
} from './components';

function FlowLine() {
	return <div className="baton-flow-line" aria-hidden="true" />;
}

function DataFilterSlot( {
	downstreamIndex,
	steps,
	abilities,
	onConfigure,
	onRemove,
} ) {
	const downstream = steps[ downstreamIndex ];
	const mappings = downstream?.input_mappings || [];
	const hasMappings = mappings.length > 0;

	if ( ! hasMappings ) {
		return (
			<div className="baton-filter-slot baton-filter-slot--empty">
				<FlowLine />
				<div className="baton-filter-slot__compact">
					<Button
						variant="secondary"
						className="baton-filter-slot__btn"
						onClick={ onConfigure }
						size="compact"
					>
						+
					</Button>
					<button
						type="button"
						className="baton-filter-slot__label-btn"
						onClick={ onConfigure }
					>
						{ __( 'Add data filter', 'baton' ) }
					</button>
				</div>
				<FlowLine />
			</div>
		);
	}

	return (
		<div className="baton-filter-slot baton-filter-slot--configured">
			<FlowLine />
			<DataFilterNode
				downstreamIndex={ downstreamIndex }
				steps={ steps }
				abilities={ abilities }
				onConfigure={ onConfigure }
				onRemove={ onRemove }
			/>
			<FlowLine />
		</div>
	);
}

function DataFilterNode( {
	downstreamIndex,
	steps,
	abilities,
	onConfigure,
	onRemove,
} ) {
	const downstream = steps[ downstreamIndex ];
	const downstreamAbility = getAbility( abilities, downstream?.ability );
	const upstream = downstreamIndex > 0 ? steps[ downstreamIndex - 1 ] : null;
	const upstreamAbility = upstream
		? getAbility( abilities, upstream.ability )
		: null;

	const mappings = downstream?.input_mappings || [];

	const fromLabel =
		downstreamIndex > 0 && upstreamAbility
			? `${ __( 'From', 'baton' ) } ${ __(
					'Step',
					'baton'
			  ) } ${ downstreamIndex }: ${ upstreamAbility.label }`
			: __( 'From workflow input', 'baton' );

	const toLabel = downstreamAbility
		? `${ __( 'Into', 'baton' ) } ${ __( 'Step', 'baton' ) } ${
				downstreamIndex + 1
		  }: ${ downstreamAbility.label }`
		: `${ __( 'Into', 'baton' ) } ${ __( 'Step', 'baton' ) } ${
				downstreamIndex + 1
		  }`;

	return (
		<Card className="baton-filter-node baton-filter-node--configured">
			<CardHeader>
				<div className="baton-filter-node__header">
					<div>
						<span className="baton-filter-node__type">
							{ __( 'Data filter', 'baton' ) }
						</span>
						<strong className="baton-filter-node__title">
							{ __( 'Data flow', 'baton' ) }
						</strong>
					</div>
					<div className="baton-filter-node__actions">
						<Button
							variant="link"
							isDestructive
							onClick={ onRemove }
							size="small"
						>
							{ __( 'Clear', 'baton' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ onConfigure }
							size="small"
						>
							{ __( 'Edit mapping', 'baton' ) }
						</Button>
					</div>
				</div>
			</CardHeader>
			<CardBody>
				<div className="baton-filter-node__route">
					<span className="baton-filter-node__endpoint">
						{ fromLabel }
					</span>
					<span
						className="baton-filter-node__arrow"
						aria-hidden="true"
					>
						→
					</span>
					<span className="baton-filter-node__endpoint">
						{ toLabel }
					</span>
				</div>
				<ul className="baton-filter-node__mappings">
					{ mappings.map( ( mapping, i ) => (
						<li key={ i }>
							<code>
								{ mappingRowLabel(
									mapping,
									downstreamAbility
								) }
							</code>
						</li>
					) ) }
				</ul>
			</CardBody>
		</Card>
	);
}

function StepCard( {
	step,
	index,
	abilities,
	totalSteps,
	onAbilityChange,
	onIoClick,
	onMoveUp,
	onMoveDown,
	onRemove,
	onLoopToggle,
	onLoopFieldChange,
} ) {
	const ability = getAbility( abilities, step.ability );
	const inputSummary =
		ability?.input_summary?.summary || __( 'No input', 'baton' );
	const outputSummary =
		ability?.output_summary?.summary || __( 'Unknown', 'baton' );
	const isSingleInput = ability?.input_summary?.kind === 'single_value';

	return (
		<Card className="baton-step-card">
			<CardHeader>
				<div className="baton-step-card__header">
					<div>
						<span className="baton-step-card__type">
							{ __( 'Ability step', 'baton' ) }
						</span>
						<strong>
							{ __( 'Step', 'baton' ) } { index + 1 }
							{ ability ? `: ${ ability.label }` : '' }
						</strong>
						{ ability && (
							<div className="baton-step-card__slug">
								<code>{ ability.slug }</code>
							</div>
						) }
					</div>
					<div className="baton-step-card__actions">
						<Button
							icon="arrow-up-alt2"
							label={ __( 'Move up', 'baton' ) }
							onClick={ onMoveUp }
							disabled={ index === 0 }
							size="small"
						/>
						<Button
							icon="arrow-down-alt2"
							label={ __( 'Move down', 'baton' ) }
							onClick={ onMoveDown }
							disabled={ index >= totalSteps - 1 }
							size="small"
						/>
						<Button
							variant="link"
							isDestructive
							onClick={ onRemove }
							size="small"
						>
							{ __( 'Remove', 'baton' ) }
						</Button>
					</div>
				</div>
			</CardHeader>
			<CardBody>
				<AbilitySelect
					value={ step.ability }
					abilities={ abilities }
					onChange={ onAbilityChange }
				/>
				<div className="baton-schema-section">
					<p className="baton-schema-section__heading">
						{ __( 'Ability contract (schema)', 'baton' ) }
					</p>
					<p className="baton-schema-section__help description">
						{ __(
							'What this ability is designed to accept and return. Use data filter nodes between steps to map actual values at run time.',
							'baton'
						) }
					</p>
					<div className="baton-io-row">
						<IoChip
							label={ __( 'Input schema', 'baton' ) }
							sublabel={ __( 'declared input', 'baton' ) }
							summary={ inputSummary }
							isSingleValue={ isSingleInput }
							onClick={ () => onIoClick( index, 'input' ) }
						/>
						<IoChip
							label={ __( 'Output schema', 'baton' ) }
							sublabel={ __( 'declared output', 'baton' ) }
							summary={ outputSummary }
							isSingleValue={
								ability?.output_summary?.kind === 'single_value'
							}
							onClick={ () => onIoClick( index, 'output' ) }
						/>
					</div>
				</div>
				{ ability && (
					<LoopPanel
						step={ step }
						ability={ ability }
						onToggle={ ( checked ) =>
							onLoopToggle( index, checked )
						}
						onFieldChange={ ( field ) =>
							onLoopFieldChange( index, field )
						}
					/>
				) }
			</CardBody>
		</Card>
	);
}

export default function WorkflowEditor( {
	abilities,
	initialDefinition,
	steps: controlledSteps,
	setSteps: setControlledSteps,
} ) {
	const [ internalSteps, setInternalSteps ] = useState( () => {
		const s = initialDefinition?.steps;
		return s?.length
			? s.map( ( step ) => ( { ...step } ) )
			: [ defaultStep() ];
	} );
	const steps = controlledSteps ?? internalSteps;
	const setSteps = setControlledSteps ?? setInternalSteps;
	const [ activeFilter, setActiveFilter ] = useState( null );
	const [ ioPanel, setIoPanel ] = useState( null );

	const updateStep = ( index, patch ) => {
		setSteps( ( prev ) =>
			prev.map( ( step, i ) =>
				i === index ? { ...step, ...patch } : step
			)
		);
	};

	const changeAbility = ( index, slug ) => {
		updateStep(
			index,
			applyAbilityChange( steps[ index ], slug, abilities )
		);
	};

	const moveStep = ( index, direction ) => {
		const target = index + direction;
		if ( target < 0 || target >= steps.length ) {
			return;
		}
		setSteps( ( prev ) => {
			const next = [ ...prev ];
			const tmp = next[ index ];
			next[ index ] = next[ target ];
			next[ target ] = tmp;
			return next;
		} );
		setActiveFilter( null );
	};

	const removeStep = ( index ) => {
		setSteps( ( prev ) => {
			if ( prev.length <= 1 ) {
				return [ defaultStep() ];
			}
			return prev.filter( ( _, i ) => i !== index );
		} );
		setActiveFilter( null );
	};

	const changeMappings = ( stepIndex, mappings ) => {
		updateStep( stepIndex, { input_mappings: mappings } );
	};

	const toggleLoop = ( stepIndex, enabled ) => {
		updateStep( stepIndex, {
			loop: enabled ? { field: '' } : null,
		} );
	};

	const changeLoopField = ( stepIndex, field ) => {
		updateStep( stepIndex, {
			loop: { field },
		} );
	};

	if ( ! abilities.length ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __( 'No abilities registered.', 'baton' ) }
			</Notice>
		);
	}

	return (
		<div className="baton-editor">
			<Notice
				status="info"
				isDismissible={ false }
				className="baton-editor__intro"
			>
				<p>
					<strong>{ __( 'Ability steps', 'baton' ) }</strong>
					{ ' — ' }
					{ __(
						'run abilities in order; input/output chips show each ability’s schema.',
						'baton'
					) }
				</p>
				<p>
					<strong>{ __( 'Data filter nodes', 'baton' ) }</strong>
					{ ' — ' }
					{ __(
						'sit between steps and define how values are passed from one step into the next at run time.',
						'baton'
					) }
				</p>
			</Notice>

			<div className="baton-editor__toolbar">
				<Button
					variant="primary"
					onClick={ () =>
						setSteps( ( prev ) => [ ...prev, defaultStep() ] )
					}
				>
					{ __( 'Add step', 'baton' ) }
				</Button>
			</div>

			<div className="baton-editor__chain">
				{ steps.map( ( step, index ) => (
					<div
						key={ `step-${ index }` }
						className="baton-editor__segment"
					>
						<StepCard
							step={ step }
							index={ index }
							abilities={ abilities }
							totalSteps={ steps.length }
							onAbilityChange={ ( slug ) =>
								changeAbility( index, slug )
							}
							onIoClick={ ( stepIndex, kind ) =>
								setIoPanel( { stepIndex, kind } )
							}
							onMoveUp={ () => moveStep( index, -1 ) }
							onMoveDown={ () => moveStep( index, 1 ) }
							onRemove={ () => removeStep( index ) }
							onLoopToggle={ ( stepIndex, enabled ) =>
								toggleLoop( stepIndex, enabled )
							}
							onLoopFieldChange={ ( stepIndex, field ) =>
								changeLoopField( stepIndex, field )
							}
						/>
						{ index < steps.length - 1 && (
							<DataFilterSlot
								downstreamIndex={ index + 1 }
								steps={ steps }
								abilities={ abilities }
								onConfigure={ () =>
									setActiveFilter( index + 1 )
								}
								onRemove={ () =>
									changeMappings( index + 1, [] )
								}
							/>
						) }
					</div>
				) ) }
			</div>

			{ activeFilter !== null && (
				<DataFilterPanel
					key={ `filter-panel-${ activeFilter }` }
					stepIndex={ activeFilter }
					steps={ steps }
					abilities={ abilities }
					onClose={ () => setActiveFilter( null ) }
					onChangeMappings={ changeMappings }
				/>
			) }

			<IoDetailPanel
				panel={ ioPanel }
				abilities={ abilities }
				steps={ steps }
				onClose={ () => setIoPanel( null ) }
				onInputChange={ ( stepIndex, input ) =>
					updateStep( stepIndex, { input } )
				}
			/>
		</div>
	);
}
