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
}
