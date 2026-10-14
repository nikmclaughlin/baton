<?php
/**
 * @package Baton
 */

declare( strict_types=1 );

/**
 * Tests for Baton_Workflow_Abilities.
 */
class Test_Workflow_Abilities extends WP_UnitTestCase {

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
	}

	// ──────────────────────────────────────────────────────────────
	// parse_workflow_ability_id()
	// ──────────────────────────────────────────────────────────────

	public function test_parse_workflow_ability_id_valid(): void {
		$this->assertSame( 42, Baton_Workflow_Abilities::parse_workflow_ability_id( 'baton/workflow-42' ) );
	}

	public function test_parse_workflow_ability_id_zero_returns_null(): void {
		$this->assertNull( Baton_Workflow_Abilities::parse_workflow_ability_id( 'baton/workflow-0' ) );
	}

	public function test_parse_workflow_ability_id_non_workflow_ability(): void {
		$this->assertNull( Baton_Workflow_Abilities::parse_workflow_ability_id( 'baton-test/echo' ) );
	}

	public function test_parse_workflow_ability_id_wrong_namespace(): void {
		$this->assertNull( Baton_Workflow_Abilities::parse_workflow_ability_id( 'other/workflow-42' ) );
	}

	public function test_parse_workflow_ability_id_empty_string(): void {
		$this->assertNull( Baton_Workflow_Abilities::parse_workflow_ability_id( '' ) );
	}

	public function test_parse_workflow_ability_id_non_numeric(): void {
		// Non-numeric suffix casts to 0, which returns null.
		$this->assertNull( Baton_Workflow_Abilities::parse_workflow_ability_id( 'baton/workflow-abc' ) );
	}

	// ──────────────────────────────────────────────────────────────
	// get_ability_name()
	// ──────────────────────────────────────────────────────────────

	public function test_get_ability_name(): void {
		$this->assertSame( 'baton/workflow-123', Baton_Workflow_Abilities::get_ability_name( 123 ) );
	}

	// ──────────────────────────────────────────────────────────────
	// get_input_schema()
	// ──────────────────────────────────────────────────────────────

	public function test_get_input_schema_is_object_with_additional_properties(): void {
		$schema = Baton_Workflow_Abilities::get_input_schema();

		$this->assertSame( 'object', $schema['type'] );
		$this->assertTrue( $schema['additionalProperties'] );
	}

	// ──────────────────────────────────────────────────────────────
	// execute_workflow_ability()
	// ──────────────────────────────────────────────────────────────

	public function test_execute_workflow_ability_returns_last_step_output(): void {
		$definition = array(
			'steps' => array(
				array(
					'ability' => 'baton-test/echo',
					'input'   => array( 'hello' => 'world' ),
				),
			),
		);

		$post_id = self::factory()->post->create(
			array(
				'post_type'  => Baton_Workflow_CPT::POST_TYPE,
				'post_title' => 'Executable Workflow',
			)
		);
		Baton_Workflow_CPT::save_definition( $post_id, $definition );

		$result = Baton_Workflow_Abilities::execute_workflow_ability( $post_id );

		$this->assertIsArray( $result );
		$this->assertSame( 'world', $result['hello'] ?? null );

		wp_delete_post( $post_id, true );
	}

	public function test_execute_workflow_ability_passes_input_as_initial_input(): void {
		$definition = array(
			'initial_input' => array( 'existing' => 'val' ),
			'steps'         => array(
				array(
					'ability' => 'baton-test/echo',
					'input'   => array(),
				),
			),
		);

		$post_id = self::factory()->post->create(
			array(
				'post_type'  => Baton_Workflow_CPT::POST_TYPE,
				'post_title' => 'Input Override Workflow',
			)
		);
		Baton_Workflow_CPT::save_definition( $post_id, $definition );

		// Pass caller input that overrides initial_input.
		$result = Baton_Workflow_Abilities::execute_workflow_ability(
			$post_id,
			array( 'caller_key' => 'caller_val' )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'caller_val', $result['caller_key'] ?? null );

		wp_delete_post( $post_id, true );
	}

	public function test_execute_workflow_ability_returns_wp_error_on_failure(): void {
		$definition = array(
			'steps' => array(
				array(
					'ability' => 'baton-test/nonexistent',
					'input'   => array(),
				),
			),
		);

		$post_id = self::factory()->post->create(
			array(
				'post_type'  => Baton_Workflow_CPT::POST_TYPE,
				'post_title' => 'Failing Workflow',
			)
		);
		Baton_Workflow_CPT::save_definition( $post_id, $definition );

		$result = Baton_Workflow_Abilities::execute_workflow_ability( $post_id );

		$this->assertInstanceOf( WP_Error::class, $result );

		wp_delete_post( $post_id, true );
	}

	public function test_execute_workflow_ability_empty_steps_returns_wp_error(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'  => Baton_Workflow_CPT::POST_TYPE,
				'post_title' => 'Empty Steps Workflow',
			)
		);
		Baton_Workflow_CPT::save_definition( $post_id, array( 'steps' => array() ) );

		$result = Baton_Workflow_Abilities::execute_workflow_ability( $post_id );

		$this->assertInstanceOf( WP_Error::class, $result );

		wp_delete_post( $post_id, true );
	}

	public function test_execute_workflow_ability_null_input_uses_stored_initial_input(): void {
		$definition = array(
			'initial_input' => array( 'stored' => 'value' ),
			'steps'         => array(
				array(
					'ability' => 'baton-test/echo',
					'input'   => array(),
				),
			),
		);

		$post_id = self::factory()->post->create(
			array(
				'post_type'  => Baton_Workflow_CPT::POST_TYPE,
				'post_title' => 'Null Input Workflow',
			)
		);
		Baton_Workflow_CPT::save_definition( $post_id, $definition );

		$result = Baton_Workflow_Abilities::execute_workflow_ability( $post_id, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'value', $result['stored'] ?? null );

		wp_delete_post( $post_id, true );
	}
}