<?php
/**
 * Save-state utility ability (baton/save-state).
 *
 * @package Baton
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the latest value per (workflow, label) — the cursor pattern.
 */
final class Baton_Save_State_Ability {

	public const SLUG = 'baton/save-state';

	/**
	 * Registration args for wp_register_ability().
	 *
	 * @return array<string, mixed>
	 */
	public static function args(): array {
		return array(
			'label'               => __( 'Keep latest', 'baton' ),
			'description'         => __( 'Keeps the latest value per label (one row, newest wins) — the cursor/counter pattern.', 'baton' ),
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
			__( 'Label to keep latest under.', 'baton' ),
			__( 'Latest value; typically mapped from a previous step.', 'baton' )
		);
	}

	/**
	 * Output schema: row summary, same shape as baton/save-output.
	 *
	 * @return array<string, mixed>
	 */
	public static function output_schema(): array {
		return Baton_Utility_Abilities::save_output_schema();
	}

	/**
	 * Upsert the state row for the label; the newest value wins.
	 *
	 * @param mixed $input Ability input.
	 * @return array<string, mixed>|WP_Error Row summary on success.
	 */
	public static function execute( $input = null ) {
		$data = Baton_Utility_Abilities::input_data( $input );

		$label = Baton_Utility_Abilities::label_from( $data );
		if ( '' === $label ) {
			return new WP_Error(
				'baton_save_state_label_required',
				__( 'Keeping the latest value requires a non-empty label.', 'baton' )
			);
		}

		$saved = Baton_Saved_Values::upsert_state(
			Baton_Utility_Abilities::workflow_id_from( $data ),
			Baton_Utility_Abilities::context_from( $data, 'run_id' ),
			$label,
			$data['value'] ?? null,
			Baton_Utility_Abilities::context_from( $data, 'step_id' )
		);

		if ( null === $saved['id'] ) {
			return new WP_Error(
				'baton_save_state_failed',
				__( 'The latest value could not be written.', 'baton' ) . Baton_Utility_Abilities::storage_error_suffix()
			);
		}

		return array(
			'id'        => $saved['id'],
			'label'     => $label,
			'run_id'    => Baton_Utility_Abilities::context_from( $data, 'run_id' ),
			'kind'      => Baton_Saved_Values::KIND_STATE,
			'truncated' => $saved['truncated'],
		);
	}
}
