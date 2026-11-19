import { registerPlugin } from '@wordpress/plugins';
import AssetStatus from '../code-module/asset-status';

if (window.MCS?.editor?.canCode && window.MCS.editor.postAssets) {
    registerPlugin('mcs-asset-status', { render: AssetStatus, icon: 'editor-code' });
}
