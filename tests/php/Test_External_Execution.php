<?php
/**
 * Tests for B2 external workflow execution (REST).
 *
 * @package Baton
 */

declare( strict_types=1 );

/**
 * Exposure-meta sanitization, opt-in workflow-ability registration
 * (show_in_rest), core abilities REST run route behavior, and the
 * external-run log row.
 *
 * Core contract pinned from WP 6.9 source
 * (wp-includes/rest-api/endpoints/class-wp-rest-abilities-v1-run-controller.php):
 *
 * - The run route is POST-only for Baton workflow abilities (annotations
 *   readonly/destructive/idempotent all false; other methods are 405).
 * - The route's permission callback 404s (rest_ability_not_found) any
 *   ability whose meta show_in_rest is falsy: an unexposed workflow is
 *   indistinguishable from a nonexistent ability — zero exposure.
 * - Input rides in the POST JSON body under "input", is normalized and
 *   validated against the ability's input schema (failure: 400
 *   ability_invalid_input) before the ability's own permission callback
 *   runs (failure: rest_ability_cannot_execute with 401 anonymous / 403
 *   logged in).
 * - The handler wraps WP_Ability::execute() and returns the raw
 *   execute-callback value via rest_ensure_response(); for a Baton workflow
 *   that is the last step's output. A WP_Error from execution maps to its
 *   status data (none set → 500) without leaking the permission error.
 */
class Test_External_Execution extends WP_UnitTestCase {

	use Baton_Test_Table_State_Trait;

	/**
	 * Administrator user ID (the only role allowed to run workflows).
	 *
	 * @var int
	 */
	private int $admin_user_id = 0;

	/**
	 * Runs before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'Abilities API is not available in this WordPress version.' );
		}

		$this->drop_baton_table();
		Baton_Saved_Values::install();

		if ( ! function_exists( 'baton_tests_ensure_abilities_registered' ) ) {
			require_once dirname( __DIR__ ) . '/fixtures/test-abilities.php';
		}

		$registration_error = baton_tests_ensure_abilities_registered();
		if ( '' !== $registration_error ) {
			$this->fail( $registration_error );
		}

		$this->admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );
	}

	/**
	 * Runs after each test.
	 */
	public function tear_down(): void {
		$this->drop_baton_table();

		parent::tear_down();
	}

	// ──────────────────────────────────────────────────────────────
	// Exposure meta sanitization (Baton_Workflow_CPT)
	// ──────────────────────────────────────────────────────────────

	public function test_sanitize_surfaces_keeps_only_known_slugs(): void {
		$this->assertSame(
			array( 'rest', 'cli', 'mcp' ),
			Baton_Workflow_CPT::sanitize_surfaces( array( 'rest', ' cli ', 'mcp', 12, 'rest', 'cli' ) )
		);

		// Unknown slugs are still dropped.
		$this->assertSame(
			array( 'rest' ),
			Baton_Workflow_CPT::sanitize_surfaces( array( 'rest', 'unknown-slug' ) )
		);
	}

	public function test_sanitize_surfaces_accepts_json_string_payload(): void {
		$this->assertSame( array( 'rest' ), Baton_Workflow_CPT::sanitize_surfaces( '["rest"]' ) );
		$this->assertSame( array( 'cli' ), Baton_Workflow_CPT::sanitize_surfaces( '["cli"]' ) );
		$this->assertSame( array( 'mcp' ), Baton_Workflow_CPT::sanitize_surfaces( '["mcp"]' ) );
		$this->assertSame( array( 'rest', 'cli' ), Baton_Workflow_CPT::sanitize_surfaces( '["rest","cli"]' ) );
	}

	public function test_sanitize_surfaces_rejects_garbage_payloads(): void {
		$this->assertSame( array(), Baton_Workflow_CPT::sanitize_surfaces( 'not json' ) );
		$this->assertSame( array(), Baton_Workflow_CPT::sanitize_surfaces( 12 ) );
		$this->assertSame( array(), Baton_Workflow_CPT::sanitize_surfaces( null ) );
		$this->assertSame( array(), Baton_Workflow_CPT::sanitize_surfaces( array() ) );
	}

	public function test_get_surfaces_roundtrips_saved_map(): void {
		$this->expect_save_time_ability_notices();
		$post_id = $this->create_published_workflow( array( 'rest' ) );

		$this->assertSame( array( 'rest' ), Baton_Workflow_CPT::get_surfaces( $post_id ) );
		$this->assertTrue( Baton_Workflow_CPT::has_surface( $post_id, 'rest' ) );
		$this->assertFalse( Baton_Workflow_CPT::has_surface( $post_id, 'cli' ) );

		Baton_Workflow_CPT::save_surfaces( $post_id, array( 'rest', 'cli' ) );

		$this->assertSame( array( 'rest', 'cli' ), Baton_Workflow_CPT::get_surfaces( $post_id ) );
		$this->assertTrue( Baton_Workflow_CPT::has_surface( $post_id, 'cli' ) );

		wp_delete_post( $post_id, true );
	}

	public function test_get_surfaces_is_empty_without_meta(): void {
		$this->expect_save_time_ability_notices();
		$post_id = $this->create_published_workflow();

		$this->assertSame( array(), Baton_Workflow_CPT::get_surfaces( $post_id ) );
		$this->assertFalse( (bool) get_post_meta( $post_id, Baton_Workflow_CPT::META_SURFACES, true ) );

		wp_delete_post( $post_id, true );
	}

	public function test_save_surfaces_clears_meta_key_when_empty(): void {
		$this->expect_save_time_ability_notices();
		$post_id = $this->create_published_workflow( array( 'rest' ) );

		$this->assertSame( array( 'rest' ), Baton_Workflow_CPT::get_surfaces( $post_id ) );

		Baton_Workflow_CPT::save_surfaces( $post_id, array() );

		$this->assertFalse( (bool) get_post_meta( $post_id, Baton_Workflow_CPT::META_SURFACES, true ) );
		$this->assertSame( array(), Baton_Workflow_CPT::get_surfaces( $post_id ) );

		wp_delete_post( $post_id, true );
	}

	public function test_get_surfaces_ignores_corrupt_stored_value(): void {
		$this->expect_save_time_ability_notices();
		$post_id = $this->create_published_workflow( array( 'rest' ) );
		update_post_meta( $post_id, Baton_Workflow_CPT::META_SURFACES, 'corrupt' );

		$this->assertSame( array(), Baton_Workflow_CPT::get_surfaces( $post_id ) );

		wp_delete_post( $post_id, true );
	}

	/**
	 * The shared save helper: a surfaces payload persists the map, an absent
	 * payload keeps the stored map, and an explicit empty array clears it.
	 */
	public function test_save_workflow_post_keeps_map_when_absent_and_clears_when_empty(): void {
		$this->expect_save_time_ability_notices();
		$post_id = $this->create_published_workflow();

		$base_args = array(
			'title'       => 'Surface persistence',
			'excerpt'     => '',
			'definition'  => array( 'steps' => array( array( 'ability' => 'baton-test/echo' ) ) ),
			'layout_raw'  => '{}',
			'workflow_id' => $post_id,
		);

		// 1) Persist the map.
		$result = Baton_Admin::save_workflow_post( $base_args + array( 'external_surfaces' => array( 'rest' ) ) );
		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( array( 'rest' ), Baton_Workflow_CPT::get_surfaces( $post_id ) );

		// 2) Absent payload: surfaces map is preserved (older editor builds).
		$this->expect_save_time_ability_notices();
		Baton_Admin::save_workflow_post( $base_args );
		$this->assertSame( array( 'rest' ), Baton_Workflow_CPT::get_surfaces( $post_id ) );

		// 3) Explicit empty array: clears the map (default = not exposed).
		$this->expect_save_time_ability_notices();
		Baton_Admin::save_workflow_post( $base_args + array( 'external_surfaces' => array() ) );
		$this->assertSame( array(), Baton_Workflow_CPT::get_surfaces( $post_id ) );

		// 4) Absent stays cleared.
		$this->expect_save_time_ability_notices();
		Baton_Admin::save_workflow_post( $base_args );
		$this->assertSame( array(), Baton_Workflow_CPT::get_surfaces( $post_id ) );

		wp_delete_post( $post_id, true );
	}

	// ──────────────────────────────────────────────────────────────
	// Registration: show_in_rest flips only with the REST surface
	// ──────────────────────────────────────────────────────────────

	public function test_registered_workflow_ability_defaults_to_rest_hidden(): void {
		$this->expect_save_time_ability_notices();
		$post_id = $this->create_published_workflow();

		$this->expect_reregistration_notices();
		do_action( 'wp_abilities_api_init' );

		$ability = wp_get_ability( Baton_Workflow_Abilities::get_ability_name( $post_id ) );
		$this->assertInstanceOf( WP_Ability::class, $ability );
		$this->assertFalse( $ability->get_meta_item( 'show_in_rest' ) );
		// Annotations are unchanged by exposure handling.
		$this->assertSame(
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			),
			$ability->get_meta_item( 'annotations' )
		);

		wp_delete_post( $post_id, true );
	}

	public function test_registered_workflow_ability_flips_show_in_rest_with_rest_surface(): void {
		$this->expect_save_time_ability_notices();
		$post_id = $this->create_published_workflow( array( 'rest' ) );

		$this->expect_reregistration_notices();
		do_action( 'wp_abilities_api_init' );

		$ability = wp_get_ability( Baton_Workflow_Abilities::get_ability_name( $post_id ) );
		$this->assertInstanceOf( WP_Ability::class, $ability );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest' ) );

		// Toggling off again re-registers hidden.
		$this->expect_reregistration_notices();
		Baton_Workflow_CPT::save_surfaces( $post_id, array() );
		do_action( 'wp_abilities_api_init' );

		$ability = wp_get_ability( Baton_Workflow_Abilities::get_ability_name( $post_id ) );
		$this->assertInstanceOf( WP_Ability::class, $ability );
		$this->assertFalse( $ability->get_meta_item( 'show_in_rest' ) );

		wp_delete_post( $post_id, true );
	}

	// ──────────────────────────────────────────────────────────────
	// Core abilities REST run route: default-off = no exposure
	// ──────────────────────────────────────────────────────────────

	public function test_unexposed_workflow_is_not_found_on_core_run_route(): void {
		$this->expect_save_time_ability_notices();
		$post_id = $this->create_published_workflow();

		$this->expect_reregistration_notices();
		do_action( 'wp_abilities_api_init' );

		// Anonymous: still a plain 404.
		wp_set_current_user( 0 );
		$response = $this->dispatch_core_run( Baton_Workflow_Abilities::get_ability_name( $post_id ) );
		$this->assertSame( 404, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'rest_ability_not_found', $body['code'] );
		$this->assertSame( 'Ability not found.', $body['message'] );

		// Admin: identical 404.
		wp_set_current_user( $this->admin_user_id );
		$response = $this->dispatch_core_run( Baton_Workflow_Abilities::get_ability_name( $post_id ) );
		$this->assertSame( 404, $response->get_status() );

		// Nothing executed, so nothing is logged.
		$this->assertSame( array(), Baton_Saved_Values::rows_for_workflow( $post_id ) );

		wp_delete_post( $post_id, true );
	}

	public function test_unexposed_workflow_is_missing_from_abilities_list_route(): void {
		$this->expect_save_time_ability_notices();
		$post_id = $this->create_published_workflow();

		$this->expect_reregistration_notices();
		do_action( 'wp_abilities_api_init' );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringNotContainsString(
			Baton_Workflow_Abilities::get_ability_name( $post_id ),
			(string) wp_json_encode( $response->get_data() )
		);

		// Single-item lookup is a 404 too.
		$response = rest_do_request(
			new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/' . Baton_Workflow_Abilities::get_ability_name( $post_id ) )
		);
		$this->assertSame( 404, $response->get_status() );

		wp_delete_post( $post_id, true );
	}

	public function test_unexposed_workflow_ability_permission_callback_still_manage_options(): void {
		$this->expect_save_time_ability_notices();
		$post_id = $this->create_published_workflow();

		$this->expect_reregistration_notices();
		do_action( 'wp_abilities_api_init' );

		$ability = wp_get_ability( Baton_Workflow_Abilities::get_ability_name( $post_id ) );
		$this->assertInstanceOf( WP_Ability::class, $ability );

		// Anonymous: denied.
		wp_set_current_user( 0 );
		$this->assertFalse( (bool) $ability->check_permissions( array( 'who' => 'rest' ) ) );

		// Non-admin: denied.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertFalse( (bool) $ability->check_permissions( array( 'who' => 'rest' ) ) );

		// Admin: allowed (the only thing B2 changes is show_in_rest).
		wp_set_current_user( $this->admin_user_id );
		$this->assertTrue( (bool) $ability->check_permissions( array( 'who' => 'rest' ) ) );

		wp_delete_post( $post_id, true );
	}

	// ──────────────────────────────────────────────────────────────
	// Core abilities REST run route: opt-in run + external log row
	// ──────────────────────────────────────────────────────────────

	public function test_exposed_workflow_runs_via_core_rest_and_logs_external_row(): void {
		$this->expect_save_time_ability_notices();
		$post_id = $this->create_published_workflow( array( 'rest' ) );

		$this->expect_reregistration_notices();
		do_action( 'wp_abilities_api_init' );

		$response = $this->dispatch_core_run(
			Baton_Workflow_Abilities::get_ability_name( $post_id ),
			array( 'who' => 'rest' )
		);

		// Success: the body is the raw execute-callback value — the last
		// step's output (external input merged into step 1).
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'who'   => 'rest',
				'hello' => 'world',
			),
			$response->get_data()
		);

		// Exactly one log row: the external run.
		$rows = Baton_Saved_Values::rows_for_workflow( $post_id );
		$this->assertCount( 1, $rows );

		$row = $rows[0];
		$this->assertSame( 'log', $row['kind'] );
		$this->assertSame( $post_id, $row['workflow_id'] );
		$this->assertSame( 'External run', $row['label'] );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			(string) $row['run_id']
		);
		$this->assertSame( '', $row['step_id'] );
		$this->assertSame(
			array(
				'trigger' => 'rest',
				'success' => true,
				'input'   => array( 'who' => 'rest' ),
			),
			$row['value']
		);

		wp_delete_post( $post_id, true );
	}

	public function test_failed_external_run_still_logs_row(): void {
		$this->expect_save_time_ability_notices();
		$post_id = $this->create_published_workflow( array( 'rest' ) );
		Baton_Workflow_CPT::save_definition(
			$post_id,
			array(
				'steps' => array(
					array(
						'ability' => 'baton-test/not-a-real-ability',
						'input'   => array(),
					),
				),
			)
		);

		$this->expect_reregistration_notices();
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );
		do_action( 'wp_abilities_api_init' );

		$response = $this->dispatch_core_run( Baton_Workflow_Abilities::get_ability_name( $post_id ) );

		$this->assertSame( 500, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'baton_workflow_failed', $body['code'] );

		$rows = Baton_Saved_Values::rows_for_workflow( $post_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'log', $rows[0]['kind'] );
		$this->assertSame( 'External run', $rows[0]['label'] );
		$this->assertFalse( $rows[0]['value']['success'] );
		$this->assertStringContainsString( 'not-a-real-ability', (string) $rows[0]['value']['error'] );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Core permission mapping: logged-in non-admin 403, anonymous 401 —
	 * the ability permission_callback (manage_options) is unchanged.
	 */
	public function test_core_rest_run_permission_still_requires_manage_options(): void {
		$this->expect_save_time_ability_notices();
		$post_id = $this->create_published_workflow( array( 'rest' ) );

		$this->expect_reregistration_notices();
		do_action( 'wp_abilities_api_init' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$response = $this->dispatch_core_run( Baton_Workflow_Abilities::get_ability_name( $post_id ), array( 'who' => 'x' ) );
		$this->assertSame( 403, $response->get_status(), 'Logged-in non-admin must be 403.' );
		$this->assertSame( 'rest_ability_cannot_execute', $response->get_data()['code'] );

		wp_set_current_user( 0 );
		$response = $this->dispatch_core_run( Baton_Workflow_Abilities::get_ability_name( $post_id ), array( 'who' => 'x' ) );
		$this->assertSame( 401, $response->get_status(), 'Anonymous must be 401.' );
		$this->assertSame( 'rest_ability_cannot_execute', $response->get_data()['code'] );

		// Refused runs produced no log row (never executed).
		$this->assertSame( array(), Baton_Saved_Values::rows_for_workflow( $post_id ) );

		// Restore admin for teardown symmetry.
		wp_set_current_user( $this->admin_user_id );
		wp_delete_post( $post_id, true );
	}

	// ──────────────────────────────────────────────────────────────
	// Baton's own surfaces never produce the external log row
	// ──────────────────────────────────────────────────────────────

	public function test_core_rest_run_rejects_body_without_input_object(): void {
		// Pinning core behavior: Baton's workflow input schema is a
		// type=object schema without nullable, so a POST without an "input"
		// object fails route-side validation (400) and never executes.
		$this->expect_save_time_ability_notices();
		$post_id = $this->create_published_workflow( array( 'rest' ) );

		$this->expect_reregistration_notices();
		do_action( 'wp_abilities_api_init' );

		$request = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/' . Baton_Workflow_Abilities::get_ability_name( $post_id ) . '/run' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'other' => 'no input key' ) ) );

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'ability_invalid_input', $response->get_data()['code'] );
		$this->assertSame( array(), Baton_Saved_Values::rows_for_workflow( $post_id ) );

		wp_delete_post( $post_id, true );
	}

	public function test_baton_run_endpoint_and_direct_execution_do_not_log_external_row(): void {
		$this->expect_save_time_ability_notices();
		$post_id = $this->create_published_workflow( array( 'rest' ) );
		$this->expect_reregistration_notices();
		do_action( 'wp_abilities_api_init' );

		// The baton/v1 admin run endpoint (non-cookie auth simulation: no
		// nonce needed; the current user is an admin with manage_options).
		$this->set_rest_cookie_auth( null );
		$response = rest_do_request( new WP_REST_Request( 'POST', '/baton/v1/workflows/' . $post_id . '/run' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
		$this->assertCount( 0, Baton_Saved_Values::rows_for_workflow( $post_id ) );

		// Direct execute-callback execution (any non-REST caller).
		$result = Baton_Workflow_Abilities::execute_workflow_ability( $post_id, array( 'who' => 'cli' ) );
		$this->assertIsArray( $result );
		$this->assertCount( 0, Baton_Saved_Values::rows_for_workflow( $post_id ) );

		// Restore the default (no cookie-auth simulation) for symmetry.
		$this->set_rest_cookie_auth( null );
		unset( $GLOBALS['wp_rest_auth_cookie'] );

		wp_delete_post( $post_id, true );
	}

	public function test_nested_inner_workflow_run_is_not_logged_as_external(): void {
		$this->expect_save_time_ability_notices();
		$inner_id = $this->create_published_workflow( array( 'rest' ) );

		$this->expect_reregistration_notices();
		do_action( 'wp_abilities_api_init' );

		// An outer workflow that nests the inner workflow.
		$this->expect_save_time_ability_notices();
		$outer_id = self::factory()->post->create(
			array(
				'post_type'   => Baton_Workflow_CPT::POST_TYPE,
				'post_title'  => 'Outer with nested',
				'post_status' => 'publish',
			)
		);
		Baton_Workflow_CPT::save_definition(
			$outer_id,
			array(
				'steps' => array(
					array(
						'ability' => Baton_Workflow_Abilities::get_ability_name( $inner_id ),
						'input'   => array(),
					),
				),
			)
		);
		Baton_Workflow_CPT::save_surfaces( $outer_id, array( 'rest' ) );

		// Re-fire so the outer (and inner) workflow ability registers.
		$this->expect_reregistration_notices();
		do_action( 'wp_abilities_api_init' );

		// Run the OUTER workflow through the core REST route.
		$response = $this->dispatch_core_run( Baton_Workflow_Abilities::get_ability_name( $outer_id ) );
		$this->assertSame( 200, $response->get_status() );

		// The outer (externally triggered) run is logged exactly once.
		$this->assertCount( 1, Baton_Saved_Values::rows_for_workflow( $outer_id ) );

		// The nested inner run rides the runner directly — no external row.
		$this->assertCount( 0, Baton_Saved_Values::rows_for_workflow( $inner_id ) );

		wp_delete_post( $outer_id, true );
		wp_delete_post( $inner_id, true );
	}

	// ──────────────────────────────────────────────────────────────
	// Helpers
	// ──────────────────────────────────────────────────────────────

	/**
	 * Create a published workflow post with a default echo step.
	 *
	 * @param array<int, string>   $surfaces   Surface slugs to save ('rest' to expose).
	 * @param array<string, mixed> $definition Optional definition override.
	 * @return int Post ID.
	 */
	private function create_published_workflow( array $surfaces = array(), array $definition = array() ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => Baton_Workflow_CPT::POST_TYPE,
				'post_title'  => 'Exposed workflow',
				'post_status' => 'publish',
			)
		);

		if ( empty( $definition ) ) {
			$definition = array(
				'steps' => array(
					array(
						'ability' => 'baton-test/echo',
						'input'   => array( 'hello' => 'world' ),
					),
				),
			);
		}

		Baton_Workflow_CPT::save_definition( $post_id, $definition );

		if ( ! empty( $surfaces ) ) {
			Baton_Workflow_CPT::save_surfaces( $post_id, $surfaces );
		}

		return $post_id;
	}

	/**
	 * Dispatch a POST to core's abilities run route for one ability.
	 *
	 * Mirrors the real REST flow: JSON body with the "input" parameter.
	 * Baton's workflow input schema is type object without nullable, so core
	 * route validation rejects bodies without an input object (400
	 * ability_invalid_input) — callers always send an input object.
	 *
	 * @param string              $ability_name Ability name (baton/workflow-{id}).
	 * @param array<string, mixed> $input       Input payload.
	 * @return WP_REST_Response
	 */
	private function dispatch_core_run( string $ability_name, array $input = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/' . $ability_name . '/run' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'input' => $input ) ) );

		return rest_do_request( $request );
	}

	/**
	 * Set the simulated cookie-auth state for REST requests.
	 *
	 * Core's rest_cookie_check_errors() reads $wp_rest_auth_cookie: true
	 * means a logged-in cookie was validated; null means another auth
	 * scheme (application password) authenticated the request.
	 *
	 * @param bool|null $state Cookie-auth state.
	 */
	private function set_rest_cookie_auth( ?bool $state ): void {
		$GLOBALS['wp_rest_auth_cookie'] = $state;
	}

	/**
	 * Declares the _doing_it_wrong notices the 6.9 registry emits when Baton
	 * attempts save-time (save_post) ability registration.
	 */
	private function expect_save_time_ability_notices(): void {
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::unregister' );
		$this->setExpectedIncorrectUsage( 'wp_register_ability' );
	}

	/**
	 * Declares the notices a re-fired wp_abilities_api_init produces: the
	 * registry re-registers previously registered abilities (fixtures +
	 * Baton utilities), and workflow abilities that were never successfully
	 * registered see an unregister first.
	 */
	private function expect_reregistration_notices(): void {
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::register' );
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::unregister' );
	}
}