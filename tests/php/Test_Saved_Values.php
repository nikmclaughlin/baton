<?php
/**
 * Tests for Baton_Saved_Values (storage CRUD, upsert, newest-wins, size cap).
 *
 * @package Baton
 */

declare( strict_types=1 );

/**
 * Storage class tests.
 */
class Test_Saved_Values extends WP_UnitTestCase {

	use Baton_Test_Table_State_Trait;

	/**
	 * Runs before each test: drop + recreate the table for isolation.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->drop_baton_table();
		Baton_Saved_Values::install();
	}

	/**
	 * Runs after each test: drop the table.
	 */
	public function tear_down(): void {
		$this->drop_baton_table();

		parent::tear_down();
	}

	/**
	 * Count rows matching a kind/label/workflow/run scope.
	 *
	 * @param string|null $kind        Optional kind filter.
	 * @param string|null $label       Optional label filter.
	 * @param int|null    $workflow_id Optional workflow filter.
	 * @return int
	 */
	private function row_count( ?string $kind = null, ?string $label = null, ?int $workflow_id = null ): int {
		global $wpdb;

		$table      = Baton_Saved_Values::table_name();
		$conditions = array();
		$values     = array();

		if ( null !== $kind ) {
			$conditions[] = 'kind = %s';
			$values[]     = $kind;
		}
		if ( null !== $label ) {
			$conditions[] = 'label = %s';
			$values[]     = $label;
		}
		if ( null !== $workflow_id ) {
			$conditions[] = 'workflow_id = %d';
			$values[]     = $workflow_id;
		}

		if ( empty( $conditions ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test helper, no dynamic values in query.
			return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table );
		}

		$sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . implode( ' AND ', $conditions );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test helper; all values go through prepare().
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $values ) );
	}

	/**
	 * Installation creates the table with the spec's columns.
	 */
	public function test_install_creates_table_with_spec_columns(): void {
		global $wpdb;

		$table = Baton_Saved_Values::table_name();

		$this->assertSame(
			$table,
			(string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) )
		);

		$columns = $wpdb->get_col( 'DESCRIBE ' . $table, 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test-only schema check.

		$this->assertSame(
			array( 'id', 'kind', 'workflow_id', 'run_id', 'label', 'value', 'step_id', 'created_at' ),
			$columns
		);
	}

	/**
	 * Save rows append and read back via find().
	 */
	public function test_append_save_and_find_round_trip(): void {
		$saved = Baton_Saved_Values::append(
			Baton_Saved_Values::KIND_SAVE,
			7,
			'11111111-2222-4333-8444-555555555555',
			'artifact',
			array(
				'a'   => 1,
				'arr' => array( 3, 4 ),
			),
			'step-1'
		);

		$this->assertIsInt( $saved['id'] );
		$this->assertGreaterThan( 0, $saved['id'] );
		$this->assertFalse( $saved['truncated'] );

		$row = Baton_Saved_Values::find( 'artifact', 7 );

		$this->assertIsArray( $row );
		$this->assertSame( $saved['id'], $row['id'] );
		$this->assertSame( 'save', $row['kind'] );
		$this->assertSame( 7, $row['workflow_id'] );
		$this->assertSame( '11111111-2222-4333-8444-555555555555', $row['run_id'] );
		$this->assertSame( 'step-1', $row['step_id'] );
		$this->assertSame(
			array(
				'a'   => 1,
				'arr' => array( 3, 4 ),
			),
			$row['value']
		);
	}

	/**
	 * Repeated save labels append rows; find returns the newest.
	 */
	public function test_append_repeated_labels_append_and_find_is_newest_wins(): void {
		Baton_Saved_Values::append( Baton_Saved_Values::KIND_SAVE, 7, 'run-a', 'artifact', 'first', 'step-0' );
		$second = Baton_Saved_Values::append( Baton_Saved_Values::KIND_SAVE, 7, 'run-b', 'artifact', 'second', 'step-1' );

		$this->assertSame( 2, $this->row_count( 'save', 'artifact', 7 ) );

		$row = Baton_Saved_Values::find( 'artifact', 7 );

		$this->assertIsArray( $row );
		$this->assertSame( $second['id'], $row['id'] );
		$this->assertSame( 'second', $row['value'] );
	}

	/**
	 * append() only accepts save and log kinds.
	 */
	public function test_append_rejects_state_and_unknown_kinds(): void {
		$this->assertSame(
			array(
				'id'        => null,
				'truncated' => false,
			),
			Baton_Saved_Values::append( Baton_Saved_Values::KIND_STATE, 7, 'run-a', 'k', 'v', '' )
		);

		$this->assertSame(
			array(
				'id'        => null,
				'truncated' => false,
			),
			Baton_Saved_Values::append( 'bogus', 7, 'run-a', 'k', 'v', '' )
		);

		$this->assertSame( 0, $this->row_count() );
	}

	/**
	 * Values over the ~1MB cap are truncated and flagged.
	 */
	public function test_append_truncates_oversize_value_and_flags_it(): void {
		$under_cap = str_repeat( 'a', 1000 );
		$over_cap  = str_repeat( 'b', Baton_Saved_Values::MAX_VALUE_BYTES + 100000 );

		$small  = Baton_Saved_Values::append( Baton_Saved_Values::KIND_SAVE, 7, 'run-a', 'small', $under_cap, '' );
		$big    = Baton_Saved_Values::append( Baton_Saved_Values::KIND_SAVE, 7, 'run-a', 'big', $over_cap, '' );
		$stored = Baton_Saved_Values::find( 'big', 7 );

		$this->assertFalse( $small['truncated'] );
		$this->assertTrue( $big['truncated'] );

		$this->assertIsArray( $stored );
		$this->assertIsString( $stored['value'] );
		// Stored payload is capped in bytes; the cut JSON is returned raw.
		$this->assertSame( Baton_Saved_Values::MAX_VALUE_BYTES, strlen( $stored['value'] ) );
		$this->assertSame( $small['id'], Baton_Saved_Values::find( 'small', 7 )['id'] );
	}

	/**
	 * Long labels are capped on write and lookups with the original form work.
	 */
	public function test_long_labels_truncate_to_190_chars_and_still_look_up(): void {
		$long_ascii = str_repeat( 'c', 400 );
		$long_multi = str_repeat( 'é', 250 );

		$saved = Baton_Saved_Values::append( Baton_Saved_Values::KIND_SAVE, 7, 'run-a', $long_ascii, 'ascii', '' );
		$multi = Baton_Saved_Values::append( Baton_Saved_Values::KIND_SAVE, 7, 'run-z', $long_multi, 'multi', '' );

		$row = Baton_Saved_Values::find( $long_ascii, 7 );
		$this->assertIsArray( $row );
		$this->assertSame( 190, strlen( $row['label'] ) );

		$multi_row = Baton_Saved_Values::find( $long_multi, 7 );
		$this->assertIsArray( $multi_row );
		$this->assertSame( $multi['id'], $multi_row['id'] );

		preg_match_all( '/./us', (string) $multi_row['label'], $chars, PREG_SET_ORDER );
		$this->assertCount( 190, $chars );
		$this->assertSame( $saved['id'], $row['id'] );
	}

	/**
	 * save-state upserts to a single row, newest value wins.
	 */
	public function test_upsert_state_single_row_newest_wins(): void {
		$first  = Baton_Saved_Values::upsert_state( 7, 'run-a', 'last_cursor', array( 'cursor' => 1 ), 'step-0' );
		$second = Baton_Saved_Values::upsert_state( 7, 'run-b', 'last_cursor', array( 'cursor' => 2 ), 'step-0' );

		$this->assertGreaterThan( 0, $first['id'] );
		$this->assertSame( 1, $this->row_count( 'state', 'last_cursor', 7 ) );

		$row = Baton_Saved_Values::find( 'last_cursor', 7 );

		$this->assertIsArray( $row );
		$this->assertSame( $second['id'], $row['id'] );
		$this->assertSame( $first['id'], $row['id'] ?? null ); // Same single row.
		$this->assertSame( 'state', $row['kind'] );
		$this->assertSame( 'run-b', $row['run_id'] );
		$this->assertSame( array( 'cursor' => 2 ), $row['value'] );
	}

	/**
	 * Different labels keep different state rows.
	 */
	public function test_upsert_state_distinct_labels(): void {
		Baton_Saved_Values::upsert_state( 7, 'run-a', 'cursor_a', 1, '' );
		Baton_Saved_Values::upsert_state( 7, 'run-a', 'cursor_b', 2, '' );

		$this->assertSame( 2, $this->row_count( 'state', null, 7 ) );
		$this->assertSame( 1, Baton_Saved_Values::find( 'cursor_a', 7 )['value'] );
		$this->assertSame( 2, Baton_Saved_Values::find( 'cursor_b', 7 )['value'] );
	}

	/**
	 * State upserts are scoped per workflow.
	 */
	public function test_state_upsert_scoped_by_workflow(): void {
		Baton_Saved_Values::upsert_state( 1, 'run-a', 'cursor', 'wf-1', '' );
		Baton_Saved_Values::upsert_state( 2, 'run-b', 'cursor', 'wf-2', '' );

		$this->assertSame( 2, $this->row_count( 'state', 'cursor' ) );
		$this->assertSame( 'wf-1', Baton_Saved_Values::find( 'cursor', 1 )['value'] );
		$this->assertSame( 'wf-2', Baton_Saved_Values::find( 'cursor', 2 )['value'] );
		$this->assertNull( Baton_Saved_Values::find( 'cursor', 3 ) );
	}

	/**
	 * find() spans save and state rows (newest id wins across kinds), and
	 * ignores log rows with the same label.
	 */
	public function test_find_spans_save_and_state_but_ignores_logs(): void {
		Baton_Saved_Values::append( Baton_Saved_Values::KIND_SAVE, 7, 'run-a', 'k', 'saved-1', '' );
		$state = Baton_Saved_Values::upsert_state( 7, 'run-b', 'k', 'state-2', '' );

		Baton_Saved_Values::append( Baton_Saved_Values::KIND_LOG, 7, 'run-b', 'k', array( 'message' => 'k' ), '' );

		$row = Baton_Saved_Values::find( 'k', 7 );
		$this->assertIsArray( $row );
		$this->assertSame( 'state-2', $row['value'] );
		$this->assertSame( $state['id'], $row['id'] ); // Newest save/state row, not the log row.

		// Saving again after the log makes the newest save row win.
		$saved = Baton_Saved_Values::append( Baton_Saved_Values::KIND_SAVE, 7, 'run-c', 'k', 'saved-3', '' );
		$this->assertSame( 'saved-3', Baton_Saved_Values::find( 'k', 7 )['value'] );
		$this->assertSame( $saved['id'], Baton_Saved_Values::find( 'k', 7 )['id'] );
	}

	/**
	 * find() is null when nothing matches.
	 */
	public function test_find_not_found_returns_null(): void {
		$this->assertNull( Baton_Saved_Values::find( 'never-saved', 7 ) );
	}

	/**
	 * from_run_id scopes the lookup to a single run.
	 */
	public function test_find_with_from_run_id_scopes_to_that_run(): void {
		Baton_Saved_Values::append( Baton_Saved_Values::KIND_SAVE, 7, 'run-a', 'k', 'first', '' );
		Baton_Saved_Values::append( Baton_Saved_Values::KIND_SAVE, 7, 'run-b', 'k', 'second', '' );

		$this->assertSame( 'first', Baton_Saved_Values::find( 'k', 7, 'run-a' )['value'] );
		$this->assertSame( 'second', Baton_Saved_Values::find( 'k', 7, 'run-b' )['value'] );
		$this->assertNull( Baton_Saved_Values::find( 'k', 7, 'run-c' ) );
	}

	/**
	 * Truncated/invalid JSON degrades to the raw stored string.
	 */
	public function test_find_degrades_to_raw_string_for_invalid_json(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only raw insert.
		$wpdb->insert(
			Baton_Saved_Values::table_name(),
			array(
				'kind'        => 'save',
				'workflow_id' => 7,
				'run_id'      => 'run-a',
				'label'       => 'broken',
				'value'       => '{"truncated": tru',
				'step_id'     => '',
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		$this->assertSame( '{"truncated": tru', Baton_Saved_Values::find( 'broken', 7 )['value'] );
	}

	/**
	 * delete_row removes a single row.
	 */
	public function test_delete_row(): void {
		$saved = Baton_Saved_Values::append( Baton_Saved_Values::KIND_SAVE, 7, 'run-a', 'k', 'v', '' );

		$this->assertTrue( Baton_Saved_Values::delete_row( (int) $saved['id'] ) );
		$this->assertNull( Baton_Saved_Values::find( 'k', 7 ) );
		$this->assertFalse( Baton_Saved_Values::delete_row( (int) $saved['id'] ) );
	}

	/**
	 * delete_run removes every row from a run.
	 */
	public function test_delete_run(): void {
		Baton_Saved_Values::append( Baton_Saved_Values::KIND_SAVE, 7, 'run-a', 's1', 'v', '' );
		Baton_Saved_Values::append( Baton_Saved_Values::KIND_LOG, 7, 'run-a', 'l1', array( 'message' => 'm' ), '' );
		Baton_Saved_Values::append( Baton_Saved_Values::KIND_SAVE, 7, 'run-b', 's2', 'v', '' );

		$this->assertSame( 2, Baton_Saved_Values::delete_run( 'run-a' ) );
		$this->assertSame( 1, $this->row_count() );
		$this->assertSame( 0, Baton_Saved_Values::delete_run( 'run-a' ) );
		$this->assertNotNull( Baton_Saved_Values::find( 's2', 7 ) );
	}

	/**
	 * rows_for_workflow returns every kind, newest-first, scoped to the workflow.
	 */
	public function test_rows_for_workflow_newest_first_and_scoped(): void {
		Baton_Saved_Values::append( Baton_Saved_Values::KIND_SAVE, 7, 'run-a', 'first', 'v', 'step-0' );
		Baton_Saved_Values::append( Baton_Saved_Values::KIND_LOG, 7, 'run-a', 'second', array( 'message' => 'm' ), 'step-1' );
		Baton_Saved_Values::upsert_state( 9, 'run-b', 'cursor', 'w9', '' );
		Baton_Saved_Values::upsert_state( 7, 'run-c', 'cursor', 'w7', '' );

		$rows = Baton_Saved_Values::rows_for_workflow( 7 );

		$this->assertCount( 3, $rows );

		// Newest id first across kinds.
		$this->assertSame( array( 'cursor', 'second', 'first' ), array_column( $rows, 'label' ) );
		$this->assertSame( array( 'state', 'log', 'save' ), array_column( $rows, 'kind' ) );

		// Formatted like find(): int casts + decoded value.
		$this->assertSame( 7, $rows[0]['workflow_id'] );
		$this->assertSame( 'w7', $rows[0]['value'] );

		// Other workflows are invisible.
		$rows_9 = Baton_Saved_Values::rows_for_workflow( 9 );
		$this->assertSame( array( 'cursor' ), array_column( $rows_9, 'label' ) );
		$this->assertSame( 'w9', $rows_9[0]['value'] );
		$this->assertSame( array(), Baton_Saved_Values::rows_for_workflow( 123 ) );
	}

	/**
	 * get_row fetches any row by id, or null.
	 */
	public function test_get_row_by_id(): void {
		$saved = Baton_Saved_Values::append( Baton_Saved_Values::KIND_SAVE, 7, 'run-a', 'label', array( 'x' => 'y' ), 's' );

		$row = Baton_Saved_Values::get_row( (int) $saved['id'] );

		$this->assertNotNull( $row );
		$this->assertSame( 'save', $row['kind'] );
		$this->assertSame( 7, $row['workflow_id'] );
		$this->assertSame( 'label', $row['label'] );
		$this->assertSame( 'run-a', $row['run_id'] );
		$this->assertSame( 's', $row['step_id'] );
		$this->assertSame( array( 'x' => 'y' ), $row['value'] );
		$this->assertNotSame( '', $row['created_at'] );

		$this->assertNull( Baton_Saved_Values::get_row( 999999 ) );
	}

	/**
	 * has_run checks run existence within one workflow.
	 */
	public function test_has_run_scopes_to_workflow(): void {
		Baton_Saved_Values::append( Baton_Saved_Values::KIND_SAVE, 7, 'run-a', 's', 'v', '' );
		Baton_Saved_Values::append( Baton_Saved_Values::KIND_SAVE, 9, 'run-b', 's', 'v', '' );

		$this->assertTrue( Baton_Saved_Values::has_run( 'run-a', 7 ) );
		$this->assertTrue( Baton_Saved_Values::has_run( 'run-b', 9 ) );
		$this->assertFalse( Baton_Saved_Values::has_run( 'run-a', 9 ) );
		$this->assertFalse( Baton_Saved_Values::has_run( 'run-b', 7 ) );
		$this->assertFalse( Baton_Saved_Values::has_run( 'missing', 7 ) );
		$this->assertFalse( Baton_Saved_Values::has_run( '', 7 ) );
	}

	/**
	 * delete_run_for_workflow removes only that workflow's rows for the run.
	 */
	public function test_delete_run_for_workflow_is_scoped(): void {
		Baton_Saved_Values::append( Baton_Saved_Values::KIND_SAVE, 7, 'run-a', 's1', 'v', '' );
		Baton_Saved_Values::append( Baton_Saved_Values::KIND_LOG, 7, 'run-a', 'l1', array( 'message' => 'm' ), '' );
		// Same run id appears for another workflow (e.g. rows written from
		// outside a runner execution); the viewer delete must not touch it.
		Baton_Saved_Values::append( Baton_Saved_Values::KIND_SAVE, 9, 'run-a', 's-other', 'v', '' );

		$this->assertSame( 2, Baton_Saved_Values::delete_run_for_workflow( 'run-a', 7 ) );

		$this->assertSame( 0, $this->row_count( null, null, 7 ) );
		$this->assertSame( 1, $this->row_count( null, 's-other', 9 ) );

		$this->assertSame( 0, Baton_Saved_Values::delete_run_for_workflow( '', 9 ) );
		$this->assertSame( 0, Baton_Saved_Values::delete_run_for_workflow( 'run-missing', 9 ) );
	}

	/**
	 * table_exists() reflects the real table state across uninstall/install.
	 */
	public function test_table_exists_tracks_table_state(): void {
		$this->assertTrue( Baton_Saved_Values::table_exists() );

		Baton_Saved_Values::uninstall();
		$this->assertFalse( Baton_Saved_Values::table_exists() );

		// Re-installing recreates the table (the admin migration action path)
		// and table_exists() reports it again.
		Baton_Saved_Values::install();
		$this->assertTrue( Baton_Saved_Values::table_exists() );

		// Writes work again on the recreated table.
		$saved = Baton_Saved_Values::append( Baton_Saved_Values::KIND_SAVE, 7, 'run-a', 's', 'v', '' );
		$this->assertNotNull( $saved['id'] );
		$this->assertNotNull( Baton_Saved_Values::find( 's', 7 ) );
	}

	/**
	 * table_exists() is static-cached: checks between DDL calls do not
	 * re-query, and install()/uninstall() reset the cache.
	 */
	public function test_table_exists_is_cached_between_checks(): void {
		$this->assertTrue( Baton_Saved_Values::table_exists() ); // primes the cache.

		// Drops without touching the cache — the cached truth still stands
		// for this request; that staleness is why install()/uninstall()
		// reset it.
		$this->drop_baton_table();
		$this->assertTrue( Baton_Saved_Values::table_exists() );

		Baton_Saved_Values::install(); // resets the cache and recreates the table.
		$this->assertTrue( Baton_Saved_Values::table_exists() );
	}
}