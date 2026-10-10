/**
 * ESLint flat config. Extends the @wordpress/scripts default with
 * project-level rule overrides.
 */
const { hasBabelConfig } = require( '@wordpress/scripts/utils' );
const wpPlugin = require( '@wordpress/eslint-plugin' );

const config = [
	// Global ignores.
	{
		ignores: [
			'**/build/**',
			'**/node_modules/**',
			'**/vendor/**',
			'assets/**',
		],
	},

	// Base recommended config from @wordpress/eslint-plugin.
	...wpPlugin.configs.recommended,

	// Project rule overrides.
	{
		rules: {
			'jsdoc/require-returns-description': 'off',
			// Core's components build only ships ToggleGroupControl under its
			// __experimental names; the stable names do not exist in this
			// @wordpress/components version (importing them resolves to
			// undefined and crashes with React error #130 at runtime).
			'@wordpress/no-unsafe-wp-apis': 'off',
		},
	},
];

// If the project has no Babel config, provide defaults (matches wp-scripts).
if ( ! hasBabelConfig() ) {
	config.push( {
		languageOptions: {
			parserOptions: {
				requireConfigFile: false,
				babelOptions: {
					presets: [
						require.resolve( '@wordpress/babel-preset-default' ),
					],
				},
			},
		},
	} );
}

module.exports = config;