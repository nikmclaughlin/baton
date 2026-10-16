<?php
/**
 * Workflow custom post type.
 *
 * @package Baton
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the baton_workflow post type.
 */
final class Baton_Workflow_CPT {

	public const POST_TYPE = 'baton_workflow';

	public const META_DEFINITION = '_baton_workflow_definition';

	public const META_LAYOUT = '_baton_editor_layout';

	public const META_SURFACES = '_baton_external_surfaces';

	/**
	 * External run surface the editor can enable (B2: REST only; CLI/MCP
	 * slugs may join this map later).
	 */
	public const SURFACE_REST = 'rest';

	/**
	 * External run surface: WP-CLI (`wp baton` commands, admin context).
	 */
	public const SURFACE_CLI = 'cli';

	/**
	 * Known surface slugs for the exposure map; unknown values are dropped.
	 */
	public const AVAILABLE_SURFACES = array( self::SURFACE_REST, self::SURFACE_CLI );

	/**
	 * Register post type.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_post_type' ) );
		add_action( 'init', array( self::class, 'register_editor_meta' ), 20 );
	}

	/**
	 * Register editor meta keys so their sanitize callbacks apply on save.
	 */
	public static function register_editor_meta(): void {
		register_post_meta(
			self::POST_TYPE,
			self::META_DEFINITION,
			array(
				'type'              => 'object',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => array( self::class, 'sanitize_definition' ),
			)
		);
		register_post_meta(
			self::POST_TYPE,
			self::META_LAYOUT,
			array(
				'type'              => 'object',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => array( self::class, 'sanitize_layout' ),
			)
		);
		register_post_meta(
			self::POST_TYPE,
			self::META_SURFACES,
			array(
				'type'              => 'array',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => array( self::class, 'sanitize_surfaces' ),
			)
		);
	}

	/**
	 * Register baton_workflow CPT.
	 */
	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'           => array(
					'name'          => __( 'Baton', 'baton' ),
					'singular_name' => __( 'Ability Workflow', 'baton' ),
					'add_new'       => __( 'Add Workflow', 'baton' ),
					'add_new_item'  => __( 'Add New Workflow', 'baton' ),
					'edit_item'     => __( 'Edit Workflow', 'baton' ),
					'view_item'     => __( 'View Workflow', 'baton' ),
					'search_items'  => __( 'Search Workflows', 'baton' ),
					'not_found'     => __( 'No workflows found.', 'baton' ),
				),
				'public'           => false,
				'show_ui'          => false,
				'show_in_menu'     => false,
				'supports'         => array( 'title', 'excerpt' ),
				'capability_type'  => 'post',
				'map_meta_cap'     => true,
				'delete_with_user' => false,
			)
		);
	}

	/**
	 * Default workflow definition.
	 *
	 * @return array<string, mixed>
	 */
	public static function default_definition(): array {
		return array(
			'initial_input' => array(),
			'steps'         => array(),
		);
	}

	/**
	 * Get workflow definition for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	public static function get_definition( int $post_id ): array {
		$stored = get_post_meta( $post_id, self::META_DEFINITION, true );

		if ( ! is_array( $stored ) ) {
			return self::default_definition();
		}

		return wp_parse_args(
			$stored,
			self::default_definition()
		);
	}

	/**
	 * Save workflow definition.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $definition Definition array.
	 */
	public static function save_definition( int $post_id, array $definition ): void {
		update_post_meta( $post_id, self::META_DEFINITION, $definition );
	}

	/**
	 * Get editor layout for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, array<string, float>> Map of node id to { x, y }.
	 */
	public static function get_layout( int $post_id ): array {
		$stored = get_post_meta( $post_id, self::META_LAYOUT, true );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		return $stored;
	}

	/**
	 * Save editor layout for a post.
	 *
	 * @param int                                 $post_id Post ID.
	 * @param array<string, array<string, float>> $layout  Map of node id to { x, y }.
	 */
	public static function save_layout( int $post_id, array $layout ): void {
		update_post_meta( $post_id, self::META_LAYOUT, $layout );
	}

	/**
	 * Get the enabled external-execution surfaces for a workflow.
	 *
	 * Unknown or corrupt stored values are stripped; the default is empty
	 * (not exposed).
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, string> Enabled surface slugs.
	 */
	public static function get_surfaces( int $post_id ): array {
		$stored = get_post_meta( $post_id, self::META_SURFACES, true );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		return array_values( array_intersect( $stored, self::AVAILABLE_SURFACES ) );
	}

	/**
	 * Whether one workflow enables an external-execution surface.
	 *
	 * @param int    $post_id Workflow post ID.
	 * @param string $surface Surface slug (self::SURFACE_*).
	 * @return bool
	 */
	public static function has_surface( int $post_id, string $surface ): bool {
		return in_array( $surface, self::get_surfaces( $post_id ), true );
	}

	/**
	 * Save the external-execution surface map for a post.
	 *
	 * An empty map removes the meta key entirely (default state = not
	 * exposed).
	 *
	 * @param int               $post_id  Post ID.
	 * @param array<int, mixed> $surfaces Raw surface slugs (sanitized here).
	 */
	public static function save_surfaces( int $post_id, array $surfaces ): void {
		$clean = self::sanitize_surfaces( $surfaces );

		if ( empty( $clean ) ) {
			delete_post_meta( $post_id, self::META_SURFACES );
			return;
		}

		update_post_meta( $post_id, self::META_SURFACES, $clean );
	}

	/**
	 * Sanitize the external-surface map: list of enabled surface slugs.
	 *
	 * Accepts a string-keyed array, a JSON string (the editor's hidden
	 * field), or anything else (no surfaces). Unknown slugs are dropped;
	 * duplicates collapse. Example input: [ 'rest' ].
	 *
	 * @param mixed $raw Raw surfaces payload.
	 * @return array<int, string> Sanitized surface slugs.
	 */
	public static function sanitize_surfaces( $raw ): array {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array();
		foreach ( $raw as $slug ) {
			$key = is_string( $slug ) ? sanitize_key( $slug ) : '';
			if (
				'' === $key
				|| ! in_array( $key, self::AVAILABLE_SURFACES, true )
				|| in_array( $key, $clean, true )
			) {
				continue;
			}
			$clean[] = $key;
		}

		return $clean;
	}

	/**
	 * Normalize a surfaces request payload from a save request.
	 *
	 * Null means no surface payload was sent — leave the stored map
	 * untouched (older editor builds); present values (array or JSON
	 * string) are sanitized to the enabled slugs, garbage clears.
	 *
	 * @param mixed $raw Raw request value.
	 * @return array<int, string>|null Sanitized surfaces, or null to keep the stored map.
	 */
	public static function decode_surfaces_param( $raw ): ?array {
		if ( null === $raw ) {
			return null;
		}

		return self::sanitize_surfaces( $raw );
	}

	/**
	 * Sanitize editor layout: node id => { x, y } floats. Node ids must be
	 * sanitize_key-shaped (<=40 chars); ids that don't belong to the
	 * definition are pruned later via prune_layout().
	 *
	 * @param mixed $raw Raw layout (array of node id to position).
	 * @return array<string, array<string, float>>
	 */
	public static function sanitize_layout( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array();
		foreach ( $raw as $node_id => $pos ) {
			$id = is_string( $node_id ) ? substr( sanitize_key( $node_id ), 0, 40 ) : '';
			if ( '' === $id ) {
				continue;
			}
			if ( ! is_array( $pos ) || ! isset( $pos['x'], $pos['y'] ) ) {
				continue;
			}
			$clean[ $id ] = array(
				'x' => (float) $pos['x'],
				'y' => (float) $pos['y'],
			);
		}

		return $clean;
	}

	/**
	 * Drop layout entries whose node id is not part of the definition
	 * ('input' plus each step's node id).
	 *
	 * @param array<string, mixed> $layout     Sanitized layout.
	 * @param array<string, mixed> $definition Sanitized definition.
	 * @return array<string, mixed>
	 */
	public static function prune_layout( array $layout, array $definition ): array {
		$valid = array( 'input' => true );

		foreach ( ( $definition['steps'] ?? array() ) as $index => $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$stable = isset( $step['id'] ) && is_string( $step['id'] )
				? substr( sanitize_key( $step['id'] ), 0, 40 )
				: '';
			// Accept both the stable id and the index fallback; the JS side
			// may fall back to 'step-N' when stable ids collide.
			$valid[ 'step-' . $index ] = true;
			if ( '' !== $stable ) {
				$valid[ $stable ] = true;
			}
		}

		return array_intersect_key( $layout, $valid );
	}

	/**
	 * Sanitize definition from request/JSON.
	 *
	 * @param mixed $raw Raw definition (may be a non-array from corrupt meta).
	 * @return array<string, mixed>
	 */
	public static function sanitize_definition( $raw ) {
		if ( ! is_array( $raw ) ) {
			return self::default_definition();
		}

		$definition = self::default_definition();

		if ( isset( $raw['initial_input'] ) ) {
			$definition['initial_input'] = Baton_Input_Sanitizer::sanitize_input_array( $raw['initial_input'] );
		}

		if ( ! isset( $raw['steps'] ) || ! is_array( $raw['steps'] ) ) {
			return $definition;
		}

		foreach ( $raw['steps'] as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$ability = isset( $step['ability'] ) ? sanitize_text_field( (string) $step['ability'] ) : '';
			if ( '' === $ability ) {
				continue;
			}

			$input = Baton_Input_Sanitizer::sanitize_input_array( $step['input'] ?? array() );

			$clean_step = array(
				'ability'             => $ability,
				'input'               => $input,
				'use_previous_output' => ! empty( $step['use_previous_output'] ),
				'input_mappings'      => Baton_Input_Mapper::sanitize_mappings( $step['input_mappings'] ?? array() ),
			);

			// Stable editor node id (optional; runner ignores it). Sanitized
			// string, length-capped, so layout persistence can key off it.
			if ( isset( $step['id'] ) && is_string( $step['id'] ) ) {
				$step_id = substr( sanitize_key( (string) $step['id'] ), 0, 40 );
				if ( '' !== $step_id ) {
					$clean_step['id'] = $step_id;
				}
			}

			// Sanitize optional loop config.
			if ( isset( $step['loop'] ) && is_array( $step['loop'] ) ) {
				$loop_field = isset( $step['loop']['field'] )
					? Baton_Input_Mapper::sanitize_field_name( (string) $step['loop']['field'] )
					: '';

				if ( '' !== $loop_field ) {
					$clean_step['loop'] = array(
						'field' => $loop_field,
					);
				}
			}

			$definition['steps'][] = $clean_step;
		}

		return $definition;
	}
}
