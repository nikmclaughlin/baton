<?php
/**
 * Minimal WordPress MCP Adapter shim for Baton's PHPUnit suite.
 *
 * The adapter (WordPress/mcp-adapter) cannot be installed into wp-env here;
 * Baton's MCP integration is runtime-gated on the adapter and exercises
 * only its documented PHP surface: the mcp_adapter_default_server_config
 * filter, its McpTool::fromArray() factory, and (in the adapter) per-tool
 * check_permission()/execute() invocation. This shim defines just those
 * classes — tool configs are recorded for assertions and tools are
 * invocable directly — so tests can drive Baton's filter callback and tool
 * handlers exactly as the adapter would.
 *
 * IMPORTANT: this file lives OUTSIDE tests/php/ on purpose. PHPUnit's
 * suite directory (suffix ".php") executes every file under tests/php
 * during test collection, and PHP pre-binds unconditional namespaced class
 * declarations past an early `return` (the same compile-time binding the
 * wp-cli stub note in AGENTS.md describes) — living anywhere under
 * tests/php would flip Baton's class-existence gate before any test runs.
 * Only tests/php/Test_Mcp_Integration.php loads it, via require_once, when
 * a test asks for the shim.
 *
 * The declarations below are CONDITIONAL (runtime-bound, not
 * compile-pre-bound) so the shim is genuinely load-on-demand: re-executing
 * the file is safe (already-declared classes are skipped) and a real
 * adapter present in the process (WP_MCP_VERSION, the constant the
 * canonical adapter plugin defines — mirroring tests/php/
 * wp-cli-stub.php's constant guard) prevents declaration entirely.
 *
 * @package Baton
 */

declare( strict_types=1 );

namespace WP\MCP\Core {

	/**
	 * MCP Adapter core registry (WordPress/mcp-adapter) — the class whose
	 * existence is Baton's adapter-active gate; nothing here is executed.
	 */
	if ( ! defined( 'WP_MCP_VERSION' ) && ! class_exists( 'WP\MCP\Core\McpAdapter', false ) ) {
		final class McpAdapter {
		}
	}
}

namespace WP\MCP\Domain\Tools {

	/**
	 * Minimal MCP tool component (capture-only, invocable for assertions).
	 */
	if ( ! defined( 'WP_MCP_VERSION' ) && ! class_exists( 'WP\MCP\Domain\Tools\McpTool', false ) ) {
		final class McpTool {

			/**
			 * Config arrays passed to fromArray(), in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public static array $from_array_calls = array();

			/**
			 * Stored tool data (name, title, description, schemas, …).
			 *
			 * @var array<string, mixed>
			 */
			private array $tool_data;

			/**
			 * Configured execution handler.
			 *
			 * @var callable
			 */
			private $handler;

			/**
			 * Configured permission callback.
			 *
			 * @var callable|null
			 */
			private $permission_callback;

			/**
			 * Wipe recorded state (between tests).
			 */
			public static function reset(): void {
				self::$from_array_calls = array();
			}

			/**
			 * Build a tool from an array config, mirroring the real factory's
			 * required-field validation.
			 *
			 * @param array<string, mixed> $config Tool configuration.
			 * @return self|\WP_Error
			 */
			public static function fromArray( array $config ) {
				if ( empty( $config['name'] ) ) {
					return new \WP_Error( 'mcp_tool_missing_name', 'Tool configuration must include a "name" field.' );
				}

				if ( ! isset( $config['handler'] ) || ! is_callable( $config['handler'] ) ) {
					return new \WP_Error( 'mcp_tool_missing_handler', 'Tool configuration must include a callable "handler" field.' );
				}

				$instance = new self(
					array(
						'tool_data'  => $config,
						'handler'    => $config['handler'],
						'permission' => isset( $config['permission'] ) && is_callable( $config['permission'] )
							? $config['permission']
							: null,
					)
				);

				self::$from_array_calls[] = $config;

				return $instance;
			}

			/**
			 * Build from pre-validated parts (the private construction path).
			 *
			 * @param array<string, mixed> $parts tool_data/handler/permission parts.
			 */
			private function __construct( array $parts ) {
				$this->tool_data           = (array) $parts['tool_data'];
				$this->handler             = $parts['handler'];
				$this->permission_callback = $parts['permission'];
			}

			/**
			 * The MCP tool name.
			 *
			 * @return string
			 */
			public function get_name(): string {
				return (string) ( $this->tool_data['name'] ?? '' );
			}

			/**
			 * Invoke the execution handler, mirroring the real component's
			 * WP_Error short-circuit and non-array result wrap.
			 *
			 * @param mixed $args    Tool arguments.
			 * @param mixed $context Tool call context (unused by Baton).
			 * @return mixed
			 */
			public function execute( $args, $context = null ) {
				$handler = $this->handler;
				$result  = call_user_func( $handler, $args, $context );

				if ( $result instanceof \WP_Error ) {
					return $result;
				}

				return is_array( $result ) ? $result : array( 'result' => $result );
			}

			/**
			 * Run the permission callback, mirroring the real component's
			 * boolean coercion.
			 *
			 * @param mixed $args Tool arguments.
			 * @return bool|\WP_Error
			 */
			public function check_permission( $args ) {
				if ( null === $this->permission_callback ) {
					return new \WP_Error( 'mcp_permission_denied', 'Access denied.' );
				}

				$callback = $this->permission_callback;
				$result   = call_user_func( $callback, $args );

				return $result instanceof \WP_Error ? $result : (bool) $result;
			}
		}
	}
}