<?php
/**
 * @package Baton
 */

declare( strict_types=1 );

/**
 * Tests for Baton_Schema_Paths.
 */
class Test_Schema_Paths extends WP_UnitTestCase {

	public function test_get_paths_flat_properties(): void {
		$schema = array(
			'type' => 'object',
			'properties' => array(
				'id' => array( 'type' => 'integer' ),
				'name' => array( 'type' => 'string' ),
			),
		);

		$paths = Baton_Schema_Paths::get_paths( $schema );

		$this->assertCount( 2, $paths );
		$this->assertSame( 'id', $paths[0]['value'] );
		$this->assertSame( 'name', $paths[1]['value'] );
	}

	public function test_get_paths_nested_object(): void {
		$schema = array(
			'type' => 'object',
			'properties' => array(
				'order' => array(
					'type' => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer' ),
						'status' => array( 'type' => 'string' ),
						'customer' => array(
							'type' => 'object',
							'properties' => array(
								'email' => array( 'type' => 'string' ),
								'name' => array( 'type' => 'string' ),
							),
						),
					),
				),
				'message' => array( 'type' => 'string' ),
			),
		);

		$paths = Baton_Schema_Paths::get_paths( $schema );

		$values = array_column( $paths, 'value' );

		// Intermediate object paths are included alongside their subproperties.
		$this->assertContains( 'order', $values );
		$this->assertContains( 'order.id', $values );
		$this->assertContains( 'order.status', $values );
		$this->assertContains( 'order.customer', $values );
		$this->assertContains( 'order.customer.email', $values );
		$this->assertContains( 'order.customer.name', $values );
		$this->assertContains( 'message', $values );
	}

	public function test_get_paths_array_with_object_items(): void {
		$schema = array(
			'type' => 'array',
			'items' => array(
				'type' => 'object',
				'properties' => array(
					'id' => array( 'type' => 'integer' ),
					'name' => array( 'type' => 'string' ),
				),
			),
		);

		$paths = Baton_Schema_Paths::get_paths( $schema );

		$values = array_column( $paths, 'value' );

		$this->assertContains( '*.id', $values );
		$this->assertContains( '*.name', $values );
	}

	public function test_get_paths_array_of_nested_objects(): void {
		$schema = array(
			'type' => 'array',
			'items' => array(
				'type' => 'object',
				'properties' => array(
					'order' => array(
						'type' => 'object',
						'properties' => array(
							'id' => array( 'type' => 'integer' ),
						),
					),
				),
			),
		);

		$paths = Baton_Schema_Paths::get_paths( $schema );

		$values = array_column( $paths, 'value' );

		$this->assertContains( '*.order', $values );
		$this->assertContains( '*.order.id', $values );
	}

	public function test_get_paths_respects_max_depth(): void {
		$schema = array(
			'type' => 'object',
			'properties' => array(
				'a' => array(
					'type' => 'object',
					'properties' => array(
						'b' => array(
							'type' => 'object',
							'properties' => array(
								'c' => array(
									'type' => 'object',
									'properties' => array(
										'd' => array(
											'type' => 'object',
											'properties' => array(
												'e' => array(
													'type' => 'object',
													'properties' => array(
														'f' => array( 'type' => 'string' ),
													),
												),
											),
										),
									),
								),
							),
						),
					),
				),
			),
		);

		$paths = Baton_Schema_Paths::get_paths( $schema );

		$values = array_column( $paths, 'value' );

		// MAX_PATH_DEPTH is 5, so we expect a, a.b, a.b.c, a.b.c.d, a.b.c.d.e
		// but NOT a.b.c.d.e.f (depth 6).
		$this->assertContains( 'a', $values );
		$this->assertContains( 'a.b', $values );
		$this->assertContains( 'a.b.c', $values );
		$this->assertContains( 'a.b.c.d', $values );
		$this->assertContains( 'a.b.c.d.e', $values );
		$this->assertNotContains( 'a.b.c.d.e.f', $values );
	}

	public function test_get_paths_empty_schema(): void {
		$this->assertSame( array(), Baton_Schema_Paths::get_paths( array() ) );
	}

	public function test_get_paths_schema_with_no_properties(): void {
		$schema = array(
			'type' => 'object',
			'additionalProperties' => true,
		);

		$this->assertSame( array(), Baton_Schema_Paths::get_paths( $schema ) );
	}

	public function test_get_paths_schema_with_union_type(): void {
		$schema = array(
			'type' => 'object',
			'properties' => array(
				'data' => array(
					'type' => array( 'object', 'null' ),
					'properties' => array(
						'id' => array( 'type' => 'integer' ),
					),
				),
			),
		);

		$paths = Baton_Schema_Paths::get_paths( $schema );

		$values = array_column( $paths, 'value' );

		$this->assertContains( 'data', $values );
		$this->assertContains( 'data.id', $values );
	}

	public function test_input_target_catalog_returns_nested_paths(): void {
		$schema = array(
			'type' => 'object',
			'properties' => array(
				'order' => array(
					'type' => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer' ),
					),
				),
				'customer_email' => array( 'type' => 'string' ),
			),
		);

		$catalog = Baton_Schema_Paths::input_target_catalog( $schema );

		$this->assertTrue( $catalog['selectable'] );

		$values = array_column( $catalog['targets'], 'value' );

		$this->assertContains( 'order', $values );
		$this->assertContains( 'order.id', $values );
		$this->assertContains( 'customer_email', $values );
	}

	public function test_output_path_catalog_returns_nested_paths(): void {
		$schema = array(
			'type' => 'object',
			'properties' => array(
				'order' => array(
					'type' => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer' ),
						'status' => array( 'type' => 'string' ),
					),
				),
			),
		);

		$catalog = Baton_Schema_Paths::output_path_catalog( $schema );

		$this->assertTrue( $catalog['selectable'] );

		$values = array_column( $catalog['paths'], 'value' );

		$this->assertContains( 'order', $values );
		$this->assertContains( 'order.id', $values );
		$this->assertContains( 'order.status', $values );
	}

	public function test_get_paths_array_property_with_object_items(): void {
		$schema = array(
			'type' => 'object',
			'properties' => array(
				'orders' => array(
					'type' => 'array',
					'items' => array(
						'type' => 'object',
						'properties' => array(
							'id'     => array( 'type' => 'integer' ),
							'status' => array( 'type' => 'string' ),
						),
					),
				),
				'total' => array( 'type' => 'integer' ),
			),
		);

		$paths = Baton_Schema_Paths::get_paths( $schema );

		$values = array_column( $paths, 'value' );

		$this->assertContains( 'orders', $values );
		$this->assertContains( 'orders.*', $values );
		$this->assertContains( 'orders.*.id', $values );
		$this->assertContains( 'orders.*.status', $values );
		$this->assertContains( 'total', $values );
	}

	public function test_get_paths_includes_type_annotation(): void {
		$schema = array(
			'type' => 'object',
			'properties' => array(
				'id'     => array( 'type' => 'integer' ),
				'name'   => array( 'type' => 'string' ),
				'items'  => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'meta'   => array(
					'type' => array( 'object', 'null' ),
					'properties' => array(
						'flag' => array( 'type' => 'boolean' ),
					),
				),
			),
		);

		$paths = Baton_Schema_Paths::get_paths( $schema );

		$by_value = array();
		foreach ( $paths as $p ) {
			$by_value[ $p['value'] ] = $p['type'] ?? null;
		}

		$this->assertSame( 'integer', $by_value['id'] ?? null );
		$this->assertSame( 'string', $by_value['name'] ?? null );
		$this->assertSame( 'array', $by_value['items'] ?? null );
		$this->assertSame( 'array', $by_value['items.*'] ?? null );
		$this->assertSame( 'object', $by_value['meta'] ?? null );
		$this->assertSame( 'boolean', $by_value['meta.flag'] ?? null );
	}
}