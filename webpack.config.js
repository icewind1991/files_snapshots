const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');

module.exports = {
	devtool: 'source-map',
	entry: path.join(__dirname, 'js', 'files_snapshots.js'),
	output: {
		path: path.resolve(__dirname, 'build'),
		publicPath: '/js/',
		filename: 'files_snapshots.js'
	},
	module: {
		rules: [
			{
				test: /\.css$/,
				use: [
					process.env.NODE_ENV !== 'production'
						? 'style-loader'
						: MiniCssExtractPlugin.loader,
					'css-loader'
				]
			},
			{
				test: /\.scss$/,
				use: ['style-loader', 'css-loader', 'sass-loader']
			},
			{
				test: /\.js$/,
				loader: 'babel-loader',
				exclude: /node_modules/
			},
			{
				test: /\.(png|jpg|gif|svg)$/,
				loader: 'file-loader',
				options: {
					name: '[name].[ext]?[hash]'
				}
			}, {
				test: /\.handlebars$/,
				loader: "handlebars-loader"
			}
		]
	},
	resolve: {
		extensions: ['*', '.js']
	}
};
