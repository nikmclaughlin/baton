<?php
/**
 * Registers Baton's built-in utility abilities.
 *
 * @package Baton
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the baton-utility category and the four storage utilities at
 * wp_abilities_api_init (WP 6.9 gate — same pattern as Baton_Workflow_Abilities).
 */
final class Baton_Utility_Abilities {

	public const CATEGORY = 'baton-utility';

	public const PERMISSION = 'manage_options';

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
	}

	/**
	 * Register the Baton utilities category.
	 */
	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Baton Utilities', 'baton' ),
				'description' => __( 'Built-in value persistence utilities for workflows.', 'baton' ),
			)
		);
	}

	/**
	 * Register the built-in utility abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability( Baton_Save_Output_Ability::SLUG, Baton_Save_Output_Ability::args() );
		wp_register_ability( Baton_Save_State_Ability::SLUG, Baton_Save_State_Ability::args() );
		wp_register_ability( Baton_Load_Output_Ability::SLUG, Baton_Load_Output_Ability::args() );
		wp_register_ability( Baton_Log_Ability::SLUG, Baton_Log_Ability::args() );
	}

	/**
	 * Registration-args fragment: a property slot that accepts any JSON value.
	 *
	 * @param string $description Property description.
	 * @return array<string, mixed>
	 */
	public static function mixed_property( string $description ): array {
		return array(
			'type'        => array( 'string', 'integer', 'number', 'boolean', 'array', 'object', 'null' ),
			'description' => $description,
		);
	}

	/**
	 * Registration-args fragment: a runner-filled execution context property.
	 *
	 * The runner fills run_id/workflow_id/step_id when the ability's input
	 * schema declares them (never overwriting user-supplied values).
	 *
	 * @param string $type        JSON type of the value ('string' or 'integer').
	 * @param string $description Property description.
	 * @return array<string, mixed>
	 */
	public static function context_property( string $type, string $description ): array {
		return array(
			'type'        => $type,
			'description' => $description,
			'x-baton'     => 'context',
		);
	}

	/**
	 * Input schema shared by the write utilities (save-output, save-state).
	 *
	 * @param string $label_description Label property description.
	 * @param string $value_description Value property description.
	 * @return array<string, mixed>
	 */
	public static function save_input_schema( string $label_description, string $value_description ): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'label'       => array(
					'type'        => 'string',
					'description' => $label_description,
				),
				'value'       => self::mixed_property( $value_description ),
				'run_id'      => self::context_property(
					'string',
					__( 'Execution run ID; filled automatically by the runner.', 'baton' )
				),
				'workflow_id' => self::context_property(
					'integer',
					__( 'Workflow post ID; filled automatically by the runner.', 'baton' )
				),
				'step_id'     => self::context_property(
					'string',
					__( 'Editor node ID of the writing step; filled automatically by the runner.', 'baton' )
				),
			),
			'required'             => array( 'label' ),
			'additionalProperties' => true,
		);
	}

	/**
	 * Output schema shared by the write utilities (save-output, save-state):
	 * row summary so downstream steps can chain, plus an additive `truncated`
	 * flag when the value exceeded the storage size cap.
	 *
	 * @return array<string, mixed>
	 */
	public static function save_output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'        => array( 'type' => 'integer' ),
				'label'     => array( 'type' => 'string' ),
				'run_id'    => array( 'type' => 'string' ),
				'kind'      => array( 'type' => 'string' ),
				'truncated' => array( 'type' => 'boolean' ),
			),
		);
	}

	/**
	 * Normalize ability input for utility callbacks.
	 *
	 * The runner passes arrays; WP_Ability may pass a stdClass for an
	 * object-schema with an empty input object.
	 *
	 * @param mixed $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function input_data( $input ): array {
		if ( is_array( $input ) ) {
			return $input;
		}

		if ( $input instanceof stdClass ) {
			return get_object_vars( $input );
		}

		return array();
	}

	/**
	 * Required string label field; empty (or whitespace-only) returns empty.
	 *
	 * @param array<string, mixed> $data Input data.
	 * @return string
	 */
	public static function label_from( array $data ): string {
		$value = $data['label'] ?? '';

		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Optional string context field.
	 *
	 * @param array<string, mixed> $data Input data.
	 * @param string               $key  Field key.
	 * @return string
	 */
	public static function context_from( array $data, string $key ): string {
		$value = $data[ $key ] ?? '';

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Workflow scope field (runner-injected when absent).
	 *
	 * @param array<string, mixed> $data Input data.
	 * @return int
	 */
	public static function workflow_id_from( array $data ): int {
		$workflow_id = $data['workflow_id'] ?? 0;

		return is_numeric( $workflow_id ) ? (int) $workflow_id : 0;
	}

	/**
	 * Shared permission check: utility abilities run under the workflow
	 * permission context (manage_options today).
	 *
	 * @return bool
	 */
	public static function can_manage_options(): bool {
		return current_user_can( self::PERMISSION );
	}

	/**
	 * Storage-failure context appended to a utility ability's error message.
	 *
	 * `$wpdb->last_error` holds the MySQL error from the most recent query
	 * (empty when that query succeeded), so appending it to a failed write or
	 * read makes the failure diagnosable from the run report — e.g.
	 * "Table … doesn't exist" when the saved-values table was never migrated.
	 * Error codes and schemas are unchanged; only the message gains context,
	 * and only when the storage layer actually reported an error.
	 *
	 * @return string Suffix ('' when the last storage query was clean).
	 */
	public static function storage_error_suffix(): string {
		global $wpdb;

		$last_error = trim( (string) $wpdb->last_error );

		if ( '' === $last_error ) {
			return '';
		}

		/* translators: %s: MySQL error reported by the last storage query. */
		return ' ' . sprintf( __( 'Storage error: %s', 'baton' ), $last_error );
	}
}
