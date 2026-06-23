<?php
/**
 * @package Baton
 */

declare( strict_types=1 );

/**
 * Tests for Baton_Input_Mapper.
 */
class Test_Input_Mapper extends WP_UnitTestCase {

	// ──────────────────────────────────────────────────────────────
	// is_scalar_input_schema()
	// ──────────────────────────────────────────────────────────────

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

	// ──────────────────────────────────────────────────────────────
	// get_scalar_type()
	// ──────────────────────────────────────────────────────────────

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

	// ──────────────────────────────────────────────────────────────
	// get_value_at_path()
	// ──────────────────────────────────────────────────────────────

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

	// ──────────────────────────────────────────────────────────────
	// coerce_value()
	// ──────────────────────────────────────────────────────────────

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

	// ──────────────────────────────────────────────────────────────
	// apply_mappings()
	// ──────────────────────────────────────────────────────────────

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

	// ──────────────────────────────────────────────────────────────
	// resolve_scalar_input()
	// ──────────────────────────────────────────────────────────────

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

	// ──────────────────────────────────────────────────────────────
	// resolve_input()
	// ──────────────────────────────────────────────────────────────

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

	// ──────────────────────────────────────────────────────────────
	// has_static_scalar_value()
	// ──────────────────────────────────────────────────────────────

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

	// ──────────────────────────────────────────────────────────────
	// sanitize_mappings()
	// ──────────────────────────────────────────────────────────────

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

	// ──────────────────────────────────────────────────────────────
	// sanitize_field_name() / sanitize_path()
	// ──────────────────────────────────────────────────────────────

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

	// ──────────────────────────────────────────────────────────────
	// schema_property_keys()
	// ──────────────────────────────────────────────────────────────

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