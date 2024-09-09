const path = require( 'path' );
const glob = require( 'glob' );

module.exports = {
	entry: {
		checkout: glob.sync(
			path.resolve( __dirname, 'src', 'public', 'js', '**', 'index.ts' )
		),
	},
	module: {
		rules: [
			{
				test: /\.tsx?$/,
				use: 'ts-loader',
			},
		],
	},
	output: {
		filename: '[name].js',
		path: path.resolve( __dirname, 'build', 'public', 'js' ),
	},
};
