<?php
/**
 * Tests for the AJAX workflow save endpoint (Baton_Admin::ajax_save_workflow).
 *
 * @package Baton
 */

declare( strict_types=1 );

/**
 * AJAX save-endpoint tests, covering the shared persist_workflow() helper.
 *
 * Follows the WP_Ajax_UnitTestCase contract: set $_POST, call
 * _handleAjax(), then read _last_response (the wp_die AJAX handler routes
 * wp_send_json_* output there and stops via WPAjaxDieContinueException).
 */
class Test_Ajax_Save_Workflow extends WP_Ajax_UnitTestCase {

	/**
	 * Runs before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'Abilities API is not available in this WordPress version.' );
		}

		$this->_setRole( 'administrator' );

		if ( ! function_exists( 'baton_tests_ensure_abilities_registered' ) ) {
			require_once dirname( __DIR__ ) . '/fixtures/test-abilities.php';
		}

		$registration_error = baton_tests_ensure_abilities_registered();
		if ( '' !== $registration_error ) {
			$this->fail( $registration_error );
		}
	}

	/**
	 * Valid insert request: creates the workflow, persists definition and
	 * layout, registers the workflow ability, and returns post metadata.
	 */
	public function test_saves_new_workflow_via_ajax(): void {
		$this->post_save_request(
			array(
				'workflow_title'      => ' AJAX _insert ',
				'workflow_excerpt'    => 'Created over AJAX.',
				'workflow_definition' => (string) wp_json_encode(
					array(
						'steps' => array(
							array(
								'ability' => 'baton-test/echo',
								'input'   => array( 'who' => 'you' ),
							),
						),
					)
				),
				'baton_editor_layout' => (string) wp_json_encode(
					array(
						'input'  => array(
							'x' => 5,
							'y' => 6,
						),
						'step-0' => array(
							'x' => 1,
							'y' => 2,
						),
					)
				),
			)
		);

		$data = $this->parsed_response();

		$this->assertSame( true, $data['success'] );
		$this->assertSame( 'Workflow saved.', $data['data']['message'] );
		$this->assertGreaterThan( 0, $data['data']['post_id'] );

		$post_id = (int) $data['data']['post_id'];
		$post    = get_post( $post_id );
		$this->assertSame( Baton_Workflow_CPT::POST_TYPE, $post->post_type );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 'AJAX _insert', $post->post_title );
		$this->assertSame( 'Created over AJAX.', $post->post_excerpt );
		$this->assertStringContainsString( 'workflow_id=' . $post_id, (string) $data['data']['edit_url'] );

		$definition = Baton_Workflow_CPT::get_definition( $post_id );
		$this->assertCount( 1, $definition['steps'] );
		$this->assertSame( 'baton-test/echo', $definition['steps'][0]['ability'] );
		$this->assertSame( 'you', $definition['steps'][0]['input']['who'] );

		$layout = Baton_Workflow_CPT::get_layout( $post_id );
		$this->assertSame(
			array(
				'x' => 5.0,
				'y' => 6.0,
			),
			$layout['input']
		);
		$this->assertSame(
			array(
				'x' => 1.0,
				'y' => 2.0,
			),
			$layout['step-0']
		);

		// On WP 6.9, abilities may only be registered during
		// wp_abilities_api_init, so the save-time attempt fires
		// _doing_it_wrong and the ability lands on the NEXT request's
		// registry init instead of being immediately available.
		$this->expect_save_time_ability_notices();
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );
		$this->assertNull( wp_get_ability( 'baton/workflow-' . $post_id ) );
	}

	/**
	 * Update request: renames and re-excerpts the post, replaces the
	 * definition, and prunes layout entries for removed nodes.
	 */
	public function test_updates_existing_workflow_and_prunes_layout(): void {
		$post_id = $this->factory()->post->create(
			array(
				'post_type'    => Baton_Workflow_CPT::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Old title',
				'post_excerpt' => 'Old excerpt',
			)
		);
		Baton_Workflow_CPT::save_definition( $post_id, array( 'steps' => array( array( 'ability' => 'baton-test/echo' ) ) ) );
		Baton_Workflow_CPT::save_layout(
			$post_id,
			array(
				'stale-node' => array(
					'x' => 9,
					'y' => 9,
				),
			)
		);

		$this->post_save_request(
			array(
				'workflow_id'         => (string) $post_id,
				'workflow_title'      => 'Renamed',
				'workflow_excerpt'    => 'New excerpt',
				'workflow_definition' => (string) wp_json_encode(
					array(
						'steps' => array(
							array(
								'id'      => 'first_step',
								'ability' => 'baton-test/echo',
								'input'   => array(),
							),
						),
					)
				),
				'baton_editor_layout' => (string) wp_json_encode(
					array(
						'input'  => array(
							'x' => 5,
							'y' => 6,
						),
						'step-0' => array(
							'x' => 1,
							'y' => 2,
						),
						'ghost'  => array(
							'x' => 9,
							'y' => 9,
						),
					)
				),
			)
		);

		$data = $this->parsed_response();

		$this->assertSame( true, $data['success'] );
		$this->assertSame( $post_id, (int) $data['data']['post_id'] );

		$post = get_post( $post_id );
		$this->assertSame( 'Renamed', $post->post_title );
		$this->assertSame( 'New excerpt', $post->post_excerpt );

		$definition = Baton_Workflow_CPT::get_definition( $post_id );
		$this->assertSame( 'first_step', $definition['steps'][0]['id'] );

		$layout = Baton_Workflow_CPT::get_layout( $post_id );
		$this->assertArrayHasKey( 'input', $layout );
		$this->assertArrayHasKey( 'step-0', $layout );
		$this->assertArrayNotHasKey( 'ghost', $layout );
		$this->assertArrayNotHasKey( 'stale-node', $layout );
		$this->expect_save_time_ability_notices();
	}

	/**
	 * A second save over the endpoint for the same post keeps one post and
	 * replaces the stored definition (the editor's save-again path).
	 */
	public function test_second_save_updates_same_post(): void {
		$this->post_save_request(
			array(
				'workflow_title'      => 'First',
				'workflow_definition' => '{}',
			)
		);
		$data    = $this->parsed_response();
		$post_id = (int) $data['data']['post_id'];

		$this->post_save_request(
			array(
				'workflow_id'         => (string) $post_id,
				'workflow_title'      => 'Second',
				'workflow_definition' => (string) wp_json_encode(
					array(
						'steps' => array(
							array( 'ability' => 'baton-test/echo' ),
						),
					)
				),
			)
		);
		// The update path re-persists; the save_post hook fires an unregister
		// (not yet registered) and a gated re-register attempt on 6.9.
		$this->expect_save_time_ability_notices();
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::unregister' );
		$this->setExpectedIncorrectUsage( 'wp_register_ability' );
		$data = $this->parsed_response();

		$this->assertSame( true, $data['success'] );
		$this->assertSame( $post_id, (int) $data['data']['post_id'] );

		$this->assertSame( 'Second', get_post( $post_id )->post_title );
		$this->assertCount( 1, Baton_Workflow_CPT::get_definition( $post_id )['steps'] );
		$this->assertSame(
			1,
			count(
				get_posts(
					array(
						'post_type'   => Baton_Workflow_CPT::POST_TYPE,
						'post_status' => 'any',
						'numberposts' => -1,
						'fields'      => 'ids',
					)
				)
			)
		);
	}

	/**
	 * A non-well-formed definition JSON is rejected and nothing is created.
	 */
	public function test_rejects_invalid_definition_json(): void {
		$this->post_save_request(
			array(
				'workflow_title'      => 'Should never exist',
				'workflow_definition' => '{"steps": [',
			)
		);

		$this->assert_error_response( 'Invalid workflow definition JSON.' );
		$this->assertSame( array(), $this->all_workflow_ids() );
	}

	/**
	 * An empty title is rejected (mirrors the client-side backstop).
	 */
	public function test_rejects_empty_title(): void {
		$this->post_save_request(
			array(
				'workflow_title'      => '   ',
				'workflow_definition' => '{}',
			)
		);

		$this->assert_error_response( 'Title is required.' );
		$this->assertSame( array(), $this->all_workflow_ids() );
	}

	/**
	 * Updating an ID that is not a workflow post errors without inserting.
	 */
	public function test_rejects_update_of_non_workflow_post(): void {
		$other_post_id = $this->factory()->post->create( array( 'post_title' => 'A normal post' ) );

		$this->post_save_request(
			array(
				'workflow_id'         => (string) $other_post_id,
				'workflow_title'      => 'Should never exist',
				'workflow_definition' => '{}',
			)
		);

		$data = $this->parsed_response();

		$this->assertSame( false, $data['success'] );
		$this->assertSame( array(), $this->all_workflow_ids() );
	}

	/**
	 * Users without manage_options are rejected even with a valid nonce.
	 */
	public function test_rejects_user_without_manage_options(): void {
		$this->_setRole( 'editor' );

		$this->post_save_request(
			array(
				'workflow_title'      => 'Should never exist',
				'workflow_definition' => '{}',
			)
		);

		$this->assert_error_response( 'Insufficient permissions.' );
		$this->assertSame( array(), $this->all_workflow_ids() );
	}

	/**
	 * An invalid nonce aborts the request with zero output and no post.
	 */
	public function test_rejects_invalid_nonce(): void {
		$this->post_save_request(
			array(
				'workflow_title'      => 'Should never exist',
				'workflow_definition' => '{}',
			),
			'not-a-real-nonce'
		);

		$this->assertSame( '', $this->_last_response );
		$this->assertSame( array(), $this->all_workflow_ids() );
	}

	/**
	 * A request with no definition at all saves the default definition.
	 */
	public function test_saves_default_definition_when_none_sent(): void {
		$this->post_save_request(
			array(
				'workflow_title' => 'Nothing to do',
			)
		);

		$data    = $this->parsed_response();
		$post_id = (int) $data['data']['post_id'];

		$this->assertSame( true, $data['success'] );
		$this->assertSame(
			array(
				'initial_input' => array(),
				'steps'         => array(),
			),
			Baton_Workflow_CPT::get_definition( $post_id )
		);
		$this->expect_save_time_ability_notices();
	}

	/**
	 * Fill $_POST (valid nonce unless overridden) and dispatch the action.
	 *
	 * The base class' _handleAjax() builds $_REQUEST from $_POST and $_GET
	 * and captures output into _last_response.
	 *
	 * @param array<string, string> $fields POST fields.
	 * @param string|null           $nonce  Override the nonce (send an invalid one to test auth).
	 */
	private function post_save_request( array $fields, ?string $nonce = null ): void {
		$this->_last_response = '';
		$_POST                = $fields;
		$_POST['nonce']       = $nonce ?? wp_create_nonce( Baton_Admin::NONCE_ACTION );

		try {
			$this->_handleAjax( Baton_Admin::AJAX_SAVE );
		} catch ( WPAjaxDieStopException $e ) {
			// Died before producing output (e.g. invalid nonce); body stays ''.
		} catch ( WPAjaxDieContinueException $e ) {
			// wp_send_json_* ended the request; the die handler captured the body.
		}
	}

	/**
	 * Decode the captured JSON response body.
	 *
	 * @return array<string, mixed>
	 */
	private function parsed_response(): array {
		$data = json_decode( $this->_last_response, true );

		if ( ! is_array( $data ) ) {
			$this->fail( 'Response was not JSON: ' . $this->_last_response );
		}

		return $data;
	}

	/**
	 * Declares the _doing_it_wrong notices the 6.9 registry emits when Baton
	 * attempts save-time (and save_post-time) ability registration.
	 */
	private function expect_save_time_ability_notices(): void {
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::unregister' );
		$this->setExpectedIncorrectUsage( 'wp_register_ability' );
	}

	/**
	 * Assert the response is a wp_send_json_error with the given message.
	 *
	 * @param string $message Expected error message.
	 */
	private function assert_error_response( string $message ): void {
		$data = $this->parsed_response();
		$this->assertSame( false, $data['success'] );
		$this->assertSame( $message, $data['data']['message'] );
	}

	/**
	 * All workflow post IDs currently in the database.
	 *
	 * @return array<int, int>
	 */
	private function all_workflow_ids(): array {
		return get_posts(
			array(
				'post_type'   => Baton_Workflow_CPT::POST_TYPE,
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);
	}
}
