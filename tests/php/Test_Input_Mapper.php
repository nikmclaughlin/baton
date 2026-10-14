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

	public function test_is_scalar_input_schema_string(): void {
		$this->assertTrue( Baton_Input_Mapper::is_scalar_input_schema( array( 'type' => 'string' ) ) );
	}

	public function test_is_scalar_input_schema_integer(): void {
		$this->assertTrue( Baton_Input_Mapper::is_scalar_input_schema( array( 'type' => 'integer' ) ) );
	}

	public function test_is_scalar_input_schema_number(): void {
		$this->assertTrue( Baton_Input_Mapper::is_scalar_input_schema( array( 'type' => 'number' ) ) );
	}

	public function test_is_scalar_input_schema_boolean(): void {
		$this->assertTrue( Baton_Input_Mapper::is_scalar_input_schema( array( 'type' => 'boolean' ) ) );
	}

	public function test_is_scalar_input_schema_type_array_with_scalar(): void {
		$this->assertTrue( Baton_Input_Mapper::is_scalar_input_schema( array( 'type' => array( 'string', 'null' ) ) ) );
	}

	public function test_is_scalar_input_schema_type_array_without_scalar(): void {
		$this->assertFalse( Baton_Input_Mapper::is_scalar_input_schema( array( 'type' => array( 'object', 'null' ) ) ) );
	}

	public function test_is_scalar_input_schema_object_with_properties(): void {
		$this->assertFalse(
			Baton_Input_Mapper::is_scalar_input_schema(
				array(
					'type'       => 'object',
					'properties' => array( 'name' => array( 'type' => 'string' ) ),
				)
			)
		);
	}

	public function test_is_scalar_input_schema_object_without_properties(): void {
		// object type alone without properties is not scalar.
		$this->assertFalse( Baton_Input_Mapper::is_scalar_input_schema( array( 'type' => 'object' ) ) );
	}

	public function test_is_scalar_input_schema_empty_schema(): void {
		$this->assertFalse( Baton_Input_Mapper::is_scalar_input_schema( array() ) );
	}

	public function test_get_scalar_type_string(): void {
		$this->assertSame( 'string', Baton_Input_Mapper::get_scalar_type( array( 'type' => 'string' ) ) );
	}

	public function test_get_scalar_type_integer(): void {
		$this->assertSame( 'integer', Baton_Input_Mapper::get_scalar_type( array( 'type' => 'integer' ) ) );
	}

	public function test_get_scalar_type_number(): void {
		$this->assertSame( 'number', Baton_Input_Mapper::get_scalar_type( array( 'type' => 'number' ) ) );
	}

	public function test_get_scalar_type_boolean(): void {
		$this->assertSame( 'boolean', Baton_Input_Mapper::get_scalar_type( array( 'type' => 'boolean' ) ) );
	}

	public function test_get_scalar_type_array_picks_first_scalar(): void {
		$this->assertSame( 'string', Baton_Input_Mapper::get_scalar_type( array( 'type' => array( 'string', 'null' ) ) ) );
	}

	public function test_get_scalar_type_non_scalar_returns_string(): void {
		$this->assertSame( 'string', Baton_Input_Mapper::get_scalar_type( array( 'type' => 'object' ) ) );
	}

	public function test_get_scalar_type_missing_type_returns_string(): void {
		$this->assertSame( 'string', Baton_Input_Mapper::get_scalar_type( array() ) );
	}

	public function test_get_value_at_path_simple_key(): void {
		$data = array( 'name' => 'Alice' );
		$this->assertSame( 'Alice', Baton_Input_Mapper::get_value_at_path( $data, 'name' ) );
	}

	public function test_get_value_at_path_nested_key(): void {
		$data = array(
			'user' => array( 'email' => 'alice@example.com' ),
		);
		$this->assertSame( 'alice@example.com', Baton_Input_Mapper::get_value_at_path( $data, 'user.email' ) );
	}

	public function test_get_value_at_path_array_index(): void {
		$data = array(
			'items' => array(
				array( 'id' => 101 ),
				array( 'id' => 202 ),
			),
		);
		$this->assertSame( 202, Baton_Input_Mapper::get_value_at_path( $data, 'items.1.id' ) );
	}

	public function test_get_value_at_path_missing_key_returns_null(): void {
		$data = array( 'name' => 'Alice' );
		$this->assertNull( Baton_Input_Mapper::get_value_at_path( $data, 'email' ) );
	}

	public function test_get_value_at_path_missing_nested_key_returns_null(): void {
		$data = array( 'user' => array( 'name' => 'Alice' ) );
		$this->assertNull( Baton_Input_Mapper::get_value_at_path( $data, 'user.email' ) );
	}

	public function test_get_value_at_path_empty_path_returns_whole_data(): void {
		$data = array( 'name' => 'Alice' );
		$this->assertSame( $data, Baton_Input_Mapper::get_value_at_path( $data, '' ) );
	}

	public function test_get_value_at_path_path_into_scalar_returns_null(): void {
		$data = array( 'name' => 'Alice' );
		$this->assertNull( Baton_Input_Mapper::get_value_at_path( $data, 'name.first' ) );
	}

	public function test_get_value_at_path_object_property(): void {
		$obj       = new stdClass();
		$obj->name = 'Bob';
		$this->assertSame( 'Bob', Baton_Input_Mapper::get_value_at_path( $obj, 'name' ) );
	}

	public function test_get_value_at_path_null_data_returns_null(): void {
		$this->assertNull( Baton_Input_Mapper::get_value_at_path( null, 'name' ) );
	}

	public function test_coerce_value_numeric_string_to_int(): void {
		$this->assertSame( 42, Baton_Input_Mapper::coerce_value( '42' ) );
	}

	public function test_coerce_value_decimal_string_to_float(): void {
		$this->assertSame( 3.14, Baton_Input_Mapper::coerce_value( '3.14' ) );
	}

	public function test_coerce_value_non_numeric_string_unchanged(): void {
		$this->assertSame( 'hello', Baton_Input_Mapper::coerce_value( 'hello' ) );
	}

	public function test_coerce_value_int_unchanged(): void {
		$this->assertSame( 42, Baton_Input_Mapper::coerce_value( 42 ) );
	}

	public function test_coerce_value_bool_unchanged(): void {
		$this->assertSame( true, Baton_Input_Mapper::coerce_value( true ) );
	}

	public function test_coerce_value_array_unchanged(): void {
		$arr = array( 'a', 'b' );
		$this->assertSame( $arr, Baton_Input_Mapper::coerce_value( $arr ) );
	}

	public function test_coerce_value_null_unchanged(): void {
		$this->assertNull( Baton_Input_Mapper::coerce_value( null ) );
	}

	public function test_apply_maps_from_previous_output(): void {
		$mappings = array(
			array(
				'source' => 'previous',
				'path'   => 'plan_id',
				'target' => 'plan_id',
			),
		);
		$previous = array( 'plan_id' => 999 );

		$result = Baton_Input_Mapper::apply_mappings( array(), $mappings, $previous );

		$this->assertSame( array( 'plan_id' => 999 ), $result['input'] );
		$this->assertSame( array(), $result['warnings'] );
	}

	public function test_apply_maps_nested_path_from_previous_output(): void {
		$mappings = array(
			array(
				'source' => 'previous',
				'path'   => 'user.email',
				'target' => 'email',
			),
		);
		$previous = array(
			'user' => array( 'email' => 'bob@example.com' ),
		);

		$result = Baton_Input_Mapper::apply_mappings( array(), $mappings, $previous );

		$this->assertSame( array( 'email' => 'bob@example.com' ), $result['input'] );
	}

	public function test_apply_maps_from_initial_input(): void {
		$mappings = array(
			array(
				'source' => 'initial',
				'path'   => 'api_key',
				'target' => 'api_key',
			),
		);
		$initial = array( 'api_key' => 'secret-123' );

		$result = Baton_Input_Mapper::apply_mappings( array(), $mappings, null, $initial );

		$this->assertSame( array( 'api_key' => 'secret-123' ), $result['input'] );
	}

	public function test_apply_maps_array_index_path(): void {
		$mappings = array(
			array(
				'source' => 'previous',
				'path'   => 'items.0.id',
				'target' => 'first_id',
			),
		);
		$previous = array(
			'items' => array(
				array( 'id' => 101 ),
				array( 'id' => 202 ),
			),
		);

		$result = Baton_Input_Mapper::apply_mappings( array(), $mappings, $previous );

		$this->assertSame( array( 'first_id' => 101 ), $result['input'] );
	}

	public function test_apply_multiple_mappings(): void {
		$mappings = array(
			array(
				'source' => 'previous',
				'path'   => 'name',
				'target' => 'display_name',
			),
			array(
				'source' => 'previous',
				'path'   => 'email',
				'target' => 'contact_email',
			),
		);
		$previous = array(
			'name'  => 'Alice',
			'email' => 'alice@example.com',
		);

		$result = Baton_Input_Mapper::apply_mappings( array(), $mappings, $previous );

		$this->assertSame(
			array(
				'display_name'  => 'Alice',
				'contact_email' => 'alice@example.com',
			),
			$result['input']
		);
	}

	public function test_apply_mappings_merge_with_static_input(): void {
		// apply_mappings receives base input as first arg; caller merges static after.
		$mappings = array(
			array(
				'source' => 'previous',
				'path'   => 'name',
				'target' => 'name',
			),
		);
		$static   = array( 'static_field' => 'static_value' );
		$previous = array( 'name' => 'Alice' );

		$result = Baton_Input_Mapper::apply_mappings( $static, $mappings, $previous );

		$this->assertSame(
			array(
				'static_field' => 'static_value',
				'name'         => 'Alice',
			),
			$result['input']
		);
	}

	public function test_apply_mappings_missing_path_produces_warning(): void {
		$mappings = array(
			array(
				'source' => 'previous',
				'path'   => 'nonexistent',
				'target' => 'out_field',
			),
		);

		$result = Baton_Input_Mapper::apply_mappings( array(), $mappings, array( 'other' => 1 ) );

		$this->assertSame( array(), $result['input'] );
		$this->assertCount( 1, $result['warnings'] );
		$this->assertStringContainsString( 'nonexistent', $result['warnings'][0] );
	}

	public function test_apply_mappings_null_previous_produces_warning(): void {
		$mappings = array(
			array(
				'source' => 'previous',
				'path'   => 'name',
				'target' => 'name',
			),
		);

		$result = Baton_Input_Mapper::apply_mappings( array(), $mappings, null );

		$this->assertSame( array(), $result['input'] );
		$this->assertCount( 1, $result['warnings'] );
	}

	public function test_apply_mappings_skips_mapping_without_target(): void {
		$mappings = array(
			array(
				'source' => 'previous',
				'path'   => 'name',
				'target' => '',
			),
		);

		$result = Baton_Input_Mapper::apply_mappings( array(), $mappings, array( 'name' => 'Alice' ) );

		$this->assertSame( array(), $result['input'] );
		$this->assertSame( array(), $result['warnings'] );
	}

	public function test_apply_mappings_skips_mapping_without_path(): void {
		$mappings = array(
			array(
				'source' => 'previous',
				'path'   => '',
				'target' => 'name',
			),
		);

		$result = Baton_Input_Mapper::apply_mappings( array(), $mappings, array( 'name' => 'Alice' ) );

		$this->assertSame( array(), $result['input'] );
		$this->assertSame( array(), $result['warnings'] );
	}

	public function test_apply_mappings_coerces_numeric_strings(): void {
		$mappings = array(
			array(
				'source' => 'previous',
				'path'   => 'count',
				'target' => 'count',
			),
		);
		$previous = array( 'count' => '42' );

		$result = Baton_Input_Mapper::apply_mappings( array(), $mappings, $previous );

		$this->assertSame( 42, $result['input']['count'] );
	}

	public function test_resolve_scalar_from_mapping(): void {
		$mappings = array(
			array(
				'source' => 'previous',
				'path'   => 'value',
				'target' => '',
			),
		);
		$previous = array( 'value' => 'hello' );

		$result = Baton_Input_Mapper::resolve_scalar_input( null, $mappings, $previous );

		$this->assertSame( 'hello', $result['input'] );
		$this->assertSame( array(), $result['warnings'] );
	}

	public function test_resolve_scalar_static_overrides_mapping(): void {
		$mappings = array(
			array(
				'source' => 'previous',
				'path'   => 'value',
				'target' => '',
			),
		);
		$previous = array( 'value' => 'from_mapping' );

		$result = Baton_Input_Mapper::resolve_scalar_input( 'static_override', $mappings, $previous );

		$this->assertSame( 'static_override', $result['input'] );
	}

	public function test_resolve_scalar_no_mapping_no_static_returns_null(): void {
		$result = Baton_Input_Mapper::resolve_scalar_input( null, array(), null );

		$this->assertNull( $result['input'] );
		$this->assertSame( array(), $result['warnings'] );
	}

	public function test_resolve_scalar_empty_static_array_not_used(): void {
		// empty array static_input is not a scalar value.
		$result = Baton_Input_Mapper::resolve_scalar_input( array(), array(), null );

		$this->assertNull( $result['input'] );
	}

	public function test_resolve_scalar_mapping_missing_path_warns(): void {
		$mappings = array(
			array(
				'source' => 'previous',
				'path'   => 'missing',
				'target' => '',
			),
		);

		$result = Baton_Input_Mapper::resolve_scalar_input( null, $mappings, array( 'other' => 1 ) );

		$this->assertNull( $result['input'] );
		$this->assertCount( 1, $result['warnings'] );
		$this->assertStringContainsString( 'missing', $result['warnings'][0] );
	}

	public function test_resolve_scalar_from_initial_input(): void {
		$mappings = array(
			array(
				'source' => 'initial',
				'path'   => 'seed',
				'target' => '',
			),
		);
		$initial = array( 'seed' => 'initial_val' );

		$result = Baton_Input_Mapper::resolve_scalar_input( null, $mappings, null, $initial );

		$this->assertSame( 'initial_val', $result['input'] );
	}

	public function test_resolve_input_object_schema_uses_apply_mappings(): void {
		$input_schema = array(
			'type'       => 'object',
			'properties' => array( 'name' => array( 'type' => 'string' ) ),
		);
		$mappings     = array(
			array(
				'source' => 'previous',
				'path'   => 'user',
				'target' => 'name',
			),
		);
		$previous     = array( 'user' => 'Alice' );

		$result = Baton_Input_Mapper::resolve_input( $input_schema, array(), $mappings, $previous );

		$this->assertSame( array( 'name' => 'Alice' ), $result['input'] );
	}

	public function test_resolve_input_scalar_schema_uses_resolve_scalar(): void {
		$input_schema = array( 'type' => 'string' );
		$mappings     = array(
			array(
				'source' => 'previous',
				'path'   => 'value',
				'target' => '',
			),
		);
		$previous     = array( 'value' => 'hello' );

		$result = Baton_Input_Mapper::resolve_input( $input_schema, null, $mappings, $previous );

		$this->assertSame( 'hello', $result['input'] );
	}

	public function test_has_static_scalar_value_string(): void {
		$this->assertTrue( Baton_Input_Mapper::has_static_scalar_value( 'hello' ) );
	}

	public function test_has_static_scalar_value_int(): void {
		$this->assertTrue( Baton_Input_Mapper::has_static_scalar_value( 42 ) );
	}

	public function test_has_static_scalar_value_null(): void {
		$this->assertFalse( Baton_Input_Mapper::has_static_scalar_value( null ) );
	}

	public function test_has_static_scalar_value_empty_string(): void {
		$this->assertFalse( Baton_Input_Mapper::has_static_scalar_value( '' ) );
	}

	public function test_has_static_scalar_value_array(): void {
		$this->assertFalse( Baton_Input_Mapper::has_static_scalar_value( array( 'a' ) ) );
	}

	public function test_sanitize_mappings_non_array_returns_empty(): void {
		$this->assertSame( array(), Baton_Input_Mapper::sanitize_mappings( 'not-array' ) );
	}

	public function test_sanitize_mappings_preserves_valid_mapping(): void {
		$raw = array(
			array(
				'source' => 'previous',
				'path'   => 'user.email',
				'target' => 'email',
			),
		);

		$result = Baton_Input_Mapper::sanitize_mappings( $raw );

		$this->assertCount( 1, $result );
		$this->assertSame( 'previous', $result[0]['source'] );
		$this->assertSame( 'user.email', $result[0]['path'] );
		$this->assertSame( 'email', $result[0]['target'] );
	}

	public function test_sanitize_mappings_strips_invalid_chars_from_path(): void {
		$raw = array(
			array(
				'source' => 'previous',
				'path'   => 'us<!>er.ema@il',
				'target' => 'email',
			),
		);

		$result = Baton_Input_Mapper::sanitize_mappings( $raw );

		$this->assertSame( 'user.email', $result[0]['path'] );
	}

	public function test_sanitize_mappings_strips_invalid_chars_from_target(): void {
		$raw = array(
			array(
				'source' => 'previous',
				'path'   => 'email',
				'target' => 'em!ail#',
			),
		);

		$result = Baton_Input_Mapper::sanitize_mappings( $raw );

		$this->assertSame( 'email', $result[0]['target'] );
	}

	public function test_sanitize_mappings_invalid_source_defaults_to_previous(): void {
		$raw = array(
			array(
				'source' => 'bogus',
				'path'   => 'email',
				'target' => 'email',
			),
		);

		$result = Baton_Input_Mapper::sanitize_mappings( $raw );

		$this->assertSame( 'previous', $result[0]['source'] );
	}

	public function test_sanitize_mappings_missing_source_defaults_to_previous(): void {
		$raw = array(
			array(
				'path'   => 'email',
				'target' => 'email',
			),
		);

		$result = Baton_Input_Mapper::sanitize_mappings( $raw );

		$this->assertSame( 'previous', $result[0]['source'] );
	}

	public function test_sanitize_mappings_drops_mapping_without_path(): void {
		$raw = array(
			array(
				'source' => 'previous',
				'path'   => '',
				'target' => 'email',
			),
		);

		$result = Baton_Input_Mapper::sanitize_mappings( $raw );

		$this->assertSame( array(), $result );
	}

	public function test_sanitize_mappings_skips_non_array_entries(): void {
		$raw = array( 'not-an-array', array( 'path' => 'x', 'target' => 'y' ) );

		$result = Baton_Input_Mapper::sanitize_mappings( $raw );

		$this->assertCount( 1, $result );
		$this->assertSame( 'x', $result[0]['path'] );
	}

	public function test_sanitize_mappings_preserves_initial_source(): void {
		$raw = array(
			array(
				'source' => 'initial',
				'path'   => 'seed',
				'target' => 'seed_val',
			),
		);

		$result = Baton_Input_Mapper::sanitize_mappings( $raw );

		$this->assertSame( 'initial', $result[0]['source'] );
	}

	public function test_sanitize_field_name_strips_special_chars(): void {
		$this->assertSame( 'email', Baton_Input_Mapper::sanitize_field_name( 'em!a@il#' ) );
	}

	public function test_sanitize_field_name_preserves_dashes_underscores(): void {
		$this->assertSame( 'my-field_name', Baton_Input_Mapper::sanitize_field_name( 'my-field_name' ) );
	}

	public function test_sanitize_field_name_trims_whitespace(): void {
		$this->assertSame( 'field', Baton_Input_Mapper::sanitize_field_name( '  field  ' ) );
	}

	public function test_sanitize_field_name_empty_string(): void {
		$this->assertSame( '', Baton_Input_Mapper::sanitize_field_name( '' ) );
	}

	public function test_sanitize_path_strips_special_chars_per_segment(): void {
		$this->assertSame( 'user.email', Baton_Input_Mapper::sanitize_path( 'us!er.em@ail' ) );
	}

	public function test_sanitize_path_empty_returns_empty(): void {
		$this->assertSame( '', Baton_Input_Mapper::sanitize_path( '' ) );
	}

	public function test_sanitize_path_trims_whitespace(): void {
		$this->assertSame( 'user.email', Baton_Input_Mapper::sanitize_path( '  user.email  ' ) );
	}

	public function test_sanitize_path_drops_empty_segments(): void {
		$this->assertSame( 'user.email', Baton_Input_Mapper::sanitize_path( 'user..email' ) );
	}

	public function test_schema_property_keys_returns_keys(): void {
		$schema = array(
			'properties' => array(
				'name'  => array( 'type' => 'string' ),
				'email' => array( 'type' => 'string' ),
			),
		);

		$this->assertSame( array( 'name', 'email' ), Baton_Input_Mapper::schema_property_keys( $schema ) );
	}

	public function test_schema_property_keys_no_properties_returns_empty(): void {
		$this->assertSame( array(), Baton_Input_Mapper::schema_property_keys( array( 'type' => 'object' ) ) );
	}

	public function test_schema_property_keys_empty_schema_returns_empty(): void {
		$this->assertSame( array(), Baton_Input_Mapper::schema_property_keys( array() ) );
	}

}