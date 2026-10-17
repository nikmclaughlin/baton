<?php
/**
 * PHPStan stubs for the WP-CLI surface Baton uses.
 *
 * PHPUnit runs without WP-CLI (tests/php/wp-cli-stub.php provides the test
 * shim) and production only ever calls these under the WP-CLI binary, so
 * the static analyzer needs explicit stubs. Signatures mirror WP-CLI 2.x —
 * keep in sync with the calls in includes/class-cli.php.
 *
 * Stub-only file: never executed by WordPress, PHP, or the tests.
 */

declare( strict_types=1 );

namespace {

	class WP_CLI {

		/**
		 * Register a command group/subcommand tree.
		 *
		 * @param string                $name     Command name.
		 * @param string|callable|false $callable Class name, function, or instance.
		 * @param array<string, mixed>  $args     Registration args (shortdesc, when, …).
		 */
		public static function add_command( string $name, $callable = false, array $args = array() ): void {
		}

		/**
		 * Display a plain informational line on STDOUT.
		 *
		 * @param string $message Message.
		 */
		public static function line( $message = '' ): void {
		}

		/**
		 * Display an informational message on STDOUT.
		 *
		 * @param string $message Message.
		 */
		public static function log( $message = '' ): void {
		}

		/**
		 * Display a success message prefixed with "Success:".
		 *
		 * @param string $message Message.
		 */
		public static function success( $message = '' ): void {
		}

		/**
		 * Display a warning on STDERR (non-fatal).
		 *
		 * @param string $message Message.
		 */
		public static function warning( $message = '' ): void {
		}

		/**
		 * Display an error on STDERR and (by default) exit the process with
		 * code 1 (the caller never regains control).
		 */
		public static function error( $message = '', bool $exit = true ): void {
		}
	}
}

namespace WP_CLI {

	/**
	 * Thrown by WP_CLI::error() (and friends) when $exit is true.
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
	 * Render items in a requested format (table/csv/json/yaml/ids/count).
	 *
	 * @param string            $format Output format.
	 * @param array<int, mixed> $items  Rows.
	 * @param array<int, mixed> $fields Row fields.
	 */
	function format_items( string $format, array $items, array $fields ): void {
	}
}