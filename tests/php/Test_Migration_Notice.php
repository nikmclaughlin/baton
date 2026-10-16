<?php
/**
 * Tests for Baton_Migration_Notice: missing-table detection, the notice,
 * and the one-click migration action.
 *
 * @package Baton
 */

declare( strict_types=1 );

/**
 * Admin migration notice tests.
 */
class Test_Migration_Notice extends WP_UnitTestCase {

	/**
	 * Runs before each test: drop + recreate the table for isolation, admin user.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->drop_table();
		Baton_Saved_Values::install();

		wp_set_current_user( 1 );

		unset( $_GET['baton_migrated'], $_GET['baton_migration_error'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.WP.Superglobals -- test hygiene.
	}

	/**
	 * Runs after each test: drop the table and clean request globals.
	 */
	public function tear_down(): void {
		$this->drop_table();

		unset( $_GET['baton_migrated'], $_GET['baton_migration_error'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.WP.Superglobals -- test hygiene.
		unset( $_GET['action'], $_GET['_wpnonce'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.WP.Superglobals -- test hygiene.

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Drop the custom table between tests.
	 */
	private function drop_table(): void {
		global $wpdb;

		$wpdb->query( 'DROP TABLE IF EXISTS ' . Baton_Saved_Values::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test-only DDL.
	}

	/**
	 * Capture everything render_notices() echoes.
	 *
	 * @return string
	 */
	private function capture_render(): string {
		ob_start();
		Baton_Migration_Notice::render_notices();

		return (string) ob_get_clean();
	}

	/**
	 * A valid migration nonce for the current user.
	 *
	 * @return string
	 */
	private function valid_nonce(): string {
		return wp_create_nonce( Baton_Migration_Notice::NONCE_ACTION );
	}

	/**
	 * Admins see the dismissible warning with the one-click migration button
	 * while the table is missing.
	 */
	public function test_missing_table_notice_renders_for_admins(): void {
		Baton_Saved_Values::uninstall();

		$out = $this->capture_render();

		$this->assertStringContainsString( 'notice-warning', $out );
		$this->assertStringContainsString( 'is-dismissible', $out );
		$this->assertStringContainsString( 'Baton needs a database migration.', $out );
		$this->assertStringContainsString( 'Run database migration', $out );

		// The button rides the nonced GET row action.
		$this->assertStringContainsString( 'action=' . Baton_Migration_Notice::ACTION_RUN_MIGRATION, $out );
		$this->assertStringContainsString( '_wpnonce=', $out );
	}

	/**
	 * No warning while the table exists (and no confirmations in a clean request).
	 */
	public function test_no_notice_when_table_exists(): void {
		$this->assertSame( '', $this->capture_render() );
	}

	/**
	 * Notices (warning and confirmations) are admins-only.
	 */
	public function test_notices_hidden_from_non_admins(): void {
		Baton_Saved_Values::uninstall();
		wp_set_current_user( 0 );

		$this->assertSame( '', $this->capture_render() );
	}

	/**
	 * The migration action calls install() (dbDelta): the table is recreated
	 * and writes work again.
	 */
	public function test_migration_action_recreates_table(): void {
		Baton_Saved_Values::uninstall();
		$this->assertFalse( Baton_Saved_Values::table_exists() );

		$this->assertTrue( Baton_Migration_Notice::handle_action( $this->valid_nonce() ) );

		$this->assertTrue( Baton_Saved_Values::table_exists() );

		$saved = Baton_Saved_Values::append( Baton_Saved_Values::KIND_SAVE, 7, 'run-a', 's', 'v', '' );
		$this->assertNotNull( $saved['id'] );
	}

	/**
	 * The migration action requires manage_options (checked before the nonce,
	 * matching the viewer deletes' failure order).
	 */
	public function test_migration_action_is_capability_gated(): void {
		Baton_Saved_Values::uninstall();

		$admin_nonce = $this->valid_nonce();
		wp_set_current_user( 0 );

		$result = Baton_Migration_Notice::handle_action( $admin_nonce );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'baton_migration_forbidden', $result->get_error_code() );
		$this->assertFalse( Baton_Saved_Values::table_exists() );
	}

	/**
	 * The migration action requires a valid nonce.
	 */
	public function test_migration_action_requires_valid_nonce(): void {
		Baton_Saved_Values::uninstall();

		$result = Baton_Migration_Notice::handle_action( 'bogus-nonce' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'baton_migration_invalid_nonce', $result->get_error_code() );
		$this->assertFalse( Baton_Saved_Values::table_exists() );
	}

	/**
	 * A successful migration redirects back with a success confirmation (the
	 * missing-table warning is gone once the table exists).
	 */
	public function test_migrated_confirmation_renders(): void {
		$_GET['baton_migrated'] = '1';

		$out = $this->capture_render();

		$this->assertStringContainsString( 'notice-success', $out );
		$this->assertStringContainsString( 'Baton database migration complete.', $out );
		$this->assertStringNotContainsString( 'notice-warning', $out );
	}

	/**
	 * A failed migration renders the error confirmation, sanitized.
	 */
	public function test_migration_error_confirmation_renders_sanitized(): void {
		$_GET['baton_migration_error'] = '<b>boom</b>';

		$out = $this->capture_render();

		$this->assertStringContainsString( 'notice-error', $out );
		$this->assertStringContainsString( 'boom', $out );
		$this->assertStringNotContainsString( '<b>', $out );
	}
}