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
