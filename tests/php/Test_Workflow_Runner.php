<?php
/**
 * @package Baton
 */

declare( strict_types=1 );

/**
 * Tests for Baton_Workflow_Runner.
 */
class Test_Workflow_Runner extends WP_UnitTestCase {


	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'Abilities API is not available in this WordPress version.' );
		}

		wp_set_current_user( 1 );

		if ( ! function_exists( 'baton_tests_ensure_abilities_registered' ) ) {
			require_once dirname( __DIR__ ) . '/fixtures/test-abilities.php';
		}

		$registration_error = baton_tests_ensure_abilities_registered();
		if ( '' !== $registration_error ) {
			$this->fail( $registration_error );
		}

		if ( ! function_exists( 'wp_get_ability' ) || ! wp_get_ability( 'baton-test/echo' ) ) {
			$this->fail( 'Test ability baton-test/echo is not registered after ensure.' );
		}

	}

	public function test_cycle_detection(): void {
		$definition = array(
			'steps' => array(
				array(
					'ability' => 'baton-test/echo',
					'input'   => array(),
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $definition, 42, array( 42 ) );

		$this->assertFalse( $report['success'] );
		$this->assertStringContainsString( 'cycle', strtolower( (string) ( $report['error'] ?? '' ) ) );
	}

	public function test_run_echo_ability_step(): void {
		$definition = array(
			'initial_input' => array(),
			'steps'         => array(
				array(
					'ability' => 'baton-test/echo',
					'input'   => array(
						'hello' => 'world',
					),
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $definition );

		$this->assertTrue(
			$report['success'],
			isset( $report['error'] ) ? (string) $report['error'] : wp_json_encode( $report )
		);
		$this->assertCount( 1, $report['steps'] );
		$this->assertTrue(
			$report['steps'][0]['success'],
			isset( $report['steps'][0]['error'] ) ? (string) $report['steps'][0]['error'] : wp_json_encode( $report['steps'][0] )
		);
		$this->assertSame( 'world', $report['steps'][0]['output']['hello'] ?? null );
	}

	public function test_nested_source_path_mapping(): void {
		$definition = array(
			'initial_input' => array(),
			'steps'         => array(
				array(
					'ability'       => 'baton-test/order-producer',
					'input'         => array(),
					'input_mappings' => array(),
				),
				array(
					'ability'       => 'baton-test/echo',
					'input'         => array(),
					'input_mappings' => array(
						array(
							'source' => 'previous',
							'path'   => 'order.id',
							'target' => 'order_id',
						),
						array(
							'source' => 'previous',
							'path'   => 'order.customer.email',
							'target' => 'customer_email',
						),
					),
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $definition );

		$this->assertTrue(
			$report['success'],
			isset( $report['error'] ) ? (string) $report['error'] : wp_json_encode( $report )
		);
		$this->assertCount( 2, $report['steps'] );
		$this->assertTrue( $report['steps'][1]['success'] );
		$this->assertSame( 123, $report['steps'][1]['output']['order_id'] ?? null );
		$this->assertSame( 'buyer@example.com', $report['steps'][1]['output']['customer_email'] ?? null );
	}

	public function test_nested_target_path_mapping(): void {
		$definition = array(
			'initial_input' => array(),
			'steps'         => array(
				array(
					'ability'       => 'baton-test/order-producer',
					'input'         => array(),
					'input_mappings' => array(),
				),
				array(
					'ability'       => 'baton-test/order-consumer',
					'input'         => array(),
					'input_mappings' => array(
						array(
							'source' => 'previous',
							'path'   => 'order.id',
							'target' => 'order.id',
						),
						array(
							'source' => 'previous',
							'path'   => 'order.customer.email',
							'target' => 'customer_email',
						),
					),
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $definition );

		$this->assertTrue(
			$report['success'],
			isset( $report['error'] ) ? (string) $report['error'] : wp_json_encode( $report )
		);
		$this->assertCount( 2, $report['steps'] );
		$this->assertTrue( $report['steps'][1]['success'] );

		$consumed = json_decode( $report['steps'][1]['output']['received'] ?? '', true );
		$this->assertSame( 123, $consumed['order']['id'] ?? null );
		$this->assertSame( 'buyer@example.com', $consumed['customer_email'] ?? null );
	}

	public function test_wildcard_array_mapping(): void {
		$definition = array(
			'initial_input' => array(),
			'steps'         => array(
				array(
					'ability'       => 'baton-test/orders-producer',
					'input'         => array(),
					'input_mappings' => array(),
				),
				array(
					'ability'       => 'baton-test/order-ids-consumer',
					'input'         => array(),
					'input_mappings' => array(
						array(
							'source' => 'previous',
							'path'   => 'orders.*.id',
							'target' => 'order_ids',
						),
					),
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $definition );

		$this->assertTrue(
			$report['success'],
			isset( $report['error'] ) ? (string) $report['error'] : wp_json_encode( $report )
		);
		$this->assertCount( 2, $report['steps'] );
		$this->assertTrue( $report['steps'][1]['success'] );

		$consumed = json_decode( $report['steps'][1]['output']['received'] ?? '', true );
		$this->assertSame(
			array( 101, 202, 303 ),
			$consumed['order_ids'] ?? null
		);
	}

	public function test_loop_over_array_field(): void {
		$definition = array(
			'initial_input' => array(),
			'steps'         => array(
				array(
					'ability'        => 'baton-test/orders-producer',
					'input'          => array(),
					'input_mappings' => array(),
				),
				array(
					'ability'        => 'baton-test/process-order',
					'input'          => array(),
					'input_mappings' => array(
						array(
							'source' => 'previous',
							'path'   => 'orders.*.id',
							'target' => 'order_id',
						),
					),
					'loop'           => array(
						'field' => 'order_id',
					),
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $definition );

		$this->assertTrue(
			$report['success'],
			isset( $report['error'] ) ? (string) $report['error'] : wp_json_encode( $report )
		);
		$this->assertCount( 2, $report['steps'] );

		// Step 1 (orders-producer) ran once and produced the array.
		$this->assertTrue( $report['steps'][0]['success'] );
		$this->assertArrayNotHasKey( 'loop', $report['steps'][0] );

		// Step 2 (process-order) looped 3 times.
		$this->assertTrue( $report['steps'][1]['success'] );
		$this->assertArrayHasKey( 'loop', $report['steps'][1] );
		$this->assertSame( 'order_id', $report['steps'][1]['loop']['field'] );
		$this->assertSame( 3, $report['steps'][1]['loop']['iterations'] );

		// Output is a flat array of per-iteration outputs.
		$outputs = $report['steps'][1]['output'];
		$this->assertCount( 3, $outputs );
		$this->assertSame( 101, $outputs[0]['processed_id'] ?? null );
		$this->assertSame( 'processed', $outputs[0]['status'] ?? null );
		$this->assertSame( 202, $outputs[1]['processed_id'] ?? null );
		$this->assertSame( 303, $outputs[2]['processed_id'] ?? null );
	}

	public function test_loop_with_static_input_array(): void {
		$definition = array(
			'initial_input' => array(),
			'steps'         => array(
				array(
					'ability' => 'baton-test/process-order',
					'input'   => array(
						'order_id' => array( 10, 20, 30 ),
						'action'   => 'sync',
					),
					'loop'    => array(
						'field' => 'order_id',
					),
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $definition );

		$this->assertTrue(
			$report['success'],
			isset( $report['error'] ) ? (string) $report['error'] : wp_json_encode( $report )
		);
		$this->assertCount( 1, $report['steps'] );
		$this->assertTrue( $report['steps'][0]['success'] );
		$this->assertSame( 3, $report['steps'][0]['loop']['iterations'] );

		$outputs = $report['steps'][0]['output'];
		$this->assertSame( 10, $outputs[0]['processed_id'] ?? null );
		$this->assertSame( 'synced', $outputs[0]['status'] ?? null );
		$this->assertSame( 20, $outputs[1]['processed_id'] ?? null );
		$this->assertSame( 30, $outputs[2]['processed_id'] ?? null );
	}

	public function test_loop_empty_array_skips_step(): void {
		$definition = array(
			'initial_input' => array(),
			'steps'         => array(
				array(
					'ability' => 'baton-test/process-order',
					'input'   => array(
						'order_id' => array(),
					),
					'loop'    => array(
						'field' => 'order_id',
					),
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $definition );

		$this->assertTrue( $report['success'] );
		$this->assertCount( 1, $report['steps'] );
		$this->assertTrue( $report['steps'][0]['success'] );
		$this->assertSame( 0, $report['steps'][0]['loop']['iterations'] );
		$this->assertSame( array(), $report['steps'][0]['output'] );
		$this->assertNotEmpty( $report['steps'][0]['warnings'] );
	}

	public function test_loop_non_array_field_falls_back_to_single(): void {
		$definition = array(
			'initial_input' => array(),
			'steps'         => array(
				array(
					'ability' => 'baton-test/process-order',
					'input'   => array(
						'order_id' => 42,
					),
					'loop'    => array(
						'field' => 'order_id',
					),
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $definition );

		$this->assertTrue( $report['success'] );
		$this->assertCount( 1, $report['steps'] );
		$this->assertTrue( $report['steps'][0]['success'] );
		// No loop metadata since it fell back to single execution.
		$this->assertArrayNotHasKey( 'loop', $report['steps'][0] );
		$this->assertSame( 42, $report['steps'][0]['output']['processed_id'] ?? null );
		$this->assertNotEmpty( $report['steps'][0]['warnings'] );
	}

	public function test_loop_with_dot_path_field(): void {
		$definition = array(
			'initial_input' => array(),
			'steps'         => array(
				array(
					'ability'        => 'baton-test/order-producer',
					'input'          => array(),
					'input_mappings' => array(),
				),
				array(
					'ability'        => 'baton-test/process-order',
					'input'          => array(),
					'input_mappings' => array(
						array(
							'source' => 'previous',
							'path'   => 'order.id',
							'target' => 'order_id',
						),
					),
					'loop'           => array(
						'field' => 'order_id',
					),
				),
			),
		);

		// Step 1 produces a single order (not an array), so the loop field
		// resolves to a single integer. The loop falls back to single execution
		// with a warning.
		$report = Baton_Workflow_Runner::run( $definition );

		$this->assertTrue( $report['success'] );
		$this->assertArrayNotHasKey( 'loop', $report['steps'][1] );
		$this->assertSame( 123, $report['steps'][1]['output']['processed_id'] ?? null );
	}

	public function test_loop_output_usable_by_wildcard_downstream(): void {
		$definition = array(
			'initial_input' => array(),
			'steps'         => array(
				array(
					'ability'        => 'baton-test/orders-producer',
					'input'          => array(),
					'input_mappings' => array(),
				),
				array(
					'ability'        => 'baton-test/process-order',
					'input'          => array(),
					'input_mappings' => array(
						array(
							'source' => 'previous',
							'path'   => 'orders.*.id',
							'target' => 'order_id',
						),
					),
					'loop'           => array(
						'field' => 'order_id',
					),
				),
				array(
					'ability'        => 'baton-test/order-ids-consumer',
					'input'          => array(),
					'input_mappings' => array(
						array(
							'source' => 'previous',
							'path'   => '*.processed_id',
							'target' => 'order_ids',
						),
					),
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $definition );

		$this->assertTrue(
			$report['success'],
			isset( $report['error'] ) ? (string) $report['error'] : wp_json_encode( $report )
		);
		$this->assertCount( 3, $report['steps'] );

		// Step 2 (process-order) looped 3 times.
		$this->assertSame( 3, $report['steps'][1]['loop']['iterations'] );

		// Step 3 received [101, 202, 303] via wildcard on the loop output array.
		$consumed = json_decode( $report['steps'][2]['output']['received'] ?? '', true );
		$this->assertSame(
			array( 101, 202, 303 ),
			$consumed['order_ids'] ?? null
		);
	}

	public function test_cycle_detection_indirect(): void {
		// Saving a workflow re-registers its ability outside wp_abilities_api_init (WP 6.9 gate).
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::unregister' );
		$this->setExpectedIncorrectUsage( 'wp_register_ability' );
		// Workflow 1 calls workflow 2 which calls workflow 1.
		$definition_2 = array(
			'steps' => array(
				array(
					'ability' => 'baton/workflow-1',
					'input'   => array(),
				),
			),
		);

		// Save workflow 2.
		$post_id_2 = self::factory()->post->create(
			array(
				'post_type'  => Baton_Workflow_CPT::POST_TYPE,
				'post_title' => 'Workflow 2',
			)
		);
		Baton_Workflow_CPT::save_definition( $post_id_2, $definition_2 );

		// Workflow 1 calls workflow 2.
		$definition_1 = array(
			'steps' => array(
				array(
					'ability' => 'baton/workflow-2',
					'input'   => array(),
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $definition_1, 1, array( 1 ) );

		$this->assertFalse( $report['success'] );
		$this->assertStringContainsString( 'cycle', strtolower( (string) ( $report['error'] ?? '' ) ) );

		wp_delete_post( $post_id_2, true );
	}

	public function test_run_no_steps_returns_error(): void {
		$definition = array(
			'initial_input' => array(),
			'steps'         => array(),
		);

		$report = Baton_Workflow_Runner::run( $definition );

		$this->assertFalse( $report['success'] );
		$this->assertStringContainsString( 'no steps', strtolower( (string) ( $report['error'] ?? '' ) ) );
	}

	public function test_run_missing_ability_slug(): void {
		$definition = array(
			'steps' => array(
				array(
					'ability' => '',
					'input'   => array(),
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $definition );

		$this->assertFalse( $report['success'] );
		$this->assertStringContainsString( 'missing', strtolower( (string) ( $report['error'] ?? '' ) ) );
	}

	public function test_run_unregistered_ability(): void {
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );
		$definition = array(
			'steps' => array(
				array(
					'ability' => 'baton-test/nonexistent',
					'input'   => array(),
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $definition );

		$this->assertFalse( $report['success'] );
		$this->assertStringContainsString( 'not found', strtolower( (string) ( $report['error'] ?? '' ) ) );
	}

	public function test_multi_step_chain_static_input(): void {
		// Step 1: multiply 5 * 3 = {result: 15}.
		// Step 2: echo (static input only, no mapping).
		$definition = array(
			'steps' => array(
				array(
					'ability' => 'baton-test/multiply',
					'input'   => array(
						'number' => 5,
						'factor' => 3,
					),
				),
				array(
					'ability' => 'baton-test/echo',
					'input'   => array(
						'static' => 'value',
					),
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $definition );

		$this->assertTrue( $report['success'], (string) ( $report['error'] ?? '' ) );
		$this->assertCount( 2, $report['steps'] );

		// Step 1 output.
		$this->assertTrue( $report['steps'][0]['success'] );
		$this->assertSame( 15, $report['steps'][0]['output']['result'] );

		// Step 2 output (static only, no mapping from previous).
		$this->assertTrue( $report['steps'][1]['success'] );
		$this->assertSame( 'value', $report['steps'][1]['output']['static'] );
	}

	public function test_multi_step_chain_with_input_mappings(): void {
		// Step 1: multiply 5 * 3 = {result: 15}.
		// Step 2: multiply with mapping: result → number, static factor = 2.
		$definition = array(
			'steps' => array(
				array(
					'ability' => 'baton-test/multiply',
					'input'   => array(
						'number' => 5,
						'factor' => 3,
					),
				),
				array(
					'ability'       => 'baton-test/multiply',
					'input'         => array(
						'factor' => 2,
					),
					'input_mappings' => array(
						array(
							'source' => 'previous',
							'path'   => 'result',
							'target' => 'number',
						),
					),
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $definition );

		$this->assertTrue( $report['success'], (string) ( $report['error'] ?? '' ) );
		$this->assertCount( 2, $report['steps'] );

		// Step 1: 5 * 3 = 15.
		$this->assertSame( 15, $report['steps'][0]['output']['result'] );

		// Step 2: 15 * 2 = 30.
		$this->assertSame( 30, $report['steps'][1]['output']['result'] );
	}

	public function test_use_previous_output_merges_array_output(): void {
		// Step 1: echo {a: 1, b: 2}.
		// Step 2: echo with use_previous_output=true, static {c: 3}.
		// Expected: previous output merged with static (static wins).
		$definition = array(
			'steps' => array(
				array(
					'ability' => 'baton-test/echo',
					'input'   => array(
						'a' => 1,
						'b' => 2,
					),
				),
				array(
					'ability'             => 'baton-test/echo',
					'input'               => array( 'c' => 3 ),
					'use_previous_output' => true,
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $definition );

		$this->assertTrue( $report['success'] );
		$this->assertCount( 2, $report['steps'] );

		// Step 2 should have a, b from previous + c from static.
		$this->assertSame( 1, $report['steps'][1]['output']['a'] );
		$this->assertSame( 2, $report['steps'][1]['output']['b'] );
		$this->assertSame( 3, $report['steps'][1]['output']['c'] );
	}

	public function test_use_previous_output_with_no_previous(): void {
		// First step with use_previous_output=true but no previous output.
		$definition = array(
			'steps' => array(
				array(
					'ability'             => 'baton-test/echo',
					'input'               => array( 'x' => 1 ),
					'use_previous_output' => true,
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $definition );

		$this->assertTrue( $report['success'] );
		$this->assertSame( 1, $report['steps'][0]['output']['x'] );
	}

	public function test_initial_input_merged_into_first_step_without_mappings(): void {
		// Step 1: echo with no static input, but workflow has initial_input.
		// Without mappings, initial_input is merged into step 1.
		$definition = array(
			'initial_input' => array( 'seed' => 'initial-value' ),
			'steps'         => array(
				array(
					'ability' => 'baton-test/echo',
					'input'   => array( 'static' => 'val' ),
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $definition );

		$this->assertTrue( $report['success'] );
		// initial_input merged, then static merged on top (static wins for same keys).
		$this->assertSame( 'initial-value', $report['steps'][0]['output']['seed'] );
		$this->assertSame( 'val', $report['steps'][0]['output']['static'] );
	}

	public function test_initial_input_available_via_mapping_on_step_1(): void {
		// Step 1: echo with a mapping from initial source.
		$definition = array(
			'initial_input' => array( 'api_key' => 'secret-123' ),
			'steps'         => array(
				array(
					'ability'       => 'baton-test/echo',
					'input'         => array(),
					'input_mappings' => array(
						array(
							'source' => 'initial',
							'path'   => 'api_key',
							'target' => 'key',
						),
					),
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $definition );

		$this->assertTrue( $report['success'] );
		$this->assertSame( 'secret-123', $report['steps'][0]['output']['key'] );
	}

	public function test_initial_input_not_available_on_step_2(): void {
		// initial_input should only be available to step 1.
		$definition = array(
			'initial_input' => array( 'secret' => 'value' ),
			'steps'         => array(
				array(
					'ability' => 'baton-test/echo',
					'input'   => array(),
				),
				array(
					'ability'       => 'baton-test/echo',
					'input'         => array(),
					'input_mappings' => array(
						array(
							'source' => 'initial',
							'path'   => 'secret',
							'target' => 'leaked',
						),
					),
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $definition );

		$this->assertTrue( $report['success'] );
		// Step 2 maps from initial, but runner passes empty initial_input for step 2.
		// So 'leaked' should not be set (mapping produces a warning).
		$this->assertArrayNotHasKey( 'leaked', $report['steps'][1]['output'] );
	}

	public function test_scalar_ability_with_mapping(): void {
		// Step 1: echo {value: 'hello'}.
		// Step 2: uppercase (scalar input) with mapping from previous value.
		$definition = array(
			'steps' => array(
				array(
					'ability' => 'baton-test/echo',
					'input'   => array( 'value' => 'hello' ),
				),
				array(
					'ability'       => 'baton-test/uppercase',
					'input'         => array(),
					'input_mappings' => array(
						array(
							'source' => 'previous',
							'path'   => 'value',
							'target' => '',
						),
					),
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $definition );

		$this->assertTrue( $report['success'] );
		$this->assertCount( 2, $report['steps'] );
		$this->assertSame( 'HELLO', $report['steps'][1]['output'] );
	}

	public function test_step_error_short_circuits_chain(): void {
		// Step 1: fail (returns WP_Error).
		// Step 2: echo (should not execute).
		$definition = array(
			'steps' => array(
				array(
					'ability' => 'baton-test/fail',
					'input'   => array(),
				),
				array(
					'ability' => 'baton-test/echo',
					'input'   => array( 'should_not' => 'run' ),
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $definition );

		$this->assertFalse( $report['success'] );
		$this->assertCount( 1, $report['steps'] );
		$this->assertFalse( $report['steps'][0]['success'] );
		$this->assertStringContainsString( 'intentionally failed', (string) ( $report['steps'][0]['error'] ?? '' ) );
	}

	public function test_nested_workflow_execution(): void {
		// Saving a workflow re-registers its ability outside wp_abilities_api_init (WP 6.9 gate).
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::unregister' );
		$this->setExpectedIncorrectUsage( 'wp_register_ability' );
		// Inner workflow: echo with static input.
		$inner_definition = array(
			'steps' => array(
				array(
					'ability' => 'baton-test/echo',
					'input'   => array( 'inner' => 'data' ),
				),
			),
		);

		$inner_id = self::factory()->post->create(
			array(
				'post_type'  => Baton_Workflow_CPT::POST_TYPE,
				'post_title' => 'Inner Workflow',
			)
		);
		Baton_Workflow_CPT::save_definition( $inner_id, $inner_definition );
		// Re-fire the registry init so the just-saved workflow ability registers (WP 6.9 gate).
		// The re-fire re-runs the fixtures' priority-0 registration callback, double-registering abilities.
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::register' );
		do_action( 'wp_abilities_api_init' );

		// Outer workflow calls baton/workflow-{inner_id}.
		$outer_definition = array(
			'steps' => array(
				array(
					'ability' => 'baton/workflow-' . $inner_id,
					'input'   => array(),
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $outer_definition );

		$this->assertTrue( $report['success'], (string) ( $report['error'] ?? '' ) );
		$this->assertCount( 1, $report['steps'] );
		$this->assertTrue( $report['steps'][0]['success'] );

		// The nested workflow's last step output should be returned.
		$this->assertSame( 'data', $report['steps'][0]['output']['inner'] ?? null );

		wp_delete_post( $inner_id, true );
	}

	public function test_nested_workflow_passes_input_as_initial_input(): void {
		// Saving a workflow re-registers its ability outside wp_abilities_api_init (WP 6.9 gate).
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::unregister' );
		$this->setExpectedIncorrectUsage( 'wp_register_ability' );
		// Inner workflow: echo (returns its input).
		$inner_definition = array(
			'steps' => array(
				array(
					'ability' => 'baton-test/echo',
					'input'   => array(),
				),
			),
		);

		$inner_id = self::factory()->post->create(
			array(
				'post_type'  => Baton_Workflow_CPT::POST_TYPE,
				'post_title' => 'Inner Workflow',
			)
		);
		Baton_Workflow_CPT::save_definition( $inner_id, $inner_definition );
		// Re-fire the registry init so the just-saved workflow ability registers (WP 6.9 gate).
		// The re-fire re-runs the fixtures' priority-0 registration callback, double-registering abilities.
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::register' );
		do_action( 'wp_abilities_api_init' );

		// Outer workflow passes input to nested workflow.
		$outer_definition = array(
			'steps' => array(
				array(
					'ability' => 'baton/workflow-' . $inner_id,
					'input'   => array( 'passed_through' => 'yes' ),
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $outer_definition );

		$this->assertTrue( $report['success'] );
		// The input should be merged into the inner workflow's initial_input and echoed back.
		$this->assertSame( 'yes', $report['steps'][0]['output']['passed_through'] ?? null );

		wp_delete_post( $inner_id, true );
	}

	public function test_nested_workflow_not_found_returns_error(): void {
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );
		$definition = array(
			'steps' => array(
				array(
					'ability' => 'baton/workflow-999999',
					'input'   => array(),
				),
			),
		);

		$report = Baton_Workflow_Runner::run( $definition );

		$this->assertFalse( $report['success'] );
		$this->assertStringContainsString( 'not found', strtolower( (string) ( $report['error'] ?? '' ) ) );
	}

	public function test_baton_before_step_fires(): void {
		$captured = array();
		add_action(
			'baton_before_step',
			static function ( $workflow_id, $step_index, $input, $step ) use ( &$captured ): void {
				$captured[] = array(
					'workflow_id' => $workflow_id,
					'step_index' => $step_index,
					'ability'    => $step['ability'] ?? '',
				);
			},
			10,
			4
		);

		$definition = array(
			'steps' => array(
				array(
					'ability' => 'baton-test/echo',
					'input'   => array( 'x' => 1 ),
				),
			),
		);

		Baton_Workflow_Runner::run( $definition, 99 );

		$this->assertCount( 1, $captured );
		$this->assertSame( 99, $captured[0]['workflow_id'] );
		$this->assertSame( 0, $captured[0]['step_index'] );
		$this->assertSame( 'baton-test/echo', $captured[0]['ability'] );

		remove_all_actions( 'baton_before_step' );
	}

	public function test_baton_after_step_fires(): void {
		$captured = array();
		add_action(
			'baton_after_step',
			static function ( $workflow_id, $step_index, $input, $output, $step ) use ( &$captured ): void {
				$captured[] = array(
					'workflow_id' => $workflow_id,
					'step_index'  => $step_index,
					'output'      => $output,
				);
			},
			10,
			5
		);

		$definition = array(
			'steps' => array(
				array(
					'ability' => 'baton-test/echo',
					'input'   => array( 'x' => 1 ),
				),
			),
		);

		Baton_Workflow_Runner::run( $definition, 77 );

		$this->assertCount( 1, $captured );
		$this->assertSame( 77, $captured[0]['workflow_id'] );
		$this->assertSame( 0, $captured[0]['step_index'] );
		$this->assertSame( array( 'x' => 1 ), $captured[0]['output'] );

		remove_all_actions( 'baton_after_step' );
	}

	public function test_baton_after_step_does_not_fire_on_error(): void {
		$after_count = 0;
		add_action(
			'baton_after_step',
			static function () use ( &$after_count ): void {
				$after_count++;
			}
		);

		$definition = array(
			'steps' => array(
				array(
					'ability' => 'baton-test/fail',
					'input'   => array(),
				),
				array(
					'ability' => 'baton-test/echo',
					'input'   => array(),
				),
			),
		);

		Baton_Workflow_Runner::run( $definition );

		// After hook should not fire because the only step that ran failed.
		$this->assertSame( 0, $after_count );

		remove_all_actions( 'baton_after_step' );
	}

	public function test_resolve_step_input_with_mappings_only(): void {
		$mappings = array(
			array(
				'source' => 'previous',
				'path'   => 'result',
				'target' => 'number',
			),
		);
		$previous = array( 'result' => 15 );

		$resolved = Baton_Workflow_Runner::resolve_step_input(
			array( 'factor' => 2 ),
			false,
			$previous,
			array(),
			$mappings,
			array( 'type' => 'object', 'properties' => array( 'number' => array( 'type' => 'integer' ) ) )
		);

		// Mappings fill number=15, static provides factor=2.
		$this->assertSame( 15, $resolved['input']['number'] );
		$this->assertSame( 2, $resolved['input']['factor'] );
	}

	public function test_resolve_step_input_static_only_no_mappings(): void {
		$resolved = Baton_Workflow_Runner::resolve_step_input(
			array( 'number' => 5, 'factor' => 3 ),
			false,
			null,
			array(),
			array(),
			array( 'type' => 'object', 'properties' => array() )
		);

		$this->assertSame( array( 'number' => 5, 'factor' => 3 ), $resolved['input'] );
	}

	public function test_resolve_step_input_use_previous_output(): void {
		$resolved = Baton_Workflow_Runner::resolve_step_input(
			array( 'extra' => 'val' ),
			true,
			array( 'a' => 1, 'b' => 2 ),
			array(),
			array(),
			array( 'type' => 'object', 'properties' => array() )
		);

		// Previous merged first, then static merged on top.
		$this->assertSame( 1, $resolved['input']['a'] );
		$this->assertSame( 2, $resolved['input']['b'] );
		$this->assertSame( 'val', $resolved['input']['extra'] );
	}

}
