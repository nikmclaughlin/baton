<?php
/**
 * Tests for B3 external workflow execution (WP-CLI).
 *
 * @package Baton
 */

declare( strict_types=1 );

/**
 * `wp baton` command registration, strict CLI gating, run report
 * rendering/exit semantics, and CLI external-run log rows.
 *
 * Runs outside the WP-CLI binary, so tests execute the command methods
 * directly against the WP_CLI shim (tests/php/wp-cli-stub.php): I/O is
 * captured and WP_CLI::error() throws WP_CLI\ExitException (exit 1), the
 * process-exit contract the command relies on.
 */
class Test_CLI_Execution extends WP_UnitTestCase {

	use Baton_Test_Table_State_Trait;

	/**
	 * Administrator user ID (WP-CLI executes in admin context).
	 *
	 * @var int
	 */
	private int $admin_user_id = 0;

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

		$this->admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );

		WP_CLI::reset();
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
		WP_CLI::reset();

		parent::tear_down();
	}

	// ──────────────────────────────────────────────────────────────
	// Command registration
	// ──────────────────────────────────────────────────────────────

	public function test_register_adds_baton_command_group(): void {
		Baton_CLI::register();

		$this->assertCount( 1, WP_CLI::$commands );
		$this->assertSame( 'baton', WP_CLI::$commands[0][0] );
		$this->assertSame( 'Baton_CLI', WP_CLI::$commands[0][1] );
		$this->assertSame(
			array( 'shortdesc' => 'Baton workflow CLI surface (list, info, run).' ),
			WP_CLI::$commands[0][2]
		);
	}

	// ──────────────────────────────────────────────────────────────
	// `wp baton list`: strict gating (only CLI-exposed workflows)
	// ──────────────────────────────────────────────────────────────

	public function test_list_shows_only_cli_exposed_workflows(): void {
		$this->expect_save_time_ability_notices();

		$this->create_published_workflow( 'Rest only', array( 'rest' ) );
		$id_cli = $this->create_published_workflow( 'CLI only', array( 'cli' ) );
		$id_both = $this->create_published_workflow( 'Both', array( 'rest', 'cli' ) );
		$this->create_published_workflow( 'Secret', array() );
		// Drafts are never runnable/listable, even with the surface set.
		$this->create_workflow( 'Draft with cli', array( 'cli' ), 'draft' );

		$cli = new Baton_CLI();
		$cli->list( array(), array( 'format' => 'json' ) );

		$lines   = WP_CLI::messages( 'line' );
		$decoded = json_decode( $lines[0] ?? 'null', true );
		$this->assertIsArray( $decoded );
		$this->assertSame( array( $id_cli, $id_both ), array_column( $decoded, 'id' ) );

		foreach ( $decoded as $row ) {
			if ( $id_both === (int) $row['id'] ) {
				$this->assertSame( 'rest, cli', $row['surfaces'] );
				$this->assertSame( 'Both', $row['title'] );
				$this->assertSame( 1, $row['steps'] );
			}
		}
	}

	public function test_list_table_renders_rows(): void {
		$this->expect_save_time_ability_notices();

		$post_id = $this->create_published_workflow( 'CLI only', array( 'cli' ) );

		$cli = new Baton_CLI();
		$cli->list( array(), array() );

		$joined = (string) implode( "\n", WP_CLI::messages( 'line' ) );
		$this->assertStringContainsString( 'id | title | surfaces | steps', $joined );
		$this->assertStringContainsString( (string) $post_id . ' | CLI only | cli | 1', $joined );
	}

	public function test_list_reports_success_when_nothing_exposed_table(): void {
		$this->expect_save_time_ability_notices();
		$this->create_published_workflow( 'Rest only', array( 'rest' ) );

		$cli = new Baton_CLI();
		$cli->list( array(), array() );

		$messages = WP_CLI::messages( 'success' );
		$this->assertCount( 1, $messages );
		$this->assertStringContainsString( 'No workflows are exposed for CLI execution.', $messages[0] );
	}

	public function test_list_json_renders_empty_collection_when_nothing_exposed(): void {
		$this->expect_save_time_ability_notices();
		$this->create_published_workflow( 'Rest only', array( 'rest' ) );

		$cli = new Baton_CLI();
		$cli->list( array(), array( 'format' => 'json' ) );

		$this->assertSame( array( '[]' ), WP_CLI::messages( 'line' ) );
	}

	public function test_list_rejects_unknown_format(): void {
		$this->expect_save_time_ability_notices();
		$this->create_published_workflow( 'CLI only', array( 'cli' ) );

		$cli = new Baton_CLI();
		$this->catch_cli_exit(
			static function () use ( $cli ) {
				$cli->list( array(), array( 'format' => 'yaml' ) );
			}
		);

		$this->assertSame(
			array( 'Invalid format: yaml (choose table, json, csv).' ),
			WP_CLI::messages( 'error' )
		);
	}

	// ──────────────────────────────────────────────────────────────
	// `wp baton info`: strict gating (same gate as run) + inspection
	// ──────────────────────────────────────────────────────────────

	public function test_info_prints_title_status_steps_surfaces_and_newest_run(): void {
		$this->expect_save_time_ability_notices();

		$post_id = $this->create_published_workflow(
			'CLI export',
			array( 'cli' ),
			array(
				'steps' => array(
					array(
						'ability' => 'baton-test/echo',
						'input'   => array( 'hello' => 'world' ),
					),
					array(
						'ability' => 'baton/save-output',
						'input'   => array( 'label' => 'run-log', 'value' => 'payload' ),
					),
				),
			)
		);

		$cli = new Baton_CLI();
		$cli->run( array( (string) $post_id ), array() );
		$cli->info( array( (string) $post_id ), array() );

		$joined = (string) implode( "\n", WP_CLI::messages( 'line' ) );

		$this->assertStringContainsString( 'Title: CLI export', $joined );
		$this->assertStringContainsString( 'Status: publish', $joined );
		$this->assertStringContainsString( 'Surfaces: cli', $joined );
		$this->assertStringContainsString( 'Steps: 2', $joined );
		$this->assertStringContainsString( '  1. baton-test/echo', $joined );
		$this->assertStringContainsString( '  2. baton/save-output', $joined );

		// The run executed through the CLI channel logged an "External run"
		// row surfaced as the newest run.
		$this->assertStringContainsString( 'Newest run: ', $joined );
		$this->assertStringContainsString( 'External run', $joined );
		$this->assertStringContainsString( 'triggered via cli', $joined );
		$this->assertStringContainsString( 'success', $joined );
	}

	public function test_info_gates_like_run_with_generic_error(): void {
		$this->expect_save_time_ability_notices();

		$id_rest  = $this->create_published_workflow( 'Rest only', array( 'rest' ) );
		$id_draft = $this->create_workflow( 'Draft cli', array( 'cli' ), 'draft' );

		$missing_id = max( $id_rest, $id_draft ) + 424242;

		$targets = array( (string) $id_rest, (string) $id_draft, (string) $missing_id );

		$cli = new Baton_CLI();

		// Missing, unpublished, and rest-only workflows all reject with the
		// same generic error — the surface map decides whether a workflow
		// exists in the CLI channel at all, not just whether it runs.
		foreach ( $targets as $target ) {
			$this->catch_cli_exit(
				static function () use ( $cli, $target ) {
					$cli->info( array( $target ), array() );
				}
			);
		}

		// No information leak: every rejection is the generic not-exposed
		// error, byte-identical for all three targets, and the requested
		// ID never appears.
		$errors = WP_CLI::messages( 'error' );
		$this->assertCount( 3, $errors );

		$previous_error = null;
		foreach ( $targets as $index => $target ) {
			$error = (string) $errors[ $index ];
			$this->assertStringContainsString(
				'Workflow not found or not exposed for CLI execution',
				$error
			);
			$this->assertStringContainsString( 'wp baton list', $error );
			$this->assertStringNotContainsString( 'No workflow found with ID', $error );
			$this->assertStringNotContainsString( $target, $error );

			if ( null !== $previous_error ) {
				$this->assertSame( $previous_error, $error );
			}
			$previous_error = $error;
		}

		// Byte-identical to `wp baton run`'s rejection — one shared gate.
		$this->catch_cli_exit(
			static function () use ( $cli, $id_rest ) {
				$cli->run( array( (string) $id_rest ), array() );
			}
		);

		$errors = WP_CLI::messages( 'error' );
		$this->assertCount( 4, $errors );
		$this->assertSame( $errors[0], $errors[3] );
	}

	public function test_info_prints_absent_run_note_for_exposed_workflow(): void {
		$this->expect_save_time_ability_notices();

		$post_id = $this->create_published_workflow( 'Quiet', array( 'cli' ) );

		$cli = new Baton_CLI();
		$cli->info( array( (string) $post_id ), array() );
		$this->assertStringContainsString(
			'Newest run: (none saved)',
			(string) implode( "\n", WP_CLI::messages( 'line' ) )
		);
	}

	// ──────────────────────────────────────────────────────────────
	// `wp baton run`: strict gating, nothing runs without the surface
	// ──────────────────────────────────────────────────────────────

	public function test_run_stops_with_generic_error_when_not_cli_exposed(): void {
		$this->expect_save_time_ability_notices();

		$id_rest  = $this->create_published_workflow( 'Rest only', array( 'rest' ) );
		$id_none  = $this->create_published_workflow( 'Secret', array() );
		$id_draft = $this->create_workflow( 'Draft cli', array( 'cli' ), 'draft' );

		$missing_id = max( $id_rest, $id_none, $id_draft ) + 54321;

		$cli = new Baton_CLI();

		foreach ( array( (string) $id_rest, (string) $id_none, (string) $id_draft, (string) $missing_id ) as $target ) {
			try {
				$cli->run( array( $target ), array() );
				$this->fail( 'Expected WP_CLI::error() to exit for ' . $target );
			} catch ( \WP_CLI\ExitException $exception ) {
				$this->assertSame( 1, $exception->getExitCode() );
			}
		}

		// Every rejection is the same generic error (indistinguishable —
		// like the REST channel's 404 invisibility).
		$errors = WP_CLI::messages( 'error' );
		$this->assertCount( 4, $errors );
		foreach ( $errors as $error ) {
			$this->assertStringContainsString(
				'Workflow not found or not exposed for CLI execution',
				$error
			);
			$this->assertStringContainsString( 'wp baton list', $error );
		}
		$this->assertSame( $errors[0], $errors[3] );

		// Nothing ran, so nothing is logged.
		foreach ( array( $id_rest, $id_none, $id_draft ) as $id ) {
			$this->assertSame( array(), Baton_Saved_Values::rows_for_workflow( $id ) );
		}
	}

	// ──────────────────────────────────────────────────────────────
	// `wp baton run`: input args are validated before anything runs
	// ──────────────────────────────────────────────────────────────

	public function test_run_rejects_bad_input_args_without_running(): void {
		$this->expect_save_time_ability_notices();

		$post_id = $this->create_published_workflow( 'CLI export', array( 'cli' ) );

		$json_path = $this->temp_input_file( '{"a":1}' );
		unlink( $json_path );
		$now_unreadable = $json_path;

		$cases = array(
			array(
				'args'   => array(
					'input'      => '{"who":1',
					'input-file' => '/tmp/other.json',
				),
				'expect' => 'Pass only one of --input or --input-file.',
			),
			array(
				'args'   => array( 'input' => 'not json' ),
				'expect' => 'Invalid input JSON: expected an object.',
			),
			array(
				'args'   => array( 'input' => '[1,2]' ),
				'expect' => 'Invalid input JSON: expected an object, not a JSON array.',
			),
			array(
				'args'   => array( 'input-file' => $now_unreadable ),
				'expect' => 'Input file "' . $now_unreadable . '" is not readable.',
			),
		);

		$cli = new Baton_CLI();

		foreach ( $cases as $case ) {
			$this->catch_cli_exit(
				static function () use ( $cli, $post_id, $case ) {
					$cli->run( array( (string) $post_id ), $case['args'] );
				}
			);
		}

		$errors = WP_CLI::messages( 'error' );
		$this->assertCount( 4, $errors );
		foreach ( $cases as $index => $case ) {
			$this->assertSame( $case['expect'], $errors[ $index ] );
		}

		// Nothing ran, so nothing is logged.
		$this->assertSame( array(), Baton_Saved_Values::rows_for_workflow( $post_id ) );
	}

	public function test_run_reads_input_from_file(): void {
		$this->expect_save_time_ability_notices();

		$post_id   = $this->create_published_workflow( 'CLI export', array( 'cli' ) );
		$json_path = $this->temp_input_file( '{"cursor":"abc123"}' );

		$cli = new Baton_CLI();
		$cli->run( array( (string) $post_id ), array( 'input-file' => $json_path ) );

		$rows = Baton_Saved_Values::rows_for_workflow( $post_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( array( 'cursor' => 'abc123' ), $rows[0]['value']['input'] );
	}

	// ──────────────────────────────────────────────────────────────
	// `wp baton run`: opt-in success + CLI external-run log row
	// ──────────────────────────────────────────────────────────────

	public function test_run_exposed_workflow_prints_report_and_logs_cli_row(): void {
		$this->expect_save_time_ability_notices();

		$post_id = $this->create_published_workflow( 'CLI export', array( 'cli' ) );

		$cli = new Baton_CLI();
		$cli->run( array( (string) $post_id ), array() );

		$lines  = WP_CLI::messages( 'line' );
		$joined = (string) implode( "\n", $lines );
		$this->assertStringContainsString( 'Run ID: ', $joined );
		$this->assertStringContainsString( 'Step 1 [ok] baton-test/echo', $joined );
		$this->assertStringContainsString( 'Output: {"hello":"world"}', $joined );

		// Exactly one log row: the CLI external run.
		$rows = Baton_Saved_Values::rows_for_workflow( $post_id );
		$this->assertCount( 1, $rows );

		$row = $rows[0];
		$this->assertSame( 'log', $row['kind'] );
		$this->assertSame( $post_id, $row['workflow_id'] );
		$this->assertSame( 'External run', $row['label'] );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			(string) $row['run_id']
		);
		$this->assertSame( '', $row['step_id'] );
		$this->assertSame(
			array(
				'trigger' => 'cli',
				'success' => true,
				'input'   => array(),
			),
			$row['value']
		);

		// The report's Run ID matches the logged run.
		$this->assertStringContainsString( (string) $row['run_id'], $joined );
	}

	public function test_run_input_sanitization_is_stored_in_log_row(): void {
		$this->expect_save_time_ability_notices();

		$post_id = $this->create_published_workflow( 'CLI export', array( 'cli' ) );

		$cli = new Baton_CLI();
		$cli->run( array( (string) $post_id ), array( 'input' => '{"who":"<em>value</em>"}' ) );

		$rows = Baton_Saved_Values::rows_for_workflow( $post_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( array( 'who' => 'value' ), $rows[0]['value']['input'] );
	}

	public function test_failed_cli_run_prints_json_report_then_exits_nonzero(): void {
		$this->expect_save_time_ability_notices();

		$post_id = $this->create_published_workflow( 'Broken', array( 'cli' ) );
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

		// The runner's missing-ability lookup may surface a registry notice.
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );

		$cli = new Baton_CLI();
		try {
			$cli->run( array( (string) $post_id ), array( 'format' => 'json' ) );
			$this->fail( 'Expected WP_CLI::error() to exit for a failed run.' );
		} catch ( \WP_CLI\ExitException $exception ) {
			$this->assertSame( 1, $exception->getExitCode() );
		}

		// The full JSON report is printed (stdout) BEFORE the error exits:
		// scripted callers get the report body and can still see non-zero.
		$lines = WP_CLI::messages( 'line' );
		$this->assertCount( 1, $lines );

		$report = json_decode( $lines[0], true );
		$this->assertIsArray( $report );
		$this->assertFalse( $report['success'] );
		$this->assertStringContainsString( 'not-a-real-ability', (string) ( $report['error'] ?? '' ) );
		$this->assertFalse( (bool) ( $report['steps'][0]['success'] ?? true ) );

		$errors = WP_CLI::messages( 'error' );
		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'Run failed: ', $errors[0] );
		$this->assertStringContainsString( 'not-a-real-ability', $errors[0] );

		// The failed run is still logged as an external CLI run.
		$rows = Baton_Saved_Values::rows_for_workflow( $post_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'External run', $rows[0]['label'] );
		$this->assertSame( (string) $report['run_id'], $rows[0]['run_id'] );
		$this->assertFalse( $rows[0]['value']['success'] );
		$this->assertSame( 'cli', $rows[0]['value']['trigger'] );
		$this->assertStringContainsString( 'not-a-real-ability', (string) $rows[0]['value']['error'] );
	}

	// ──────────────────────────────────────────────────────────────
	// Run input override semantics (mirrors the admin run endpoint)
	// ──────────────────────────────────────────────────────────────

	public function test_run_input_flags_replace_saved_initial_input(): void {
		$this->expect_save_time_ability_notices();

		$post_id = $this->create_published_workflow( 'Input override', array( 'cli' ) );
		Baton_Workflow_CPT::save_definition(
			$post_id,
			array(
				'initial_input' => array( 'hello' => 'world' ),
				'steps'         => array(
					array(
						'ability' => 'baton-test/echo',
						'input'   => array(),
					),
				),
			)
		);

		$cli = new Baton_CLI();

		// Without flags: the saved initial input flows into step 1.
		$cli->run( array( (string) $post_id ), array() );
		$this->assertStringContainsString(
			'Output: {"hello":"world"}',
			(string) implode( "\n", WP_CLI::messages( 'line' ) )
		);

		// Explicit {} clears the override (empty input object).
		WP_CLI::reset();
		$cli->run( array( (string) $post_id ), array( 'input' => '{}' ) );
		$this->assertStringContainsString(
			'Output: []',
			(string) implode( "\n", WP_CLI::messages( 'line' ) )
		);

		// A non-empty --input replaces the saved initial input.
		WP_CLI::reset();
		$cli->run( array( (string) $post_id ), array( 'input' => '{"who":"cli"}' ) );
		$this->assertStringContainsString(
			'Output: {"who":"cli"}',
			(string) implode( "\n", WP_CLI::messages( 'line' ) )
		);
	}

	// ──────────────────────────────────────────────────────────────
	// Helpers
	// ──────────────────────────────────────────────────────────────

	/**
	 * Run a callback, swallow its WP_CLI::error() exit, check the code.
	 *
	 * @param callable $callback Callback expected to exit through WP_CLI::error().
	 */
	private function catch_cli_exit( callable $callback ): void {
		try {
			$callback();
			$this->fail( 'Expected WP_CLI::error() to exit.' );
		} catch ( \WP_CLI\ExitException $exception ) {
			$this->assertSame( 1, $exception->getExitCode() );
		}
	}

	/**
	 * Write a JSON input file into the system temp dir.
	 *
	 * @param string $json JSON body.
	 * @return string File path (caller unlinks).
	 */
	private function temp_input_file( string $json ): string {
		$path = tempnam( sys_get_temp_dir(), 'baton-cli-input-' );
		$this->assertIsString( $path );
		$this->assertNotFalse( file_put_contents( $path, $json ) );

		return $path;
	}

	/**
	 * Create a workflow post (any status) with a default echo step.
	 *
	 * @param string               $title      Post title.
	 * @param array<int, string>   $surfaces   Surface slugs to save.
	 * @param string               $status     Post status.
	 * @param array<string, mixed> $definition Optional definition override.
	 * @return int Post ID.
	 */
	private function create_workflow( string $title, array $surfaces = array(), string $status = 'publish', array $definition = array() ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => Baton_Workflow_CPT::POST_TYPE,
				'post_title'  => $title,
				'post_status' => $status,
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
	 * Create a published workflow with a default echo step.
	 *
	 * @param string               $title      Post title.
	 * @param array<int, string>   $surfaces   Surface slugs to save.
	 * @param array<string, mixed> $definition Optional definition override.
	 * @return int Post ID.
	 */
	private function create_published_workflow( string $title, array $surfaces = array(), array $definition = array() ): int {
		return $this->create_workflow( $title, $surfaces, 'publish', $definition );
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