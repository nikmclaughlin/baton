<?php
/**
 * @package Baton
 */

declare( strict_types=1 );

/**
 * Tests for Baton_Input_Mapper.
 */
class Test_Input_Mapper extends WP_UnitTestCase {

	public function test_get_value_at_path_resolves_flat_key(): void {
		$data = array( 'id' => 42 );
		$this->assertSame( 42, Baton_Input_Mapper::get_value_at_path( $data, 'id' ) );
	}

	public function test_get_value_at_path_resolves_nested_dot_path(): void {
		$data = array(
			'order' => array(
				'id' => 123,
				'customer' => array(
					'email' => 'buyer@example.com',
				),
			),
		);
		$this->assertSame( 123, Baton_Input_Mapper::get_value_at_path( $data, 'order.id' ) );
		$this->assertSame( 'buyer@example.com', Baton_Input_Mapper::get_value_at_path( $data, 'order.customer.email' ) );
	}

	public function test_get_value_at_path_resolves_array_index(): void {
		$data = array(
			'items' => array(
				array( 'id' => 'a' ),
				array( 'id' => 'b' ),
			),
		);
		$this->assertSame( 'b', Baton_Input_Mapper::get_value_at_path( $data, 'items.1.id' ) );
	}

	public function test_get_value_at_path_returns_null_for_missing_path(): void {
		$data = array( 'order' => array( 'id' => 1 ) );
		$this->assertNull( Baton_Input_Mapper::get_value_at_path( $data, 'order.status' ) );
		$this->assertNull( Baton_Input_Mapper::get_value_at_path( $data, 'nonexistent' ) );
	}

	public function test_set_value_at_path_sets_flat_key(): void {
		$input = array();
		Baton_Input_Mapper::set_value_at_path( $input, 'id', 42 );
		$this->assertSame( array( 'id' => 42 ), $input );
	}

	public function test_set_value_at_path_sets_nested_path(): void {
		$input = array();
		Baton_Input_Mapper::set_value_at_path( $input, 'order.id', 123 );
		$this->assertSame( array( 'order' => array( 'id' => 123 ) ), $input );
	}

	public function test_set_value_at_path_sets_deep_nested_path(): void {
		$input = array();
		Baton_Input_Mapper::set_value_at_path( $input, 'order.customer.email', 'buyer@example.com' );
		$this->assertSame(
			array(
				'order' => array(
					'customer' => array(
						'email' => 'buyer@example.com',
					),
				),
			),
			$input
		);
	}

	public function test_set_value_at_path_preserves_siblings(): void {
		$input = array( 'order' => array( 'status' => 'processing' ) );
		Baton_Input_Mapper::set_value_at_path( $input, 'order.id', 123 );
		$this->assertSame(
			array(
				'order' => array(
					'status' => 'processing',
					'id'     => 123,
				),
			),
			$input
		);
	}

	public function test_apply_mappings_flat_target(): void {
		$mappings = array(
			array(
				'source' => 'previous',
				'path'   => 'id',
				'target' => 'order_id',
			),
		);
		$previous = array( 'id' => 42 );

		$result = Baton_Input_Mapper::apply_mappings( array(), $mappings, $previous );

		$this->assertSame( array( 'order_id' => 42 ), $result['input'] );
	}

	public function test_apply_mappings_nested_source_path(): void {
		$mappings = array(
			array(
				'source' => 'previous',
				'path'   => 'order.id',
				'target' => 'order_id',
			),
		);
		$previous = array(
			'order' => array( 'id' => 123 ),
		);

		$result = Baton_Input_Mapper::apply_mappings( array(), $mappings, $previous );

		$this->assertSame( array( 'order_id' => 123 ), $result['input'] );
		$this->assertEmpty( $result['warnings'] );
	}

	public function test_apply_mappings_nested_target_path(): void {
		$mappings = array(
			array(
				'source' => 'previous',
				'path'   => 'id',
				'target' => 'order.id',
			),
		);
		$previous = array( 'id' => 123 );

		$result = Baton_Input_Mapper::apply_mappings( array(), $mappings, $previous );

		$this->assertSame( array( 'order' => array( 'id' => 123 ) ), $result['input'] );
		$this->assertEmpty( $result['warnings'] );
	}

	public function test_apply_mappings_deep_nested_source_to_nested_target(): void {
		$mappings = array(
			array(
				'source' => 'previous',
				'path'   => 'order.customer.email',
				'target' => 'customer.email',
			),
		);
		$previous = array(
			'order' => array(
				'customer' => array( 'email' => 'buyer@example.com' ),
			),
		);

		$result = Baton_Input_Mapper::apply_mappings( array(), $mappings, $previous );

		$this->assertSame(
			array(
				'customer' => array( 'email' => 'buyer@example.com' ),
			),
			$result['input']
		);
		$this->assertEmpty( $result['warnings'] );
	}

	public function test_apply_mappings_multiple_nested_targets(): void {
		$mappings = array(
			array(
				'source' => 'previous',
				'path'   => 'order.id',
				'target' => 'order.order_id',
			),
			array(
				'source' => 'previous',
				'path'   => 'order.status',
				'target' => 'order.order_status',
			),
		);
		$previous = array(
			'order' => array(
				'id'     => 123,
				'status' => 'processing',
			),
		);

		$result = Baton_Input_Mapper::apply_mappings( array(), $mappings, $previous );

		$this->assertSame(
			array(
				'order' => array(
					'order_id'     => 123,
					'order_status' => 'processing',
				),
			),
			$result['input']
		);
		$this->assertEmpty( $result['warnings'] );
	}

	public function test_apply_mappings_warns_when_nested_source_path_missing(): void {
		$mappings = array(
			array(
				'source' => 'previous',
				'path'   => 'order.nonexistent',
				'target' => 'id',
			),
		);
		$previous = array( 'order' => array( 'id' => 1 ) );

		$result = Baton_Input_Mapper::apply_mappings( array(), $mappings, $previous );

		$this->assertSame( array(), $result['input'] );
		$this->assertNotEmpty( $result['warnings'] );
	}

	public function test_sanitize_mappings_preserves_nested_target_paths(): void {
		$raw = array(
			array(
				'source' => 'previous',
				'path'   => 'order.id',
				'target' => 'order.order_id',
			),
		);

		$sanitized = Baton_Input_Mapper::sanitize_mappings( $raw );

		$this->assertCount( 1, $sanitized );
		$this->assertSame( 'order.id', $sanitized[0]['path'] );
		$this->assertSame( 'order.order_id', $sanitized[0]['target'] );
	}

	public function test_sanitize_mappings_strips_invalid_chars_in_nested_target(): void {
		$raw = array(
			array(
				'source' => 'previous',
				'path'   => 'order.id',
				'target' => 'order.or@der_id!',
			),
		);

		$sanitized = Baton_Input_Mapper::sanitize_mappings( $raw );

		$this->assertSame( 'order.order_id', $sanitized[0]['target'] );
	}

	public function test_get_value_at_path_wildcard_plucks_subproperty_from_array(): void {
		$data = array(
			'orders' => array(
				array( 'id' => 101, 'status' => 'processing' ),
				array( 'id' => 202, 'status' => 'completed' ),
				array( 'id' => 303, 'status' => 'on-hold' ),
			),
		);

		$this->assertSame(
			array( 101, 202, 303 ),
			Baton_Input_Mapper::get_value_at_path( $data, 'orders.*.id' )
		);
	}

	public function test_get_value_at_path_wildcard_plucks_nested_subproperty(): void {
		$data = array(
			'orders' => array(
				array( 'customer' => array( 'email' => 'a@example.com' ) ),
				array( 'customer' => array( 'email' => 'b@example.com' ) ),
			),
		);

		$this->assertSame(
			array( 'a@example.com', 'b@example.com' ),
			Baton_Input_Mapper::get_value_at_path( $data, 'orders.*.customer.email' )
		);
	}

	public function test_get_value_at_path_wildcard_returns_null_for_non_array(): void {
		$this->assertNull( Baton_Input_Mapper::get_value_at_path( 'not-an-array', '*.id' ) );
	}

	public function test_get_value_at_path_wildcard_skips_elements_missing_path(): void {
		$data = array(
			array( 'id' => 1 ),
			array( 'name' => 'foo' ),
			array( 'id' => 3 ),
		);

		// Only elements that actually have the path should appear in the result.
		$this->assertSame(
			array( 1, 3 ),
			Baton_Input_Mapper::get_value_at_path( $data, '*.id' )
		);
	}

	public function test_get_value_at_path_wildcard_bare_star_returns_all_elements(): void {
		$data = array( 10, 20, 30 );
		$this->assertSame( array( 10, 20, 30 ), Baton_Input_Mapper::get_value_at_path( $data, '*' ) );
	}

	public function test_apply_mappings_wildcard_source_to_flat_target(): void {
		$mappings = array(
			array(
				'source' => 'previous',
				'path'   => 'orders.*.id',
				'target' => 'order_ids',
			),
		);
		$previous = array(
			'orders' => array(
				array( 'id' => 101 ),
				array( 'id' => 202 ),
				array( 'id' => 303 ),
			),
		);

		$result = Baton_Input_Mapper::apply_mappings( array(), $mappings, $previous );

		$this->assertSame( array( 'order_ids' => array( 101, 202, 303 ) ), $result['input'] );
		$this->assertEmpty( $result['warnings'] );
	}

	public function test_sanitize_mappings_preserves_wildcard_in_path(): void {
		$raw = array(
			array(
				'source' => 'previous',
				'path'   => 'orders.*.id',
				'target' => 'order_ids',
			),
		);

		$sanitized = Baton_Input_Mapper::sanitize_mappings( $raw );

		$this->assertSame( 'orders.*.id', $sanitized[0]['path'] );
	}
}