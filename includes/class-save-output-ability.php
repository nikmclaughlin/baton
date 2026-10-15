<?php
/**
 * Save-output utility ability (baton/save-output).
 *
 * @package Baton
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Appends a permanent saved-value record keyed by a user label.
 */
final class Baton_Save_Output_Ability {

	public const SLUG = 'baton/save-output';

	/**
	 * Registration args for wp_register_ability().
	 *
	 * @return array<string, mixed>
	 */
	public static function args(): array {
		return array(
			'label'               => __( 'Save output', 'baton' ),
			'description'         => __( 'Saves a value under a reusable label so later steps (or later runs) can load it.', 'baton' ),
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
		return Baton_Utility_Abilities::save_input_schema(
			__( 'Label to store the value under.', 'baton' ),
			__( 'Value to save; typically mapped from a previous step.', 'baton' )
		);
	}

	/**
	 * Output schema: row summary, same shape as baton/save-state.
	 *
	 * @return array<string, mixed>
	 */
	public static function output_schema(): array {
		return Baton_Utility_Abilities::save_output_schema();
	}

	/**
	 * Append a save row under the given label.
	 *
	 * @param mixed $input Ability input.
	 * @return array<string, mixed>|WP_Error Row summary on success.
	 */
	public static function execute( $input = null ) {
		$data = Baton_Utility_Abilities::input_data( $input );

		$label = Baton_Utility_Abilities::label_from( $data );
		if ( '' === $label ) {
			return new WP_Error(
				'baton_save_output_label_required',
				__( 'Saving output requires a non-empty label.', 'baton' )
			);
		}

		$saved = Baton_Saved_Values::append(
			Baton_Saved_Values::KIND_SAVE,
			Baton_Utility_Abilities::workflow_id_from( $data ),
			Baton_Utility_Abilities::context_from( $data, 'run_id' ),
			$label,
			$data['value'] ?? null,
			Baton_Utility_Abilities::context_from( $data, 'step_id' )
		);

		if ( null === $saved['id'] ) {
			return new WP_Error(
				'baton_save_output_failed',
				__( 'The saved output could not be written.', 'baton' )
			);
		}

		return array(
			'id'        => $saved['id'],
			'label'     => $label,
			'run_id'    => Baton_Utility_Abilities::context_from( $data, 'run_id' ),
			'kind'      => Baton_Saved_Values::KIND_SAVE,
			'truncated' => $saved['truncated'],
		);
	}
}
