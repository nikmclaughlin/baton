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

	// ──────────────────────────────────────────────────────────────
	// Cycle detection
	// ──────────────────────────────────────────────────────────────

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

	public function test_cycle_detection_indirect(): void {
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

	// ──────────────────────────────────────────────────────────────
	// Single-step execution
	// ──────────────────────────────────────────────────────────────

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

	// ──────────────────────────────────────────────────────────────
	// Empty steps
	// ──────────────────────────────────────────────────────────────

	public function test_run_no_steps_returns_error(): void {
		$definition = array(
			'initial_input' => array(),
			'steps'         => array(),
		);

		$report = Baton_Workflow_Runner::run( $definition );

		$this->assertFalse( $report['success'] );
		$this->assertStringContainsString( 'no steps', strtolower( (string) ( $report['error'] ?? '' ) ) );
	}

	// ──────────────────────────────────────────────────────────────
	// Missing ability
	// ──────────────────────────────────────────────────────────────

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

	// ──────────────────────────────────────────────────────────────
	// Multi-step chain
	// ──────────────────────────────────────────────────────────────

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

	// ──────────────────────────────────────────────────────────────
	// use_previous_output legacy flag
	// ──────────────────────────────────────────────────────────────

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

	// ──────────────────────────────────────────────────────────────
	// Initial input → step 1
	// ──────────────────────────────────────────────────────────────

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

	// ──────────────────────────────────────────────────────────────
	// Scalar ability in a workflow
	// ──────────────────────────────────────────────────────────────

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

	// ──────────────────────────────────────────────────────────────
	// Error short-circuiting
	// ──────────────────────────────────────────────────────────────

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

	// ──────────────────────────────────────────────────────────────
	// Nested workflows
	// ──────────────────────────────────────────────────────────────

	public function test_nested_workflow_execution(): void {
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

	// ──────────────────────────────────────────────────────────────
	// Hooks
	// ──────────────────────────────────────────────────────────────

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

	// ──────────────────────────────────────────────────────────────
	// resolve_step_input() — direct unit tests
	// ──────────────────────────────────────────────────────────────

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