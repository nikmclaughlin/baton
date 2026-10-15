<?php
/**
 * Per-workflow admin viewer for saved values and logs.
 *
 * @package Baton
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders Baton's saved-value timeline on the workflow edit screen and
 * handles its delete-row / delete-run actions.
 *
 * The viewer is plain server-rendered admin markup (below the run panel):
 * newest-first runs, each a unified timeline of its save/state/log rows
 * with kind chips and click-to-expand values. Deletes ride classic GET row
 * actions with a per-target nonce — the same WP-idiomatic mechanism the
 * workflow Delete link uses — gated by `manage_options` on every path.
 */
final class Baton_Run_Viewer {

	/**
	 * GET action for deleting one row.
	 */
	public const ACTION_DELETE_VALUE = 'baton_delete_value';

	/**
	 * GET action for deleting every row of one run.
	 */
	public const ACTION_DELETE_RUN = 'baton_delete_run';

	/**
	 * Character cap for the collapsed value preview.
	 */
	public const VALUE_PREVIEW_CHARS = 240;

	/**
	 * Character cap for the expanded value display (storage already caps
	 * values at ~1MB; the viewer truncates all output for render safety).
	 */
	public const VALUE_DISPLAY_MAX = 8192;

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'maybe_handle_action' ) );
	}

	/**
	 * Handle viewer delete actions from row links (redirects on completion).
	 */
	public static function maybe_handle_action(): void {
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $action, array( self::ACTION_DELETE_VALUE, self::ACTION_DELETE_RUN ), true ) ) {
			return;
		}

		$workflow_id = isset( $_GET['workflow_id'] ) ? absint( $_GET['workflow_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$value_id    = isset( $_GET['value_id'] ) ? absint( $_GET['value_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$run_id      = isset( $_GET['run_id'] ) ? sanitize_text_field( wp_unslash( $_GET['run_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$nonce       = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$result = self::handle_action( $action, $workflow_id, $value_id, $run_id, $nonce );

		if ( is_wp_error( $result ) ) {
			self::redirect_to_editor(
				$workflow_id,
				array( 'error' => rawurlencode( $result->get_error_message() ) )
			);
		}

		$notice = self::ACTION_DELETE_VALUE === $action ? 'value_deleted' : 'run_deleted';

		self::redirect_to_editor( $workflow_id, array( $notice => '1' ) );
	}

	/**
	 * Perform a viewer delete action (capability + nonce + ownership checks).
	 *
	 * Kept free of request/redirect side effects so tests can exercise every
	 * failure path; maybe_handle_action() wraps it with $_GET plumbing.
	 *
	 * @param string $action      ACTION_DELETE_VALUE or ACTION_DELETE_RUN.
	 * @param int    $workflow_id Workflow post ID whose viewer the link came from.
	 * @param int    $value_id    Row id (delete-value action).
	 * @param string $run_id      Run id (delete-run action).
	 * @param string $nonce       Nonce from the row link.
	 * @return true|WP_Error True on success.
	 */
	public static function handle_action( string $action, int $workflow_id, int $value_id, string $run_id, string $nonce ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'baton_viewer_forbidden',
				__( 'Insufficient permissions.', 'baton' )
			);
		}

		$post = $workflow_id > 0 ? get_post( $workflow_id ) : null;
		if ( ! $post || Baton_Workflow_CPT::POST_TYPE !== $post->post_type ) {
			return new WP_Error(
				'baton_viewer_workflow_not_found',
				__( 'Workflow not found.', 'baton' )
			);
		}

		if ( ! in_array( $action, array( self::ACTION_DELETE_VALUE, self::ACTION_DELETE_RUN ), true ) ) {
			return new WP_Error(
				'baton_viewer_unknown_action',
				__( 'Unknown saved-values action.', 'baton' )
			);
		}

		if ( self::ACTION_DELETE_VALUE === $action ) {
			return self::handle_delete_value( $workflow_id, $value_id, $nonce );
		}

		return self::handle_delete_run( $workflow_id, $run_id, $nonce );
	}

	/**
	 * Delete a single row after verifying it belongs to the viewed workflow.
	 *
	 * @param int    $workflow_id Workflow post ID.
	 * @param int    $value_id    Row id.
	 * @param string $nonce       Nonce from the row link.
	 * @return true|WP_Error True on success.
	 */
	private static function handle_delete_value( int $workflow_id, int $value_id, string $nonce ) {
		if ( $value_id <= 0 ) {
			return new WP_Error(
				'baton_viewer_invalid_row',
				__( 'No saved value selected.', 'baton' )
			);
		}

		if ( ! wp_verify_nonce( $nonce, self::value_nonce_action( $value_id ) ) ) {
			return new WP_Error(
				'baton_viewer_invalid_nonce',
				__( 'This link is no longer valid. Reload the page and try again.', 'baton' )
			);
		}

		$row = Baton_Saved_Values::get_row( $value_id );
		if ( ! $row || $row['workflow_id'] !== $workflow_id ) {
			return new WP_Error(
				'baton_viewer_row_not_found',
				__( 'Saved value not found.', 'baton' )
			);
		}

		if ( ! Baton_Saved_Values::delete_row( $value_id ) ) {
			return new WP_Error(
				'baton_viewer_delete_failed',
				__( 'The saved value could not be deleted.', 'baton' )
			);
		}

		return true;
	}

	/**
	 * Delete every row of a run (scoped to the viewed workflow).
	 *
	 * @param int    $workflow_id Workflow post ID.
	 * @param string $run_id      Run id.
	 * @param string $nonce       Nonce from the row link.
	 * @return true|WP_Error True on success.
	 */
	private static function handle_delete_run( int $workflow_id, string $run_id, string $nonce ) {
		if ( '' === $run_id ) {
			return new WP_Error(
				'baton_viewer_invalid_run',
				__( 'No run selected.', 'baton' )
			);
		}

		if ( ! wp_verify_nonce( $nonce, self::run_nonce_action( $run_id ) ) ) {
			return new WP_Error(
				'baton_viewer_invalid_nonce',
				__( 'This link is no longer valid. Reload the page and try again.', 'baton' )
			);
		}

		if ( ! Baton_Saved_Values::has_run( $run_id, $workflow_id ) ) {
			return new WP_Error(
				'baton_viewer_run_not_found',
				__( 'Run not found for this workflow.', 'baton' )
			);
		}

		if ( 0 === Baton_Saved_Values::delete_run_for_workflow( $run_id, $workflow_id ) ) {
			return new WP_Error(
				'baton_viewer_delete_failed',
				__( 'The run could not be deleted.', 'baton' )
			);
		}

		return true;
	}

	/**
	 * Nonce action for a delete-row link.
	 *
	 * @param int $value_id Row id.
	 * @return string
	 */
	public static function value_nonce_action( int $value_id ): string {
		return self::ACTION_DELETE_VALUE . '_' . $value_id;
	}

	/**
	 * Nonce action for a delete-run link.
	 *
	 * @param string $run_id Run id.
	 * @return string
	 */
	public static function run_nonce_action( string $run_id ): string {
		return self::ACTION_DELETE_RUN . '_' . $run_id;
	}

	/**
	 * Render the saved-values panel below the editor/run panel.
	 *
	 * @param int $workflow_id Workflow post ID.
	 */
	public static function render( int $workflow_id ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$runs = self::get_runs( $workflow_id );

		echo '<div id="baton-saved-values" class="baton-runs-panel">';
		echo '<h2>' . esc_html__( 'Saved values & logs', 'baton' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Values written by save-output and save-state steps, plus log entries, grouped by run (newest first). Deleting is permanent and removes only what you choose here.', 'baton' ) . '</p>';

		self::maybe_render_notices();

		if ( empty( $runs ) ) {
			echo '<p class="description baton-runs-panel__empty">' . esc_html__( 'No saved values or logs yet. Run a workflow that includes save-output, save-state, or log steps and its output will appear here.', 'baton' ) . '</p>';
			echo '</div>';
			return;
		}

		foreach ( $runs as $run ) {
			if ( is_array( $run ) ) {
				self::render_run( $workflow_id, $run );
			}
		}

		echo '</div>';
	}

	/**
	 * Render one run section and its timeline rows.
	 *
	 * @param int                  $workflow_id Workflow post ID.
	 * @param array<string, mixed> $run         Run grouping (run_id, latest, rows).
	 */
	private static function render_run( int $workflow_id, array $run ): void {
		$rows    = is_array( $run['rows'] ?? null ) ? $run['rows'] : array();
		$latest  = (string) ( $run['latest'] ?? '' );
		$run_id  = (string) ( $run['run_id'] ?? '' );
		$count   = count( $rows );
		$display = $run_id ? $run_id : __( 'unknown run', 'baton' );

		echo '<div class="baton-run">';
		echo '<div class="baton-run__header">';
		echo '<span class="baton-run__id"><code>' . esc_html( $display ) . '</code></span>';
		echo '<span class="baton-run__meta">';
		/* translators: %d: number of stored rows for this run. */
		echo esc_html( sprintf( _n( '%d row', '%d rows', $count, 'baton' ), $count ) );
		if ( '' !== $latest ) {
			echo ' · ' . esc_html( self::time_label( $latest ) );
		}
		echo '</span>';
		if ( $run_id ) {
			echo '<a class="baton-run__delete" href="' . esc_url( self::delete_run_link( $workflow_id, $run_id ) ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete every row from this run?', 'baton' ) ) . '\');">' . esc_html__( 'Delete run', 'baton' ) . '</a>';
		}
		echo '</div>';
		echo '<ul class="baton-run__timeline">';
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				self::render_row( $workflow_id, $row );
			}
		}
		echo '</ul>';
		echo '</div>';
	}

	/**
	 * Render one timeline row.
	 *
	 * @param int                  $workflow_id Workflow post ID.
	 * @param array<string, mixed> $row         Formatted saved-value row.
	 */
	private static function render_row( int $workflow_id, array $row ): void {
		$kind = isset( $row['kind'] ) ? (string) $row['kind'] : '';

		echo '<li class="baton-run__row baton-run__row--' . esc_attr( $kind ) . '">';
		echo '<span class="baton-run__chip baton-run__chip--' . esc_attr( $kind ) . '">' . esc_html( self::kind_label( $kind ) ) . '</span>';
		echo '<span class="baton-run__label">' . esc_html( (string) ( $row['label'] ?? '' ) ) . '</span>';
		echo '<span class="baton-run__meta">';
		echo esc_html( self::time_label( (string) ( $row['created_at'] ?? '' ) ) );
		if ( '' !== ( $row['step_id'] ?? '' ) ) {
			/* translators: %s: editor node ID of the writing step. */
			echo ' · ' . esc_html( sprintf( __( 'step %s', 'baton' ), (string) $row['step_id'] ) );
		}
		echo '</span>';
		echo '<a class="baton-run__delete" href="' . esc_url( self::delete_value_link( $workflow_id, (int) ( $row['id'] ?? 0 ) ) ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this saved value?', 'baton' ) ) . '\');">' . esc_html__( 'Delete', 'baton' ) . '</a>';
		self::render_value( $row['value'] ?? null );
		echo '</li>';
	}

	/**
	 * Render a decoded stored value: inline when short, click-to-expand when
	 * long. Values are JSON-decoded by storage and escaped here; all output
	 * is truncated to the display caps.
	 *
	 * @param mixed $value Decoded stored value.
	 */
	private static function render_value( $value ): void {
		if ( is_string( $value ) ) {
			$pretty = $value;
		} else {
			$encoded = wp_json_encode(
				$value,
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
			);
			$pretty  = is_string( $encoded ) ? $encoded : '';
		}

		$truncated = strlen( $pretty ) > self::VALUE_DISPLAY_MAX;
		if ( $truncated ) {
			$pretty .= "\n… " . __( '(value truncated for display)', 'baton' );
		}

		if ( ! $truncated && strlen( $pretty ) <= self::VALUE_PREVIEW_CHARS ) {
			echo '<code class="baton-run__value-inline">' . esc_html( $pretty ) . '</code>';
			return;
		}

		echo '<details class="baton-run__value">';
		echo '<summary>' . esc_html( self::truncate( $pretty, self::VALUE_PREVIEW_CHARS ) ) . '</summary>';
		echo '<pre>' . esc_html( $pretty ) . '</pre>';
		echo '</details>';
	}

	/**
	 * Runs for one workflow, newest-first.
	 *
	 * @param int $workflow_id Workflow post ID.
	 * @return array<int, array<string, mixed>> array{run_id, latest, rows}
	 */
	public static function get_runs( int $workflow_id ): array {
		if ( $workflow_id <= 0 ) {
			return array();
		}

		return self::group_rows_into_runs( Baton_Saved_Values::rows_for_workflow( $workflow_id ) );
	}

	/**
	 * Group rows (newest-first) into runs; rows inside each run are ordered
	 * chronologically so the timeline replays the run in execution order.
	 *
	 * Pure helper (unit-tested) over Baton_Saved_Values::rows_for_workflow().
	 *
	 * @param array<int, array<string, mixed>> $rows Newest-first rows.
	 * @return array<int, array<string, mixed>> Runs newest-first with chronological rows.
	 */
	public static function group_rows_into_runs( array $rows ): array {
		$runs      = array();
		$run_index = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$run_id = isset( $row['run_id'] ) ? (string) $row['run_id'] : '';

			if ( ! isset( $run_index[ $run_id ] ) ) {
				$run_index[ $run_id ] = count( $runs );
				$runs[]               = array(
					'run_id' => $run_id,
					'latest' => (string) ( $row['created_at'] ?? '' ),
					'rows'   => array(),
				);
			}

			$runs[ $run_index[ $run_id ] ]['rows'][] = $row;
		}

		foreach ( $runs as $index => $run ) {
			$runs[ $index ]['rows'] = array_reverse( $run['rows'] );
		}

		return $runs;
	}

	/**
	 * Chip label for a stored row kind.
	 *
	 * @param string $kind Kind column value.
	 * @return string
	 */
	private static function kind_label( string $kind ): string {
		switch ( $kind ) {
			case Baton_Saved_Values::KIND_SAVE:
				return __( 'Save', 'baton' );
			case Baton_Saved_Values::KIND_STATE:
				return __( 'State', 'baton' );
			case Baton_Saved_Values::KIND_LOG:
				return __( 'Log', 'baton' );
			default:
				return $kind;
		}
	}

	/**
	 * Site-localized time label for a stored GMT datetime.
	 *
	 * @param string $created_at GMT datetime (Y-m-d H:i:s).
	 * @return string
	 */
	private static function time_label( string $created_at ): string {
		if ( '' === $created_at ) {
			return '';
		}

		$timestamp = (int) get_date_from_gmt( $created_at, 'U' );
		if ( $timestamp <= 0 ) {
			return $created_at;
		}

		return wp_date( get_option( 'time_format' ) . ' ' . get_option( 'date_format' ), $timestamp );
	}

	/**
	 * Delete-row link for the confirm-checked row action.
	 *
	 * @param int $workflow_id Workflow post ID.
	 * @param int $value_id    Row id.
	 * @return string Raw URL (escape with esc_url() at the output site).
	 */
	private static function delete_value_link( int $workflow_id, int $value_id ): string {
		$base = add_query_arg(
			array(
				'page'        => Baton_Admin::PAGE_SLUG,
				'action'      => self::ACTION_DELETE_VALUE,
				'workflow_id' => $workflow_id,
				'value_id'    => $value_id,
			),
			admin_url( 'tools.php' )
		);

		return wp_nonce_url( $base, self::value_nonce_action( $value_id ) );
	}

	/**
	 * Delete-run link for the confirm-checked run header action.
	 *
	 * @param int    $workflow_id Workflow post ID.
	 * @param string $run_id      Run id.
	 * @return string Raw URL (escape with esc_url() at the output site).
	 */
	private static function delete_run_link( int $workflow_id, string $run_id ): string {
		$base = add_query_arg(
			array(
				'page'        => Baton_Admin::PAGE_SLUG,
				'action'      => self::ACTION_DELETE_RUN,
				'workflow_id' => $workflow_id,
				'run_id'      => $run_id,
			),
			admin_url( 'tools.php' )
		);

		return wp_nonce_url( $base, self::run_nonce_action( $run_id ) );
	}

	/**
	 * Redirect back to the workflow editor and stop the request.
	 *
	 * @param int                   $workflow_id Workflow post ID (0 falls back to the list).
	 * @param array<string, string> $extra       Extra query args (notices).
	 * @return never
	 */
	private static function redirect_to_editor( int $workflow_id, array $extra ): void {
		$args = array(
			'page'   => Baton_Admin::PAGE_SLUG,
			'action' => 'edit',
		);

		if ( $workflow_id > 0 ) {
			$args['workflow_id'] = $workflow_id;
		}

		wp_safe_redirect( add_query_arg( array_merge( $args, $extra ), admin_url( 'tools.php' ) ) );
		exit;
	}

	/**
	 * Multi-use display-only notices (successful deletes).
	 */
	private static function maybe_render_notices(): void {
		if ( isset( $_GET['value_deleted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only confirmation.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved value deleted.', 'baton' ) . '</p></div>';
		}

		if ( isset( $_GET['run_deleted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only confirmation.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Run rows deleted.', 'baton' ) . '</p></div>';
		}
	}

	/**
	 * Multibyte-safe cut for display strings.
	 *
	 * @param string $text   Input.
	 * @param int    $chars  Max characters.
	 * @return string
	 */
	private static function truncate( string $text, int $chars ): string {
		if ( strlen( $text ) <= $chars ) {
			return $text;
		}

		$cut = preg_replace( '/^(.{' . $chars . '}).+/su', '$1', $text );

		return is_string( $cut ) ? $cut : substr( $text, 0, $chars );
	}
}
