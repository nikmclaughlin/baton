/**
 * Editor components shared between the stack view and the node canvas:
 * ability select, IO chips, loop panel, and the data-filter / IO-schema
 * modals. Extracted verbatim from the Phase 2 stack editor so both views
 * stay visually and behaviorally identical.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import {
	Button,
	Modal,
	Notice,
	Panel,
	PanelBody,
	SelectControl,
	TextareaControl,
	TextControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
	formatStepInput,
	getAbility,
	parseStepInput,
	indentNestedLabel,
	sanitizeMappingsForSave,
	groupAbilitiesByCategory,
	stringifyJson,
} from './utils';

export function defaultMappingRow( stepIndex ) {
	return {
		source: stepIndex === 0 ? 'initial' : 'previous',
		path: '',
		target: '',
	};
}

export function AbilitySelect( { value, abilities, onChange } ) {
	const groups = useMemo(
		() => groupAbilitiesByCategory( abilities, __( 'Other', 'baton' ) ),
		[ abilities ]
	);

	return (
		<div className="baton-ability-field">
			<span className="baton-ability-field__label">
				{ __( 'Ability', 'baton' ) }
			</span>
			<select
				className="baton-ability-select"
				value={ value }
				aria-label={ __( 'Ability', 'baton' ) }
				onChange={ ( event ) => onChange( event.target.value ) }
			>
				<option value="">
					{ __( 'Select an ability…', 'baton' ) }
				</option>
				{ groups.map( ( group ) => (
					<optgroup key={ group.label } label={ group.label }>
						{ group.abilities.map( ( ability ) => (
							<option key={ ability.slug } value={ ability.slug }>
								{ ability.label } ({ ability.slug })
							</option>
						) ) }
					</optgroup>
				) ) }
			</select>
		</div>
	);
}

export function IoChip( { label, sublabel, summary, isSingleValue, onClick } ) {
	return (
		<button
			type="button"
			className={ `baton-io-chip${
				isSingleValue ? ' baton-io-chip--single' : ''
			}` }
			onClick={ onClick }
		>
			<span className="baton-io-chip__label">{ label }</span>
			{ sublabel && (
				<span className="baton-io-chip__sublabel">{ sublabel }</span>
			) }
			<span className="baton-io-chip__value">{ summary }</span>
		</button>
	);
}

export function DataFilterPanel( {
	stepIndex,
	steps,
	abilities,
	onClose,
	onChangeMappings,
	onSave,
	saving,
} ) {
	const downstream = steps[ stepIndex ];
	const downstreamAbility = getAbility( abilities, downstream?.ability );
	const upstream = stepIndex > 0 ? steps[ stepIndex - 1 ] : null;
	const upstreamAbility = upstream
		? getAbility( abilities, upstream.ability )
		: null;

	const [ draftMappings, setDraftMappings ] = useState( () => {
		const saved = downstream?.input_mappings;
		return saved?.length
			? saved.map( ( row ) => ( { ...row } ) )
			: [ defaultMappingRow( stepIndex ) ];
	} );

	const mappings = draftMappings;

	const savedMappings = useMemo(
		() =>
			sanitizeMappingsForSave(
				downstream?.input_mappings,
				downstreamAbility
			),
		[ downstream?.input_mappings, downstreamAbility ]
	);
	const isDirty = useMemo( () => {
		if ( draftMappings.length !== savedMappings.length ) {
			return true;
		}
		return draftMappings.some(
			( row, i ) =>
				row.path !== savedMappings[ i ]?.path ||
				row.target !== savedMappings[ i ]?.target
		);
	}, [ draftMappings, savedMappings ] );

	const sourceOptions = useMemo( () => {
		if ( stepIndex === 0 || ! upstreamAbility?.source_selectable ) {
			return [];
		}
		return ( upstreamAbility.output_paths || [] ).map( ( p ) => ( {
			value: p.value,
			label: indentNestedLabel( p.label ),
		} ) );
	}, [ stepIndex, upstreamAbility ] );

	const targetOptions = useMemo( () => {
		if ( ! downstreamAbility?.target_selectable ) {
			return [];
		}
		return ( downstreamAbility.input_targets || [] ).map( ( t ) => ( {
			value: t.value,
			label: indentNestedLabel( t.label ),
		} ) );
	}, [ downstreamAbility ] );

	const updateMapping = ( rowIndex, field, value ) => {
		const source = stepIndex === 0 ? 'initial' : 'previous';
		setDraftMappings( ( prev ) =>
			prev.map( ( row, i ) =>
				i === rowIndex
					? { ...row, [ field ]: value, source }
					: { ...row, source: row.source || source }
			)
		);
	};

	const addMapping = () => {
		setDraftMappings( ( prev ) => [
			...prev,
			defaultMappingRow( stepIndex ),
		] );
	};

	const removeMapping = ( rowIndex ) => {
		setDraftMappings( ( prev ) => {
			const next = prev.filter( ( _, i ) => i !== rowIndex );
			return next.length ? next : [ defaultMappingRow( stepIndex ) ];
		} );
	};

	const handleDone = () => {
		onChangeMappings(
			stepIndex,
			sanitizeMappingsForSave( draftMappings, downstreamAbility )
		);
		onClose();
	};

	const handleModalSave = () => {
		onChangeMappings(
			stepIndex,
			sanitizeMappingsForSave( draftMappings, downstreamAbility )
		);
		if ( typeof onSave === 'function' ) {
			onSave( onClose );
		}
	};

	return (
		<Modal
			title={ `${ __( 'Configure data filter', 'baton' ) } — ${ __(
				'Step',
				'baton'
			) } ${ stepIndex + 1 }` }
			onRequestClose={ onClose }
			className="baton-data-filter-modal"
		>
			<Notice status="info" isDismissible={ false }>
				{ __(
					'Data filters define how values move between steps at run time. Ability schemas (on step cards) describe what each ability accepts and returns in general.',
					'baton'
				) }
			</Notice>
			{ stepIndex > 0 && upstreamAbility && (
				<p className="description">
					<strong>{ __( 'Source', 'baton' ) }:</strong>{ ' ' }
					{ upstreamAbility.output_summary?.summary }
					<span className="baton-schema-hint">
						{ ' ' }
						({ __( 'ability output schema', 'baton' ) })
					</span>
				</p>
			) }
			{ downstreamAbility && (
				<p className="description">
					<strong>{ __( 'Target', 'baton' ) }:</strong>{ ' ' }
					{ downstreamAbility.input_summary?.summary }
					<span className="baton-schema-hint">
						{ ' ' }
						({ __( 'ability input schema', 'baton' ) })
					</span>
				</p>
			) }
			{ stepIndex > 0 && ! upstreamAbility && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Select an ability on the previous step first.',
						'baton'
					) }
				</Notice>
			) }
			{ mappings.map( ( mapping, rowIndex ) => (
				<div key={ rowIndex } className="baton-mapping-row">
					<div className="baton-mapping-row__fields">
						{ stepIndex > 0 &&
						! upstreamAbility?.source_selectable ? (
							<div className="baton-mapping-readonly">
								<span className="baton-mapping-readonly__label">
									{ __( 'Source path', 'baton' ) }
								</span>
								<div className="baton-mapping-readonly__value">
									{ upstreamAbility?.source_display?.label ||
										__( 'Previous step output', 'baton' ) }
								</div>
							</div>
						) : (
							<SelectControl
								label={ __( 'Source path', 'baton' ) }
								help={ __(
									'Value taken from the previous step or workflow input.',
									'baton'
								) }
								value={ mapping.path || '' }
								options={ [
									{
										value: '',
										label: __( 'Select path…', 'baton' ),
									},
									...sourceOptions,
								] }
								onChange={ ( path ) =>
									updateMapping( rowIndex, 'path', path )
								}
							/>
						) }
						<span className="baton-mapping-arrow">→</span>
						{ ! downstreamAbility?.target_selectable ? (
							<div className="baton-mapping-readonly">
								<span className="baton-mapping-readonly__label">
									{ __( 'Target', 'baton' ) }
								</span>
								<div className="baton-mapping-readonly__value">
									{ downstreamAbility?.target_display
										?.label ||
										__( 'Entire input', 'baton' ) }
								</div>
							</div>
						) : (
							<SelectControl
								label={ __( 'Target field', 'baton' ) }
								help={ __(
									'Field on the next step’s input object.',
									'baton'
								) }
								value={ mapping.target || '' }
								options={ [
									{
										value: '',
										label: __( 'Select field…', 'baton' ),
									},
									...targetOptions,
								] }
								onChange={ ( target ) =>
									updateMapping( rowIndex, 'target', target )
								}
							/>
						) }
					</div>
					{ mappings.length > 1 && (
						<Button
							variant="link"
							isDestructive
							onClick={ () => removeMapping( rowIndex ) }
						>
							{ __( 'Remove row', 'baton' ) }
						</Button>
					) }
				</div>
			) ) }
			{ downstreamAbility?.target_selectable && (
				<Button variant="secondary" onClick={ addMapping }>
					{ __( 'Add mapping row', 'baton' ) }
				</Button>
			) }
			<div className="baton-modal-actions">
				{ isDirty ? (
					<Button
						variant="primary"
						isBusy={ !! saving }
						onClick={ handleModalSave }
					>
						{ __( 'Save', 'baton' ) }
					</Button>
				) : (
					<Button variant="primary" onClick={ handleDone }>
						{ __( 'Done', 'baton' ) }
					</Button>
				) }
			</div>
		</Modal>
	);
}

export function LoopPanel( { step, ability, onToggle, onFieldChange } ) {
	const fieldOptions = useMemo( () => {
		if ( ! ability?.input_targets ) {
			return [];
		}
		return ability.input_targets.map( ( t ) => ( {
			value: t.value,
			label: indentNestedLabel( t.label ),
		} ) );
	}, [ ability ] );

	const hasLoop = !! step.loop;
	const loopField = step.loop?.field || '';

	return (
		<div className="baton-loop-panel">
			<div className="baton-loop-panel__header">
				<div className="baton-loop-panel__toggle">
					<input
						type="checkbox"
						checked={ hasLoop }
						onChange={ ( e ) => {
							const checked = e.target.checked;
							onToggle( checked );
						} }
					/>
					<span className="baton-loop-panel__icon">↻</span>
					<span className="baton-loop-panel__label">
						{ __( 'Loop over array', 'baton' ) }
					</span>
				</div>
				{ hasLoop && (
					<Button
						variant="link"
						isDestructive
						onClick={ () => {
							onToggle( false );
						} }
						size="small"
					>
						{ __( 'Remove loop', 'baton' ) }
					</Button>
				) }
			</div>
			{ hasLoop && (
				<div className="baton-loop-panel__body">
					<p className="description baton-loop-panel__help">
						{ __(
							'Runs this step once per element in the selected input field. Use a data filter to map an array (e.g. via wildcard) into the field, then select it here to loop over each element.',
							'baton'
						) }
					</p>
					{ fieldOptions.length > 0 ? (
						<div className="baton-ability-field">
							<span className="baton-ability-field__label">
								{ __( 'Iterate over', 'baton' ) }
							</span>
							<select
								className="baton-ability-select"
								value={ loopField }
								aria-label={ __( 'Loop field', 'baton' ) }
								onChange={ ( e ) =>
									onFieldChange( e.target.value )
								}
							>
								<option value="">
									{ __( 'Select field…', 'baton' ) }
								</option>
								{ fieldOptions.map( ( opt ) => (
									<option
										key={ opt.value }
										value={ opt.value }
									>
										{ opt.label }
									</option>
								) ) }
							</select>
						</div>
					) : (
						<p className="description baton-loop-panel__empty">
							{ __(
								'This ability has no input fields. Add a data filter to map an array into an input field first.',
								'baton'
							) }
						</p>
					) }
				</div>
			) }
		</div>
	);
}

export function IoDetailPanel( {
	panel,
	abilities,
	steps,
	onClose,
	onInputChange,
	onSave,
	saving,
} ) {
	const step = panel ? steps[ panel.stepIndex ] : null;
	const ability = step ? getAbility( abilities, step.ability ) : null;
	const isInput = panel?.kind === 'input';
	const formattedInput = formatStepInput( step?.input, ability );

	const [ inputDraft, setInputDraft ] = useState( formattedInput );
	const [ jsonError, setJsonError ] = useState( null );

	// Reset draft only when opening a different step/ability panel, not on each keystroke.
	useEffect( () => {
		if ( ! panel || ! isInput ) {
			return;
		}
		setInputDraft(
			formatStepInput( steps[ panel.stepIndex ]?.input, ability )
		);
		setJsonError( null );
	}, [ panel?.stepIndex, panel?.kind, ability?.slug, isInput ] ); // eslint-disable-line react-hooks/exhaustive-deps -- avoid resetting draft while typing.

	if ( ! panel ) {
		return null;
	}

	if ( ! ability ) {
		return null;
	}

	const schema =
		panel.kind === 'input' ? ability.input_schema : ability.output_schema;

	const handleInputDraftChange = ( text ) => {
		setInputDraft( text );

		if ( ability.input_is_scalar ) {
			onInputChange( panel.stepIndex, parseStepInput( text, ability ) );
			setJsonError( null );
			return;
		}

		if ( ! text || ! String( text ).trim() ) {
			onInputChange( panel.stepIndex, {} );
			setJsonError( null );
			return;
		}

		try {
			const parsed = JSON.parse( text );
			onInputChange( panel.stepIndex, parsed ?? {} );
			setJsonError( null );
		} catch {
			setJsonError(
				__(
					'Invalid JSON — keep editing or fix before saving the workflow.',
					'baton'
				)
			);
		}
	};

	const handleClose = () => {
		setJsonError( null );
		onClose();
	};

	const isDirty =
		isInput && inputDraft !== formatStepInput( step?.input, ability );

	return (
		<Modal
			title={
				isInput
					? `${ __( 'Ability input schema', 'baton' ) } — ${ __(
							'Step',
							'baton'
					  ) } ${ panel.stepIndex + 1 }`
					: `${ __( 'Ability output schema', 'baton' ) } — ${ __(
							'Step',
							'baton'
					  ) } ${ panel.stepIndex + 1 }`
			}
			onRequestClose={ handleClose }
		>
			<Notice status="info" isDismissible={ false }>
				{ __(
					'This is the ability’s declared contract (JSON Schema). It does not show your workflow mappings — configure those in the data filter nodes between steps.',
					'baton'
				) }
			</Notice>
			<p className="description">
				<strong>{ ability.label }</strong>
				<br />
				<code>{ ability.slug }</code>
			</p>
			{ ability.description && (
				<p className="description">{ ability.description }</p>
			) }
			<Panel>
				<PanelBody title={ __( 'JSON Schema', 'baton' ) } initialOpen>
					<pre className="baton-schema-pre">
						{ JSON.stringify( schema || {}, null, 2 ) }
					</pre>
				</PanelBody>
			</Panel>
			{ isInput && (
				<div className="baton-static-input">
					{ jsonError && (
						<Notice status="warning" isDismissible={ false }>
							{ jsonError }
						</Notice>
					) }
					{ ability.input_is_scalar ? (
						<TextControl
							label={ __(
								'Static input (optional override)',
								'baton'
							) }
							help={ __(
								'Fixed value merged at run time; overrides data filter mappings for this step.',
								'baton'
							) }
							value={ inputDraft }
							onChange={ handleInputDraftChange }
						/>
					) : (
						<TextareaControl
							label={ __( 'Static input (JSON)', 'baton' ) }
							help={ __(
								'Fixed fields merged at run time; overrides matching keys from data filters.',
								'baton'
							) }
							value={ inputDraft }
							onChange={ handleInputDraftChange }
							rows={ 6 }
						/>
					) }
				</div>
			) }
			<div className="baton-modal-actions">
				{ isDirty ? (
					<Button
						variant="primary"
						disabled={ !! jsonError }
						isBusy={ !! saving }
						onClick={ () => {
							setJsonError( null );
							if ( typeof onSave === 'function' ) {
								onSave( handleClose );
							}
						} }
					>
						{ __( 'Save', 'baton' ) }
					</Button>
				) : (
					<Button variant="primary" onClick={ handleClose }>
						{ __( 'Close', 'baton' ) }
					</Button>
				) }
			</div>
		</Modal>
	);
}

/**
 * Initial input (workflow-level) JSON modal, shared by both views. On the
 * canvas it is opened from the input node; on the stack from the workflow
 * input anchor card. Live-parses JSON while typing; "Save" persists via
 * the workflow's save endpoint when the draft differs from the saved
 * value, otherwise it is a plain "Done" close.
 *
 * @param {Object}   root0              Component props.
 * @param {boolean}  root0.open         Whether the modal is open.
 * @param {Object}   root0.initialInput Saved workflow initial input.
 * @param {Function} root0.onClose      Called to close the modal.
 * @param {Function} root0.onChange     Called with the parsed draft while typing.
 * @param {Function} root0.onSave       Called with the close callback to persist.
 * @param {boolean}  root0.saving       Whether a workflow save is in flight.
 */
export function InitialInputPanel( {
	open,
	initialInput,
	onClose,
	onChange,
	onSave,
	saving,
} ) {
	const [ draft, setDraft ] = useState( () =>
		stringifyJson( initialInput || {} )
	);
	const [ jsonError, setJsonError ] = useState( null );

	useEffect( () => {
		if ( open ) {
			setDraft( stringifyJson( initialInput || {} ) );
			setJsonError( null );
		}
	}, [ open ] ); // eslint-disable-line react-hooks/exhaustive-deps -- reset only when opening.

	if ( ! open ) {
		return null;
	}

	const handleChange = ( text ) => {
		setDraft( text );
		if ( ! text || ! String( text ).trim() ) {
			onChange( {} );
			setJsonError( null );
			return;
		}
		try {
			const parsed = JSON.parse( text );
			onChange( parsed ?? {} );
			setJsonError( null );
		} catch {
			setJsonError(
				__(
					'Invalid JSON — keep editing or fix before saving the workflow.',
					'baton'
				)
			);
		}
	};

	const handleDone = () => {
		setJsonError( null );
		if ( typeof onSave === 'function' ) {
			onSave( onClose );
		}
	};

	return (
		<Modal
			title={ __( 'Workflow initial input', 'baton' ) }
			onRequestClose={ onClose }
		>
			<Notice status="info" isDismissible={ false }>
				{ __(
					'Fixed values available to step 1 via its data filter (source: workflow input).',
					'baton'
				) }
			</Notice>
			<TextareaControl
				label={ __( 'Initial input (JSON)', 'baton' ) }
				value={ draft }
				onChange={ handleChange }
				rows={ 8 }
			/>
			{ jsonError && (
				<Notice status="warning" isDismissible={ false }>
					{ jsonError }
				</Notice>
			) }
			<div className="baton-modal-actions">
				{ draft !== stringifyJson( initialInput || {} ) ? (
					<Button
						variant="primary"
						disabled={ !! jsonError }
						isBusy={ !! saving }
						onClick={ handleDone }
					>
						{ __( 'Save', 'baton' ) }
					</Button>
				) : (
					<Button variant="primary" onClick={ onClose }>
						{ __( 'Done', 'baton' ) }
					</Button>
				) }
			</div>
		</Modal>
	);
}
