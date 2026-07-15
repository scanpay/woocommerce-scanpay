/*
 *	2024-09-11: This is a temporary solution to the eslint problem.
 *	Woo and WP still use the old eslint config format ...
 *	so we try our best to implement their rules here.
 */

import globals from 'globals';
import pluginJs from '@eslint/js';
import tseslint from 'typescript-eslint';

export default [
	{
		ignores: ['build/**', 'node_modules/**', 'vendor/**'],
	},
	pluginJs.configs.recommended,
	...tseslint.configs.recommended,
	{
		files: ['src/**/*.ts'],
		languageOptions: {
			globals: {
				...globals.browser,
			},
			parserOptions: {
				ecmaVersion: 2020,
				sourceType: 'module',
			},
		},
		rules: {
			'one-var': 'off',
			'sort-keys': 'off',
			'func-style': ['error', 'declaration'],
			'max-statements': ['warn', 15, { ignoreTopLevelFunctions: true }],
			'no-inline-comments': 'off',
			'no-ternary': 'off',
			'no-magic-numbers': 'off',
			'capitalized-comments': 'off',
			'no-nested-ternary': 'off',
			'no-plusplus': 'off',
			'id-length': 'off',
			'@typescript-eslint/no-explicit-any': 'off', // allow `any` tmp
		},
	},
];
