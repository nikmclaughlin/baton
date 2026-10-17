<?php
/**
 * Tests for the Command Palette support surface: the GET /baton/v1/workflows
 * list endpoint and the palette-trigger logging on the admin run endpoint.
 *
 * @package Baton
 */

declare( strict_types=1 );

/**
 * Command Palette slice tests.
 *
 * The palette itself is a runtime-gated admin script (no PHP surface); the
 * testable contract is the REST list endpoint ({id, title} only) and the
 * "Palette run" log rows written for runs tagged trigger=palette.
 */
class Test_Command_Palette extends WP_UnitTestCase {

	use Baton_Test_Table_State_Trait;

	/**
	 * Workflow post ID under test.
	 *
	 * @var int
	 */
	private int $workflow_id = 0;

	/**
	 * Every post created by the current test (the shared table-state trait
	 * commits away the harness transaction, so posts are deleted by hand).
	 *
	 * @var array<int, int>
	 */
	private array $created_post_ids = array();

	/**
	 * Runs before each test: admin user, fresh table, simulated cookie auth.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'Abilities API is not available in this WordPress version.' );
		}

		$this->drop_baton_table();
		Baton_Saved_Values::install();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// Simulate a cookie-authenticated admin (the browser path).
		$this->set_rest_cookie_auth( true );
	}

	/**
	 * Runs after each test.
	 */
	public function tear_down(): void {
		foreach ( $this->created_post_ids as $post_id ) {
			if ( get_post( $post_id ) ) {
				wp_delete_post( $post_id, true );
			}
		}
		$this->created_post_ids = array();
		$this->workflow_id      = 0;

		$this->drop_baton_table();

		wp_set_current_user( 0 );
		$this->set_rest_cookie_auth( null );

		parent::tear_down();
	}

	// ──────────────────────────────────────────────────────────────
	// GET /baton/v1/workflows — the palette list endpoint
	// ──────────────────────────────────────────────────────────────

	/**
	 * Published workflows come back as {id, title} pairs, title ASC.
	 */
	public function test_list_returns_published_id_title_pairs_sorted_by_title(): void {
		$first_id  = $this->create_workflow( 'Palette workflow' );
		$second_id = $this->create_workflow( 'Another workflow' );

		$response = $this->dispatch_list();

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertCount( 2, $data );

		// Title-ascending order for a stable palette listing.
		$this->assertSame( array( $second_id, $first_id ), array( $data[0]['id'], $data[1]['id'] ) );
		$this->assertSame( array( 'Another workflow', 'Palette workflow' ), array( $data[0]['title'], $data[1]['title'] ) );

		// Nothing but id and title is disclosed.
		foreach ( $data as $item ) {
			$this->assertSame( array( 'id', 'title' ), array_keys( $item ) );
			$this->assertIsInt( $item['id'] );
		}
	}

	/**
	 * Drafts and non-workflow posts are not listed.
	 */
	public function test_list_excludes_drafts_and_other_post_types(): void {
		$this->create_workflow( 'Published one' );
		$this->create_workflow( 'Draft workflow', 'draft' );
		$this->created_post_ids[] = (int) self::factory()->post->create( array( 'post_title' => 'A normal post' ) );

		$data = $this->dispatch_list()->get_data();

		$this->assertCount( 1, $data );
		$this->assertSame( 'Published one', $data[0]['title'] );
	}

	/**
	 * An empty catalog returns an empty array.
	 */
	public function test_list_returns_empty_array_when_nothing_published(): void {
		$this->create_workflow( 'Draft workflow', 'draft' );

		$data = $this->dispatch_list()->get_data();

		$this->assertSame( array(), $data );
	}

	/**
	 * The list endpoint is manage_options-gated.
	 */
	public function test_list_requires_manage_options(): void {
		$this->create_workflow();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = $this->dispatch_list();

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'Insufficient permissions.', $response->get_data()['message'] );
	}

	/**
	 * Cookie-authenticated requests need the wp_rest nonce, like the other
	 * baton/v1 routes (shared check_permissions()).
	 */
	public function test_list_rejects_invalid_and_missing_nonce(): void {
		$this->create_workflow();

		$this->assertSame( 403, $this->dispatch_list( 'not-a-real-nonce' )->get_status() );
		$this->assertSame( 403, $this->dispatch_list( null, false )->get_status() );
	}

	// ──────────────────────────────────────────────────────────────
	// trigger=palette on the run endpoint — the logged run origin
	// ──────────────────────────────────────────────────────────────

	/**
	 * A palette-tagged run writes one "Palette run" log row with the
	 * effective (saved) input and the report's run_id.
	 */
	public function test_palette_triggered_run_logs_row(): void {
		$workflow_id = $this->create_workflow();

		Baton_Workflow_CPT::save_definition(
			$workflow_id,
			array(
				'steps'         => array(
					array(
						'ability' => 'baton-test/echo',
						'input'   => array( 'hello' => 'world' ),
					),
				),
				'initial_input' => array( 'seed' => 'palette-seed' ),
			)
		);

		$response = $this->dispatch_run( $workflow_id, array( 'trigger' => 'palette' ) );

		$this->assertSame( 200, $response->get_status() );

		$report = $response->get_data();
		$this->assertTrue( (bool) $report['success'] );

		$rows = Baton_Saved_Values::rows_for_workflow( $workflow_id );
		$this->assertCount( 1, $rows );

		$row = $rows[0];
		$this->assertSame( 'log', $row['kind'] );
		$this->assertSame( 'Palette run', $row['label'] );
		$this->assertSame( (string) $report['run_id'], $row['run_id'] );
		$this->assertSame(
			array(
				'trigger' => 'palette',
				'success' => true,
				'input'   => array( 'seed' => 'palette-seed' ),
			),
			$row['value']
		);
	}

	/**
	 * Plain admin runs stay unlogged, and unknown trigger values are
	 * ignored — only the palette tag writes a row.
	 */
	public function test_plain_and_unknown_triggers_log_nothing(): void {
		$workflow_id = $this->create_workflow();

		Baton_Workflow_CPT::save_definition(
			$workflow_id,
			array(
				'steps'         => array(
					array(
						'ability' => 'baton-test/echo',
						'input'   => array( 'hello' => 'world' ),
					),
				),
				'initial_input' => array(),
			)
		);

		// Plain admin run (what the Run button has always sent).
		$plain = $this->dispatch_run( $workflow_id );
		$this->assertSame( 200, $plain->get_status() );
		$this->assertSame( array(), Baton_Saved_Values::rows_for_workflow( $workflow_id ) );

		// An arbitrary trigger tag does nothing (and cannot spoof channel
		// rows — those are written by their own code paths).
		$unknown = $this->dispatch_run( $workflow_id, array( 'trigger' => 'rest' ) );
		$this->assertSame( 200, $unknown->get_status() );
		$this->assertSame( array(), Baton_Saved_Values::rows_for_workflow( $workflow_id ) );
	}

	/**
	 * A failing palette run still logs — with the error surfaced in the
	 * row value, same as the channel rows.
	 */
	public function test_failing_palette_run_logs_row_with_error(): void {
		$workflow_id = $this->create_workflow();

		Baton_Workflow_CPT::save_definition(
			$workflow_id,
			array(
				'steps'         => array(
					array(
						'ability' => 'baton-test/not-a-real-ability',
						'input'   => array(),
					),
				),
				'initial_input' => array(),
			)
		);

		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );

		$response = $this->dispatch_run( $workflow_id, array( 'trigger' => 'palette' ) );

		$this->assertSame( 200, $response->get_status() );

		$report = $response->get_data();
		$this->assertFalse( (bool) $report['success'] );

		$rows = Baton_Saved_Values::rows_for_workflow( $workflow_id );
		$this->assertCount( 1, $rows );

		$row = $rows[0];
		$this->assertSame( 'Palette run', $row['label'] );

		$value = $row['value'];
		$this->assertSame( 'palette', $value['trigger'] );
		$this->assertFalse( (bool) $value['success'] );
		$this->assertStringContainsString( 'not-a-real-ability', (string) $value['error'] );
	}

	// ──────────────────────────────────────────────────────────────
	// Dispatch helpers
	// ──────────────────────────────────────────────────────────────

	/**
	 * Dispatch a palette-list request to the REST endpoint.
	 *
	 * @param string|null $nonce             Override the nonce (send an invalid one to test auth).
	 * @param bool        $with_nonce_header Whether to send the header at all.
	 * @return WP_REST_Response
	 */
	private function dispatch_list( ?string $nonce = null, bool $with_nonce_header = true ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/baton/v1/workflows' );

		if ( $with_nonce_header ) {
			$request->set_header( 'X-WP-Nonce', $nonce ?? wp_create_nonce( 'wp_rest' ) );
		}

		return rest_do_request( $request );
	}

	/**
	 * Dispatch a run request (optional body params, e.g. trigger).
	 *
	 * @param int                   $workflow_id Workflow post ID.
	 * @param array<string, string> $body        Body params to set.
	 * @return WP_REST_Response
	 */
	private function dispatch_run( int $workflow_id, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/baton/v1/workflows/' . $workflow_id . '/run' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		foreach ( $body as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * Create a workflow post (expects the WP 6.9 registration notices the
	 * save_post hook fires) and remember its ID.
	 *
	 * @param string $title  Workflow title.
	 * @param string $status Post status.
	 * @return int
	 */
	private function create_workflow( string $title = 'Palette workflow', string $status = 'publish' ): int {
		// Saves always unregister the (potentially stale) ability; only
		// publishing attempts the wp_register_ability no-op outside
		// wp_abilities_api_init.
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::unregister' );
		if ( 'publish' === $status ) {
			$this->setExpectedIncorrectUsage( 'wp_register_ability' );
		}

		$this->workflow_id = (int) wp_insert_post(
			array(
				'post_type'   => Baton_Workflow_CPT::POST_TYPE,
				'post_status' => $status,
				'post_title'  => $title,
			)
		);

		$this->assertGreaterThan( 0, $this->workflow_id );
		$this->created_post_ids[] = $this->workflow_id;

		return $this->workflow_id;
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