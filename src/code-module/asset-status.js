import { Button, Modal, Notice, PanelBody, Spinner, ToggleControl } from '@wordpress/components';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { useSelect } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import { PluginSidebar, store as editorStore } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { config, request } from './api';
import { getDiagnostics, LivePreview } from './live-preview';

const t = (text) => __(text, 'modular-code-studio');
const title = t('Modular Code Studio');
const kinds = ['css', 'js'];

function count(blocks, counts = { all: 0, html: 0, css: 0, js: 0 }) {
    for (const block of blocks) {
        if (block.name === 'mcs/code-module') {
            const attrs = block.attributes;
            counts.all++;
            for (const [key, flag] of [['html', 'enableHtml'], ['css', 'enableCss'], ['js', 'enableJs']]) {
                if (attrs[flag] && attrs[key].trim()) counts[key]++;
            }
        }
        count(block.innerBlocks, counts);
    }
    return counts;
}

function extensionStatus(ext) {
    if (!ext?.installed) return t('Not detected');
    return ext.version + ' · ' + (ext.active ? t('Active') : t('Inactive'));
}

function BundleDetails({ kind, info }) {
    const asset = info[kind];
    const issues = info.issues[kind] || [];
    return <div className="mcs-bundle-details">
        <strong>{kind.toUpperCase()}</strong>
        <p>{asset ? `${(asset.bytes / 1024).toFixed(2)} KiB` : t('No bundle file')}</p>
        {asset && <><p><a href={asset.url} target="_blank" rel="noreferrer">{t('Open asset URL')}</a></p><code>{asset.hash}</code></>}
        {issues.map(([module, error], index) => <p key={index}>{module} — {error}</p>)}
    </div>;
}

export default function AssetStatus() {
    const { id, saving, dirty, counts } = useSelect((select) => {
        const editor = select(editorStore);
        const blocks = select(blockEditorStore).getBlocks();
        return { id: editor.getCurrentPostId(), saving: editor.isSavingPost(), dirty: editor.isEditedPostDirty(), counts: count(blocks) };
    }, []);
    const [info, setInfo] = useState(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [modal, setModal] = useState('');
    const [diag, setDiag] = useState(null);
    const [copy, setCopy] = useState('');
    useEffect(() => {
        if (!id || saving) return undefined;
        const abort = new AbortController();
        request(id, 'status', undefined, abort.signal).then(setInfo).catch((err) => {
            if (!abort.signal.aborted) setError(err.message);
        });
        return () => abort.abort();
    }, [id, saving]);

    useEffect(() => {
        const change = () => setDiag(getDiagnostics(id));
        change();
        window.addEventListener('mcs-preview-diagnostics', change);
        return () => window.removeEventListener('mcs-preview-diagnostics', change);
    }, [id]);

    async function rebuild(kind) {
        setBusy(true);
        setError('');
        try {
            setInfo(await request(id, 'rebuild', { kind }));
        } catch (exception) {
            setError(exception.message);
        } finally {
            setBusy(false);
        }
    }

    async function setOption(key, value) {
        setBusy(true);
        setError('');
        try {
            const options = await request(id, 'litespeed', { ...info.integrations.litespeed.options, [key]: value });
            const next = await request(id, 'status');
            setInfo({ ...next, integrations: { ...next.integrations, litespeed: { ...next.integrations.litespeed, options } } });
        } catch (exception) {
            setError(exception.message);
        } finally {
            setBusy(false);
        }
    }

    const shownCounts = dirty || !info ? counts : info.counts;
    const litespeed = info?.integrations.litespeed;
    return (
        <PluginSidebar name="mcs-page-assets" title={title}>
            <div className="mcs-sidebar">
                {error && <Notice status="error" onRemove={() => setError('')}>{error}</Notice>}
                <PanelBody title={t('Page assets')}>
                    <dl className="mcs-counts">
                        {Object.entries({ all: t('Modules'), html: 'HTML', css: 'CSS', js: 'JavaScript' }).map(([key, label]) => <div key={key}><dt>{label}</dt><dd>{shownCounts[key]}</dd></div>)}
                    </dl>
                    {info ? <>
                        {info.error && <p role="alert">{info.error}</p>}
                        {kinds.map((kind) => <BundleDetails key={kind} kind={kind} info={info} />)}
                        {info.generated && <p>{t('Last generated')} {new Date(info.generated).toLocaleString()}</p>}
                    </> : !error && <Spinner />}
                    {dirty && <p>{t('Rebuild uses the last saved page. Save to include your changes.')}</p>}
                    <div className="mcs-sidebar-actions">
                        {config.bundling && ['css', 'js', 'all'].map((kind) => <Button key={kind} variant="secondary" disabled={busy || saving || !id} onClick={() => rebuild(kind)}>
                            {kind === 'all' ? t('Rebuild all') : t('Rebuild ') + kind.toUpperCase()}
                        </Button>)}
                        {config.preview && <Button variant="secondary" disabled={!id} onClick={() => setModal('preview')}>{t('Preview page')}</Button>}
                        <Button variant="secondary" onClick={() => setModal('diagnostics')}>{t('View diagnostics')}</Button>
                    </div>
                </PanelBody>
                <PanelBody title="WPCodeBox 2" initialOpen={false}>
                    <p>{extensionStatus(info?.integrations.wpcodebox)}</p>
                    <p>{t('Review the URLs and handles observed on the frontend in preview diagnostics.')}</p>
                </PanelBody>
                <PanelBody title="LiteSpeed Cache" initialOpen={false}>
                    <p>{extensionStatus(litespeed)}</p>
                    {litespeed?.lastPurgeRequest && <p>{t('Last URL purge request')} {new Date(litespeed.lastPurgeRequest).toLocaleString()}</p>}
                    {litespeed?.active && <p>{t('Check X-LiteSpeed-Cache on a public page request for cache status.')}</p>}
                    {litespeed?.options && [
                        ['optimize', 'Allow LiteSpeed JS optimization'], ['defer', 'Exclude bundle from defer'], ['delay', 'Exclude bundle from delay'],
                        ['guest', 'Exclude bundle from Guest Optimization delay'],
                    ].map(([key, label]) => <ToggleControl key={key} label={t(label)} checked={litespeed.options[key]} disabled={busy} onChange={(value) => setOption(key, value)} />)}
                    <p>{t('LiteSpeed uses one exclusion list for Deferred and Delayed modes.')}</p>
                    {info?.js && <p className="mcs-exclude-path"><code>{new URL(info.js.url).pathname}</code></p>}
                    <Button variant="secondary" disabled={!info?.selectors?.length} onClick={() => setModal('selectors')}>{t('UCSS selector allowlist')}</Button>
                </PanelBody>
            </div>
            {modal === 'preview' && <Modal title={t('Page preview')} onRequestClose={() => setModal('')} className="mcs-preview-modal"><LivePreview id={id} /></Modal>}
            {modal === 'diagnostics' && <Modal title={t('Frontend diagnostics')} onRequestClose={() => setModal('')} className="mcs-preview-modal">
                <p>{t('Requested dependencies')} {(info?.dependencies || []).join(', ') || '—'}</p>
                {diag ? <>
                    <p>{t('Viewport')} {diag.width} px</p>
                    {diag.missing?.length > 0 && <p>{t('Unknown handles')} {diag.missing.join(', ')}</p>}
                    {diag.errors?.map((item, i) => <p key={i}>{item}</p>)}
                    <h3>{t('WordPress handles and URLs')}</h3>
                    <pre className="mcs-diagnostics-list">{JSON.stringify(diag.handles, null, 2)}</pre>
                    <h3>{t('Rendered resources')}</h3>
                    <pre className="mcs-diagnostics-list">{JSON.stringify(diag.resources, null, 2)}</pre>
                    <h3>{t('Build diagnostics')}</h3>
                    <pre className="mcs-diagnostics-list">{JSON.stringify(diag.issues, null, 2)}</pre>
                </> : <p>{t('Open a preview to capture the frontend resources.')}</p>}
            </Modal>}
            {modal === 'selectors' && <Modal title={t('UCSS selector allowlist')} onRequestClose={() => { setCopy(''); setModal(''); }}>
                <p>{t('In LiteSpeed Cache, open Page Optimization → Tuning - CSS → UCSS Allowlist. Paste the selectors needed for interactive states.')}</p>
                <textarea className="mcs-selector-list" aria-label={t('CSS selectors')} rows={14} readOnly value={info.selectors.join('\n')} />
                <Button variant="primary" onClick={async () => {
                    try {
                        await navigator.clipboard.writeText(info.selectors.join('\n'));
                        setCopy(t('Copied'));
                    } catch {
                        setCopy(t('Select and copy the text from the list.'));
                    }
                }}>{t('Copy selectors')}</Button> <span role="status">{copy}</span>
            </Modal>}
        </PluginSidebar>
    );
}
