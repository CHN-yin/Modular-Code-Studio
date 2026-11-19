import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import Edit from './edit';
import './editor.css';

registerBlockType(metadata.name, {
    ...metadata,
    title: __('Modular Code Module', 'modular-code-studio'),
    edit: Edit,
    save: () => null,
});

