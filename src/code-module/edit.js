import { InspectorControls, useBlockProps, store as blockEditorStore } from '@wordpress/block-editor';
import { Button, Notice, PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { config } from './api';
import { LivePreview } from './live-preview';

const t = (text) => __(text, 'modular-code-studio');
const languages = { html: 'HTML', css: 'CSS', js: 'JavaScript' };

function firstInstance(blocks, id) {
    for (const block of blocks) {
        if (block.name === 'mcs/code-module' && block.attributes.instanceId === id) return block.clientId;
        const child = firstInstance(block.innerBlocks, id);
        if (child) return child;
    }
    return null;
}

function Toggle({ attribute, label, attrs, update }) {
    return <ToggleControl label={t(label)} checked={attrs[attribute]} onChange={(value) => update({ [attribute]: value })} />;
}

export default function Edit({ attributes, setAttributes, clientId }) {
    const blockProps = useBlockProps({ className: 'mcs-studio-block' });
    const blockId = attributes.instanceId;
    const { postId, first } = useSelect((select) => {
        const blocks = select(blockEditorStore);
        const rootId = blocks.getBlockRootClientId(clientId);
        return {
            postId: config.postAssets ? select('core/editor')?.getCurrentPostId?.() : undefined,
            first: blockId ? firstInstance(blocks.getBlocks(), blockId) || firstInstance(blocks.getBlocks(rootId), blockId) : null,
        };
    }, [blockId, clientId]);
    const [opened, setOpened] = useState(false);
    const [mode, setMode] = useState('code');
    const [tab, setTab] = useState('html');
    const [Editor, setEditor] = useState(null);
    const [error, setError] = useState('');
    const [formatting, setFormatting] = useState(false);
    const [expanded, setExpanded] = useState(false);
    const shell = useRef(null);
    const editor = useRef(null);
    const attrsNow = useRef(attributes);
    attrsNow.current = attributes;

    useEffect(() => {
        if (!config.canCode || (blockId && (!first || first === clientId))) return;
        // A client ID is already a fresh, persisted UUID when Gutenberg duplicates a block.
        setAttributes({ instanceId: clientId });
    }, [blockId, clientId, first, setAttributes]);

    useEffect(() => {
        if (!opened) return undefined;
        let mounted = true;
        import(/* webpackChunkName: "mcs-code-editor" */ './code-editor').then(({ CodeEditor }) => {
            if (mounted) setEditor(() => CodeEditor);
        }).catch(() => {
            if (mounted) setError(t('The code editor could not load. Reload the editor and try again.'));
        });
        return () => { mounted = false; };
    }, [opened]);

    useEffect(() => {
        if (!expanded) return undefined;
        const doc = shell.current?.ownerDocument;
        const closeOnEscape = (event) => {
            if (event.key === 'Escape' && !event.defaultPrevented) setExpanded(false);
        };
        doc?.addEventListener('keydown', closeOnEscape);
        return () => doc?.removeEventListener('keydown', closeOnEscape);
    }, [expanded]);

    const activate = (next) => {
        if (next === 'code') setOpened(true);
        setMode(next);
    };
    const previewAllowed = config.preview && config.supported && attributes.enablePreview && postId && blockId;

    async function format() {
        if (!editor.current) return;
        const before = editor.current.text(tab);
        setFormatting(true);
        setError('');
        try {
            await editor.current.format(tab, before);
        } catch (exception) {
            setError(exception.message || t('This buffer could not be formatted.'));
        } finally {
            setFormatting(false);
        }
    }

    if (!config.canCode) {
        return <div {...blockProps}><p>{attributes.moduleName || t('Modular Code Module')}</p><p>{t('Editing this module requires the unfiltered HTML capability.')}</p></div>;
    }
    return (
        <div {...blockProps}>
            <InspectorControls>
                <PanelBody title={t('Module settings')}>
                    {!config.preview && <p>{t('Preview this shared content on a frontend page where it is displayed.')}</p>}
                    <TextControl label={t('Module name')} value={attributes.moduleName} onChange={(moduleName) => setAttributes({ moduleName })} />
                    {[
                        ['enableHtml', 'Enable HTML'], ['enableCss', 'Enable CSS'], ['enableJs', 'Enable JavaScript'],
                        ['enableShortcode', 'Enable shortcodes'], ['enablePreview', 'Enable live preview'],
                        ['wrapper', 'Wrap module HTML'],
                    ].map(([key, label]) => <Toggle key={key} attribute={key} label={label} attrs={attributes} update={setAttributes} />)}
                    <TextControl label={t('JavaScript dependencies')} help={t('Registered script handles, separated by spaces or commas.')} value={attributes.dependencies} onChange={(dependencies) => setAttributes({ dependencies })} />
                    <TextControl label={t('Custom root selector')} value={attributes.rootSelector} onChange={(rootSelector) => setAttributes({ rootSelector })} />
                    <TextControl label={t('Module instance ID')} value={blockId} readOnly />
                </PanelBody>
            </InspectorControls>
            <section ref={shell} className={`mcs-shell mcs-theme-${config.theme}${expanded ? ' mcs-expanded' : ''}`} aria-label={t('Modular code editor')}>
                <header className="mcs-header">
                    <span className="mcs-brand-mark" aria-hidden="true">{'{ }'}</span>
                    <input className="mcs-module-name" aria-label={t('Module name')} placeholder={t('Untitled module')} value={attributes.moduleName} onChange={(event) => setAttributes({ moduleName: event.target.value })} />
                    <div className="mcs-actions">
                        <Button className="mcs-button" aria-pressed={mode === 'code'} onClick={() => activate('code')}>{t('Code')}</Button>
                        {previewAllowed && <Button className="mcs-button" aria-pressed={mode === 'preview'} onClick={() => activate('preview')}>{t('Preview')}</Button>}
                        <Button className="mcs-button" onClick={() => setExpanded(!expanded)}>{expanded ? t('Exit full screen') : t('Full screen')}</Button>
                    </div>
                </header>
                {error && <Notice status="error" onRemove={() => setError('')}>{error}</Notice>}
                <div hidden={mode === 'preview'}>
                    <div className="mcs-toolbar">
                        <div role="tablist" aria-label={t('Code language')} className="mcs-tabs" onKeyDown={(event) => {
                            const keys = Object.keys(languages);
                            const index = keys.indexOf(tab);
                            const next = { ArrowLeft: keys[(index + 2) % 3], ArrowRight: keys[(index + 1) % 3], Home: keys[0], End: keys[2] }[event.key];
                            if (!next) return;
                            event.preventDefault();
                            setTab(next);
                            setOpened(true);
                            shell.current?.querySelector(`#mcs-${clientId}-tab-${next}`)?.focus();
                        }}>
                            {Object.entries(languages).map(([key, label]) => <button type="button" key={key} role="tab"
                                id={`mcs-${clientId}-tab-${key}`} aria-controls={`mcs-${clientId}-panel`} aria-selected={tab === key} tabIndex={tab === key ? 0 : -1}
                                onClick={() => { setTab(key); setOpened(true); }}>{label}</button>)}
                        </div>
                        <div className="mcs-actions">
                            {opened && Editor && <Button className="mcs-button" onClick={() => editor.current?.search()}>{t('Find / replace')}</Button>}
                            {opened && Editor && config.format && <Button className="mcs-button" disabled={formatting} onClick={format}>{formatting ? t('Formatting…') : t('Format')}</Button>}
                        </div>
                    </div>
                    <div id={`mcs-${clientId}-panel`} role="tabpanel" aria-labelledby={`mcs-${clientId}-tab-${tab}`}>
                        {opened && Editor && <Editor controls={editor} active={tab} visible={mode === 'code'} attrs={attributes} onChange={(key, value) => {
                            if (attrsNow.current[key] !== value) setAttributes({ [key]: value });
                        }} />}
                        {opened && !Editor && !error && <p className="mcs-editor-placeholder" role="status">{t('Loading editor…')}</p>}
                        {!opened && <div className="mcs-editor-placeholder"><Button className="mcs-button mcs-primary" onClick={() => setOpened(true)}>{t('Open code editor')}</Button></div>}
                    </div>
                </div>
                {mode === 'preview' && previewAllowed && <LivePreview id={postId} attrs={attributes} />}
                {mode === 'preview' && !previewAllowed && <p className="mcs-editor-placeholder">{t('Live preview is disabled for this module.')}</p>}
            </section>
        </div>
    );
}
