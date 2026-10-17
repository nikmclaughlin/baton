<?php
/**
 * Tests for B4 external execution (MCP via the WordPress MCP Adapter).
 *
 * @package Baton
 */

declare( strict_types=1 );

/**
 * "mcp" surface gating, per-workflow MCP tool registration through the
 * adapter's default-server config filter, tool invocation (the Baton
 * handler runs the workflow and logs), the adapter-absent silent no-op,
 * and the MCP run log row.
 *
 * The MCP Adapter plugin cannot be installed into the wp-env test stack,
 * so the adapter's PHP surface is provided by the shim in
 * tests/php/mcp-adapter-stub.php (loaded per test by the adapter tests;
 * class existence is the runtime gate). The first declared test must stay
 * adapter-absent (never calls ensure_adapter_stub()) — see its docblock.
 */
class Test_Mcp_Integration extends WP_UnitTestCase {

	use Baton_Test_Table_State_Trait;

	/**
	 * Workflow posts created during this test, deleted in tear_down (the
	 * real-table drop in set_up commits away the harness transaction, so
	 * created posts must be removed explicitly).
	 *
	 * @var int[]
	 */
	private array $created_post_ids = array();

	/**
	 * Runs before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'Abilities API is not available in this WordPress version.' );
		}

		$this->drop_baton_table();
		Baton_Saved_Values::install();

		if ( ! function_exists( 'baton_tests_ensure_abilities_registered' ) ) {
			require_once dirname( __DIR__ ) . '/fixtures/test-abilities.php';
		}

		$registration_error = baton_tests_ensure_abilities_registered();
		if ( '' !== $registration_error ) {
			$this->fail( $registration_error );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
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

		$this->drop_baton_table();

		wp_set_current_user( 0 );

		remove_filter( 'mcp_adapter_default_server_config', array( 'Baton_Mcp_Integration', 'add_workflow_tools' ) );
		remove_filter( 'baton_mcp_adapter_active', '__return_false' );

		// Wipe the adapter shim's recorded state when it is loaded.
		if ( class_exists( 'WP\MCP\Domain\Tools\McpTool', false ) ) {
			\WP\MCP\Domain\Tools\McpTool::reset();
		}

		parent::tear_down();
	}

	// ──────────────────────────────────────────────────────────────
	// Adapter-absent behavior
	// ──────────────────────────────────────────────────────────────

	/**
	 * Must run BEFORE any test loads the adapter shim (this is the file's
	 * first declared test; the shim loads per test, so the class gate has
	 * not flipped yet here): with no adapter in the process, registering is
	 * a silent no-op — no hooks attached, no notices.
	 */
	public function test_register_without_adapter_is_noop(): void {
		Baton_Mcp_Integration::register();

		$this->assertFalse( has_filter( 'mcp_adapter_default_server_config', array( 'Baton_Mcp_Integration', 'add_workflow_tools' ) ) );

		// Re-registering stays a no-op.
		Baton_Mcp_Integration::register();

		$this->assertFalse( has_filter( 'mcp_adapter_default_server_config', array( 'Baton_Mcp_Integration', 'add_workflow_tools' ) ) );
	}

	// ──────────────────────────────────────────────────────────────
	// Registration gate + default-server config filter
	// ──────────────────────────────────────────────────────────────

	/**
	 * The baton_mcp_adapter_active filter forces the integration off even
	 * with the adapter present (sites opt out of the MCP channel).
	 */
	public function test_baton_mcp_adapter_active_filter_can_force_disable_integration(): void {
		$this->ensure_adapter_stub();
		add_filter( 'baton_mcp_adapter_active', '__return_false' );

		Baton_Mcp_Integration::register();

		$this->assertFalse( has_filter( 'mcp_adapter_default_server_config', array( 'Baton_Mcp_Integration', 'add_workflow_tools' ) ) );
	}

	/**
	 * With the adapter active, registration hooks the default-server
	 * config filter Baton nominates its tools through.
	 */
	public function test_register_attaches_default_server_config_filter_when_adapter_active(): void {
		$this->ensure_adapter_stub();

		Baton_Mcp_Integration::register();

		$this->assertNotFalse( has_filter( 'mcp_adapter_default_server_config', array( 'Baton_Mcp_Integration', 'add_workflow_tools' ) ) );
	}

	/**
	 * Registered tools ride the default server config untouched by Baton:
	 * existing tool entries are preserved and the workflow tool is appended.
	 */
	public function test_add_workflow_tools_appends_to_existing_tools(): void {
		$this->ensure_adapter_stub();
		$post_id = $this->create_published_workflow( array( 'mcp' ) );

		$config = Baton_Mcp_Integration::add_workflow_tools(
			array(
				'tools' => array(
					'mcp-adapter/discover-abilities',
					'mcp-adapter/get-ability-info',
					'mcp-adapter/execute-ability',
				),
			)
		);

		$this->assertCount( 4, $config['tools'] );
		$this->assertSame( 'mcp-adapter/discover-abilities', $config['tools'][0] );
		$this->assertSame( 'mcp-adapter/get-ability-info', $config['tools'][1] );
		$this->assertSame( 'mcp-adapter/execute-ability', $config['tools'][2] );
		$this->assertInstanceOf( 'WP\MCP\Domain\Tools\McpTool', $config['tools'][3] );
		$this->assertSame( Baton_Mcp_Integration::get_tool_name( $post_id ), $config['tools'][3]->get_name() );
	}

	/**
	 * Only published workflows with the "mcp" surface become tools:
	 * unexposed and unpublished workflows are skipped, and no tool exists
	 * when nothing is exposed.
	 */
	public function test_add_workflow_tools_gates_on_publish_and_mcp_surface(): void {
		$this->ensure_adapter_stub();

		$unexposed = $this->create_published_workflow( array( 'rest' ) );

		$this->expect_save_time_ability_notices();
		$draft = self::factory()->post->create(
			array(
				'post_type'   => Baton_Workflow_CPT::POST_TYPE,
				'post_title'  => 'Draft MCP workflow',
				'post_status' => 'draft',
			)
		);
		$this->created_post_ids[] = $draft;

		// Drafts do not even count once unpublished (unpublish deletes nothing;
		// here it is simply a draft post).
		$config = Baton_Mcp_Integration::add_workflow_tools( array() );

		$this->assertEmpty( $config['tools'] );

		// The saved 'mcp' meta on a draft is not enough — publish requires.
		Baton_Workflow_CPT::save_surfaces( $draft, array( 'mcp' ) );
		$config = Baton_Mcp_Integration::add_workflow_tools( array() );
		$this->assertEmpty( $config['tools'] );

		Baton_Workflow_CPT::save_surfaces( $unexposed, array( 'rest', 'mcp' ) );
		$config = Baton_Mcp_Integration::add_workflow_tools( array( 'tools' => array() ) );

		$this->assertCount( 1, $config['tools'] );
	}

	/**
	 * The composed tool mirrors the MCP contract from the task slice: a
	 * ^[a-zA-Z0-9_-]{1,64}$ name, a description that cross-references the
	 * backing ability name, an object input schema (plus the empty
	 * properties object the adapter projects), a manage_options permission
	 * callback, and non-mutating annotations.
	 */
	public function test_registered_tool_matches_mcp_tool_contract(): void {
		$this->ensure_adapter_stub();
		$post_id = $this->create_published_workflow( array( 'mcp' ) );

		$config = Baton_Mcp_Integration::add_workflow_tools( array( 'tools' => array() ) );
		$this->assertCount( 1, $config['tools'] );

		$passed = \WP\MCP\Domain\Tools\McpTool::$from_array_calls[0];

		// MCP tool-name rule.
		$this->assertSame( 'baton_run_workflow_' . $post_id, $passed['name'] );
		$this->assertMatchesRegularExpression( Baton_Mcp_Integration::TOOL_NAME_PATTERN, $passed['name'] );

		// The ability name is cross-referenced in the description.
		$ability_name = Baton_Workflow_Abilities::get_ability_name( $post_id );
		$this->assertStringContainsString( $ability_name, (string) $passed['description'] );

		// Input schema: the workflow ability's object schema, ready for
		// MCP wire projection.
		$this->assertSame( 'object', $passed['inputSchema']['type'] );
		$this->assertArrayHasKey( 'properties', $passed['inputSchema'] );
		$this->assertInstanceOf( 'stdClass', $passed['inputSchema']['properties'] );

		// Title rides the workflow title; annotations are the non-mutating hints.
		$this->assertSame( 'Exposed workflow', $passed['title'] );
		$this->assertFalse( $passed['annotations']['readOnlyHint'] );

		// The tool is invocable through the adapter's component API and its
		// permission callback is the workflow ability's manage_options gate.
		$tool = $config['tools'][0];
		$this->assertSame( $passed['name'], $tool->get_name() );
		$this->assertTrue( $tool->check_permission( array() ) );
	}

	/**
	 * Tool permission tracks manage_options: subscribers are refused, and a
	 * WP_Error-shaped false result mirrors the adapter's boolean coercion.
	 */
	public function test_tool_permission_requires_manage_options(): void {
		$this->ensure_adapter_stub();
		$post_id = $this->create_published_workflow( array( 'mcp' ) );

		$config = Baton_Mcp_Integration::add_workflow_tools( array( 'tools' => array() ) );
		$tool   = $config['tools'][0];

		$admin_id = get_current_user_id();
		$this->assertTrue( $tool->check_permission( array() ) );

		// Every lesser role inherits the ability's refusal: the tool
		// permission callback is the workflow ability's manage_options gate,
		// not a second decision layer.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( $tool->check_permission( array() ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertFalse( $tool->check_permission( array() ) );

		wp_set_current_user( $admin_id );
	}

	// ──────────────────────────────────────────────────────────────
	// Invocation: the tool handler runs + logs the workflow run
	// ──────────────────────────────────────────────────────────────

	/**
	 * A tools/call against the workflow tool runs the workflow through the
	 * runner and logs an "MCP run" row (trigger 'mcp') in the same shape as
	 * the REST/CLI external-run rows. The returned value matches the raw
	 * last-step output contract.
	 */
	public function test_tool_call_runs_workflow_and_logs_mcp_row(): void {
		$post_id = $this->create_published_workflow( array( 'mcp' ) );

		$result = Baton_Mcp_Integration::run_workflow_tool_call(
			$post_id,
			array(
				'who'   => 'mcp',
				'extra' => array( 'nested' => true ),
			)
		);

		// Success body: the raw last-step output — same contract as the
		// core REST run route. The runner merges the caller input into the
		// step payload (caller keys first, the step's static input keys
		// appended — the pinned merge order).
		$this->assertSame(
			array(
				'who'   => 'mcp',
				'extra' => array( 'nested' => true ),
				'hello' => 'world',
			),
			$result
		);

		// Exactly one log row: the MCP run.
		$rows = Baton_Saved_Values::rows_for_workflow( $post_id );
		$this->assertCount( 1, $rows );

		$row = $rows[0];
		$this->assertSame( 'log', $row['kind'] );
		$this->assertSame( $post_id, $row['workflow_id'] );
		$this->assertSame( 'MCP run', $row['label'] );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			(string) $row['run_id']
		);
		$this->assertSame(
			array(
				'trigger' => 'mcp',
				'success' => true,
				'input'   => array(
					'who'   => 'mcp',
					'extra' => array( 'nested' => true ),
				),
			),
			$row['value']
		);
	}

	/**
	 * A failing run still logs (success false + the runner error) and the
	 * handler returns the same baton_workflow_failed WP_Error the ability
	 * execute callback returns on other channels.
	 */
	public function test_failed_tool_call_returns_error_and_logs(): void {
		$post_id = $this->create_published_workflow( array( 'mcp' ) );
		Baton_Workflow_CPT::save_definition(
			$post_id,
			array(
				'steps' => array(
					array(
						'ability' => 'baton-test/not-a-real-ability',
						'input'   => array(),
					),
				),
			)
		);

		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );

		$result = Baton_Mcp_Integration::run_workflow_tool_call( $post_id, array() );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'baton_workflow_failed', $result->get_error_code() );

		$rows = Baton_Saved_Values::rows_for_workflow( $post_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'MCP run', $rows[0]['label'] );
		$this->assertFalse( $rows[0]['value']['success'] );
		$this->assertStringContainsString( 'not-a-real-ability', (string) $rows[0]['value']['error'] );
	}

	/**
	 * Defensive invocation gate: a handler reached for a workflow without
	 * the mcp surface (e.g. un-exposed after registration) is rejected as
	 * not exposed and logs nothing.
	 */
	public function test_tool_call_on_unexposed_workflow_is_rejected_and_not_logged(): void {
		$this->ensure_adapter_stub();
		$post_id = $this->create_published_workflow( array( 'rest' ) );

		$result = Baton_Mcp_Integration::run_workflow_tool_call( $post_id, array( 'who' => 'x' ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'baton_workflow_not_exposed', $result->get_error_code() );
		$this->assertCount( 0, Baton_Saved_Values::rows_for_workflow( $post_id ) );
	}

	// ──────────────────────────────────────────────────────────────
	// Naming + surface plumbing
	// ──────────────────────────────────────────────────────────────

	/**
	 * The tool-name contract holds for every integer post ID (the composed
	 * name stays within ^[a-zA-Z0-9_-]{1,64}$ — even PHP_INT_MAX, 19 digits,
	 * is 38 characters).
	 */
	public function test_get_tool_name_matches_mcp_contract(): void {
		$this->ensure_adapter_stub();

		$this->assertSame( 'baton_run_workflow_42', Baton_Mcp_Integration::get_tool_name( 42 ) );
		$this->assertSame( 'baton_run_workflow_' . PHP_INT_MAX, Baton_Mcp_Integration::get_tool_name( PHP_INT_MAX ) );
		$this->assertMatchesRegularExpression( Baton_Mcp_Integration::TOOL_NAME_PATTERN, Baton_Mcp_Integration::get_tool_name( PHP_INT_MAX ) );
	}

	/**
	 * The "mcp" slug persists through the shared surfaces plumbing exactly
	 * like "rest"/"cli".
	 */
	public function test_mcp_surface_slug_survives_sanitize_and_persists(): void {
		$this->expect_save_time_ability_notices();
		$post_id = $this->create_published_workflow();

		Baton_Workflow_CPT::save_surfaces( $post_id, array( 'mcp' ) );

		$this->assertSame( array( 'mcp' ), Baton_Workflow_CPT::get_surfaces( $post_id ) );
		$this->assertTrue( Baton_Workflow_CPT::has_surface( $post_id, 'mcp' ) );

		wp_delete_post( $post_id, true );
	}

	// ──────────────────────────────────────────────────────────────
	// Helpers
	// ──────────────────────────────────────────────────────────────

	/**
	 * Load the MCP Adapter shim (tests/mcp-adapter-stub.php) so the
	 * integration's class-existence gate sees an active adapter.
	 *
	 * The shim lives outside tests/php/ (PHPUnit's suite directory executes
	 * every file there during discovery, pre-binding its classes before any
	 * test runs); loading is on-demand via require_once, which is safe here
	 * precisely because discovery never touches that path.
	 */
	private function ensure_adapter_stub(): void {
		require_once dirname( __DIR__ ) . '/mcp-adapter-stub.php';
	}

	/**
	 * Create a published workflow post with a default echo step.
	 *
	 * @param array<int, string>   $surfaces   Surface slugs to save ('mcp' to expose).
	 * @param array<string, mixed> $definition Optional definition override.
	 * @return int Post ID.
	 */
	private function create_published_workflow( array $surfaces = array(), array $definition = array() ): int {
		$this->expect_save_time_ability_notices();

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => Baton_Workflow_CPT::POST_TYPE,
				'post_title'  => 'Exposed workflow',
				'post_status' => 'publish',
			)
		);
		$this->created_post_ids[] = $post_id;

		if ( empty( $definition ) ) {
			$definition = array(
				'steps' => array(
					array(
						'ability' => 'baton-test/echo',
						'input'   => array( 'hello' => 'world' ),
					),
				),
			);
		}

		Baton_Workflow_CPT::save_definition( $post_id, $definition );

		if ( ! empty( $surfaces ) ) {
			Baton_Workflow_CPT::save_surfaces( $post_id, $surfaces );
		}

		return $post_id;
	}

	/**
	 * Declares the _doing_it_wrong notices the 6.9 registry emits when Baton
	 * attempts save-time (save_post) ability registration.
	 */
	private function expect_save_time_ability_notices(): void {
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::unregister' );
		$this->setExpectedIncorrectUsage( 'wp_register_ability' );
	}
}