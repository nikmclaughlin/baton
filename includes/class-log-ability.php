<?php
/**
 * Log utility ability (baton/log).
 *
 * @package Baton
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes inspectable, per-run entries for the workflow timeline.
 */
final class Baton_Log_Ability {

	public const SLUG = 'baton/log';

	/**
	 * Registration args for wp_register_ability().
	 *
	 * @return array<string, mixed>
	 */
	public static function args(): array {
		return array(
			'label'               => __( 'Log entry', 'baton' ),
			'description'         => __( 'Writes a message (with optional context) that is inspectable per run; no downstream output dependency.', 'baton' ),
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
				'message'     => array(
					'type'        => 'string',
					'description' => __( 'Message to log (headline for the timeline); first ~190 characters.', 'baton' ),
				),
				'context'     => Baton_Utility_Abilities::mixed_property( __( 'Optional extra data stored alongside the message.', 'baton' ) ),
				'run_id'      => Baton_Utility_Abilities::context_property(
					'string',
					__( 'Execution run ID; filled automatically by the runner.', 'baton' )
				),
				'workflow_id' => Baton_Utility_Abilities::context_property(
					'integer',
					__( 'Workflow post ID; filled automatically by the runner.', 'baton' )
				),
				'step_id'     => Baton_Utility_Abilities::context_property(
					'string',
					__( 'Editor node ID of the writing step; filled automatically by the runner.', 'baton' )
				),
			),
			'required'             => array( 'message' ),
			'additionalProperties' => true,
		);
	}

	/**
	 * Output schema: no output dependency expected downstream.
	 *
	 * @return array<string, mixed>
	 */
	public static function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'logged' => array( 'type' => 'boolean' ),
				'id'     => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * Append a log row: label is the (truncated) message headline, value holds
	 * the full message + context.
	 *
	 * @param mixed $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute( $input = null ) {
		$data    = Baton_Utility_Abilities::input_data( $input );
		$message = $data['message'] ?? '';

		if ( ! is_string( $message ) ) {
			$message = (string) wp_json_encode( $message );
		}

		$headline = trim( $message );
		if ( '' === $headline ) {
			return new WP_Error(
				'baton_log_message_required',
				__( 'Logging requires a non-empty message.', 'baton' )
			);
		}

		$stored = array(
			'message' => $message,
			'context' => $data['context'] ?? null,
		);

		$saved = Baton_Saved_Values::append(
			Baton_Saved_Values::KIND_LOG,
			Baton_Utility_Abilities::workflow_id_from( $data ),
			Baton_Utility_Abilities::context_from( $data, 'run_id' ),
			sanitize_text_field( $headline ),
			$stored,
			Baton_Utility_Abilities::context_from( $data, 'step_id' )
		);

		if ( null === $saved['id'] ) {
			return new WP_Error(
				'baton_log_failed',
				__( 'The log entry could not be written.', 'baton' ) . Baton_Utility_Abilities::storage_error_suffix()
			);
		}

		return array(
			'logged' => true,
			'id'     => $saved['id'],
		);
	}
}
