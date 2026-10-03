<?php
/**
 * Tests for the REST workflow endpoints (Baton_Rest_Controller).
 *
 * @package Baton
 */

declare( strict_types=1 );

/**
 * REST save/run endpoint tests, covering the shared persist_workflow() and
 * save_workflow_post() helpers.
 *
 * Dispatches through rest_do_request() with the browser-auth handshake
 * simulated the way core's rest_cookie_check_errors() sees it: an
 * authenticated user whose auth-cookie status global is true, plus an
 * X-WP-Nonce header carrying a wp_rest nonce.
 */
class Test_Rest_Save_Workflow extends WP_UnitTestCase {

	/**
	 * Runs before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'Abilities API is not available in this WordPress version.' );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		if ( ! function_exists( 'baton_tests_ensure_abilities_registered' ) ) {
			require_once dirname( __DIR__ ) . '/fixtures/test-abilities.php';
		}

		$registration_error = baton_tests_ensure_abilities_registered();
		if ( '' !== $registration_error ) {
			$this->fail( $registration_error );
		}

		// Simulate a cookie-authenticated admin (the browser path).
		$this->set_rest_cookie_auth( true );
	}

	/**
	 * Runs after each test.
	 *
	 * Restores the cookie-auth flag so other test classes don't inherit the
	 * simulated browser handshake.
	 */
	public function tear_down(): void {
		$this->set_rest_cookie_auth( null );

		parent::tear_down();
	}

	/**
	 * Valid insert request: creates the workflow, persists definition and
	 * layout, registers the workflow ability, and returns post metadata.
	 */
	public function test_saves_new_workflow_via_rest(): void {
		$data = $this->post_save_request(
			array(
				'workflow_title'      => ' REST _insert ',
				'workflow_excerpt'    => 'Created over REST.',
				'workflow_definition' => (string) wp_json_encode(
					array(
						'steps' => array(
							array(
								'ability' => 'baton-test/echo',
								'input'   => array( 'who' => 'you' ),
							),
						),
					)
				),
				'baton_editor_layout' => (string) wp_json_encode(
					array(
						'input'  => array(
							'x' => 5,
							'y' => 6,
						),
						'step-0' => array(
							'x' => 1,
							'y' => 2,
						),
					)
				),
			)
		);

		$this->assertSame( 'Workflow saved.', $data['message'] );
		$this->assertGreaterThan( 0, $data['post_id'] );

		$post_id = (int) $data['post_id'];
		$post    = get_post( $post_id );
		$this->assertSame( Baton_Workflow_CPT::POST_TYPE, $post->post_type );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 'REST _insert', $post->post_title );
		$this->assertSame( 'Created over REST.', $post->post_excerpt );
		$this->assertStringContainsString( 'workflow_id=' . $post_id, (string) $data['edit_url'] );

		$definition = Baton_Workflow_CPT::get_definition( $post_id );
		$this->assertCount( 1, $definition['steps'] );
		$this->assertSame( 'baton-test/echo', $definition['steps'][0]['ability'] );
		$this->assertSame( 'you', $definition['steps'][0]['input']['who'] );

		$layout = Baton_Workflow_CPT::get_layout( $post_id );
		$this->assertSame(
			array(
				'x' => 5.0,
				'y' => 6.0,
			),
			$layout['input']
		);
		$this->assertSame(
			array(
				'x' => 1.0,
				'y' => 2.0,
			),
			$layout['step-0']
		);

		// On WP 6.9, abilities may only be registered during
		// wp_abilities_api_init, so the save-time attempt fires
		// _doing_it_wrong and the ability lands on the NEXT request's
		// registry init instead of being immediately available.
		$this->expect_save_time_ability_notices();
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );
		$this->assertNull( wp_get_ability( 'baton/workflow-' . $post_id ) );
	}

	/**
	 * Update request: renames and re-excerpts the post, replaces the
	 * definition, and prunes layout entries for removed nodes.
	 */
	public function test_updates_existing_workflow_and_prunes_layout(): void {
		$post_id = $this->factory()->post->create(
			array(
				'post_type'    => Baton_Workflow_CPT::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Old title',
				'post_excerpt' => 'Old excerpt',
			)
		);
		Baton_Workflow_CPT::save_definition( $post_id, array( 'steps' => array( array( 'ability' => 'baton-test/echo' ) ) ) );
		Baton_Workflow_CPT::save_layout(
			$post_id,
			array(
				'stale-node' => array(
					'x' => 9,
					'y' => 9,
				),
			)
		);

		$data = $this->post_save_request(
			array(
				'workflow_id'         => (string) $post_id,
				'workflow_title'      => 'Renamed',
				'workflow_excerpt'    => 'New excerpt',
				'workflow_definition' => (string) wp_json_encode(
					array(
						'steps' => array(
							array(
								'id'      => 'first_step',
								'ability' => 'baton-test/echo',
								'input'   => array(),
							),
						),
					)
				),
				'baton_editor_layout' => (string) wp_json_encode(
					array(
						'input'  => array(
							'x' => 5,
							'y' => 6,
						),
						'step-0' => array(
							'x' => 1,
							'y' => 2,
						),
						'ghost'  => array(
							'x' => 9,
							'y' => 9,
						),
					)
				),
			)
		);

		$this->assertSame( $post_id, (int) $data['post_id'] );

		$post = get_post( $post_id );
		$this->assertSame( 'Renamed', $post->post_title );
		$this->assertSame( 'New excerpt', $post->post_excerpt );

		$definition = Baton_Workflow_CPT::get_definition( $post_id );
		$this->assertSame( 'first_step', $definition['steps'][0]['id'] );

		$layout = Baton_Workflow_CPT::get_layout( $post_id );
		$this->assertArrayHasKey( 'input', $layout );
		$this->assertArrayHasKey( 'step-0', $layout );
		$this->assertArrayNotHasKey( 'ghost', $layout );
		$this->assertArrayNotHasKey( 'stale-node', $layout );
		$this->expect_save_time_ability_notices();
	}

	/**
	 * A second save over the endpoint for the same post keeps one post and
	 * replaces the stored definition (the editor's save-again path).
	 */
	public function test_second_save_updates_same_post(): void {
		$data    = $this->post_save_request(
			array(
				'workflow_title'      => 'First',
				'workflow_definition' => '{}',
			)
		);
		$post_id = (int) $data['post_id'];

		$this->expect_save_time_ability_notices();
		$data = $this->post_save_request(
			array(
				'workflow_id'         => (string) $post_id,
				'workflow_title'      => 'Second',
				'workflow_definition' => (string) wp_json_encode(
					array(
						'steps' => array(
							array( 'ability' => 'baton-test/echo' ),
						),
					)
				),
			)
		);

		$this->assertSame( $post_id, (int) $data['post_id'] );

		$this->assertSame( 'Second', get_post( $post_id )->post_title );
		$this->assertCount( 1, Baton_Workflow_CPT::get_definition( $post_id )['steps'] );
		$this->assertSame(
			1,
			count( $this->all_workflow_ids() )
		);
	}

	/**
	 * A non-well-formed definition JSON is rejected and nothing is created.
	 */
	public function test_rejects_invalid_definition_json(): void {
		$response = $this->dispatch_save(
			array(
				'workflow_title'      => 'Should never exist',
				'workflow_definition' => '{"steps": [',
			)
		);

		$this->assert_error_response( $response, 400, 'Invalid workflow definition JSON.' );
		$this->assertSame( array(), $this->all_workflow_ids() );
	}

	/**
	 * An empty title is rejected (mirrors the client-side backstop).
	 */
	public function test_rejects_empty_title(): void {
		$response = $this->dispatch_save(
			array(
				'workflow_title'      => '   ',
				'workflow_definition' => '{}',
			)
		);

		$this->assert_error_response( $response, 400, 'Title is required.' );
		$this->assertSame( array(), $this->all_workflow_ids() );
	}

	/**
	 * Updating an ID that is not a workflow post errors without inserting.
	 */
	public function test_rejects_update_of_non_workflow_post(): void {
		$other_post_id = $this->factory()->post->create( array( 'post_title' => 'A normal post' ) );

		$response = $this->dispatch_save(
			array(
				'workflow_id'         => (string) $other_post_id,
				'workflow_title'      => 'Should never exist',
				'workflow_definition' => '{}',
			)
		);

		$this->assert_error_response( $response, 404, 'Workflow not found.' );
		$this->assertSame( array(), $this->all_workflow_ids() );
	}

	/**
	 * Users without manage_options are rejected even with a valid nonce.
	 */
	public function test_rejects_user_without_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = $this->dispatch_save(
			array(
				'workflow_title'      => 'Should never exist',
				'workflow_definition' => '{}',
			)
		);

		$this->assert_error_response( $response, 403, 'Insufficient permissions.' );
		$this->assertSame( array(), $this->all_workflow_ids() );
	}

	/**
	 * A cookie-authenticated request without a valid wp_rest nonce is
	 * rejected (the REST analogue of the AJAX nonce check).
	 */
	public function test_rejects_invalid_nonce(): void {
		$response = $this->dispatch_save(
			array(
				'workflow_title'      => 'Should never exist',
				'workflow_definition' => '{}',
			),
			'not-a-real-nonce'
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( array(), $this->all_workflow_ids() );
	}

	/**
	 * A request with no definition at all saves the default definition.
	 */
	public function test_saves_default_definition_when_none_sent(): void {
		$data = $this->post_save_request(
			array(
				'workflow_title' => 'Nothing to do',
			)
		);

		$post_id = (int) $data['post_id'];

		$this->assertGreaterThan( 0, $post_id );
		$this->assertSame(
			array(
				'initial_input' => array(),
				'steps'         => array(),
			),
			Baton_Workflow_CPT::get_definition( $post_id )
		);
		$this->expect_save_time_ability_notices();
	}

	/**
	 * The run endpoint executes a stored workflow and returns the report.
	 */
	public function test_runs_a_stored_workflow(): void {
		$post_id = $this->factory()->post->create(
			array(
				'post_type'   => Baton_Workflow_CPT::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		Baton_Workflow_CPT::save_definition(
			$post_id,
			array(
				'steps' => array(
					array(
						'ability' => 'baton-test/echo',
						'input'   => array( 'hello' => 'world' ),
					),
				),
			)
		);

		$this->expect_save_time_ability_notices();

		$response = $this->dispatch_run( $post_id );
		$this->assertSame( 200, $response->get_status() );

		$report = $response->get_data();
		$this->assertTrue( $report['success'] );
		$this->assertCount( 1, $report['steps'] );
		$this->assertTrue( $report['steps'][0]['success'] );
		$this->assertSame( 'world', $report['steps'][0]['output']['hello'] );
	}

	/**
	 * initial_input rides as a JSON string, mirroring the panel's textarea.
	 */
	public function test_run_accepts_initial_input_json(): void {
		$post_id = $this->factory()->post->create(
			array(
				'post_type'   => Baton_Workflow_CPT::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		Baton_Workflow_CPT::save_definition(
			$post_id,
			array(
				'steps' => array(
					array(
						'ability' => 'baton-test/echo',
						'input'   => array(),
					),
				),
			)
		);

		$this->expect_save_time_ability_notices();

		$response = $this->dispatch_run( $post_id, '{"who":"panel"}' );
		$this->assertSame( 200, $response->get_status() );

		$report = $response->get_data();
		$this->assertTrue( $report['success'] );
		$this->assertSame( 'panel', $report['steps'][0]['output']['who'] );
	}

	/**
	 * A workflow whose ability steps fail reports in-band, with HTTP 200.
	 */
	public function test_run_reports_failed_workflow_in_band(): void {
		$post_id = $this->factory()->post->create(
			array(
				'post_type'   => Baton_Workflow_CPT::POST_TYPE,
				'post_status' => 'publish',
			)
		);
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

		$this->expect_save_time_ability_notices();
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );

		$response = $this->dispatch_run( $post_id );
		$this->assertSame( 200, $response->get_status() );

		$report = $response->get_data();
		$this->assertFalse( $report['success'] );
		$this->assertStringContainsString( 'not-a-real-ability', (string) $report['error'] );
		$this->assertArrayHasKey( 'steps', $report );
	}

	/**
	 * Running an unknown workflow ID is a 404.
	 */
	public function test_run_returns_404_for_unknown_workflow(): void {
		$response = $this->dispatch_run( 424242 );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'Workflow not found.', $response->get_data()['message'] );
	}

	/**
	 * Running a non-workflow post ID is a 404.
	 */
	public function test_run_returns_404_for_non_workflow_post(): void {
		$other_post_id = $this->factory()->post->create( array( 'post_title' => 'A normal post' ) );

		$response = $this->dispatch_run( $other_post_id );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'Workflow not found.', $response->get_data()['message'] );
	}

	/**
	 * Malformed initial_input JSON is a 400 and does not run the workflow.
	 */
	public function test_run_rejects_invalid_initial_input_json(): void {
		$post_id = $this->factory()->post->create(
			array(
				'post_type'   => Baton_Workflow_CPT::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		Baton_Workflow_CPT::save_definition(
			$post_id,
			array(
				'steps' => array(
					array(
						'ability' => 'baton-test/echo',
						'input'   => array(),
					),
				),
			)
		);

		$this->expect_save_time_ability_notices();

		$response = $this->dispatch_run( $post_id, '{"who": ' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'Invalid workflow input JSON.', $response->get_data()['message'] );
	}

	/**
	 * The run route declines non-POST methods.
	 */
	public function test_run_rejects_get_requests(): void {
		$post_id = $this->factory()->post->create(
			array(
				'post_type'   => Baton_Workflow_CPT::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		$this->expect_save_time_ability_notices();

		$request  = new WP_REST_Request( 'GET', '/baton/v1/workflows/' . $post_id . '/run' );
		$response = rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Authenticated cookie flows without any nonce skip the nonce gate only
	 * when another auth scheme authenticated the user; simulate by clearing
	 * the cookie-auth flag and confirm the request still succeeds.
	 */
	public function test_run_without_nonce_header_ok_via_other_auth(): void {
		$post_id = $this->factory()->post->create(
			array(
				'post_type'   => Baton_Workflow_CPT::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		Baton_Workflow_CPT::save_definition(
			$post_id,
			array(
				'steps' => array(
					array(
						'ability' => 'baton-test/echo',
						'input'   => array( 'who' => 'token' ),
					),
				),
			)
		);

		$this->expect_save_time_ability_notices();

		// No cookie-auth flag: e.g. application-password authenticated.
		$this->set_rest_cookie_auth( null );

		$response = $this->dispatch_run( $post_id, '', false );

		$this->assertSame( 200, $response->get_status() );
		$report = $response->get_data();
		$this->assertTrue( $report['success'] );
		$this->assertSame( 'token', $report['steps'][0]['output']['who'] );

		// Restore the default for subsequent assertions/teardown symmetry.
		$this->set_rest_cookie_auth( true );
	}

	/**
	 * Fill params and dispatch a save request to the REST endpoint.
	 *
	 * @param array<string, string> $fields Request params (values mirror the editor's string payloads).
	 * @param string|null           $nonce  Override the nonce (send an invalid one to test auth).
	 * @param bool                  $with_nonce_header Whether to send the header at all.
	 * @return WP_REST_Response
	 */
	private function dispatch_save(
		array $fields,
		?string $nonce = null,
		bool $with_nonce_header = true
	): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/baton/v1/workflows' );

		foreach ( $fields as $key => $value ) {
			$request->set_param( $key, $value );
		}

		if ( $with_nonce_header ) {
			$request->set_header(
				'X-WP-Nonce',
				$nonce ?? wp_create_nonce( 'wp_rest' )
			);
		}

		return rest_do_request( $request );
	}

	/**
	 * Dispatch a save request and return the decoded data payload,
	 * asserting envelope-free 200 semantics first.
	 *
	 * @param array<string, string> $fields Request params.
	 * @return array<string, mixed>
	 */
	private function post_save_request( array $fields ): array {
		$response = $this->dispatch_save( $fields );

		if ( 200 !== $response->get_status() ) {
			$this->fail(
				sprintf(
					'Expected 200 for save request, got %d: %s',
					$response->get_status(),
					(string) wp_json_encode( $response->get_data() )
				)
			);
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			$this->fail( 'Save response was not an array.' );
		}

		return $data;
	}

	/**
	 * Dispatch a run request to the REST run endpoint.
	 *
	 * @param int    $post_id           Workflow post ID.
	 * @param string $initial_input_raw Raw initial_input string (may be empty to omit).
	 * @param bool   $with_nonce_header Whether to send the X-WP-Nonce header.
	 * @return WP_REST_Response
	 */
	private function dispatch_run( int $post_id, string $initial_input_raw = '', bool $with_nonce_header = true ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/baton/v1/workflows/' . $post_id . '/run' );

		if ( '' !== $initial_input_raw ) {
			$request->set_param( 'initial_input', $initial_input_raw );
		}

		if ( $with_nonce_header ) {
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}

		return rest_do_request( $request );
	}

	/**
	 * Set the simulated cookie-auth state for REST requests.
	 *
	 * Core's rest_cookie_check_errors() reads $wp_rest_auth_cookie: true
	 * means a logged-in cookie was validated. The unit-test environment
	 * short-circuits that filter (no real cookie processing), so setting the
	 * global here reproduces the exact state a real browser session is in.
	 *
	 * @param bool|null $state true (cookie auth), null (other auth scheme).
	 */
	private function set_rest_cookie_auth( ?bool $state ): void {
		$GLOBALS['wp_rest_auth_cookie'] = $state;
	}

	/**
	 * Assert the response is an error payload with the given message.
	 *
	 * @param WP_REST_Response $response Response to assert.
	 * @param int              $status   Expected HTTP status.
	 * @param string           $message  Expected error message.
	 */
	private function assert_error_response( WP_REST_Response $response, int $status, string $message ): void {
		$this->assertSame( $status, $response->get_status(), 'Unexpected HTTP status.' );

		$body = $response->get_data();
		$this->assertSame( $message, $body['message'] );
	}

	/**
	 * Declares the _doing_it_wrong notices the 6.9 registry emits when Baton
	 * attempts save-time (and save_post-time) ability registration.
	 */
	private function expect_save_time_ability_notices(): void {
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::unregister' );
		$this->setExpectedIncorrectUsage( 'wp_register_ability' );
	}

	/**
	 * All workflow post IDs currently in the database.
	 *
	 * @return array<int, int>
	 */
	private function all_workflow_ids(): array {
		return get_posts(
			array(
				'post_type'   => Baton_Workflow_CPT::POST_TYPE,
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);
	}
}