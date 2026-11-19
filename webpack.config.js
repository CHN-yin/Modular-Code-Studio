const path = require('path');
const base = require('@wordpress/scripts/config/webpack.config');

module.exports = {
    ...base,
    entry: async () => ({
        ...(typeof base.entry === 'function' ? await base.entry() : base.entry),
        'asset-sidebar/index': path.resolve(__dirname, 'src/asset-sidebar/index.js'),
    }),
};
