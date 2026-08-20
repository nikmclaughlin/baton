<?php
/**
 * Test abilities for wp-env integration tests.
 *
 * @package Baton
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Test ability category registration args.
 *
 * @return array<string, string>
 */
function baton_tests_get_category_args(): array {
	return array(
		'label'       => 'Baton Test',
		'description' => 'Test ability category for Baton PHPUnit fixtures.',
	);
}

/**
 * Echo ability registration args.
 *
 * @return array<string, mixed>
 */
function baton_tests_get_echo_ability_args(): array {
	return array(
		'label'               => 'Echo',
		'description'         => 'Returns input for Baton tests.',
		'category'            => 'baton-test',
		'input_schema'        => array(
			'type'                 => 'object',
			'additionalProperties' => true,
		),
		'output_schema'       => array(
			'type'                 => 'object',
			'additionalProperties' => true,
		),
		'execute_callback'    => static function ( $input = null ) {
			return is_array( $input ) ? $input : array();
		},
		'permission_callback' => static function (): bool {
			return true;
		},
		'meta'                => array(
			'show_in_rest' => false,
		),
	);
}

/**
 * Order producer ability registration args.
 *
 * Returns a nested order object so subproperty mapping can be tested.
 *
 * @return array<string, mixed>
 */
function baton_tests_get_order_producer_args(): array {
	return array(
		'label'               => 'Order Producer',
		'description'         => 'Returns a sample order with nested properties for Baton tests.',
		'category'            => 'baton-test',
		'input_schema'        => array(
			'type' => 'object',
			'properties' => array(
				'count' => array( 'type' => 'integer' ),
			),
		),
		'output_schema'       => array(
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
								'name'  => array( 'type' => 'string' ),
							),
						),
					),
				),
				'message' => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => static function ( $input = null ) {
			return array(
				'order' => array(
					'id' => 123,
					'status' => 'processing',
					'customer' => array(
						'email' => 'buyer@example.com',
						'name' => 'Test Buyer',
					),
				),
				'message' => 'Order created',
			);
		},
		'permission_callback' => static function (): bool {
			return true;
		},
		'meta'                => array(
			'show_in_rest' => false,
		),
	);
}

/**
 * Orders producer ability registration args.
 *
 * Returns an array of order objects so wildcard subproperty mapping
 * (e.g. orders.*.id) can be tested.
 *
 * @return array<string, mixed>
 */
function baton_tests_get_orders_producer_args(): array {
	return array(
		'label'               => 'Orders Producer',
		'description'         => 'Returns an array of sample orders for Baton wildcard mapping tests.',
		'category'            => 'baton-test',
		'input_schema'        => array(
			'type' => 'object',
			'properties' => array(
				'count' => array( 'type' => 'integer' ),
			),
		),
		'output_schema'       => array(
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
		),
		'execute_callback'    => static function ( $input = null ) {
			return array(
				'orders' => array(
					array(
						'id'     => 101,
						'status' => 'processing',
					),
					array(
						'id'     => 202,
						'status' => 'completed',
					),
					array(
						'id'     => 303,
						'status' => 'on-hold',
					),
				),
				'total' => 3,
			);
		},
		'permission_callback' => static function (): bool {
			return true;
		},
		'meta'                => array(
			'show_in_rest' => false,
		),
	);
}

/**
 * Order IDs consumer ability registration args.
 *
 * Accepts an array of order IDs so wildcard source-to-flat-target
 * mapping can be tested.
 *
 * @return array<string, mixed>
 */
function baton_tests_get_order_ids_consumer_args(): array {
	return array(
		'label'               => 'Order IDs Consumer',
		'description'         => 'Echoes its input back for Baton wildcard mapping tests.',
		'category'            => 'baton-test',
		'input_schema'        => array(
			'type' => 'object',
			'properties' => array(
				'order_ids' => array(
					'type' => 'array',
					'items' => array( 'type' => 'integer' ),
				),
			),
		),
		'output_schema'       => array(
			'type' => 'object',
			'properties' => array(
				'received' => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => static function ( $input = null ) {
			return array( 'received' => wp_json_encode( $input ) );
		},
		'permission_callback' => static function (): bool {
			return true;
		},
		'meta'                => array(
			'show_in_rest' => false,
		),
	);
}

/**
 * Order consumer ability registration args.
 *
 * Accepts a nested input object so subproperty targeting can be tested.
 *
 * @return array<string, mixed>
 */
function baton_tests_get_order_consumer_args(): array {
	return array(
		'label'               => 'Order Consumer',
		'description'         => 'Echoes its input back for Baton nested-target tests.',
		'category'            => 'baton-test',
		'input_schema'        => array(
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
		),
		'output_schema'       => array(
			'type' => 'object',
			'properties' => array(
				'received' => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => static function ( $input = null ) {
			return array( 'received' => wp_json_encode( $input ) );
		},
		'permission_callback' => static function (): bool {
			return true;
		},
		'meta'                => array(
			'show_in_rest' => false,
		),
	);
}

/**
 * Process order ability registration args.
 *
 * Accepts a single order_id integer so loop-over-array iteration
 * can be tested.
 *
 * @return array<string, mixed>
 */
function baton_tests_get_process_order_args(): array {
	return array(
		'label'               => 'Process Order',
		'description'         => 'Processes a single order ID for Baton loop tests.',
		'category'            => 'baton-test',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'order_id' => array( 'type' => 'integer' ),
				'action'   => array( 'type' => 'string' ),
			),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'processed_id' => array( 'type' => 'integer' ),
				'status'       => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => static function ( $input = null ) {
			$order_id = is_array( $input ) && isset( $input['order_id'] ) ? (int) $input['order_id'] : 0;
			$action   = is_array( $input ) && isset( $input['action'] ) ? (string) $input['action'] : 'process';
			return array(
				'processed_id' => $order_id,
				'status'       => $action . 'ed',
			);
		},
		'permission_callback' => static function (): bool {
			return true;
		},
		'meta'                => array(
			'show_in_rest' => false,
		),
	);
}

/**
 * Register test ability category on the required hook.
 */
function baton_tests_register_ability_category(): void {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}

	wp_register_ability_category( 'baton-test', baton_tests_get_category_args() );
}

/**
 * Register echo ability used by runner tests on the required hook.
 */
function baton_tests_register_abilities(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'baton-test/echo', baton_tests_get_echo_ability_args() );
	wp_register_ability( 'baton-test/order-producer', baton_tests_get_order_producer_args() );
	wp_register_ability( 'baton-test/orders-producer', baton_tests_get_orders_producer_args() );
	wp_register_ability( 'baton-test/order-consumer', baton_tests_get_order_consumer_args() );
	wp_register_ability( 'baton-test/order-ids-consumer', baton_tests_get_order_ids_consumer_args() );
	wp_register_ability( 'baton-test/process-order', baton_tests_get_process_order_args() );
}

/**
 * Register test fixtures when PHPUnit loads the plugin after core ability hooks already ran.
 *
 * @return string Empty on success, otherwise an error message for assertions.
 */
function baton_tests_ensure_abilities_registered(): string {
	if ( ! did_action( 'init' ) ) {
		return 'WordPress init has not fired.';
	}

	if ( function_exists( 'wp_get_ability' ) && wp_get_ability( 'baton-test/echo' ) ) {
		return '';
	}

	if ( ! class_exists( 'WP_Ability_Categories_Registry' ) || ! class_exists( 'WP_Abilities_Registry' ) ) {
		return 'Abilities API registry classes are not available.';
	}

	$categories = WP_Ability_Categories_Registry::get_instance();
	$registry   = WP_Abilities_Registry::get_instance();

	if ( ! $categories || ! $registry ) {
		return 'Abilities API registries could not be initialized.';
	}

	if ( ! $categories->is_registered( 'baton-test' ) ) {
		$category = $categories->register( 'baton-test', baton_tests_get_category_args() );

		if ( ! $category ) {
			return 'Failed to register ability category baton-test.';
		}
	}

	if ( ! $registry->is_registered( 'baton-test/echo' ) ) {
		$ability = $registry->register( 'baton-test/echo', baton_tests_get_echo_ability_args() );

		if ( ! $ability ) {
			return 'Failed to register ability baton-test/echo.';
		}
	}

	if ( ! $registry->is_registered( 'baton-test/order-producer' ) ) {
		$ability = $registry->register( 'baton-test/order-producer', baton_tests_get_order_producer_args() );

		if ( ! $ability ) {
			return 'Failed to register ability baton-test/order-producer.';
		}
	}

	if ( ! $registry->is_registered( 'baton-test/orders-producer' ) ) {
		$ability = $registry->register( 'baton-test/orders-producer', baton_tests_get_orders_producer_args() );

		if ( ! $ability ) {
			return 'Failed to register ability baton-test/orders-producer.';
		}
	}

	if ( ! $registry->is_registered( 'baton-test/order-consumer' ) ) {
		$ability = $registry->register( 'baton-test/order-consumer', baton_tests_get_order_consumer_args() );

		if ( ! $ability ) {
			return 'Failed to register ability baton-test/order-consumer.';
		}
	}

	if ( ! $registry->is_registered( 'baton-test/order-ids-consumer' ) ) {
		$ability = $registry->register( 'baton-test/order-ids-consumer', baton_tests_get_order_ids_consumer_args() );

		if ( ! $ability ) {
			return 'Failed to register ability baton-test/order-ids-consumer.';
		}
	}

	if ( ! $registry->is_registered( 'baton-test/process-order' ) ) {
		$ability = $registry->register( 'baton-test/process-order', baton_tests_get_process_order_args() );

		if ( ! $ability ) {
			return 'Failed to register ability baton-test/process-order.';
		}
	}

	return '';
}

add_action( 'wp_abilities_api_categories_init', 'baton_tests_register_ability_category', 0 );
add_action( 'wp_abilities_api_init', 'baton_tests_register_abilities', 0 );
