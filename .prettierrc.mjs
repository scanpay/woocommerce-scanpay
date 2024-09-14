import wpConfig from '@wordpress/prettier-config';

export default {
	...wpConfig,
	overrides: [
		{
			files: '*.ts',
			options: {
				printWidth: 120,
			},
		},
	],
};
