<?php
/**
 * REST API controller for admin workflow endpoints.
 *
 * @package Baton
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes for saving and running workflows, replacing the legacy
 * admin-ajax actions (baton_save_workflow, baton_run).
 *
 * Authentication rides the logged-in cookie plus a wp_rest nonce sent in
 * the X-WP-Nonce header, matching core's cookie-auth REST semantics:
 * cookie-authenticated requests must present a valid nonce, while other
 * schemes (application passwords, etc.) need none.
 */
final class Baton_Rest_Controller {

	/**
	 * REST namespace for all Baton endpoints.
	 */
	public const ROUTE_NAMESPACE = 'baton/v1';

	/**
	 * Route name for the workflow collection.
	 */
	public const ROUTE_BASE = 'workflows';

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 *
	 * Fires on rest_api_init, which rest_get_server() also triggers the first
	 * time a REST request is dispatched, so routes always exist when needed.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/' . self::ROUTE_BASE,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'handle_save' ),
					'permission_callback' => array( self::class, 'check_permissions' ),
				),
				array(
					// Command Palette listing: published workflows only,
					// {id, title} pairs — nothing else disclosed.
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'handle_list' ),
					'permission_callback' => array( self::class, 'check_permissions' ),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/' . self::ROUTE_BASE . '/(?P<id>\d+)/run',
			array(
				'args' => array(
					'id' => array(
						'description' => __( 'Unique identifier for the workflow.', 'baton' ),
						'type'        => 'integer',
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'handle_run' ),
					'permission_callback' => array( self::class, 'check_permissions' ),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/' . self::ROUTE_BASE . '/(?P<id>\d+)/viewer',
			array(
				'args' => array(
					'id' => array(
						'description' => __( 'Unique identifier for the workflow.', 'baton' ),
						'type'        => 'integer',
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'handle_viewer' ),
					'permission_callback' => array( self::class, 'check_permissions' ),
				),
			)
		);
	}

	/**
	 * List published workflows for the admin Command Palette.
	 *
	 * Deliberately thin: [{id, title}] for every published workflow — no
	 * schemas, definitions, or exposure maps are disclosed here (the
	 * palette is the admin user acting, not an external channel, so no
	 * surface filtering applies).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_list( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- REST dispatch always passes the request.
		$posts = get_posts(
			array(
				'post_type'      => Baton_Workflow_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$items = array();

		foreach ( $posts as $post ) {
			$items[] = array(
				'id'    => (int) $post->ID,
				'title' => (string) $post->post_title,
			);
		}

		return rest_ensure_response( $items );
	}

	/**
	 * URL of the save endpoint.
	 *
	 * @return string
	 */
	public static function save_url(): string {
		return rest_url( self::ROUTE_NAMESPACE . '/' . self::ROUTE_BASE );
	}

	/**
	 * URL of the run endpoint for one workflow.
	 *
	 * @param int $post_id Workflow post ID.
	 * @return string
	 */
	public static function run_url( int $post_id ): string {
		return rest_url( self::ROUTE_NAMESPACE . '/' . self::ROUTE_BASE . '/' . absint( $post_id ) . '/run' );
	}

	/**
	 * URL of the saved-values viewer endpoint for one workflow.
	 *
	 * @param int $post_id Workflow post ID.
	 * @return string
	 */
	public static function viewer_url( int $post_id ): string {
		return rest_url( self::ROUTE_NAMESPACE . '/' . self::ROUTE_BASE . '/' . absint( $post_id ) . '/viewer' );
	}

	/**
	 * Shared permission callback for both routes.
	 *
	 * Requires manage_options. For cookie-authenticated requests also
	 * requires a valid wp_rest nonce (the REST equivalent of the AJAX
	 * nonce check); requests through other auth schemes skip it, as core
	 * itself does.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function check_permissions( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'baton_rest_forbidden',
				__( 'Insufficient permissions.', 'baton' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		global $wp_rest_auth_cookie;

		if ( true === $wp_rest_auth_cookie ) {
			$nonce = $request->get_header( 'X-WP-Nonce' );

			if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return new WP_Error(
					'rest_cookie_invalid_nonce',
					__( 'Invalid or missing REST nonce.', 'baton' ),
					array( 'status' => 403 )
				);
			}
		}

		return true;
	}

	/**
	 * Save a workflow (insert or update) from the editor's JSON payload.
	 *
	 * Accepts the same fields as the classic form POST and returns the saved
	 * post ID (inserting it for new workflows) plus its edit URL.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_save( WP_REST_Request $request ) {
		$title_param   = $request->get_param( 'workflow_title' );
		$excerpt_param = $request->get_param( 'workflow_excerpt' );
		$title         = sanitize_text_field( is_string( $title_param ) ? $title_param : '' );
		$excerpt       = sanitize_textarea_field( is_string( $excerpt_param ) ? $excerpt_param : '' );

		$definition_param = $request->get_param( 'workflow_definition' );

		// REST params are not magic-quoted; no unslashing needed.
		if ( is_array( $definition_param ) ) {
			$decoded = $definition_param;
		} elseif ( null === $definition_param ) {
			// Same default as the classic form: a valid empty definition.
			$decoded = array();
		} else {
			$decoded = json_decode( (string) $definition_param, true );
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'baton_rest_invalid_definition',
				__( 'Invalid workflow definition JSON.', 'baton' ),
				array( 'status' => 400 )
			);
		}

		$save_args = array(
			'title'       => $title,
			'excerpt'     => $excerpt,
			'definition'  => $decoded,
			'layout_raw'  => self::layout_raw_from( $request ),
			'workflow_id' => absint( $request->get_param( 'workflow_id' ) ),
		);

		// B2: per-workflow external-execution surface map (array in the JSON
		// body). Absent (older editor builds) = keep the stored map.
		if ( $request->has_param( 'external_surfaces' ) ) {
			$save_args['external_surfaces'] = $request->get_param( 'external_surfaces' );
		}

		$result = Baton_Admin::save_workflow_post( $save_args );

		if ( is_wp_error( $result ) ) {
			return self::save_error_to_rest( $result );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Map a save_workflow_post() error to a REST-appropriate status code.
	 *
	 * @param WP_Error $error Error from Baton_Admin::save_workflow_post().
	 * @return WP_Error
	 */
	private static function save_error_to_rest( WP_Error $error ): WP_Error {
		$statuses = array(
			'baton_save_missing_title'      => 400,
			'baton_save_workflow_not_found' => 404,
			'baton_save_update_failed'      => 500,
			'baton_save_insert_failed'      => 500,
		);

		$code = $error->get_error_code();

		return new WP_Error(
			$code,
			$error->get_error_message(),
			array( 'status' => $statuses[ $code ] ?? 500 )
		);
	}

	/**
	 * Run one workflow by ID and return the step-by-step report.
	 *
	 * A completed run reports in-band: the body is the runner report with
	 * success true/false, steps[], and error on failure. HTTP error codes
	 * are reserved for requests that never ran (unknown ID, bad JSON, etc.).
	 *
	 * An optional `trigger` tag records the run's origin. Today only
	 * "palette" (the Command Palette's auto-run on the edit screen) logs a
	 * row here — plain admin runs stay unlogged, and any other value is
	 * ignored (REST/CLI channel rows are written by their own paths).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_run( WP_REST_Request $request ) {
		$workflow_id = absint( $request['id'] );

		$post = get_post( $workflow_id );
		if ( ! $post || Baton_Workflow_CPT::POST_TYPE !== $post->post_type ) {
			return new WP_Error(
				'baton_rest_workflow_not_found',
				__( 'Workflow not found.', 'baton' ),
				array( 'status' => 404 )
			);
		}

		$definition = Baton_Workflow_CPT::get_definition( $workflow_id );

		$initial_param = $request->get_param( 'initial_input' );

		if ( is_array( $initial_param ) ) {
			$definition['initial_input'] = Baton_Input_Sanitizer::sanitize_input_array( $initial_param );
		} elseif ( is_string( $initial_param ) && '' !== $initial_param ) {
			$initial = json_decode( $initial_param, true );
			if ( null === $initial && JSON_ERROR_NONE !== json_last_error() ) {
				return new WP_Error(
					'baton_rest_invalid_input',
					__( 'Invalid workflow input JSON.', 'baton' ),
					array( 'status' => 400 )
				);
			}
			if ( is_array( $initial ) ) {
				$definition['initial_input'] = Baton_Input_Sanitizer::sanitize_input_array( $initial );
			}
		}

		$trigger_param = $request->get_param( 'trigger' );
		$trigger       = is_string( $trigger_param ) ? sanitize_key( $trigger_param ) : '';

		$report = Baton_Workflow_Runner::run( $definition, $workflow_id );

		if ( 'palette' === $trigger ) {
			Baton_External_Execution::log_palette_run( $workflow_id, $definition['initial_input'] ?? array(), $report );
		}

		return rest_ensure_response( $report );
	}

	/**
	 * Return the server-rendered saved-values panel for one workflow.
	 *
	 * Powers the run panel's live refresh: after any completed run the
	 * client swaps this partial into the viewer without a page reload.
	 * Rendering and escaping are Baton_Run_Viewer's own (no markup
	 * divergence); permission rides the shared check_permissions().
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_viewer( WP_REST_Request $request ) {
		$workflow_id = absint( $request['id'] );

		$post = get_post( $workflow_id );
		if ( ! $post || Baton_Workflow_CPT::POST_TYPE !== $post->post_type ) {
			return new WP_Error(
				'baton_rest_workflow_not_found',
				__( 'Workflow not found.', 'baton' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'html' => Baton_Run_Viewer::viewer_markup( $workflow_id ),
			)
		);
	}

	/**
	 * Normalize the layout payload to the raw JSON string persist_workflow().
	 *
	 * Accepts a JSON string (the editor's serialized hidden field) or an
	 * already-decoded object/array.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private static function layout_raw_from(
		WP_REST_Request $request
	): string {
		$layout_param = $request->get_param( 'baton_editor_layout' );

		if ( is_array( $layout_param ) ) {
			return (string) wp_json_encode( $layout_param );
		}

		if ( is_string( $layout_param ) && '' !== $layout_param ) {
			return $layout_param;
		}

		return '{}';
	}
}
