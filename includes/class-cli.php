<?php
/**
 * WP-CLI commands for Baton (external execution, CLI surface).
 *
 * @package Baton
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The `wp baton` command group: list / info / run for workflows that opted
 * in to the CLI external-execution surface (the `_baton_external_surfaces`
 * map contains 'cli').
 *
 * Gating mirrors the REST channel's invisibility semantics: a workflow
 * without the 'cli' surface is not listed and not runnable — "run" stops
 * with a generic not-found error before the runner executes anything.
 * WP-CLI already runs in admin context, so no permission surface is added;
 * like the REST channel, every CLI-triggered run appends an "External run"
 * log row (value { trigger: 'cli', input, success, error? }) to the
 * workflow's saved-values viewer.
 */
class Baton_CLI {

	/**
	 * Register the command group with WP-CLI.
	 *
	 * Hooked at cli_init; no-op outside WP-CLI, where the WP_CLI constant
	 * (and class) does not exist.
	 */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI' ) ) {
			return;
		}

		WP_CLI::add_command(
			'baton',
			self::class,
			array(
				'shortdesc' => __( 'Baton workflow CLI surface (list, info, run).', 'baton' ),
			)
		);
	}

	/**
	 * List workflows exposed for CLI execution.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render one row per workflow as table, json, or csv. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     # See which workflows can run under WP-CLI
	 *     wp baton list
	 *
	 *     # Machine-readable list for scripts
	 *     wp baton list --format=json
	 *
	 * @param array<int, string>   $args       Positional args (unused).
	 * @param array<string, mixed> $assoc_args Associative args.
	 * @return void
	 */
	public function list( array $args, array $assoc_args ): void {
		$format = $this->format_from_args( $assoc_args, array( 'table', 'json', 'csv' ), 'table' );

		$items = array();
		foreach ( $this->cli_exposed_workflow_ids() as $post_id ) {
			$items[] = $this->list_row( $post_id );
		}

		if ( empty( $items ) && 'json' !== $format ) {
			WP_CLI::success( __( 'No workflows are exposed for CLI execution.', 'baton' ) );
			return;
		}

		WP_CLI\Utils\format_items(
			$format,
			$items,
			array( 'id', 'title', 'surfaces', 'steps' )
		);
	}

	/**
	 * Show details for one workflow (any Baton workflow; read-only).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Workflow post ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp baton info 12
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Associative args (unused).
	 * @return void
	 */
	public function info( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- WP-CLI dispatch always passes both arrays.
		$data = $this->info_data( $this->positional_id( $args ) );

		if ( null === $data ) {
			WP_CLI::error( $this->not_found_error( $this->positional_id( $args ) ) );
			return;
		}

		WP_CLI::line(
			sprintf(
				/* translators: %s: workflow title */
				__( 'Title: %s', 'baton' ),
				$data['title']
			)
		);
		WP_CLI::line(
			sprintf(
				/* translators: %s: post status, e.g. publish */
				__( 'Status: %s', 'baton' ),
				$data['status']
			)
		);
		WP_CLI::line(
			'' !== $data['surfaces']
				? sprintf(
					/* translators: %s: comma-separated surface slugs */
					__( 'Surfaces: %s', 'baton' ),
					$data['surfaces']
				)
				: __( 'Surfaces: (none)', 'baton' )
		);

		if ( empty( $data['steps'] ) ) {
			WP_CLI::line( __( 'Steps: (none)', 'baton' ) );
		} else {
			WP_CLI::line(
				sprintf(
				/* translators: %d: number of workflow steps */
					__( 'Steps: %d', 'baton' ),
					count( $data['steps'] )
				)
			);
			foreach ( $data['steps'] as $step ) {
				WP_CLI::line(
					sprintf(
						/* translators: 1: one-based step number, 2: ability name */
						__( '  %1$d. %2$s', 'baton' ),
						(int) $step['index'] + 1,
						$step['ability']
					)
				);
			}
		}

		$this->render_newest_run( $data['newest_run'] );
	}

	/**
	 * Run a workflow exposed for CLI execution.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Workflow post ID.
	 *
	 * [--input=<json>]
	 * : Run input as a JSON object; replaces the workflow's saved initial input. Pass either --input or --input-file, not both.
	 *
	 * [--input-file=<path>]
	 * : Read the run input JSON object from a file.
	 *
	 * [--format=<format>]
	 * : Render the run report as table (human readable) or json (the raw runner report). Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     # Run and inspect the step-by-step report
	 *     wp baton run 12
	 *
	 *     # Run with a JSON input object
	 *     wp baton run 12 --input='{"cursor":"a1b2"}'
	 *
	 *     # Script the raw runner report
	 *     wp baton run 12 --input-file=/tmp/input.json --format=json
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Associative args.
	 * @return void
	 */
	public function run( array $args, array $assoc_args ): void {
		$post_id = $this->positional_id( $args );

		// Validate the request shape before running (mirrors the admin REST
		// endpoint's bad-request-before-run ordering).
		$format = $this->format_from_args( $assoc_args, array( 'table', 'json' ), 'table' );

		// Strict CLI gating: exposed-or-nothing (the workflow must exist, be
		// published, and carry the 'cli' surface).
		if ( ! $this->is_cli_runnable( $post_id ) ) {
			WP_CLI::error( $this->not_runnable_error() );
			return;
		}

		$resolved = $this->resolve_input( $assoc_args );
		if ( null === $resolved ) {
			return;
		}

		$definition = Baton_Workflow_CPT::get_definition( $post_id );
		if ( $resolved['provided'] ) {
			$definition['initial_input'] = Baton_Input_Sanitizer::sanitize_input_array( $resolved['input'] );
		}

		$report = Baton_Workflow_Runner::run( $definition, $post_id );

		// Channel-triggered run: append the same "External run" log row the
		// REST channel writes. Write failures are silent.
		Baton_External_Execution::log_cli_run( $post_id, $resolved['input'], $report );

		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $report ) );
		} else {
			$this->render_run_report( $report );
		}

		if ( empty( $report['success'] ) ) {
			WP_CLI::error(
				sprintf(
				/* translators: %s: workflow error from the runner report */
					__( 'Run failed: %s', 'baton' ),
					(string) ( $report['error'] ?? __( 'Unknown error.', 'baton' ) )
				)
			);
		}
	}

	/**
	 * Whether a workflow may run through the CLI surface: it must exist, be
	 * published, and have the 'cli' surface enabled. Workflows that fail
	 * any check get the same generic error (nothing is revealed about a
	 * missing, unpublished, or unexposed target).
	 *
	 * @param int $post_id Requested post ID.
	 * @return bool
	 */
	private function is_cli_runnable( int $post_id ): bool {
		$post = get_post( $post_id );

		return $post
			&& Baton_Workflow_CPT::POST_TYPE === $post->post_type
			&& 'publish' === $post->post_status
			&& Baton_Workflow_CPT::has_surface( $post_id, Baton_Workflow_CPT::SURFACE_CLI );
	}

	/**
	 * Post IDs (ascending) of published workflows exposed for CLI.
	 *
	 * @return array<int, int>
	 */
	private function cli_exposed_workflow_ids(): array {
		$ids = get_posts(
			array(
				'post_type'      => Baton_Workflow_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		$exposed = array();
		foreach ( array_map( 'intval', $ids ) as $post_id ) {
			if ( Baton_Workflow_CPT::has_surface( $post_id, Baton_Workflow_CPT::SURFACE_CLI ) ) {
				$exposed[] = $post_id;
			}
		}

		return $exposed;
	}

	/**
	 * One `wp baton list` row for a CLI-exposed workflow.
	 *
	 * @param int $post_id Workflow post ID.
	 * @return array{id: int, title: string, surfaces: string, steps: int}
	 */
	private function list_row( int $post_id ): array {
		$post       = get_post( $post_id );
		$definition = Baton_Workflow_CPT::get_definition( $post_id );

		return array(
			'id'       => $post_id,
			'title'    => $post ? (string) $post->post_title : '',
			'surfaces' => implode( ', ', Baton_Workflow_CPT::get_surfaces( $post_id ) ),
			'steps'    => count( is_array( $definition['steps'] ?? null ) ? $definition['steps'] : array() ),
		);
	}

	/**
	 * Inspection data for `wp baton info`.
	 *
	 * Any Baton workflow (read-only; exposure applies to runs, not to admin
	 * inspection) — null when the post is not a workflow.
	 *
	 * @param int $post_id Workflow post ID.
	 * @return array{title: string, status: string, surfaces: string, steps: array<int, array{index: int, ability: string}>, newest_run: array<string, mixed>|null}|null
	 */
	private function info_data( int $post_id ): ?array {
		$post = get_post( $post_id );

		if ( ! $post || Baton_Workflow_CPT::POST_TYPE !== $post->post_type ) {
			return null;
		}

		$definition = Baton_Workflow_CPT::get_definition( $post_id );
		$steps      = array();

		foreach ( ( $definition['steps'] ?? array() ) as $index => $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$steps[] = array(
				'index'   => (int) $index,
				'ability' => isset( $step['ability'] ) ? (string) $step['ability'] : '',
			);
		}

		$surfaces = Baton_Workflow_CPT::get_surfaces( $post_id );

		return array(
			'title'      => (string) $post->post_title,
			'status'     => (string) $post->post_status,
			'surfaces'   => implode( ', ', $surfaces ),
			'steps'      => $steps,
			'newest_run' => $this->newest_run_summary( $post_id ),
		);
	}

	/**
	 * Newest saved run for a workflow (the runner tags every row a run
	 * writes with its UUID; Baton_Saved_Values rows read back newest-first).
	 *
	 * @param int $post_id Workflow post ID.
	 * @return array<string, mixed>|null Run summary keys: run_id, kind, label, success, trigger, error, created_at; null when nothing was saved.
	 */
	private function newest_run_summary( int $post_id ): ?array {
		foreach ( Baton_Saved_Values::rows_for_workflow( $post_id ) as $row ) {
			if ( '' === $row['run_id'] ) {
				continue;
			}

			$summary = array(
				'run_id'     => $row['run_id'],
				'kind'       => $row['kind'],
				'label'      => $row['label'],
				'success'    => null,
				'trigger'    => null,
				'error'      => null,
				'created_at' => (string) $row['created_at'],
			);

			if ( is_array( $row['value'] ) ) {
				if ( isset( $row['value']['trigger'] ) && is_string( $row['value']['trigger'] ) ) {
					$summary['trigger'] = $row['value']['trigger'];
				}
				if ( isset( $row['value']['success'] ) && is_bool( $row['value']['success'] ) ) {
					$summary['success'] = $row['value']['success'];
				}
				if ( isset( $row['value']['error'] ) ) {
					$summary['error'] = (string) $row['value']['error'];
				}
			}

			return $summary;
		}

		return null;
	}

	/**
	 * Resolve the run input from --input / --input-file.
	 *
	 * A present flag replaces the stored initial input (the empty object
	 * `{}` clears it, matching the admin run endpoint); absent flags keep
	 * the saved input. JSON must decode to an object — errors exit.
	 *
	 * @param array<string, mixed> $assoc_args WP-CLI associative args.
	 * @return array{input: array<string, mixed>, provided: bool}|null Null after WP_CLI::error() exits (never reached at runtime).
	 */
	private function resolve_input( array $assoc_args ): ?array {
		$raw_input = isset( $assoc_args['input'] ) && is_string( $assoc_args['input'] )
			? $assoc_args['input']
			: '';
		$raw_file  = isset( $assoc_args['input-file'] ) && is_string( $assoc_args['input-file'] )
			? $assoc_args['input-file']
			: '';

		if ( '' !== trim( $raw_input ) && '' !== trim( $raw_file ) ) {
			WP_CLI::error( __( 'Pass only one of --input or --input-file.', 'baton' ) );
			return null;
		}

		if ( '' !== trim( $raw_file ) ) {
			$path = trim( $raw_file );
			if ( ! is_file( $path ) || ! is_readable( $path ) ) {
				WP_CLI::error(
					sprintf(
					/* translators: %s: file path */
						__( 'Input file "%s" is not readable.', 'baton' ),
						$path
					)
				);
				return null;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI passes an arbitrary local file path; wp_remote_get() targets URLs, not local files.
			$raw_input = (string) file_get_contents( $path );
		}

		if ( '' === trim( $raw_input ) ) {
			return array(
				'input'    => array(),
				'provided' => false,
			);
		}

		$decoded = json_decode( $raw_input, true );

		if ( ! is_array( $decoded ) ) {
			WP_CLI::error( __( 'Invalid input JSON: expected an object.', 'baton' ) );
			return null;
		}

		if ( ! empty( $decoded ) && wp_is_numeric_array( $decoded ) ) {
			WP_CLI::error( __( 'Invalid input JSON: expected an object, not a JSON array.', 'baton' ) );
			return null;
		}

		return array(
			'input'    => $decoded,
			'provided' => true,
		);
	}

	/**
	 * Human-readable rendering of the runner report (default format).
	 *
	 * @param array<string, mixed> $report Runner report.
	 */
	private function render_run_report( array $report ): void {
		WP_CLI::line(
			sprintf(
			/* translators: %s: run ID */
				__( 'Run ID: %s', 'baton' ),
				(string) ( $report['run_id'] ?? '' )
			)
		);

		$steps = is_array( $report['steps'] ?? null ) ? $report['steps'] : array();

		if ( empty( $steps ) ) {
			WP_CLI::line( __( 'No steps run.', 'baton' ) );
		}

		$position = 1;
		foreach ( $steps as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$success = ! empty( $step['success'] );
			WP_CLI::line(
				sprintf(
				/* translators: 1: one-based step position, 2: ok/failed state, 3: ability name */
					__( 'Step %1$d [%2$s] %3$s', 'baton' ),
					$position,
					$success ? 'ok' : 'failed',
					(string) ( $step['ability'] ?? '' )
				)
			);

			foreach ( (array) ( $step['warnings'] ?? array() ) as $warning ) {
				WP_CLI::warning( (string) $warning );
			}

			if ( ! $success && isset( $step['error'] ) ) {
				WP_CLI::line(
					sprintf(
					/* translators: %s: step error message */
						__( 'Error: %s', 'baton' ),
						(string) $step['error']
					)
				);
			}

			if ( $success && isset( $step['output'] ) ) {
				WP_CLI::line(
					sprintf(
					/* translators: %s: step output, JSON-encoded */
						__( 'Output: %s', 'baton' ),
						(string) wp_json_encode( $step['output'] )
					)
				);
			}

			++$position;
		}
	}

	/**
	 * Render the newest saved run summary (or its absence).
	 *
	 * @param array<string, mixed>|null $run Run summary from newest_run_summary().
	 */
	private function render_newest_run( ?array $run ): void {
		if ( null === $run ) {
			WP_CLI::line( __( 'Newest run: (none saved)', 'baton' ) );
			return;
		}

		$line = sprintf(
			/* translators: 1: run ID, 2: row kind, 3: label */
			__( 'Newest run: %1$s (%2$s "%3$s")', 'baton' ),
			(string) $run['run_id'],
			(string) $run['kind'],
			(string) $run['label']
		);

		if ( null !== $run['trigger'] ) {
			$line .= sprintf(
				/* translators: %s: trigger channel */
				__( ', triggered via %s', 'baton' ),
				(string) $run['trigger']
			);
		}

		if ( null !== $run['success'] ) {
			$line .= $run['success']
				? __( ', success', 'baton' )
				: __( ', failed', 'baton' );
		}

		if ( null !== $run['error'] && '' !== $run['error'] ) {
			$line .= sprintf(
				/* translators: %s: run error message */
				__( ' — %s', 'baton' ),
				(string) $run['error']
			);
		}

		WP_CLI::line( $line );
	}

	/**
	 * Validated --format value from the CLI args (lowercase).
	 *
	 * @param array<string, mixed> $assoc_args WP-CLI associative args.
	 * @param array<int, string>   $allowed    Allowed format slugs.
	 * @param string               $fallback   Default format.
	 * @return string Valid format slug.
	 */
	private function format_from_args( array $assoc_args, array $allowed, string $fallback ): string {
		$format = isset( $assoc_args['format'] ) && is_string( $assoc_args['format'] )
			? strtolower( trim( $assoc_args['format'] ) )
			: $fallback;

		if ( ! in_array( $format, $allowed, true ) ) {
			WP_CLI::error(
				sprintf(
				/* translators: 1: provided format, 2: comma-separated valid formats */
					__( 'Invalid format: %1$s (choose %2$s).', 'baton' ),
					$format,
					implode( ', ', $allowed )
				)
			);
		}

		return $format;
	}

	/**
	 * First positional argument as a workflow post ID (0 when absent).
	 *
	 * @param array<int, string> $args Positional args.
	 * @return int
	 */
	private function positional_id( array $args ): int {
		return absint( $args[0] ?? 0 );
	}

	/**
	 * Generic not-found/not-exposed error (mirrors the REST channel's 404
	 * invisibility for `wp baton run`).
	 *
	 * @return string
	 */
	private function not_runnable_error(): string {
		return __(
			'Workflow not found or not exposed for CLI execution. Run "wp baton list" to see exposed workflows.',
			'baton'
		);
	}

	/**
	 * Not-found error for `wp baton info` (admin inspection; includes the
	 * requested ID).
	 *
	 * @param int $post_id Requested post ID.
	 * @return string
	 */
	private function not_found_error( int $post_id ): string {
		return sprintf(
			/* translators: %d: workflow post ID */
			__( 'No workflow found with ID %d.', 'baton' ),
			$post_id
		);
	}
}
