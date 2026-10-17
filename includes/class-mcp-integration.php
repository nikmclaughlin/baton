<?php
/**
 * MCP external surface via the WordPress MCP Adapter.
 *
 * @package Baton
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes published workflows as MCP tools through the canonical WordPress
 * MCP Adapter plugin (WordPress/mcp-adapter), gated on the adapter's
 * per-workflow "mcp" surface slug in _baton_external_surfaces.
 *
 * Scope discipline (B4): the adapter owns MCP transport, JSON-RPC handling,
 * the initialize handshake, sessions, and tool invocation. Baton owns none
 * of that — Baton only nominates its workflow abilities as tools on the
 * adapter's *default server* using the adapter's documented extension
 * points: the `mcp_adapter_default_server_config` filter (tool nomination on
 * the default server) and the adapter's own `McpTool::fromArray()` factory
 * (tool construction). No baton-owned MCP endpoint exists and none should
 * ever be added here.
 *
 * Integration mechanics:
 * - Runtime gate — `class_exists( 'WP\MCP\Core\McpAdapter' )`, evaluated
 *   after all plugins have loaded (Baton's register() runs at
 *   plugins_loaded, by which time the adapter plugin — which loads its
 *   classes at plugin-file execution time — is fully present or absent).
 *   The `baton_mcp_adapter_active` filter lets sites force the integration
 *   off. When the adapter is absent, register() is a silent no-op: no
 *   hooks, no notices.
 * - Tool naming — MCP restricts tool names to
 *   ^[a-zA-Z0-9_-]{1,64}$ (no dots/slashes, 64 max). Ability names
 *   (baton/workflow-{id}) violate both, so each registered tool name is
 *   composed as `baton_run_workflow_{post_id}` (the ability name is
 *   cross-referenced in the tool description instead).
 * - Tool registration is request-scoped: the default server and its tools
 *   are (re)built by the adapter on every request that initializes it, so
 *   un-publishing a workflow or unchecking the "mcp" surface removes the
 *   tool for all subsequent MCP requests without Baton keeping any state.
 *   The run handler re-checks the exposure gate defensively even though
 *   registration already enforced it for the current request.
 * - Run tagging — the tool handler is Baton's own code (registered with
 *   and invoked by the adapter), so it logs every invocation through
 *   Baton_External_Execution::log_mcp_run() (trigger 'mcp', label
 *   "MCP run"), mirroring the explicit log path the WP-CLI channel uses.
 */
final class Baton_Mcp_Integration {

	/**
	 * MCP-compliant tool-name prefix; the workflow post ID follows.
	 */
	public const TOOL_PREFIX = 'baton_run_workflow_';

	/**
	 * MCP tool-name contract Baton composes tool names against.
	 */
	public const TOOL_NAME_PATTERN = '#^[a-zA-Z0-9_-]{1,64}$#';

	/**
	 * The adapter's default-server ID Baton nominates tools onto.
	 */
	public const ADAPTER_SERVER_ID = 'mcp-adapter-default-server';

	/**
	 * Register hooks.
	 *
	 * Silent no-op while the WordPress MCP Adapter plugin is inactive —
	 * adapter-specific integration is intentional here (unlike Baton's
	 * abilities-first convention, where features gate on the Abilities API).
	 */
	public static function register(): void {
		if ( ! self::is_adapter_active() ) {
			return;
		}

		// Fires from DefaultServerFactory::create() during the adapter's
		// mcp_adapter_init action (every request that initializes the
		// adapter), before the default server is constructed.
		add_filter( 'mcp_adapter_default_server_config', array( self::class, 'add_workflow_tools' ) );
	}

	/**
	 * Whether the WordPress MCP Adapter integration is active.
	 *
	 * Auto-detected from the adapter's canonical core class; the
	 * `baton_mcp_adapter_active` filter can force the integration off (or,
	 * for test harnesses, on).
	 *
	 * @return bool
	 */
	public static function is_adapter_active(): bool {
		return (bool) apply_filters( 'baton_mcp_adapter_active', class_exists( 'WP\MCP\Core\McpAdapter' ) );
	}

	/**
	 * Add one MCP tool per exposed workflow to the default server config.
	 *
	 * Adapter extension point (filter mcp_adapter_default_server_config).
	 * Receives the default-server configuration and appends
	 * McpTool instances — the adapter's component registry accepts ability
	 * names or prebuilt McpTool objects in its tools list. Only published
	 * workflows with the "mcp" surface slug become tools; everything else
	 * is left untouched, and construction failures (invalid MCP name etc.)
	 * skip the workflow silently — the same tolerance the adapter applies
	 * to misconfigured entries.
	 *
	 * @param array<string, mixed> $config Default server configuration.
	 * @return array<string, mixed> Configuration with Baton workflow tools appended.
	 */
	public static function add_workflow_tools( array $config ): array {
		if ( ! class_exists( 'WP\MCP\Domain\Tools\McpTool' ) ) {
			return $config;
		}

		if ( ! isset( $config['tools'] ) || ! is_array( $config['tools'] ) ) {
			$config['tools'] = array();
		}

		$published = (array) get_posts(
			array(
				'post_type'      => Baton_Workflow_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $published as $post_id ) {
			$post_id = (int) $post_id;

			if ( ! Baton_Workflow_CPT::has_surface( $post_id, Baton_Workflow_CPT::SURFACE_MCP ) ) {
				continue;
			}

			$tool = self::build_workflow_tool( $post_id );
			if ( null !== $tool ) {
				$config['tools'][] = $tool;
			}
		}

		return $config;
	}

	/**
	 * MCP tool name for one workflow.
	 *
	 * `baton_run_workflow_` + a base-10 integer post ID: 19 ASCII characters
	 * plus at most 19 digits stay within the MCP tool-name contract
	 * (^[a-zA-Z0-9_-]{1,64}$) for every integer post ID, so the name needs
	 * no runtime sanitization. The ability name itself
	 * (baton/workflow-{id}) cannot be the tool name — it carries a slash
	 * and is cross-referenced in the tool description instead.
	 *
	 * @param int $post_id Workflow post ID.
	 * @return string
	 */
	public static function get_tool_name( int $post_id ): string {
		return self::TOOL_PREFIX . (string) $post_id;
	}

	/**
	 * Tool description for one workflow, cross-referencing the ability name.
	 *
	 * The MCP tool name is composed (not ability-derived), so the backing
	 * ability is named in the description for MCP clients working with the
	 * Abilities API or Baton's other external surfaces.
	 *
	 * @param WP_Post $post Workflow post.
	 * @return string
	 */
	private static function get_tool_description( WP_Post $post ): string {
		$summary = '' !== $post->post_excerpt
			? $post->post_excerpt
			: sprintf(
				/* translators: %s: workflow title */
				__( 'Run the Baton workflow "%s".', 'baton' ),
				$post->post_title
			);

		return sprintf(
			/* translators: 1: workflow summary, 2: WordPress ability name */
			__( '%1$s Backed by the WordPress ability %2$s; every tool call runs the workflow\'s steps in order and returns the last step\'s output.', 'baton' ),
			$summary,
			Baton_Workflow_Abilities::get_ability_name( (int) $post->ID )
		);
	}

	/**
	 * Build the MCP tool for one exposed workflow.
	 *
	 * Built with the adapter's own McpTool::fromArray() factory: the result
	 * is a standard adapter component, projected/listed/invoked by the
	 * adapter exactly like its ability-derived tools. Execution runs
	 * through Baton's handler: the workflow runner plus MCP-channel run
	 * logging — no REST dispatch, so nothing relies on Baton_External_
	 * Execution's dispatch observation (the CLI-channel pattern).
	 *
	 * @param int $post_id Workflow post ID.
	 * @return object|null McpTool instance, or null when construction failed.
	 */
	private static function build_workflow_tool( int $post_id ): ?object {
		$post = get_post( $post_id );

		if ( ! $post || Baton_Workflow_CPT::POST_TYPE !== $post->post_type ) {
			return null;
		}

		// Mirror the adapter's own ability-tool construction: same input
		// schema as the backing ability, with the empty-object properties
		// shape abilities tools also send.
		$tool = WP\MCP\Domain\Tools\McpTool::fromArray(
			array(
				'name'        => self::get_tool_name( $post_id ),
				'title'       => $post->post_title,
				'description' => self::get_tool_description( $post ),
				'inputSchema' => Baton_Workflow_Abilities::get_input_schema()
					+ array( 'properties' => new stdClass() ),
				'handler'     => static function ( $args, $context = null ) use ( $post_id ) {
					unset( $context );
					return Baton_Mcp_Integration::run_workflow_tool_call( $post_id, $args );
				},
				'permission'  => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'annotations' => array(
					'readOnlyHint'    => false,
					'destructiveHint' => false,
					'idempotentHint'  => false,
				),
			)
		);

		if ( is_wp_error( $tool ) || ! $tool instanceof WP\MCP\Domain\Tools\McpTool ) {
			return null;
		}

		return $tool;
	}

	/**
	 * Whether a workflow is currently runnable on the MCP surface.
	 *
	 * Registration already gates on this for the current request; the tool
	 * handler re-checks defensively so a handler reached through any other
	 * wiring never runs an unexposed workflow.
	 *
	 * @param int $post_id Workflow post ID.
	 * @return bool
	 */
	public static function is_workflow_exposed( int $post_id ): bool {
		$post = get_post( $post_id );

		if ( ! $post || Baton_Workflow_CPT::POST_TYPE !== $post->post_type ) {
			return false;
		}

		if ( 'publish' !== $post->post_status ) {
			return false;
		}

		return Baton_Workflow_CPT::has_surface( $post_id, Baton_Workflow_CPT::SURFACE_MCP );
	}

	/**
	 * Handle one MCP tool call: run the workflow and log the run.
	 *
	 * The explicit MCP invocation point — the adapter calls this handler
	 * for tools/call requests against the workflow tool. Mirrors the
	 * workflow ability's execute semantics (input override, WP_Error on
	 * failure, raw last-step output on success) so MCP callers get the
	 * same contract as the core REST run route; the only channel-specific
	 * addition is the saved-values log row (trigger 'mcp', label
	 * "MCP run").
	 *
	 * @param int   $post_id Workflow post ID.
	 * @param mixed $input   Tool call arguments (workflow input object).
	 * @return mixed|WP_Error Raw last-step output, or WP_Error on failure.
	 */
	public static function run_workflow_tool_call( int $post_id, $input = null ) {
		if ( ! self::is_workflow_exposed( $post_id ) ) {
			return new WP_Error(
				'baton_workflow_not_exposed',
				__( 'This Baton workflow is not exposed to external execution.', 'baton' )
			);
		}

		$result = Baton_Workflow_Abilities::run_workflow( $post_id, $input );
		$report = $result['report'];

		Baton_External_Execution::log_mcp_run( $post_id, $input, $report );

		if ( ! $report['success'] ) {
			return new WP_Error(
				'baton_workflow_failed',
				$report['error'] ?? __( 'Workflow failed.', 'baton' )
			);
		}

		return $result['output'];
	}
}
