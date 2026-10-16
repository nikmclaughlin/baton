<?php
/**
 * Shared table-state isolation for tests that drop/recreate Baton's storage
 * table.
 *
 * The PHPUnit harness wraps every test in a transaction and rewrites all
 * CREATE TABLE / DROP TABLE queries into their TEMPORARY variants (see the
 * `_create_temporary_tables()` / `_drop_temporary_tables()` callbacks the
 * suite attaches to the `query` filter). TEMPORARY tables cannot remove a
 * real table, and wp-env activates the plugin on the test environment
 * (register_activation_hook → Baton_Saved_Values::install()), so the tests
 * database can hold a permanent wp_baton_saved_values table that in-suite
 * DDL can never drop, leaving table_exists() stuck at true. This trait
 * drops the real table (and any lingering temp table) during the per-test
 * lifecycle so every table-state assertion stays meaningful.
 *
 * @package Baton
 */

declare( strict_types=1 );

if ( trait_exists( 'Baton_Test_Table_State_Trait' ) ) {
	return;
}

/**
 * Real-table drop helper for Baton storage-table tests.
 */
trait Baton_Test_Table_State_Trait {

	/**
	 * Drop Baton's storage table: no lingering temp table and no real one.
	 *
	 * The first (harness-rewritten) drop removes any TEMPORARY table created
	 * by earlier in-suite dbDelta() calls. The second drop detaches the
	 * harness's DROP TABLE rewriting so the REAL table — e.g. created by
	 * wp-env's tests-environment plugin activation — is removed as well.
	 *
	 * Always follow with Baton_Saved_Values::install() in set_up: it
	 * recreates the table under the tests' usual (rewritten) semantics and
	 * resets the static table_exists() cache, keeping state self-contained.
	 */
	protected function drop_baton_table(): void {
		global $wpdb;

		$table = Baton_Saved_Values::table_name();

		// 1) Removes the session-scoped TEMPORARY variant (rewritten by the
		// harness's `_drop_temporary_tables()` query filter).
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test-only DDL.

		// 2) Removes the REAL table, bypassing the harness's TEMPORARY rewrite.
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test-only DDL.
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}
}