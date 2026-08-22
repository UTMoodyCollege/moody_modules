const path = require('path');
const TerserPlugin = require('terser-webpack-plugin');
const webpack = require('webpack');

module.exports = {
  mode: 'production',
  optimization: {
    minimize: true,
    minimizer: [
      new TerserPlugin({
        terserOptions: { format: { comments: false } },
        extractComments: false,
      }),
    ],
  },
  entry: {
    path: path.resolve(__dirname, 'js/ckeditor5_plugins/moodyAiGenerate/src/index.js'),
  },
  output: {
    path: path.resolve(__dirname, 'js/build'),
    filename: 'moodyAiGenerate.js',
    library: ['CKEditor5', 'moodyAiGenerate'],
    libraryTarget: 'umd',
    libraryExport: 'default',
  },
  plugins: [
    new webpack.DllReferencePlugin({
      manifest: require('./node_modules/ckeditor5/build/ckeditor5-dll.manifest.json'),
      scope: 'ckeditor5/src',
      name: 'CKEditor5.dll',
    }),
  ],
};
