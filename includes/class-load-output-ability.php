<?php
/**
 * Load-output utility ability (baton/load-output).
 *
 * @package Baton
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads saved values back into workflow steps. Newest-wins lookup scoped to
 * the workflow; not-found is a loud step failure (never silent bad data).
 *
 * Boundary: reads only values written by Baton's save utilities — general
 * site-data reads (options, postmeta, arbitrary tables) are out of scope.
 */
final class Baton_Load_Output_Ability {

	public const SLUG = 'baton/load-output';

	/**
	 * Registration args for wp_register_ability().
	 *
	 * @return array<string, mixed>
	 */
	public static function args(): array {
		return array(
			'label'               => __( 'Load saved value', 'baton' ),
			'description'         => __( 'Loads the latest saved value for a label (save outputs or kept-latest state) into the workflow.', 'baton' ),
			'category'            => Baton_Utility_Abilities::CATEGORY,
			'input_schema'        => self::input_schema(),
			'output_schema'       => self::output_schema(),
			'execute_callback'    => static function ( $input = null ) {
				return self::execute( $input );
			},
			'permission_callback' => static function (): bool {
				return Baton_Utility_Abilities::can_manage_options();
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => false,
			),
		);
	}

	/**
	 * Input schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'label'       => array(
					'type'        => 'string',
					'description' => __( 'Label saved by an earlier save/keep-latest step.', 'baton' ),
				),
				'workflow_id' => Baton_Utility_Abilities::context_property(
					'integer',
					__( 'Workflow scope; defaults to the current workflow (filled automatically by the runner).', 'baton' )
				),
				'from_run_id' => array(
					'type'        => 'string',
					'description' => __( 'Advanced: restrict the lookup to values written during this run ID.', 'baton' ),
				),
			),
			'required'             => array( 'label' ),
			'additionalProperties' => true,
		);
	}

	/**
	 * Output schema: the decoded stored value (any JSON value).
	 *
	 * @return array<string, mixed>
	 */
	public static function output_schema(): array {
		return array(
			'type' => array( 'string', 'integer', 'number', 'boolean', 'array', 'object', 'null' ),
		);
	}

	/**
	 * Newest-wins lookup for the label within the workflow scope.
	 *
	 * @param mixed $input Ability input.
	 * @return mixed|WP_Error The decoded stored value on success.
	 */
	public static function execute( $input = null ) {
		$data  = Baton_Utility_Abilities::input_data( $input );
		$label = Baton_Utility_Abilities::label_from( $data );

		if ( '' === $label ) {
			return new WP_Error(
				'baton_load_output_label_required',
				__( 'Loading a value requires a non-empty label.', 'baton' )
			);
		}

		$from_run_id = Baton_Utility_Abilities::context_from( $data, 'from_run_id' );

		$row = Baton_Saved_Values::find(
			$label,
			Baton_Utility_Abilities::workflow_id_from( $data ),
			$from_run_id
		);

		if ( null === $row ) {
			return new WP_Error(
				'baton_load_output_not_found',
				sprintf(
					/* translators: %s: value label */
					__( 'No saved value found for label "%s".', 'baton' ),
					$label
				)
			);
		}

		return $row['value'];
	}
}
