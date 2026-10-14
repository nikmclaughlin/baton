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

	public function test_sanitize_definition_skips_non_array_steps(): void {
		$raw = array(
			'steps' => array(
				'not-an-array',
				array(
					'ability' => 'baton-test/echo',
					'input'   => array(),
				),
			),
		);

		$result = Baton_Workflow_CPT::sanitize_definition( $raw );

		$this->assertCount( 1, $result['steps'] );
		$this->assertSame( 'baton-test/echo', $result['steps'][0]['ability'] );
	}

	public function test_sanitize_definition_preserves_use_previous_output_flag(): void {
		$raw = array(
			'steps' => array(
				array(
					'ability'             => 'baton-test/echo',
					'input'               => array(),
					'use_previous_output' => true,
				),
			),
		);

		$result = Baton_Workflow_CPT::sanitize_definition( $raw );

		$this->assertTrue( $result['steps'][0]['use_previous_output'] );
	}

	public function test_sanitize_definition_defaults_use_previous_output_to_false(): void {
		$raw = array(
			'steps' => array(
				array(
					'ability' => 'baton-test/echo',
					'input'   => array(),
				),
			),
		);

		$result = Baton_Workflow_CPT::sanitize_definition( $raw );

		$this->assertFalse( $result['steps'][0]['use_previous_output'] );
	}

	public function test_sanitize_definition_sanitizes_input_mappings(): void {
		$raw = array(
			'steps' => array(
				array(
					'ability' => 'baton-test/echo',
					'input'   => array(),
					'input_mappings' => array(
						array(
							'source' => 'previous',
							'path'   => 'user<!>.email',
							'target' => 'em!ail',
						),
						array(
							'source' => 'bogus',
							'path'   => 'name',
							'target' => 'name',
						),
						array(
							'source' => 'initial',
							'path'   => '',
							'target' => 'missing_path',
						),
					),
				),
			),
		);

		$result = Baton_Workflow_CPT::sanitize_definition( $raw );

		// First mapping: path and target stripped of invalid chars.
		$this->assertCount( 2, $result['steps'][0]['input_mappings'] );
		$this->assertSame( 'user.email', $result['steps'][0]['input_mappings'][0]['path'] );
		$this->assertSame( 'email', $result['steps'][0]['input_mappings'][0]['target'] );

		// Second mapping: bogus source defaults to 'previous'.
		$this->assertSame( 'previous', $result['steps'][0]['input_mappings'][1]['source'] );

		// Third mapping (empty path) should have been dropped.
	}

	public function test_sanitize_definition_missing_steps_returns_default(): void {
		$raw = array( 'initial_input' => array( 'x' => 1 ) );

		$result = Baton_Workflow_CPT::sanitize_definition( $raw );

		$this->assertSame( array(), $result['steps'] );
		$this->assertSame( array( 'x' => 1 ), $result['initial_input'] );
	}

	public function test_sanitize_definition_empty_raw_returns_default(): void {
		$result = Baton_Workflow_CPT::sanitize_definition( array() );

		$this->assertSame( array(), $result['initial_input'] );
		$this->assertSame( array(), $result['steps'] );
	}

	public function test_save_and_get_definition_round_trip(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'  => Baton_Workflow_CPT::POST_TYPE,
				'post_title' => 'Test Workflow',
			)
		);

		$definition = array(
			'initial_input' => array( 'seed' => 'abc' ),
			'steps'         => array(
				array(
					'ability'             => 'baton-test/echo',
					'input'               => array( 'foo' => 'bar' ),
					'use_previous_output' => false,
					'input_mappings'      => array(
						array(
							'source' => 'previous',
							'path'   => 'result',
							'target' => 'number',
						),
					),
				),
			),
		);

		Baton_Workflow_CPT::save_definition( $post_id, $definition );

		$retrieved = Baton_Workflow_CPT::get_definition( $post_id );

		$this->assertSame( 'abc', $retrieved['initial_input']['seed'] );
		$this->assertCount( 1, $retrieved['steps'] );
		$this->assertSame( 'baton-test/echo', $retrieved['steps'][0]['ability'] );
		$this->assertSame( 'bar', $retrieved['steps'][0]['input']['foo'] );
		$this->assertFalse( $retrieved['steps'][0]['use_previous_output'] );
		$this->assertCount( 1, $retrieved['steps'][0]['input_mappings'] );
		$this->assertSame( 'previous', $retrieved['steps'][0]['input_mappings'][0]['source'] );
		$this->assertSame( 'result', $retrieved['steps'][0]['input_mappings'][0]['path'] );
		$this->assertSame( 'number', $retrieved['steps'][0]['input_mappings'][0]['target'] );

		wp_delete_post( $post_id, true );
	}

	public function test_get_definition_returns_default_when_no_meta(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'  => Baton_Workflow_CPT::POST_TYPE,
				'post_title' => 'Empty Workflow',
			)
		);

		$definition = Baton_Workflow_CPT::get_definition( $post_id );

		$this->assertSame( array(), $definition['initial_input'] );
		$this->assertSame( array(), $definition['steps'] );

		wp_delete_post( $post_id, true );
	}

	public function test_get_definition_returns_default_for_non_array_meta(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'  => Baton_Workflow_CPT::POST_TYPE,
				'post_title' => 'Corrupt Meta Workflow',
			)
		);

		// Simulate corrupt meta (string instead of array).
		update_post_meta( $post_id, Baton_Workflow_CPT::META_DEFINITION, 'not-an-array' );

		$definition = Baton_Workflow_CPT::get_definition( $post_id );

		$this->assertSame( array(), $definition['initial_input'] );
		$this->assertSame( array(), $definition['steps'] );

		wp_delete_post( $post_id, true );
	}

	public function test_default_definition_structure(): void {
		$default = Baton_Workflow_CPT::default_definition();

		$this->assertArrayHasKey( 'initial_input', $default );
		$this->assertArrayHasKey( 'steps', $default );
		$this->assertSame( array(), $default['initial_input'] );
		$this->assertSame( array(), $default['steps'] );
	}

}
