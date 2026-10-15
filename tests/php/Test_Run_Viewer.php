<?php
/**
 * Tests for the admin saved-values viewer (Baton_Run_Viewer): run grouping,
 * rendering, and delete handling.
 *
 * @package Baton
 */

declare( strict_types=1 );

/**
 * Admin viewer tests.
 */
class Test_Run_Viewer extends WP_UnitTestCase {

	/**
	 * Workflow post ID under test.
	 *
	 * @var int
	 */
	private int $workflow_id = 0;

	/**
	 * Runs before each test: drop + recreate the table, admin user, workflow post.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Baton_Saved_Values::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test-only DDL.
		Baton_Saved_Values::install();

		// Creating a published workflow post triggers the WP 6.9
		// registration gate on the save_post hook (unregister + register).
		$this->setExpectedIncorrectUsage( 'wp_register_ability' );
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::unregister' );

		wp_set_current_user( 1 );

		$this->workflow_id = (int) wp_insert_post(
			array(
				'post_type'   => Baton_Workflow_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Viewer workflow',
			)
		);

		$this->assertGreaterThan( 0, $this->workflow_id );
	}

	/**
	 * Runs after each test: drop the table.
	 */
	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Baton_Saved_Values::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test-only DDL.

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Seed two runs with save/log/state rows for the test workflow.
	 *
	 * @return array{latest_run: string, older_run: string} Run IDs.
	 */
	private function seed_runs(): array {
		$older  = 'run-older';
		$latest = 'run-latest';

		Baton_Saved_Values::append( Baton_Saved_Values::KIND_SAVE, $this->workflow_id, $older, 'first save', 'v1', 'step-0' );
		Baton_Saved_Values::upsert_state( $this->workflow_id, $latest, 'cursor', array( 'pos' => 2 ), 'step-1' );
		Baton_Saved_Values::append( Baton_Saved_Values::KIND_LOG, $this->workflow_id, $latest, 'processed batch 2', array( 'message' => 'processed batch 2', 'context' => 'extra' ), 'step-0' );

		return array(
			'latest_run' => $latest,
			'older_run'  => $older,
		);
	}

	/**
	 * get_runs()/group_rows_into_runs() put the newest run first and rows in
	 * chronological order inside the run.
	 */
	public function test_get_runs_groups_newest_first_with_chronological_rows(): void {
		self::seed_runs();

		$runs = Baton_Run_Viewer::get_runs( $this->workflow_id );

		$this->assertCount( 2, $runs );
		$this->assertSame( 'run-latest', $runs[0]['run_id'] );
		$this->assertSame( 'run-older', $runs[1]['run_id'] );

		// Inside a run the timeline replays execution order (oldest first).
		$this->assertSame( array( 'cursor', 'processed batch 2' ), array_column( $runs[0]['rows'], 'label' ) );
		$this->assertCount( 1, $runs[1]['rows'] );

		// The run header carries the newest activity time.
		$this->assertSame( 'run-latest', $runs[0]['run_id'] );
		$this->assertNotSame( '', $runs[0]['latest'] );

		// Empty and invalid workflow scopes.
		$this->assertSame( array(), Baton_Run_Viewer::group_rows_into_runs( array() ) );
		$this->assertSame( array(), Baton_Run_Viewer::get_runs( 0 ) );
		$this->assertSame( array(), Baton_Run_Viewer::get_runs( 555111 ) );
	}

	/**
	 * The panel renders runs newest-first with kind chips, escaped values,
	 * click-to-expand long values, and per-target delete links.
	 */
	public function test_render_outputs_timeline_chips_escapes_and_delete_links(): void {
		self::seed_runs();
		Baton_Saved_Values::append( Baton_Saved_Values::KIND_SAVE, $this->workflow_id, 'run-latest', '<b>injected</b>', str_repeat( 'A', 1000 ), 'step-2' );

		ob_start();
		Baton_Run_Viewer::render( $this->workflow_id );
		$html = (string) ob_get_clean();

		// Newest run first.
		$this->assertLessThan(
			strpos( $html, 'run-older' ),
			strpos( $html, 'run-latest' )
		);

		// Kind chips.
		$this->assertStringContainsString( 'baton-run__chip--save', $html );
		$this->assertStringContainsString( 'baton-run__chip--state', $html );
		$this->assertStringContainsString( 'baton-run__chip--log', $html );
		$this->assertStringContainsString( 'Save', $html );
		$this->assertStringContainsString( 'State', $html );
		$this->assertStringContainsString( 'Log', $html );

		// Labels are escaped on render.
		$this->assertStringNotContainsString( '<b>injected</b>', $html );
		$this->assertStringContainsString( '&lt;b&gt;injected&lt;/b&gt;', $html );

		// Long values render collapsed with the full value behind the toggle.
		$this->assertStringContainsString( '<details class="baton-run__value">', $html );
		$this->assertStringContainsString( str_repeat( 'A', 1000 ), $html );

		// Structured values are pretty-printed JSON.
		$this->assertStringContainsString( 'processed batch 2', $html );
		$this->assertStringContainsString( '&quot;context&quot;', $html );

		// Step attribution and per-run row counts (latest run has three rows).
		$this->assertStringContainsString( 'step-1', $html );
		$this->assertStringContainsString( '3 rows', $html );
		$this->assertStringContainsString( '1 row', $html );

		// Delete links carry the viewer actions with nonces.
		$this->assertStringContainsString( 'action=' . Baton_Run_Viewer::ACTION_DELETE_VALUE, $html );
		$this->assertStringContainsString( 'action=' . Baton_Run_Viewer::ACTION_DELETE_RUN, $html );
		$this->assertStringContainsString( '_wpnonce', $html );
	}

	/**
	 * Short values render inline; the empty workflow renders a calm empty state.
	 */
	public function test_render_empty_state_and_inline_values(): void {
		ob_start();
		Baton_Run_Viewer::render( $this->workflow_id );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'baton-runs-panel__empty', $html );
		$this->assertStringNotContainsString( 'baton-run__row', $html );

		Baton_Saved_Values::append( Baton_Saved_Values::KIND_SAVE, $this->workflow_id, 'run-1', 'short', 'world', '' );

		ob_start();
		Baton_Run_Viewer::render( $this->workflow_id );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'baton-run__value-inline', $html );
		$this->assertStringContainsString( 'world', $html );
		$this->assertStringNotContainsString( '<details', $html );
	}

	/**
	 * The viewer is manage_options only.
	 */
	public function test_render_requires_manage_options(): void {
		self::seed_runs();
		wp_set_current_user( 0 );

		ob_start();
		Baton_Run_Viewer::render( $this->workflow_id );
		$html = (string) ob_get_clean();

		$this->assertSame( '', $html );
	}

	/**
	 * handle_action deletes a row for an admin with a valid nonce.
	 */
	public function test_handle_action_deletes_row(): void {
		$saved = Baton_Saved_Values::append( Baton_Saved_Values::KIND_SAVE, $this->workflow_id, 'run-a', 'gone', 'v', '' );
		$value_id = (int) $saved['id'];

		$result = Baton_Run_Viewer::handle_action(
			Baton_Run_Viewer::ACTION_DELETE_VALUE,
			$this->workflow_id,
			$value_id,
			'',
			wp_create_nonce( Baton_Run_Viewer::value_nonce_action( $value_id ) )
		);

		$this->assertTrue( $result );
		$this->assertNull( Baton_Saved_Values::get_row( $value_id ) );
		$this->assertFalse( Baton_Saved_Values::delete_row( $value_id ) );
	}

	/**
	 * handle_action deletes a run scoped to the viewed workflow.
	 */
	public function test_handle_action_deletes_run_for_workflow(): void {
		self::seed_runs();

		Baton_Saved_Values::append( Baton_Saved_Values::KIND_SAVE, 99, 'run-latest', 'foreign', 'v', '' );

		$this->assertTrue(
			Baton_Run_Viewer::handle_action(
				Baton_Run_Viewer::ACTION_DELETE_RUN,
				$this->workflow_id,
				0,
				'run-latest',
				wp_create_nonce( Baton_Run_Viewer::run_nonce_action( 'run-latest' ) )
			)
		);

		// Only this workflow's OTHER run (plus foreign workflow rows) survive.
		$this->assertSame( 1, count( Baton_Saved_Values::rows_for_workflow( 99 ) ) );
		$remaining = Baton_Saved_Values::rows_for_workflow( $this->workflow_id );
		$this->assertSame( array( 'first save' ), array_column( $remaining, 'label' ) );
		$this->assertSame( array( 'run-older' ), array_column( $remaining, 'run_id' ) );
	}

	/**
	 * Failure paths: capability, unknown workflow, invalid nonce, foreign
	 * row/run, and empty identifiers all fail with WP_Error and no deletion.
	 */
	public function test_handle_action_failure_paths(): void {
		$saved = Baton_Saved_Values::append( Baton_Saved_Values::KIND_SAVE, $this->workflow_id, 'run-a', 'stay', 'v', '' );
		$value_id = (int) $saved['id'];

		$row_nonce = wp_create_nonce( Baton_Run_Viewer::value_nonce_action( $value_id ) );

		// Wrong user.
		wp_set_current_user( 0 );
		$result = Baton_Run_Viewer::handle_action( Baton_Run_Viewer::ACTION_DELETE_VALUE, $this->workflow_id, $value_id, '', $row_nonce );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'baton_viewer_forbidden', $result->get_error_code() );
		wp_set_current_user( 1 );

		// Unknown workflow.
		$result = Baton_Run_Viewer::handle_action( Baton_Run_Viewer::ACTION_DELETE_VALUE, 555111, $value_id, '', $row_nonce );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'baton_viewer_workflow_not_found', $result->get_error_code() );

		// Unknown action.
		$result = Baton_Run_Viewer::handle_action( 'baton_delete_what', $this->workflow_id, $value_id, '', $row_nonce );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'baton_viewer_unknown_action', $result->get_error_code() );

		// Bad nonce.
		$result = Baton_Run_Viewer::handle_action( Baton_Run_Viewer::ACTION_DELETE_VALUE, $this->workflow_id, $value_id, '', '' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'baton_viewer_invalid_nonce', $result->get_error_code() );

		// Missing value id.
		$result = Baton_Run_Viewer::handle_action( Baton_Run_Viewer::ACTION_DELETE_VALUE, $this->workflow_id, 0, '', '' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'baton_viewer_invalid_row', $result->get_error_code() );

		// Row belongs to another workflow.
		$other = Baton_Saved_Values::append( Baton_Saved_Values::KIND_SAVE, 99, 'run-x', 'other', 'v', '' );
		$result = Baton_Run_Viewer::handle_action(
			Baton_Run_Viewer::ACTION_DELETE_VALUE,
			$this->workflow_id,
			(int) $other['id'],
			'',
			wp_create_nonce( Baton_Run_Viewer::value_nonce_action( (int) $other['id'] ) )
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'baton_viewer_row_not_found', $result->get_error_code() );

		// Run delete: missing run, bad nonce, run absent from this workflow.
		$result = Baton_Run_Viewer::handle_action( Baton_Run_Viewer::ACTION_DELETE_RUN, $this->workflow_id, 0, '', '' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'baton_viewer_invalid_run', $result->get_error_code() );
		$result = Baton_Run_Viewer::handle_action( Baton_Run_Viewer::ACTION_DELETE_RUN, $this->workflow_id, 0, 'run-a', '' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'baton_viewer_invalid_nonce', $result->get_error_code() );
		$result = Baton_Run_Viewer::handle_action(
			Baton_Run_Viewer::ACTION_DELETE_RUN,
			$this->workflow_id,
			0,
			'run-not-here',
			wp_create_nonce( Baton_Run_Viewer::run_nonce_action( 'run-not-here' ) )
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'baton_viewer_run_not_found', $result->get_error_code() );

		// Nothing was deleted by any failure path.
		$this->assertNotNull( Baton_Saved_Values::get_row( $value_id ) );
		$this->assertNotNull( Baton_Saved_Values::get_row( (int) $other['id'] ) );
	}
}