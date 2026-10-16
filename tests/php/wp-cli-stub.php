<?php
/**
 * Minimal WP-CLI shim for Baton's PHPUnit suite.
 *
 * The `wp baton` command methods (includes/class-cli.php) run only under
 * WP-CLI, which is absent from the phpunit environment. This shim defines
 * just enough of WP-CLI's surface — I/O no-ops captured in static arrays,
 * an ExitException for WP_CLI::error(), and a simplified format_items —
 * so the command methods can be exercised and asserted directly. Loaded
 * from tests/bootstrap.php.
 *
 * The bail-out guard checks the WP_CLI CONSTANT (real WP-CLI defines it
 * true before any plugin code runs): the shim's own class declarations are
 * bound before the file's statements execute, so class_exists() here would
 * always appear true and swallow the shim.
 *
 * @package Baton
 */

declare( strict_types=1 );

namespace {

	// Real WP-CLI always defines the constant; never redeclare over it.
	if ( defined( 'WP_CLI' ) ) {
		return;
	}

	define( 'WP_CLI', true );

/**
 * Minimal WP-CLI facade (capture-only I/O for assertions).
 */
class WP_CLI {

	/**
	 * Captured output, in order: arrays with `type` and `message` keys.
	 *
	 * @var array<int, array{type: string, message: string}>
	 */
	public static $captured = array();

	/**
	 * Registered command groups, in order: name/callable/registration args.
	 *
	 * @var array<int, array{0: string, 1: mixed, 2: array<string, mixed>}>
	 */
	public static $commands = array();

	/**
	 * Wipe captured state (between tests).
	 */
	public static function reset(): void {
		self::$captured = array();
		self::$commands = array();
	}

	/**
	 * All captured messages of one type (or every type), in order.
	 *
	 * @param string|null $type line|log|success|warning|error.
	 * @return array<int, string>
	 */
	public static function messages( ?string $type = null ): array {
		$messages = array();
		foreach ( self::$captured as $entry ) {
			if ( null === $type || $entry['type'] === $type ) {
				$messages[] = $entry['message'];
			}
		}

		return $messages;
	}

	/**
	 * Record a message without output.
	 *
	 * @param string $type    Output channel type.
	 * @param string $message Message.
	 */
	public static function capture( string $type, string $message ): void {
		self::$captured[] = array(
			'type'    => $type,
			'message' => $message,
		);
	}

	/**
	 * Register a command group.
	 *
	 * @param string                $name     Command name.
	 * @param string|callable|false $callable Class name or callable.
	 * @param array<string, mixed>  $args     Registration args.
	 */
	public static function add_command( $name, $callable = false, $args = array() ): void {
		self::$commands[] = array(
			(string) $name,
			$callable,
			(array) $args,
		);
	}

	/**
	 * @param string $message Message.
	 */
	public static function line( $message = '' ): void {
		self::capture( 'line', (string) $message );
	}

	/**
	 * @param string $message Message.
	 */
	public static function log( $message = '' ): void {
		self::capture( 'log', (string) $message );
	}

	/**
	 * @param string $message Message.
	 */
	public static function success( $message = '' ): void {
		self::capture( 'success', (string) $message );
	}

	/**
	 * @param string $message Message.
	 */
	public static function warning( $message = '' ): void {
		self::capture( 'warning', (string) $message );
	}

	/**
	 * Real semantics: the message prints first, and a request to exit
	 * throws the process-exit exception (exit code 1, no message payload).
	 *
	 * @param string|\WP_Error $message Message.
	 * @param bool             $exit    Whether to exit.
	 */
	public static function error( $message, $exit = true ): void {
		if ( is_object( $message ) && method_exists( $message, 'get_error_message' ) ) {
			self::capture( 'error', implode( ' ', (array) $message->get_error_messages() ) );
		} else {
			self::capture( 'error', (string) $message );
		}

		if ( $exit ) {
			throw new \WP_CLI\ExitException( null, 1 );
		}
	}
}

} // End global namespace block.

namespace WP_CLI {

	/**
	 * WP-CLI's process-exit exception: thrown by WP_CLI::error() when
	 * $exit is true; the exit code rides getExitCode().
	 */
	final class ExitException extends \RuntimeException {

		/**
		 * Requested process exit code.
		 *
		 * @var int
		 */
		private $exit_code;

		/**
		 * @param string|null $message   Optional message payload.
		 * @param int         $exit_code Process exit code.
		 */
		public function __construct( $message = null, $exit_code = 1 ) {
			parent::__construct( null === $message ? '' : (string) $message, (int) $exit_code );
			$this->exit_code = (int) $exit_code;
		}

		/**
		 * @return int
		 */
		public function getExitCode(): int {
			return $this->exit_code;
		}
	}
}

namespace WP_CLI\Utils {

	/**
	 * Simplified format_items: json renders one encoded line; table/csv
	 * render a header line plus pipe-joined field rows (deterministic for
	 * string assertions).
	 *
	 * @param string            $format Output format.
	 * @param array<int, mixed> $items  Rows.
	 * @param array<int, mixed> $fields Row fields.
	 */
	function format_items( $format, $items, $fields ): void {
		$format = is_string( $format ) ? $format : 'table';
		$items  = (array) $items;
		$fields = array_values( (array) $fields );

		if ( 'json' === $format ) {
			\WP_CLI::capture( 'line', (string) wp_json_encode( $items ) );
			return;
		}

		\WP_CLI::capture( 'line', implode( ' | ', $fields ) );

		foreach ( $items as $item ) {
			$row = array();
			foreach ( $fields as $field ) {
				$value = is_array( $item ) ? ( $item[ $field ] ?? null ) : null;
				$row[] = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
			}
			\WP_CLI::capture( 'line', implode( ' | ', $row ) );
		}
	}
}