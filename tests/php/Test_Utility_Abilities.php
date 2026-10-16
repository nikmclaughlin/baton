<?php
/**
 * Tests for the Baton utility abilities (save-output, save-state,
 * load-output, log) — registration and ability contracts.
 *
 * @package Baton
 */

declare( strict_types=1 );

/**
 * Utility ability contract tests (direct ability execution).
 */
class Test_Utility_Abilities extends WP_UnitTestCase {

	use Baton_Test_Table_State_Trait;

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

		wp_set_current_user( 1 );

		// Utilities register during the real registry init (plugin bootstrap).
		foreach ( array_keys( self::utility_slugs() ) as $slug ) {
			if ( ! wp_get_ability( $slug ) ) {
				$this->fail( 'Utility ability ' . $slug . ' is not registered.' );
			}
		}
	}

	/**
	 * Runs after each test: drop the table.
	 */
	public function tear_down(): void {
		$this->drop_baton_table();

		parent::tear_down();
	}

	/**
	 * Utility ability slugs by display label.
	 *
	 * @return array<string, string>
	 */
	private static function utility_slugs(): array {
		return array(
			'baton/save-output' => 'Save output',
			'baton/save-state'  => 'Keep latest',
			'baton/load-output' => 'Load saved value',
			'baton/log'         => 'Log entry',
		);
	}

	/**
	 * Execute a utility ability with an input array.
	 *
	 * @param string               $slug  Ability slug.
	 * @param array<string, mixed> $input Ability input.
	 * @return mixed|WP_Error
	 */
	private static function run_ability( string $slug, array $input ) {
		$ability = wp_get_ability( $slug );

		if ( ! $ability instanceof WP_Ability ) {
			return new WP_Error( 'baton_test_missing_ability', 'Ability ' . $slug . ' not registered.' );
		}

		return $ability->execute( $input );
	}

	/**
	 * All four utilities are registered under the Baton Utilities category.
	 */
	public function test_utilities_registered_with_category_and_labels(): void {
		$category = wp_get_ability_category( 'baton-utility' );

		$this->assertInstanceOf( WP_Ability_Category::class, $category );
		$this->assertSame( 'Baton Utilities', $category->get_label() );

		foreach ( self::utility_slugs() as $slug => $label ) {
			$ability = wp_get_ability( $slug );

			$this->assertInstanceOf( WP_Ability::class, $ability, $slug );
			$this->assertSame( $label, $ability->get_label(), $slug );
			$this->assertSame( 'baton-utility', $ability->get_category(), $slug );
			$this->assertFalse(
				(bool) $ability->get_meta_item( 'show_in_rest', false ),
				$slug . ' must not leak to core REST.'
			);
		}
	}

	/**
	 * save-output appends a row and returns the row summary with the
	 * truncated flag (additive key on the spec's {id, label, run_id, kind}).
	 */
	public function test_save_output_appends_row_and_outputs_summary(): void {
		$result = self::run_ability(
			'baton/save-output',
			array(
				'label'       => 'artifact',
				'value'       => array(
					'a' => 1,
					'b' => array( 2, 3 ),
				),
				'run_id'      => 'run-1',
				'workflow_id' => 9,
				'step_id'     => 'step-1',
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertIsInt( $result['id'] );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( 'artifact', $result['label'] );
		$this->assertSame( 'run-1', $result['run_id'] );
		$this->assertSame( 'save', $result['kind'] );
		$this->assertFalse( $result['truncated'] );

		$row = Baton_Saved_Values::find( 'artifact', 9 );

		$this->assertIsArray( $row );
		$this->assertSame(
			array(
				'a' => 1,
				'b' => array( 2, 3 ),
			),
			$row['value']
		);
		$this->assertSame( 'step-1', $row['step_id'] );
	}

	/**
	 * save-output requires a non-empty label — missing or blank is a loud
	 * failure, never a silent empty-key row.
	 */
	public function test_save_output_requires_label(): void {
		$missing = self::run_ability( 'baton/save-output', array( 'value' => 1 ) );
		$this->assertInstanceOf( WP_Error::class, $missing );

		$blank = self::run_ability( 'baton/save-output', array( 'label' => '   ', 'value' => 1 ) );
		$this->assertInstanceOf( WP_Error::class, $blank );
	}

	/**
	 * Labels are trimmed on write and lookup stays consistent.
	 */
	public function test_save_output_trims_label(): void {
		$result = self::run_ability(
			'baton/save-output',
			array(
				'label'       => '  padded  ',
				'value'       => 'v',
				'workflow_id' => 9,
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'padded', $result['label'] );
		$this->assertNotNull( Baton_Saved_Values::find( 'padded', 9 ) );
	}

	/**
	 * save-state upserts one row per label, newest value wins.
	 */
	public function test_save_state_upserts_single_row(): void {
		$first = self::run_ability(
			'baton/save-state',
			array(
				'label'       => 'last_cursor',
				'value'       => array( 'cursor' => 1 ),
				'run_id'      => 'run-1',
				'workflow_id' => 9,
				'step_id'     => 'step-0',
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $first );
		$this->assertSame( 'state', $first['kind'] );

		$second = self::run_ability(
			'baton/save-state',
			array(
				'label'       => 'last_cursor',
				'value'       => array( 'cursor' => 2 ),
				'run_id'      => 'run-2',
				'workflow_id' => 9,
				'step_id'     => 'step-0',
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $second );
		$this->assertSame( $first['id'], $second['id'], 'Upsert must keep a single row per label.' );

		$row = Baton_Saved_Values::find( 'last_cursor', 9 );

		$this->assertIsArray( $row );
		$this->assertSame( array( 'cursor' => 2 ), $row['value'] );
		$this->assertSame( 'run-2', $row['run_id'] );
	}

	/**
	 * save-state requires a non-empty label too.
	 */
	public function test_save_state_requires_label(): void {
		$this->assertInstanceOf( WP_Error::class, self::run_ability( 'baton/save-state', array( 'value' => 1 ) ) );
	}

	/**
	 * load-output returns the decoded stored value (latest by id).
	 */
	public function test_load_output_returns_latest_value(): void {
		self::run_ability(
			'baton/save-output',
			array(
				'label'       => 'artifact',
				'value'       => 'first',
				'workflow_id' => 9,
			)
		);
		self::run_ability(
			'baton/save-output',
			array(
				'label'       => 'artifact',
				'value'       => array( 'hello' => 'world' ),
				'workflow_id' => 9,
			)
		);

		$loaded = self::run_ability(
			'baton/load-output',
			array(
				'label'       => 'artifact',
				'workflow_id' => 9,
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $loaded );
		$this->assertSame( array( 'hello' => 'world' ), $loaded );
	}

	/**
	 * load-output not-found is a loud step failure.
	 */
	public function test_load_output_not_found_fails_loudly(): void {
		$loaded = self::run_ability(
			'baton/load-output',
			array(
				'label'       => 'never-saved',
				'workflow_id' => 9,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $loaded );
		$this->assertStringContainsString( 'never-saved', $loaded->get_error_message() );
	}

	/**
	 * load-output honors an explicit workflow scope (cross-workflow loads).
	 */
	public function test_load_output_scope_overrides_and_isolation(): void {
		self::run_ability(
			'baton/save-output',
			array(
				'label'       => 'shared-label',
				'value'       => 'from-workflow-9',
				'workflow_id' => 9,
			)
		);

		$this->assertSame(
			'from-workflow-9',
			self::run_ability(
				'baton/load-output',
				array(
					'label'       => 'shared-label',
					'workflow_id' => 9,
				)
			)
		);

		// Another workflow's scope does not see workflow 9's rows.
		$this->assertInstanceOf(
			WP_Error::class,
			self::run_ability(
				'baton/load-output',
				array(
					'label'       => 'shared-label',
					'workflow_id' => 10,
				)
			)
		);
	}

	/**
	 * load-output accepts the advanced from_run_id scope.
	 */
	public function test_load_output_from_run_id_scope(): void {
		self::run_ability(
			'baton/save-output',
			array(
				'label'       => 'history',
				'value'       => 'from-run-a',
				'run_id'      => 'run-a',
				'workflow_id' => 9,
			)
		);
		self::run_ability(
			'baton/save-output',
			array(
				'label'       => 'history',
				'value'       => 'from-run-b',
				'run_id'      => 'run-b',
				'workflow_id' => 9,
			)
		);

		$this->assertSame(
			'from-run-a',
			self::run_ability(
				'baton/load-output',
				array(
					'label'       => 'history',
					'workflow_id' => 9,
					'from_run_id' => 'run-a',
				)
			)
		);

		$this->assertSame(
			'from-run-b',
			self::run_ability(
				'baton/load-output',
				array(
					'label'       => 'history',
					'workflow_id' => 9,
					'from_run_id' => 'run-b',
				)
			)
		);
	}

	/**
	 * load-output requires a non-empty label.
	 */
	public function test_load_output_requires_label(): void {
		$this->assertInstanceOf( WP_Error::class, self::run_ability( 'baton/load-output', array() ) );
	}

	/**
	 * log writes message+context, truncates the label headline, sanitizes it
	 * for the timeline, and keeps the raw message in value.context.
	 */
	public function test_log_write_shape_and_label_truncation(): void {
		$long_message = str_repeat( 'x', 250 ) . ' <b>tail</b>';

		$result = self::run_ability(
			'baton/log',
			array(
				'message'     => $long_message,
				'context'     => array( 'k' => 'v' ),
				'run_id'      => 'run-1',
				'workflow_id' => 9,
				'step_id'     => 'step-3',
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertTrue( $result['logged'] );
		$this->assertIsInt( $result['id'] );
		$this->assertCount( 2, $result, 'Log output contract is exactly {logged, id}.' );

		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test helper.
		$stored_raw = (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT value FROM ' . Baton_Saved_Values::table_name() . ' WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifier from plugin prefix.
				$result['id']
			)
		);
		$stored = json_decode( $stored_raw, true );

		$this->assertSame( $long_message, $stored['message'] );
		$this->assertSame( array( 'k' => 'v' ), $stored['context'] );
	}

	/**
	 * The log label headline is the sanitized, truncated message.
	 */
	public function test_log_label_is_sanitized_and_truncated(): void {
		$result = self::run_ability(
			'baton/log',
			array(
				'message'     => 'start <b>tail</b> ' . str_repeat( 'x', 200 ),
				'workflow_id' => 9,
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $result );

		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test helper.
		$label = (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT label FROM ' . Baton_Saved_Values::table_name() . ' WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifier from plugin prefix.
				$result['id']
			)
		);

		// Sanitized headline: the HTML tag is stripped before truncation.
		$this->assertStringStartsWith( 'start tail ', $label );
		$this->assertStringNotContainsString( '<b>', $label );

		preg_match_all( '/./us', $label, $chars, PREG_SET_ORDER );
		$this->assertCount( 190, $chars, 'Log label stays within the VARCHAR headroom.' );

		// And log rows are not loadable via the label lookup (kind boundary).
		$this->assertNull( Baton_Saved_Values::find( $label, 9 ) );
	}

	/**
	 * Save over the value cap surfaces the truncated flag in the output
	 * summary; the log contract keeps its exact output shape either way.
	 */
	public function test_truncation_flag_surfaces_in_save_summary(): void {
		$big = str_repeat( 'v', Baton_Saved_Values::MAX_VALUE_BYTES + 5000 );

		$result = self::run_ability(
			'baton/save-output',
			array(
				'label'       => 'huge',
				'value'       => $big,
				'workflow_id' => 9,
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertTrue( $result['truncated'] );

		$log = self::run_ability(
			'baton/log',
			array(
				'message'     => 'big context',
				'context'     => $big,
				'workflow_id' => 9,
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $log );
		$this->assertCount( 2, $log );
	}

	/**
	 * Editor catalog verification: the utilities surface under "Baton
	 * Utilities" with clean field/target options, and the runner-filled
	 * context fields are hidden from every step input UI — while the
	 * registered schemas keep them (runner injection depends on them).
	 */
	public function test_editor_catalog_hides_runner_context_fields(): void {
		$formatted = array();
		foreach ( array_keys( self::utility_slugs() ) as $slug ) {
			$ability            = wp_get_ability( $slug );
			$formatted[ $slug ] = Baton_Ability_Catalog::format_ability( $ability );
		}

		$context_keys = array( 'run_id', 'workflow_id', 'step_id' );
		$expected_visible = array(
			'baton/save-output' => array( 'label', 'value' ),
			'baton/save-state'  => array( 'label', 'value' ),
			'baton/load-output' => array( 'label', 'from_run_id' ),
			'baton/log'         => array( 'message', 'context' ),
		);
		$expected_summary = array(
			'baton/save-output' => 'Object · label, value',
			'baton/save-state'  => 'Object · label, value',
			'baton/load-output' => 'Object · label, from_run_id',
			'baton/log'         => 'Object · message, context',
		);

		foreach ( $formatted as $slug => $catalog ) {
			$visible = $expected_visible[ $slug ];

			// Category + grouping: the editor's <optgroup> label.
			$this->assertSame( 'Baton Utilities', $catalog['category'], $slug );

			// Step input UIs (input_fields/targets/example) hide context fields.
			$this->assertSame(
				$visible,
				$catalog['input_fields'],
				$slug . ' input_fields'
			);
			$this->assertSame(
				$visible,
				array_column( $catalog['input_targets'], 'value' ),
				$slug . ' input_targets'
			);
			$this->assertSame(
				$expected_summary[ $slug ],
				$catalog['input_summary']['summary'],
				$slug . ' input summary'
			);
			foreach ( $context_keys as $key ) {
				$this->assertArrayNotHasKey( $key, $catalog['example_input'], $slug . ' example input' );
			}

			// The schemas keep the context fields (runner injection).
			$schema = wp_get_ability( $slug )->get_input_schema();
			foreach ( $context_keys as $key ) {
				if ( ! array_key_exists( $key, $schema['properties'] ) ) {
					continue;
				}
				$this->assertSame(
					'context',
					$schema['properties'][ $key ]['x-baton'],
					$slug . ' schema keeps the context marker for ' . $key
				);
			}
		}
	}

	/**
	 * Storage failures stay diagnosable: when the storage layer reports a
	 * MySQL error (e.g. the table was never migrated), every utility's
	 * failure message gains the storage error while the error code — and so
	 * the run-report contract — stays unchanged.
	 */
	public function test_storage_failures_include_wpdb_error_context(): void {
		Baton_Saved_Values::uninstall();

		$log = self::run_ability(
			'baton/log',
			array(
				'message' => 'an entry',
				'run_id'  => 'run-1',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $log );
		$this->assertSame( 'baton_log_failed', $log->get_error_code() );
		$this->assertStringContainsString( 'The log entry could not be written.', $log->get_error_message() );
		$this->assertStringContainsString( 'Storage error:', $log->get_error_message() );

		$save = self::run_ability(
			'baton/save-output',
			array(
				'label' => 'artifact',
				'value' => 1,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $save );
		$this->assertSame( 'baton_save_output_failed', $save->get_error_code() );
		$this->assertStringContainsString( 'The saved output could not be written.', $save->get_error_message() );
		$this->assertStringContainsString( 'Storage error:', $save->get_error_message() );

		$state = self::run_ability(
			'baton/save-state',
			array(
				'label' => 'cursor',
				'value' => 1,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $state );
		$this->assertSame( 'baton_save_state_failed', $state->get_error_code() );
		$this->assertStringContainsString( 'The latest value could not be written.', $state->get_error_message() );
		$this->assertStringContainsString( 'Storage error:', $state->get_error_message() );

		$load = self::run_ability( 'baton/load-output', array( 'label' => 'missing-thing' ) );
		$this->assertInstanceOf( WP_Error::class, $load );
		$this->assertSame( 'baton_load_output_not_found', $load->get_error_code() );
		$this->assertStringContainsString( 'No saved value found for label', $load->get_error_message() );
		$this->assertStringContainsString( 'Storage error:', $load->get_error_message() );

		// Once the table is back, clean misses carry no storage-context text
		// (the suffix only appears when the storage layer reported an error).
		Baton_Saved_Values::install();
		$clean_miss = self::run_ability( 'baton/load-output', array( 'label' => 'never-saved-anything' ) );
		$this->assertInstanceOf( WP_Error::class, $clean_miss );
		$this->assertSame( 'baton_load_output_not_found', $clean_miss->get_error_code() );
		$this->assertStringContainsString( 'No saved value found for label', $clean_miss->get_error_message() );
		$this->assertStringNotContainsString( 'Storage error:', $clean_miss->get_error_message() );
	}
}