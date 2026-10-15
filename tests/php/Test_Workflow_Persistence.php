<?php
/**
 * Tests for run_id threading, the additive run-report contract, and the
 * save/load/log utilities executed through the workflow runner.
 *
 * @package Baton
 */

declare( strict_types=1 );

/**
 * Runner + persistence integration tests.
 */
class Test_Workflow_Persistence extends WP_UnitTestCase {

	/**
	 * Runs before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'Abilities API is not available in this WordPress version.' );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test-only DDL.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Baton_Saved_Values::table_name() );
		Baton_Saved_Values::install();

		wp_set_current_user( 1 );

		if ( ! function_exists( 'baton_tests_ensure_abilities_registered' ) ) {
			require_once dirname( __DIR__ ) . '/fixtures/test-abilities.php';
		}

		$registration_error = baton_tests_ensure_abilities_registered();
		if ( '' !== $registration_error ) {
			$this->fail( $registration_error );
		}

		foreach ( array( 'baton/save-output', 'baton/save-state', 'baton/load-output', 'baton/log' ) as $slug ) {
			if ( ! wp_get_ability( $slug ) ) {
				$this->fail( 'Utility ability ' . $slug . ' is not registered.' );
			}
		}
	}

	/**
	 * Runs after each test: drop the table.
	 */
	public function tear_down(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test-only DDL.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Baton_Saved_Values::table_name() );

		parent::tear_down();
	}

	/**
	 * Count stored rows for a run.
	 *
	 * @param string $run_id Execution run ID.
	 * @return int
	 */
	private function rows_for_run( string $run_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test helper.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Baton_Saved_Values::table_name() . ' WHERE run_id = %s',
				$run_id
			)
		);
	}

	/**
	 * All rows for a run.
	 *
	 * @param string $run_id Execution run ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function rows_raw_for_run( string $run_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test helper.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Baton_Saved_Values::table_name() . ' WHERE run_id = %s ORDER BY id ASC',
				$run_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	// ──────────────────────────────────────────────────────────────
	// Run report contract (additive run_id)
	// ──────────────────────────────────────────────────────────────

	/**
	 * A successful report keeps its old keys byte-stable and gains run_id.
	 */
	public function test_success_report_keys_stable_and_run_id_additive(): void {
		$report = Baton_Workflow_Runner::run(
			array(
				'steps' => array(
					array(
						'ability' => 'baton-test/echo',
						'input'   => array( 'hello' => 'world' ),
					),
				),
			)
		);

		$this->assertTrue( $report['success'] );
		$this->assertSame( array( 'success', 'steps', 'run_id' ), array_keys( $report ) );
		$this->assertSame(
			array( 'index', 'ability', 'success', 'input', 'warnings', 'output' ),
			array_keys( $report['steps'][0] )
		);
		$this->assertSame( 'world', $report['steps'][0]['output']['hello'] );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			(string) $report['run_id']
		);
	}

	/**
	 * Every execution gets its own run id.
	 */
	public function test_run_ids_differ_between_runs(): void {
		$definition = array(
			'steps' => array(
				array(
					'ability' => 'baton-test/echo',
					'input'   => array(),
				),
			),
		);

		$first  = Baton_Workflow_Runner::run( $definition );
		$second = Baton_Workflow_Runner::run( $definition );

		$this->assertNotSame( $first['run_id'], $second['run_id'] );
	}

	/**
	 * Failure reports keep the old keys and gain run_id additively.
	 */
	public function test_failure_and_cycle_reports_gain_run_id_additively(): void {
		$report = Baton_Workflow_Runner::run(
			array(
				'steps' => array(
					array(
						'ability' => 'baton-test/fail',
						'input'   => array(),
					),
				),
			)
		);

		$this->assertFalse( $report['success'] );
		$this->assertSame( array( 'success', 'steps', 'run_id', 'error' ), array_keys( $report ) );
		$this->assertSame(
			array( 'index', 'ability', 'success', 'input', 'warnings', 'error' ),
			array_keys( $report['steps'][0] )
		);

		$empty = Baton_Workflow_Runner::run( array( 'steps' => array() ) );
		$this->assertSame( array( 'success', 'steps', 'run_id', 'error' ), array_keys( $empty ) );

		$cycle = Baton_Workflow_Runner::run( array( 'steps' => array() ), 42, array( 42 ) );
		$this->assertSame( array( 'success', 'error', 'steps', 'run_id' ), array_keys( $cycle ) );
	}

	// ──────────────────────────────────────────────────────────────
	// Context threading to steps
	// ──────────────────────────────────────────────────────────────

	/**
	 * Runner context (run_id/workflow_id/step_id) is injected only when the
	 * ability's input schema declares the field, and never overwrites
	 * user-resolved values.
	 */
	public function test_context_injection_is_schema_opt_in(): void {
		$definition = array(
			'steps' => array(
				array(
					'id'      => 'stable-0',
					'ability' => 'baton-test/context-receiver',
					'input'   => array( 'marker' => 'm' ),
				),
				array(
					'ability' => 'baton-test/echo',
					'input'   => array( 'hello' => 'world' ),
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $definition, 42 );

		$this->assertTrue( $report['success'], (string) ( $report['error'] ?? '' ) );
		$this->assertCount( 2, $report['steps'] );

		$receiver_output = $report['steps'][0]['output'];
		$this->assertSame( 'm', $receiver_output['marker'] );
		$this->assertSame( $report['run_id'], $receiver_output['run_id'] );
		$this->assertSame( 42, $receiver_output['workflow_id'] );
		$this->assertSame( 'stable-0', $receiver_output['step_id'] );

		// Abilities without the context fields in their schema stay untouched.
		$echo_output = $report['steps'][1]['output'];
		$this->assertArrayNotHasKey( 'run_id', $echo_output );
		$this->assertArrayNotHasKey( 'workflow_id', $echo_output );
		$this->assertArrayNotHasKey( 'step_id', $echo_output );

		$receiver_input = $report['steps'][0]['input'];
		$this->assertSame( 'stable-0', $receiver_input['step_id'] );
	}

	/**
	 * Steps without a stable id fall back to the step-N node id.
	 */
	public function test_context_step_id_falls_back_to_index(): void {
		$definition = array(
			'steps' => array(
				array(
					'ability' => 'baton-test/context-receiver',
					'input'   => array(),
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $definition, 42 );

		$this->assertSame( 'step-0', $report['steps'][0]['output']['step_id'] );
	}

	/**
	 * User-supplied context values are never overwritten by the runner.
	 */
	public function test_context_injection_does_not_override_user_values(): void {
		$definition = array(
			'steps' => array(
				array(
					'ability' => 'baton-test/context-receiver',
					'input'   => array( 'run_id' => 'caller-pinned' ),
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $definition, 42 );

		$this->assertSame( 'caller-pinned', $report['steps'][0]['output']['run_id'] );
		$this->assertSame( 42, $report['steps'][0]['output']['workflow_id'] );
		$this->assertSame( 'caller-pinned', $report['steps'][0]['input']['run_id'] );
	}

	// ──────────────────────────────────────────────────────────────
	// Save → Load round trip (same run) + row attribution
	// ──────────────────────────────────────────────────────────────

	/**
	 * save → load in the same run returns the saved payload, and the
	 * stored row carries the report's run_id/workflow scope.
	 */
	public function test_save_then_load_same_run_round_trip(): void {
		$definition = array(
			'steps' => array(
				array(
					'ability' => 'baton-test/echo',
					'input'   => array( 'hello' => 'world' ),
				),
				array(
					'ability'        => 'baton/save-output',
					'input'          => array(
						'label' => 'artifact',
					),
					'input_mappings' => array(
						array(
							'source' => 'previous',
							'path'   => 'hello',
							'target' => 'value',
						),
					),
				),
				array(
					'ability' => 'baton/load-output',
					'input'   => array( 'label' => 'artifact' ),
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $definition );

		$this->assertTrue( $report['success'], (string) ( $report['error'] ?? '' ) );
		$this->assertCount( 3, $report['steps'] );
		$this->assertTrue( $report['steps'][1]['success'], (string) ( $report['steps'][1]['error'] ?? '' ) );
		$this->assertSame( 'world', $report['steps'][2]['output'] );

		$row = Baton_Saved_Values::find( 'artifact', 0 );

		$this->assertIsArray( $row );
		$this->assertSame( $report['run_id'], $row['run_id'] );
		$this->assertSame( 0, $row['workflow_id'] );
		$this->assertSame( 'step-1', $row['step_id'] );
		$this->assertSame( 'world', $row['value'] );

		$this->assertSame( 1, $this->rows_for_run( (string) $report['run_id'] ) );
	}

	/**
	 * The cursor pattern across runs: run 1 keeps state, run 2 loads it,
	 * run 3 overwrites it — one state row, newest wins, no run bookkeeping.
	 */
	public function test_cross_run_cursor_pattern(): void {
		$keep_step = array(
			'ability' => 'baton/save-state',
			'input'   => array(
				'label' => 'last_cursor',
				'value' => array( 'cursor' => 1 ),
			),
		);

		$run_a = Baton_Workflow_Runner::run( array( 'steps' => array( $keep_step ) ), 7 );
		$this->assertTrue( $run_a['success'], (string) ( $run_a['error'] ?? '' ) );

		$load_step = array(
			'ability' => 'baton/load-output',
			'input'   => array( 'label' => 'last_cursor' ),
		);

		$run_b = Baton_Workflow_Runner::run( array( 'steps' => array( $load_step ) ), 7 );
		$this->assertTrue( $run_b['success'], (string) ( $run_b['error'] ?? '' ) );
		$this->assertSame( array( 'cursor' => 1 ), $run_b['steps'][0]['output'] );
		$this->assertNotSame( $run_a['run_id'], $run_b['run_id'] );

		$run_c = Baton_Workflow_Runner::run(
			array(
				'steps' => array(
					array(
						'ability' => 'baton/save-state',
						'input'   => array(
							'label' => 'last_cursor',
							'value' => array( 'cursor' => 9 ),
						),
					),
					$load_step,
				),
			),
			7
		);

		$this->assertTrue( $run_c['success'], (string) ( $run_c['error'] ?? '' ) );
		$this->assertSame( array( 'cursor' => 9 ), $run_c['steps'][1]['output'] );
		$this->assertSame( 1, $this->rows_for_run( (string) $run_c['run_id'] ) );

		$rows = $this->rows_raw_for_run( (string) $run_c['run_id'] );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'state', $rows[0]['kind'] );
		$this->assertSame( 'step-0', $rows[0]['step_id'] );
	}

	/**
	 * load-output not-found is a loud workflow failure.
	 */
	public function test_load_not_found_fails_the_workflow(): void {
		$definition = array(
			'steps' => array(
				array(
					'ability' => 'baton/load-output',
					'input'   => array( 'label' => 'never-saved' ),
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $definition, 7 );

		$this->assertFalse( $report['success'] );
		$this->assertFalse( $report['steps'][0]['success'] );
		$this->assertStringContainsString( 'never-saved', (string) ( $report['steps'][0]['error'] ?? '' ) );
	}

	/**
	 * A log step inside a loop writes one row per iteration, all sharing the
	 * run's run_id and the writing step's node id.
	 */
	public function test_log_rows_in_loop_share_run_id(): void {
		$definition = array(
			'steps' => array(
				array(
					'ability'        => 'baton-test/orders-producer',
					'input'          => array(),
					'input_mappings' => array(),
				),
				array(
					'ability'        => 'baton/log',
					'input'          => array( 'message' => 'processing order' ),
					'input_mappings' => array(
						array(
							'source' => 'previous',
							'path'   => 'orders.*.id',
							'target' => 'context',
						),
					),
					'loop'           => array( 'field' => 'context' ),
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $definition, 7 );

		$this->assertTrue( $report['success'], (string) ( $report['error'] ?? '' ) );
		$this->assertCount( 2, $report['steps'] );
		$this->assertSame( 3, $report['steps'][1]['loop']['iterations'] );

		$run_id = (string) $report['run_id'];
		$this->assertSame( 3, $this->rows_for_run( $run_id ) );

		$rows = $this->rows_raw_for_run( $run_id );
		$this->assertCount( 3, $rows );
		foreach ( $rows as $log_row ) {
			$this->assertSame( 'log', $log_row['kind'] );
			$this->assertSame( 7, (int) $log_row['workflow_id'] );
			$this->assertSame( 'step-1', $log_row['step_id'] );
			$this->assertSame( 'processing order', $log_row['label'] );
			$stored = json_decode( (string) $log_row['value'], true );
			$this->assertSame( 'processing order', $stored['message'] );
			$this->assertIsInt( $stored['context'] );
			$this->assertContains( $stored['context'], array( 101, 202, 303 ) );
		}
	}
}