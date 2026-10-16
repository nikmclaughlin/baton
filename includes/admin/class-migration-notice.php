<?php
/**
 * Admin notice + one-click migration for a missing saved-values table.
 *
 * @package Baton
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects a missing Baton_Saved_Values table on admin pages and repairs it.
 *
 * The storage table is created on activation only (dbDelta), so a
 * replaced/restored database can leave an active Baton without it. When that
 * happens, admins see a dismissible warning on every admin page with a
 * one-click "Run database migration" button (WooCommerce-style update
 * notice). The button rides a classic GET row action with a nonce — the same
 * mechanism the workflow Delete link and the saved-values viewer deletes use
 * — gated by `manage_options` on every path, then redirects back with a
 * confirmation.
 */
final class Baton_Migration_Notice {

	/**
	 * GET action for the one-click migration.
	 */
	public const ACTION_RUN_MIGRATION = 'baton_run_migration';

	/**
	 * Nonce action for the migration link.
	 */
	public const NONCE_ACTION = 'baton_run_migration';

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'maybe_handle_action' ) );
		add_action( 'admin_notices', array( self::class, 'render_notices' ) );
	}

	/**
	 * Handle the migration row action from the notice button (redirects).
	 */
	public static function maybe_handle_action(): void {
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( self::ACTION_RUN_MIGRATION !== $action ) {
			return;
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$result = self::handle_action( $nonce );

		if ( is_wp_error( $result ) ) {
			self::redirect_back( array( 'baton_migration_error' => rawurlencode( $result->get_error_message() ) ) );
		}

		self::redirect_back( array( 'baton_migrated' => '1' ) );
	}

	/**
	 * Perform the migration action (capability + nonce checks, then dbDelta).
	 *
	 * Kept free of request/redirect side effects so tests can exercise every
	 * failure path; maybe_handle_action() wraps it with $_GET plumbing.
	 *
	 * @param string $nonce Nonce from the notice button link.
	 * @return true|WP_Error True on success.
	 */
	public static function handle_action( string $nonce ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'baton_migration_forbidden',
				__( 'Insufficient permissions.', 'baton' )
			);
		}

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return new WP_Error(
				'baton_migration_invalid_nonce',
				__( 'This link is no longer valid. Reload the page and try again.', 'baton' )
			);
		}

		Baton_Saved_Values::install();

		if ( ! Baton_Saved_Values::table_exists() ) {
			return new WP_Error(
				'baton_migration_failed',
				__( 'The database migration could not be completed.', 'baton' )
			);
		}

		return true;
	}

	/**
	 * Admin notices: request-scoped migration confirmations, plus the
	 * missing-table warning with the one-click migration button.
	 */
	public static function render_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_GET['baton_migration_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only confirmation.
			$error = sanitize_text_field( wp_unslash( $_GET['baton_migration_error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only confirmation.

			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error ) . '</p></div>';
		}

		if ( isset( $_GET['baton_migrated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only confirmation.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Baton database migration complete. Saved values, state, and logs are ready.', 'baton' ) . '</p></div>';
		}

		if ( Baton_Saved_Values::table_exists() ) {
			return;
		}

		self::render_missing_table_notice();
	}

	/**
	 * The missing-table warning: explains the impact, offers the fix.
	 */
	private static function render_missing_table_notice(): void {
		$migration_url = wp_nonce_url(
			add_query_arg(
				array( 'action' => self::ACTION_RUN_MIGRATION ),
				admin_url( 'index.php' )
			),
			self::NONCE_ACTION
		);
		?>
		<div class="notice notice-warning is-dismissible baton-migration-notice">
			<p>
				<strong><?php esc_html_e( 'Baton needs a database migration.', 'baton' ); ?></strong>
				<?php esc_html_e( 'The table that stores workflow saved values, state, and logs is missing — typically after a database import or restore. Steps that save output, keep state, or write logs will fail until it is created. Running the migration now creates the table; it does not remove or change your workflow definitions.', 'baton' ); ?>
			</p>
			<p><a class="button button-primary" href="<?php echo esc_url( $migration_url ); ?>"><?php esc_html_e( 'Run database migration', 'baton' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * Redirect back to the referring admin page and stop the request.
	 *
	 * The notice renders on every admin page, so "back" is the page the
	 * button was clicked from (the referer), not a fixed Baton screen.
	 *
	 * @param array<string, string> $extra Extra query args (notices).
	 * @return never
	 */
	private static function redirect_back( array $extra ): void {
		$target = wp_get_referer();

		if ( ! $target ) {
			$target = admin_url( 'index.php' );
		}

		// Exactly one migration confirmation survives the redirect.
		$target = remove_query_arg( array( 'baton_migrated', 'baton_migration_error' ), $target );

		wp_safe_redirect( add_query_arg( $extra, $target ) );
		exit;
	}
}