<?php
/**
 * @package Baton
 */

declare( strict_types=1 );

/**
 * Tests for Baton_Workflow_CPT definition sanitization.
 */
class Test_Workflow_Cpt extends WP_UnitTestCase {

	public function test_sanitize_definition_sanitizes_initial_input_and_step_input(): void {
		$raw = array(
			'initial_input' => array(
				'note' => '  trimmed  ',
			),
			'steps'         => array(
				array(
					'ability' => 'baton-test/echo',
					'input'   => array(
						'foo' => '<script>alert(1)</script>',
					),
				),
			),
		);

		$result = Baton_Workflow_CPT::sanitize_definition( $raw );

		$this->assertIsArray( $result );
		$this->assertSame( 'trimmed', $result['initial_input']['note'] );
		$this->assertCount( 1, $result['steps'] );
		$this->assertSame( 'baton-test/echo', $result['steps'][0]['ability'] );
		$this->assertStringNotContainsString( '<script>', $result['steps'][0]['input']['foo'] );
	}

	public function test_sanitize_definition_skips_steps_without_ability(): void {
		$raw = array(
			'steps' => array(
				array( 'input' => array( 'x' => 1 ) ),
			),
		);

		$result = Baton_Workflow_CPT::sanitize_definition( $raw );

		$this->assertSame( array(), $result['steps'] );
	}

	public function test_sanitize_definition_preserves_stable_step_id(): void {
		$raw = array(
			'steps' => array(
				array(
					'id'      => 'Fetch Orders',
					'ability' => 'baton-test/echo',
					'input'   => array(),
				),
				array(
					'id'      => 'x' . str_repeat( 'y', 60 ),
					'ability' => 'baton-test/echo',
					'input'   => array(),
				),
			),
		);

		$result = Baton_Workflow_CPT::sanitize_definition( $raw );

		// sanitize_key strips spaces: 'Fetch Orders' -> 'fetchorders'.
		$this->assertSame( 'fetchorders', $result['steps'][0]['id'] );
		// Length-capped at 40 chars.
		$this->assertLessThanOrEqual( 40, strlen( $result['steps'][1]['id'] ) );
	}

	public function test_sanitize_definition_drops_invalid_step_ids(): void {
		$raw = array(
			'steps' => array(
				array(
					'id'      => '   ',
					'ability' => 'baton-test/echo',
					'input'   => array(),
				),
				array(
					'id'      => 12345,
					'ability' => 'baton-test/echo',
					'input'   => array(),
				),
				array(
					'ability' => 'baton-test/echo',
					'input'   => array(),
				),
			),
		);

		$result = Baton_Workflow_CPT::sanitize_definition( $raw );

		$this->assertArrayNotHasKey( 'id', $result['steps'][0] );
		$this->assertArrayNotHasKey( 'id', $result['steps'][1] );
		$this->assertArrayNotHasKey( 'id', $result['steps'][2] );
	}

	public function test_sanitize_layout_keeps_valid_positions(): void {
		$raw = array(
			'input'  => array( 'x' => 40, 'y' => '12.5' ),
			'step-0' => array( 'x' => -10.75, 'y' => 300 ),
			's-a1b2' => array( 'x' => 5, 'y' => 6 ),
		);

		$result = Baton_Workflow_CPT::sanitize_layout( $raw );

		$this->assertSame( 40.0, $result['input']['x'] );
		$this->assertSame( 12.5, $result['input']['y'] );
		$this->assertSame( -10.75, $result['step-0']['x'] );
		$this->assertSame( 300.0, $result['step-0']['y'] );
		$this->assertSame( 5.0, $result['s-a1b2']['x'] );
	}

	public function test_sanitize_layout_drops_malformed_rows(): void {
		$raw = array(
			'nonsense'  => array( 'x' => 1, 'y' => 2 ),
			'__proto__' => array( 'x' => 1, 'y' => 2 ),
			'step-0'    => array( 'x' => 'abc', 'y' => 2 ),
			'step-1'    => array( 'x' => 1 ),
			'step-2'    => 'not-an-array',
		);

		$result = Baton_Workflow_CPT::sanitize_layout( $raw );

		// Shallow-validated rows kept (nonsense/__proto__ pass shape; unknown
		// ids are pruned against the definition later). step-1 has no y ->
		// dropped; step-0's non-numeric x coerces via (float).
		$this->assertSame(
			array( 'nonsense', '__proto__', 'step-0' ),
			array_keys( $result )
		);
		$this->assertSame( 0.0, $result['step-0']['x'] );
	}

	public function test_sanitize_layout_handles_non_array_input(): void {
		$this->assertSame( array(), Baton_Workflow_CPT::sanitize_layout( null ) );
		$this->assertSame( array(), Baton_Workflow_CPT::sanitize_layout( 'x' ) );
		$this->assertSame( array(), Baton_Workflow_CPT::sanitize_layout( 3 ) );
	}

	public function test_prune_layout_keeps_definition_ids_only(): void {
		$definition = array(
			'initial_input' => array(),
			'steps'         => array(
				array( 'ability' => 'baton-test/echo', 'input' => array() ),
				array(
					'id'      => 'fetch_orders',
					'ability' => 'baton-test/echo',
					'input'   => array(),
				),
			),
		);
		$layout = array(
			'input'        => array( 'x' => 1, 'y' => 2 ),
			'step-0'       => array( 'x' => 3, 'y' 	=> 4 ),
			'fetch_orders' => array( 'x' => 5, 'y' => 6 ),
			'ghost-node'   => array( 'x' => 7, 'y' => 8 ),
		);

		$result = Baton_Workflow_CPT::prune_layout( $layout, $definition );

		$this->assertSame(
			array( 'input', 'step-0', 'fetch_orders' ),
			array_keys( $result )
		);
	}

	public function test_sanitize_definition_preserves_loop_config(): void {
		$raw = array(
			'steps' => array(
				array(
					'ability' => 'baton-test/echo',
					'input'   => array(),
					'loop'    => array(
						'field' => 'order_ids',
					),
				),
			),
		);

		$result = Baton_Workflow_CPT::sanitize_definition( $raw );

		$this->assertCount( 1, $result['steps'] );
		$this->assertArrayHasKey( 'loop', $result['steps'][0] );
		$this->assertSame( 'order_ids', $result['steps'][0]['loop']['field'] );
	}

	public function test_sanitize_definition_preserves_loop_with_dot_path_field(): void {
		$raw = array(
			'steps' => array(
				array(
					'ability' => 'baton-test/echo',
					'input'   => array(),
					'loop'    => array(
						'field' => 'order.items',
					),
				),
			),
		);

		$result = Baton_Workflow_CPT::sanitize_definition( $raw );

		$this->assertCount( 1, $result['steps'] );
		$this->assertSame( 'order.items', $result['steps'][0]['loop']['field'] );
	}

	public function test_sanitize_definition_strips_loop_with_empty_field(): void {
		$raw = array(
			'steps' => array(
				array(
					'ability' => 'baton-test/echo',
					'input'   => array(),
					'loop'    => array(
						'field' => '',
					),
				),
			),
		);

		$result = Baton_Workflow_CPT::sanitize_definition( $raw );

		$this->assertCount( 1, $result['steps'] );
		$this->assertArrayNotHasKey( 'loop', $result['steps'][0] );
	}

	public function test_sanitize_definition_strips_loop_without_field_key(): void {
		$raw = array(
			'steps' => array(
				array(
					'ability' => 'baton-test/echo',
					'input'   => array(),
					'loop'    => array(),
				),
			),
		);

		$result = Baton_Workflow_CPT::sanitize_definition( $raw );

		$this->assertCount( 1, $result['steps'] );
		$this->assertArrayNotHasKey( 'loop', $result['steps'][0] );
	}
}
