<?php
/**
 * PHPStan stubs for the WordPress MCP Adapter surface Baton uses.
 *
 * Baton integrates with the canonical MCP Adapter plugin
 * (WordPress/mcp-adapter) at runtime but never bundles it: the adapter is
 * installed on the site and Baton's integration is runtime-gated on it
 * (see includes/class-mcp-integration.php). The WordPress stubs package
 * knows nothing about the adapter's classes, so the static analyzer needs
 * explicit stubs for the tiny surface Baton touches — McpTool::fromArray()
 * and the registry class the runtime gate checks. Signatures mirror
 * MCP Adapter 0.7.x — keep in sync with Baton's calls.
 *
 * Stub-only file: never executed by WordPress, PHP, or the tests (tests
 * provide their own shim in tests/php/mcp-adapter-stub.php).
 */

declare( strict_types=1 );

namespace WP\MCP\Core {

	/**
	 * MCP Adapter core registry (WordPress/mcp-adapter).
	 *
	 * Baton only probes this class's existence as the adapter-active gate;
	 * nothing is executed here.
	 */
	final class McpAdapter {
	}
}

namespace WP\MCP\Domain\Tools {

	/**
	 * MCP tool component (WordPress/mcp-adapter).
	 */
	final class McpTool {

		/**
		 * Build a tool definition from an array configuration.
		 *
		 * @param array<string, mixed> $config {
		 *     Tool configuration.
		 *
		 *     @type string   $name        MCP-compliant tool name.
		 *     @type string   $title       Human-readable title.
		 *     @type string   $description Tool description.
		 *     @type array    $inputSchema JSON Schema object for the input.
		 *     @type callable $handler     Execution handler (args, context).
		 *     @type callable $permission  Permission callback (args).
		 *     @type array    $annotations MCP annotation hints.
		 * }
		 * @return \WP\MCP\Domain\Tools\McpTool|\WP_Error Instance, or an error for invalid configs.
		 */
		public static function fromArray( array $config ) {
			return new \WP\MCP\Domain\Tools\McpTool();
		}

		/**
		 * The MCP tool name.
		 *
		 * @return string
		 */
		public function get_name(): string {
			return '';
		}
	}
}