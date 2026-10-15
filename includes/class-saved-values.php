<?php
/**
 * Storage for Baton's saved workflow values.
 *
 * @package Baton
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One custom table for Baton's memory: save/state/log rows are the same act
 * ("record a value during a run") with different retention semantics.
 *
 * - save  (baton/save-output): append-only records, keyed by user label.
 * - state (baton/save-state):  one row per (workflow_id, label), newest wins.
 * - log   (baton/log):         per-run inspectable entries; never loaded back.
 */
final class Baton_Saved_Values {

	public const KIND_SAVE = 'save';

	public const KIND_STATE = 'state';

	public const KIND_LOG = 'log';

	/**
	 * Cap for a single stored value, in bytes (~1MB).
	 */
	public const MAX_VALUE_BYTES = 1048576;

	/**
	 * Cap for the label column payload, in characters (VARCHAR(191) headroom).
	 */
	public const MAX_LABEL_CHARS = 190;

	/**
	 * Full table name for the current site prefix.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'baton_saved_values';
	}

	/**
	 * Table schema for dbDelta.
	 *
	 * @return string
	 */
	public static function schema(): string {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$table           = self::table_name();

		return "CREATE TABLE {$table} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	kind VARCHAR(16) NOT NULL DEFAULT '',
	workflow_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
	run_id VARCHAR(64) NOT NULL DEFAULT '',
	label VARCHAR(191) NOT NULL DEFAULT '',
	value LONGTEXT NOT NULL,
	step_id VARCHAR(64) NOT NULL DEFAULT '',
	created_at DATETIME NOT NULL,
	PRIMARY KEY  (id),
	KEY workflow_id (workflow_id),
	KEY run_id (run_id)
	) {$charset_collate};";
	}

	/**
	 * Create/migrate the table (dbDelta) — plugin activation path.
	 */
	public static function install(): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( self::schema() );
	}

	/**
	 * Drop the table (plugin uninstall path).
	 */
	public static function uninstall(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- DDL cannot be prepared; identifier built from $wpdb->prefix.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table_name() );
	}

	/**
	 * Append a save/log row.
	 *
	 * Repeated labels are allowed for `save` (records scale with objects);
	 * `log` rows are tagged with the run for the per-run timeline.
	 *
	 * @param string $kind        KIND_SAVE or KIND_LOG.
	 * @param int    $workflow_id Workflow post ID.
	 * @param string $run_id      Runner-generated execution ID.
	 * @param string $label       User label (save) or message headline (log).
	 * @param mixed  $value       Payload stored JSON-encoded.
	 * @param string $step_id     Editor node id that wrote the row.
	 * @return array{id: int|null, truncated: bool} Row summary; id is null on write failure.
	 */
	public static function append( string $kind, int $workflow_id, string $run_id, string $label, $value, string $step_id = '' ): array {
		if ( self::KIND_SAVE !== $kind && self::KIND_LOG !== $kind ) {
			// Only save/log rows append; state rows must go through upsert_state().
			return array(
				'id'        => null,
				'truncated' => false,
			);
		}

		return self::insert_row( $kind, $workflow_id, $run_id, $label, $value, $step_id );
	}

	/**
	 * Upsert a state row keyed by (workflow_id, label, kind='state') — one row
	 * per label, newest values win. App-level upsert; no unique DB constraint.
	 *
	 * @param int    $workflow_id Workflow post ID.
	 * @param string $run_id      Runner-generated execution ID.
	 * @param string $label       User label.
	 * @param mixed  $value       Payload stored JSON-encoded.
	 * @param string $step_id     Editor node id that wrote the row.
	 * @return array{id: int|null, truncated: bool} Row summary; id is null on write failure.
	 */
	public static function upsert_state( int $workflow_id, string $run_id, string $label, $value, string $step_id = '' ): array {
		global $wpdb;

		$label = self::truncate_label( $label );
		$sql   = 'SELECT id FROM ' . self::table_name() . ' WHERE workflow_id = %d AND kind = %s AND label = %s ORDER BY id DESC LIMIT 1';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifier built from $wpdb->prefix; values are prepared.
		$existing_id = (int) $wpdb->get_var( $wpdb->prepare( $sql, $workflow_id, self::KIND_STATE, $label ) );

		$encoded = self::encode_value( $value );

		if ( $existing_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Value storage CRUD; no cache API for this table.
			$updated = $wpdb->update(
				self::table_name(),
				array(
					'run_id'     => $run_id,
					'value'      => $encoded['json'],
					'step_id'    => $step_id,
					'created_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $existing_id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			return array(
				'id'        => ( false !== $updated ) ? $existing_id : null,
				'truncated' => $encoded['truncated'],
			);
		}

		return self::insert_row( self::KIND_STATE, $workflow_id, $run_id, $label, $value, $step_id );
	}

	/**
	 * Newest-wins lookup by label across save/state rows.
	 *
	 * @param string $label       User label.
	 * @param int    $workflow_id Workflow scope.
	 * @param string $from_run_id Optional run scope for save-output history.
	 * @return array<string, mixed>|null Row (with decoded `value`) or null when not found.
	 */
	public static function find( string $label, int $workflow_id = 0, string $from_run_id = '' ): ?array {
		global $wpdb;

		$label = self::truncate_label( $label );

		if ( '' !== $from_run_id ) {
			$sql = 'SELECT * FROM ' . self::table_name() . ' WHERE label = %s AND workflow_id = %d AND run_id = %s AND kind IN ( %s, %s ) ORDER BY id DESC LIMIT 1';

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifier built from $wpdb->prefix; values are prepared.
			$row = $wpdb->get_row( $wpdb->prepare( $sql, $label, $workflow_id, $from_run_id, self::KIND_SAVE, self::KIND_STATE ), ARRAY_A );
		} else {
			$sql = 'SELECT * FROM ' . self::table_name() . ' WHERE label = %s AND workflow_id = %d AND kind IN ( %s, %s ) ORDER BY id DESC LIMIT 1';

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifier built from $wpdb->prefix; values are prepared.
			$row = $wpdb->get_row( $wpdb->prepare( $sql, $label, $workflow_id, self::KIND_SAVE, self::KIND_STATE ), ARRAY_A );
		}

		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'id'          => (int) $row['id'],
			'kind'        => (string) $row['kind'],
			'workflow_id' => (int) $row['workflow_id'],
			'run_id'      => (string) $row['run_id'],
			'label'       => (string) $row['label'],
			'value'       => self::decode_value( (string) $row['value'] ),
			'step_id'     => (string) $row['step_id'],
			'created_at'  => (string) $row['created_at'],
		);
	}

	/**
	 * Delete one row by id.
	 *
	 * @param int $id Row id.
	 * @return bool True when a row was deleted.
	 */
	public static function delete_row( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Value storage CRUD; no cache API for this table.
		$deleted = $wpdb->delete( self::table_name(), array( 'id' => $id ), array( '%d' ) );

		return is_int( $deleted ) && $deleted > 0;
	}

	/**
	 * Delete every row written during a run.
	 *
	 * @param string $run_id Runner-generated execution ID.
	 * @return int Number of rows deleted.
	 */
	public static function delete_run( string $run_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Value storage CRUD; no cache API for this table.
		$deleted = $wpdb->delete( self::table_name(), array( 'run_id' => $run_id ), array( '%s' ) );

		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Insert a row directly (after append/upsert validation).
	 *
	 * @param string $kind        KIND_SAVE, KIND_STATE or KIND_LOG.
	 * @param int    $workflow_id Workflow post ID.
	 * @param string $run_id      Runner-generated execution ID.
	 * @param string $label       Label or log message headline.
	 * @param mixed  $value       Payload stored JSON-encoded.
	 * @param string $step_id     Editor node id.
	 * @return array{id: int|null, truncated: bool}
	 */
	private static function insert_row( string $kind, int $workflow_id, string $run_id, string $label, $value, string $step_id ): array {
		global $wpdb;

		$encoded = self::encode_value( $value );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Value storage CRUD; no cache API for this table.
		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'kind'        => $kind,
				'workflow_id' => $workflow_id,
				'run_id'      => $run_id,
				'label'       => self::truncate_label( $label ),
				'value'       => $encoded['json'],
				'step_id'     => $step_id,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return array(
			'id'        => ( false !== $inserted ) ? (int) $wpdb->insert_id : null,
			'truncated' => $encoded['truncated'],
		);
	}

	/**
	 * JSON-encode a value under the size cap.
	 *
	 * @param mixed $value Raw value.
	 * @return array{json: string, truncated: bool}
	 */
	private static function encode_value( $value ): array {
		$encoded = wp_json_encode( $value, JSON_INVALID_UTF8_SUBSTITUTE );

		if ( ! is_string( $encoded ) ) {
			$encoded = 'null';
		}

		$truncated = false;

		if ( strlen( $encoded ) > self::MAX_VALUE_BYTES ) {
			$encoded   = self::utf8_cut( $encoded, self::MAX_VALUE_BYTES );
			$truncated = true;
		}

		return array(
			'json'      => $encoded,
			'truncated' => $truncated,
		);
	}

	/**
	 * Decode a stored JSON value; falls back to the raw string for
	 * truncated/invalid JSON (never silently invents data).
	 *
	 * @param string $json Stored payload.
	 * @return mixed
	 */
	private static function decode_value( string $json ) {
		$decoded = json_decode( $json, true );

		if ( null === $decoded && 'null' !== strtolower( trim( $json ) ) ) {
			return $json;
		}

		return $decoded;
	}

	/**
	 * Byte-cut a UTF-8 string without breaking a trailing multibyte sequence.
	 *
	 * @param string $text      Input string.
	 * @param int    $max_bytes Maximum length in bytes.
	 * @return string
	 */
	private static function utf8_cut( string $text, int $max_bytes ): string {
		if ( strlen( $text ) <= $max_bytes ) {
			return $text;
		}

		$text = substr( $text, 0, $max_bytes );

		for ( $i = 0; $i < 4 && '' !== $text; $i++ ) {
			if ( false !== preg_match( '//u', $text ) ) {
				break;
			}
			$text = substr( $text, 0, -1 );
		}

		return $text;
	}

	/**
	 * Cap a label to the column width (first ~190 characters).
	 *
	 * Applied on write AND lookup so a stored long label is still findable by
	 * its original form; invalid UTF-8 falls back to a byte cut.
	 *
	 * @param string $label Raw label.
	 * @return string
	 */
	private static function truncate_label( string $label ): string {
		if ( strlen( $label ) <= self::MAX_LABEL_CHARS ) {
			return $label;
		}

		$cut = preg_replace( '/^(.{190}).+/su', '$1', $label );

		return is_string( $cut ) ? $cut : self::utf8_cut( $label, self::MAX_LABEL_CHARS );
	}
}
