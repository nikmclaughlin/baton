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
 * Args for a "multiply" ability that takes object input {number, factor} and returns {result}.
 *
 * @return array<string, mixed>
 */
function baton_tests_get_multiply_ability_args(): array {
	return array(
		'label'               => 'Multiply',
		'description'         => 'Multiplies number by factor for Baton tests.',
		'category'            => 'baton-test',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'number'  => array( 'type' => 'integer' ),
				'factor'  => array( 'type' => 'integer' ),
			),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'result' => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => static function ( $input = null ) {
			$number = is_array( $input ) && isset( $input['number'] ) ? (int) $input['number'] : 0;
			$factor = is_array( $input ) && isset( $input['factor'] ) ? (int) $input['factor'] : 1;
			return array( 'result' => $number * $factor );
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
 * Args for a scalar "uppercase" ability that takes a string and returns it uppercased.
 *
 * @return array<string, mixed>
 */
function baton_tests_get_uppercase_ability_args(): array {
	return array(
		'label'               => 'Uppercase',
		'description'         => 'Uppercases a string for Baton tests.',
		'category'            => 'baton-test',
		'input_schema'        => array( 'type' => 'string' ),
		'output_schema'       => array( 'type' => 'string' ),
		'execute_callback'    => static function ( $input = null ) {
			return is_string( $input ) ? strtoupper( $input ) : '';
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
 * Args for an "append_tag" ability that takes {text, tag} and returns {text: "text [tag]"}.
 *
 * @return array<string, mixed>
 */
function baton_tests_get_append_tag_ability_args(): array {
	return array(
		'label'               => 'Append Tag',
		'description'         => 'Appends a tag to text for Baton tests.',
		'category'            => 'baton-test',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'text' => array( 'type' => 'string' ),
				'tag'  => array( 'type' => 'string' ),
			),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'text' => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => static function ( $input = null ) {
			$text = is_array( $input ) && isset( $input['text'] ) ? (string) $input['text'] : '';
			$tag  = is_array( $input ) && isset( $input['tag'] ) ? (string) $input['tag'] : '';
			return array( 'text' => $text . ' [' . $tag . ']' );
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
 * Args for a "fail" ability that always returns a WP_Error.
 *
 * @return array<string, mixed>
 */
function baton_tests_get_fail_ability_args(): array {
	return array(
		'label'               => 'Fail',
		'description'         => 'Always fails for Baton tests.',
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
			return new WP_Error( 'test_fail', 'Ability intentionally failed.' );
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
	wp_register_ability( 'baton-test/multiply', baton_tests_get_multiply_ability_args() );
	wp_register_ability( 'baton-test/uppercase', baton_tests_get_uppercase_ability_args() );
	wp_register_ability( 'baton-test/append-tag', baton_tests_get_append_tag_ability_args() );
	wp_register_ability( 'baton-test/fail', baton_tests_get_fail_ability_args() );
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

	$abilities = array(
		'baton-test/echo'      => 'baton_tests_get_echo_ability_args',
		'baton-test/multiply'   => 'baton_tests_get_multiply_ability_args',
		'baton-test/uppercase'  => 'baton_tests_get_uppercase_ability_args',
		'baton-test/append-tag' => 'baton_tests_get_append_tag_ability_args',
		'baton-test/fail'      => 'baton_tests_get_fail_ability_args',
	);

	foreach ( $abilities as $slug => $args_fn ) {
		if ( ! $registry->is_registered( $slug ) ) {
			$ability = $registry->register( $slug, $args_fn() );

			if ( ! $ability ) {
				return 'Failed to register ability ' . $slug . '.';
			}
		}
	}

	return '';
}

add_action( 'wp_abilities_api_categories_init', 'baton_tests_register_ability_category', 0 );
add_action( 'wp_abilities_api_init', 'baton_tests_register_abilities', 0 );