<?php
/**
 * External workflow execution via core's Abilities REST route.
 *
 * @package Baton
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Distinguishes runs that arrive through core's Abilities run route
 * (wp-abilities/v1/abilities/{name}/run) from Baton's own admin surfaces
 * (the baton/v1 run endpoint and the runner's nested-workflow path, both of
 * which call Baton_Workflow_Runner::run() directly and never go through the
 * ability's execute callback), and writes a saved-values log row for every
 * such run.
 *
 * The REST channel is observed (core owns the run controller): the
 * rest_request_before_callbacks / rest_request_after_callbacks filters
 * bracket the window (permission callback + handler) in which the workflow
 * ability executes. The marker is keyed by request object, so concurrent or
 * nested internal dispatches in the same PHP process cannot bleed into each
 * other and sequential requests clean up after themselves.
 *
 * Baton-owned channels that call the runner directly (B3: WP-CLI's
 * `wp baton run`) log through the public log_*_run() methods instead — no
 * dispatch observation needed because the calling code is Baton's own.
 */
final class Baton_External_Execution {

	/**
	 * Core REST route for the Abilities run endpoint. The ability name may
	 * itself contain slashes (baton/workflow-{post_id}).
	 */
	private const RUN_ROUTE_PATTERN = '#^wp-abilities/v1/abilities/(?P<name>[a-zA-Z0-9\-\/]+?)/run$#';

	/**
	 * Workflow ability names currently executing under the core abilities
	 * run route, keyed by request object id.
	 *
	 * @var array<int, string>
	 */
	private static $rest_dispatching = array();

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_filter( 'rest_request_before_callbacks', array( self::class, 'mark_rest_dispatching' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( self::class, 'unmark_rest_dispatching' ), 10, 3 );
	}

	/**
	 * Flag a matched request that runs a Baton workflow ability's run route.
	 *
	 * Fires for every matched REST request — even when the permission
	 * callback later refuses — so the marker is cleared by the paired
	 * after-callbacks filter in every dispatch outcome.
	 *
	 * @param WP_REST_Response|WP_Error|mixed $response Current response (untouched).
	 * @param array<string, mixed>            $handler  Matched route handler.
	 * @param WP_REST_Request                 $request  Request being dispatched.
	 * @return mixed Unchanged response.
	 */
	public static function mark_rest_dispatching( $response, $handler, $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return $response;
		}

		$ability_name = self::workflow_ability_name_from_route( $request );

		if ( null !== $ability_name ) {
			self::$rest_dispatching[ spl_object_id( $request ) ] = $ability_name;
		}

		return $response;
	}

	/**
	 * Clear the per-request flag.
	 *
	 * @param WP_REST_Response|WP_Error|mixed $response Response after callbacks (untouched).
	 * @param array<string, mixed>            $handler  Matched route handler.
	 * @param WP_REST_Request|mixed           $request  Request that finished.
	 * @return mixed Unchanged response.
	 */
	public static function unmark_rest_dispatching( $response, $handler, $request ) {
		if ( $request instanceof WP_REST_Request ) {
			unset( self::$rest_dispatching[ spl_object_id( $request ) ] );
		}

		return $response;
	}

	/**
	 * Whether the given ability is currently executing under the core
	 * Abilities REST run route (Baton's admin run and nested-workflow paths
	 * are never flagged).
	 *
	 * @param string $ability_name Ability name (baton/workflow-{post_id}).
	 * @return bool
	 */
	public static function is_rest_triggered( string $ability_name ): bool {
		return in_array( $ability_name, self::$rest_dispatching, true );
	}

	/**
	 * Workflow ability name executed by a matched core run route, if any.
	 *
	 * @param WP_REST_Request $request Request being dispatched.
	 * @return string|null Ability name or null for other routes/abilities.
	 */
	private static function workflow_ability_name_from_route( WP_REST_Request $request ): ?string {
		$route = trim( (string) $request->get_route(), '/' );

		if ( ! preg_match( self::RUN_ROUTE_PATTERN, $route, $matches ) ) {
			return null;
		}

		$post_id = Baton_Workflow_Abilities::parse_workflow_ability_id( (string) $matches['name'] );

		if ( null === $post_id ) {
			return null;
		}

		return Baton_Workflow_Abilities::get_ability_name( $post_id );
	}

	/**
	 * Log an externally triggered (core REST) run as a saved-values log row.
	 *
	 * Additive only: the run report the caller receives is not reshaped and
	 * write failures are ignored (the run result still reaches the caller).
	 * Admin-scoped input is stored sanitized like the run panel does.
	 *
	 * @param int                  $post_id Workflow post ID.
	 * @param mixed                $input   Caller input.
	 * @param array<string, mixed> $report  Runner report (run_id, success, error…).
	 */
	public static function log_rest_run( int $post_id, $input, array $report ): void {
		self::log_external_run( Baton_Workflow_CPT::SURFACE_REST, $post_id, $input, $report );
	}

	/**
	 * Log a WP-CLI (`wp baton run`) run as a saved-values log row.
	 *
	 * B3: the CLI command calls the runner directly (no REST dispatch), so
	 * the CLI channel logs through this explicit path using the same row
	 * shape as REST external runs — value = { trigger, success, input,
	 * error? }, label "External run". Write failures are silent, same as
	 * the REST path: the run result still reaches the CLI caller.
	 *
	 * @param int                  $post_id Workflow post ID.
	 * @param mixed                $input   Caller input.
	 * @param array<string, mixed> $report  Runner report (run_id, success, error…).
	 */
	public static function log_cli_run( int $post_id, $input, array $report ): void {
		self::log_external_run( Baton_Workflow_CPT::SURFACE_CLI, $post_id, $input, $report );
	}

	/**
	 * Shared external-run log-row writer (REST + CLI channels today).
	 *
	 * @param string               $trigger Channel/surface slug ('rest', 'cli').
	 * @param int                  $post_id Workflow post ID.
	 * @param mixed                $input   Caller input.
	 * @param array<string, mixed> $report  Runner report (run_id, success, error…).
	 */
	private static function log_external_run( string $trigger, int $post_id, $input, array $report ): void {
		$value = array(
			'trigger' => $trigger,
			'success' => ! empty( $report['success'] ),
			'input'   => is_array( $input ) ? Baton_Input_Sanitizer::sanitize_input_array( $input ) : $input,
		);

		if ( empty( $report['success'] ) && isset( $report['error'] ) ) {
			$value['error'] = (string) $report['error'];
		}

		Baton_Saved_Values::append(
			Baton_Saved_Values::KIND_LOG,
			$post_id,
			(string) ( $report['run_id'] ?? '' ),
			__( 'External run', 'baton' ),
			$value
		);
	}
}
