<?php
/**
 * Tests for the REST viewer endpoint (GET /baton/v1/workflows/{id}/viewer).
 *
 * @package Baton
 */

declare( strict_types=1 );

/**
 * Viewer endpoint tests: permission gating plus the live-refresh markup
 * contract (server-rendered Baton_Run_Viewer partial, rows carrying the
 * run_id just written by a completed run).
 */
class Test_Viewer_Endpoint extends WP_UnitTestCase {

	/**
	 * Workflow post ID under test.
	 *
	 * @var int
	 */
	private int $workflow_id = 0;

	/**
	 * Runs before each test: admin user, fresh table, simulated cookie auth.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'Abilities API is not available in this WordPress version.' );
		}

		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Baton_Saved_Values::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test-only DDL.
		Baton_Saved_Values::install();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// Simulate a cookie-authenticated admin (the browser path).
		$this->set_rest_cookie_auth( true );
	}

	/**
	 * Runs after each test.
	 */
	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Baton_Saved_Values::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test-only DDL.

		wp_set_current_user( 0 );
		$this->set_rest_cookie_auth( null );

		parent::tear_down();
	}

	/**
	 * Create a published workflow post (expects the WP 6.9 registration
	 * notices the save_post hook fires) and remember its ID.
	 */
	private function create_workflow(): int {
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::unregister' );
		$this->setExpectedIncorrectUsage( 'wp_register_ability' );

		$this->workflow_id = (int) wp_insert_post(
			array(
				'post_type'   => Baton_Workflow_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Viewer endpoint workflow',
			)
		);

		$this->assertGreaterThan( 0, $this->workflow_id );

		return $this->workflow_id;
	}

	/**
	 * Admins get the server-rendered panel markup for seeded rows.
	 */
	public function test_returns_viewer_markup_for_seeded_rows(): void {
		$workflow_id = $this->create_workflow();

		Baton_Saved_Values::append(
			Baton_Saved_Values::KIND_LOG,
			$workflow_id,
			'run-seed',
			'seeded message',
			array( 'message' => 'seeded message' ),
			'step-0'
		);

		$response = $this->dispatch_viewer( $workflow_id );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'html', $data );

		$html = (string) $data['html'];
		$this->assertStringContainsString( 'baton-saved-values', $html );
		$this->assertSame( 1, substr_count( $html, 'id="baton-saved-values"' ), 'The panel wrapper is returned exactly once.' );
		$this->assertStringContainsString( 'run-seed', $html );
		$this->assertStringContainsString( 'seeded message', $html );
		$this->assertStringContainsString( 'baton-run__chip--log', $html );

		// The panel is safe to inject as-is.
		$this->assertStringNotContainsString( '<script', $html );
	}

	/**
	 * A fetch right after a completed run includes the just-used run_id.
	 */
	public function test_viewer_after_run_includes_just_used_run_id(): void {
		$workflow_id = $this->create_workflow();

		Baton_Workflow_CPT::save_definition(
			$workflow_id,
			array(
				'steps'        => array(
					array(
						'ability' => 'baton/log',
						'input'   => array( 'message' => 'refresh me' ),
					),
				),
				'initial_input' => array(),
			)
		);

		$run_response = $this->dispatch_run( $workflow_id );
		$this->assertSame( 200, $run_response->get_status() );

		$report = $run_response->get_data();
		$this->assertTrue( (bool) $report['success'] );

		$run_id = (string) ( $report['run_id'] ?? '' );
		$this->assertNotSame( '', $run_id, 'The run report carries the runner-generated run_id.' );

		$response = $this->dispatch_viewer( $workflow_id );
		$this->assertSame( 200, $response->get_status() );

		$html = (string) $response->get_data()['html'];
		$this->assertStringContainsString( $run_id, $html );
		$this->assertStringContainsString( 'refresh me', $html );
	}

	/**
	 * An unknown workflow ID is a 404.
	 */
	public function test_returns_404_for_unknown_workflow(): void {
		$response = $this->dispatch_viewer( 424242 );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'Workflow not found.', $response->get_data()['message'] );
	}

	/**
	 * A non-workflow post ID is a 404.
	 */
	public function test_returns_404_for_non_workflow_post(): void {
		$other_post_id = $this->factory()->post->create( array( 'post_title' => 'A normal post' ) );

		$response = $this->dispatch_viewer( $other_post_id );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'Workflow not found.', $response->get_data()['message'] );
	}

	/**
	 * Users without manage_options are rejected even with a valid nonce.
	 */
	public function test_rejects_user_without_manage_options(): void {
		$workflow_id = $this->create_workflow();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = $this->dispatch_viewer( $workflow_id );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'Insufficient permissions.', $response->get_data()['message'] );
	}

	/**
	 * A cookie-authenticated request without a valid wp_rest nonce is
	 * rejected (403), matching the other Baton routes.
	 */
	public function test_rejects_invalid_nonce(): void {
		$workflow_id = $this->create_workflow();

		$response = $this->dispatch_viewer( $workflow_id, 'not-a-real-nonce' );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * A cookie-authenticated request without any nonce header is rejected.
	 */
	public function test_rejects_missing_nonce_header(): void {
		$workflow_id = $this->create_workflow();

		$response = $this->dispatch_viewer( $workflow_id, null, false );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * The viewer route declines non-GET methods.
	 */
	public function test_declines_post_requests(): void {
		$workflow_id = $this->create_workflow();

		$request = new WP_REST_Request( 'POST', '/baton/v1/workflows/' . $workflow_id . '/viewer' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$this->assertSame( 404, rest_do_request( $request )->get_status() );
	}

	/**
	 * Fill headers and dispatch a viewer request to the REST endpoint.
	 *
	 * @param int         $workflow_id       Workflow post ID.
	 * @param string|null $nonce             Override the nonce (send an invalid one to test auth).
	 * @param bool        $with_nonce_header Whether to send the header at all.
	 * @return WP_REST_Response
	 */
	private function dispatch_viewer( int $workflow_id, ?string $nonce = null, bool $with_nonce_header = true ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/baton/v1/workflows/' . $workflow_id . '/viewer' );

		if ( $with_nonce_header ) {
			$request->set_header(
				'X-WP-Nonce',
				$nonce ?? wp_create_nonce( 'wp_rest' )
			);
		}

		return rest_do_request( $request );
	}

	/**
	 * Dispatch a run request to the REST run endpoint.
	 *
	 * @param int $workflow_id Workflow post ID.
	 * @return WP_REST_Response
	 */
	private function dispatch_run( int $workflow_id ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/baton/v1/workflows/' . $workflow_id . '/run' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return rest_do_request( $request );
	}

	/**
	 * Set the simulated cookie-auth state for REST requests.
	 *
	 * @param bool|null $state true (cookie auth), null (other auth scheme).
	 */
	private function set_rest_cookie_auth( ?bool $state ): void {
		$GLOBALS['wp_rest_auth_cookie'] = $state;
	}
}