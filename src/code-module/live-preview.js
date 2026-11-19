import { Button, Notice, SelectControl, ToggleControl } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { config, request } from './api';

const t = (text) => __(text, 'modular-code-studio');
const devices = { 1440: 'Desktop', 768: 'Tablet', 390: 'Mobile' };
const diagnostics = new Map();

export function getDiagnostics(id) {
    return diagnostics.get(String(id));
}

export function LivePreview({ id, attrs }) {
    const frame = useRef(null);
    const viewport = useRef(null);
    const channel = useRef('');
    const timeout = useRef(null);
    const [width, setWidth] = useState(config.previewWidth);
    const [mode, setMode] = useState(attrs ? config.previewMode : 'page');
    const [manual, refresh] = useState(0);
    const [auto, setAuto] = useState(true);
    const [status, setStatus] = useState(t('Connecting to the front page…'));
    const [error, setError] = useState('');
    const [available, setAvailable] = useState(0);
    const latest = useRef(attrs);
    latest.current = attrs;
    const frameName = useRef(`mcs-preview-${id}-${Math.random().toString(36).slice(2)}`);
    const source = auto ? JSON.stringify(attrs, (key, value) => key === 'signature' ? undefined : value) : '';

    useEffect(() => {
        const media = viewport.current;
        const owner = media.ownerDocument.defaultView;
        const resize = new owner.ResizeObserver(([measurement]) => setAvailable(measurement.contentRect.width));
        resize.observe(media);
        const receive = (event) => {
            const result = event.data;
            if (event.source !== frame.current?.contentWindow || event.origin !== 'null'
                || result?.type !== 'mcs-preview' || result.channel !== channel.current) return;
            clearTimeout(timeout.current);
            diagnostics.set(String(id), result);
            window.dispatchEvent(new Event('mcs-preview-diagnostics'));
            setStatus(result.errors?.length ? t('Preview loaded with warnings') : t('Preview loaded'));
        };
        owner.addEventListener('message', receive);
        return () => {
            owner.removeEventListener('message', receive);
            resize.disconnect();
            clearTimeout(timeout.current);
        };
    }, [id]);

    useEffect(() => {
        const abort = new AbortController();
        const update = async () => {
            setError('');
            setStatus(t('Loading preview…'));
            try {
                const session = await request(id, 'preview', { mode, module: latest.current }, abort.signal);
                if (abort.signal.aborted || !frame.current) return;
                const doc = frame.current.ownerDocument;
                const action = new URL(session.url);
                if (action.origin !== window.location.origin) throw new Error(t('The preview returned a different site origin.'));
                const form = doc.createElement('form');
                form.method = 'POST';
                form.action = action.href;
                form.target = frameName.current;
                form.hidden = true;
                const field = doc.createElement('input');
                field.name = 'mcs_preview';
                field.value = session.channel;
                form.appendChild(field);
                doc.body.appendChild(form);
                channel.current = session.channel;
                clearTimeout(timeout.current);
                form.submit();
                form.remove();
                timeout.current = setTimeout(() => {
                    setStatus('');
                    setError(t('The front page did not confirm the preview. Check the frame, then try refreshing.'));
                }, 15000);
            } catch (exception) {
                if (!abort.signal.aborted) {
                    setStatus('');
                    setError(exception.message || t('The preview could not load.'));
                }
            }
        };
        const timer = setTimeout(update, config.debounce);
        return () => {
            clearTimeout(timer);
            abort.abort();
        };
    }, [id, mode, manual, source]);

    const scale = Math.min(1, available ? available / width : 1);
    const height = Math.max(420, config.height);
    return (
        <section className="mcs-preview" aria-label={t('Live preview')}>
            <div className="mcs-preview-toolbar">
                <div className="mcs-devices" role="group" aria-label={t('Viewport size')}>
                    {[1440, 768, 390].map((value) => <Button key={value} className="mcs-button" aria-pressed={width === value} title={`${value}px`} onClick={() => setWidth(value)}>{t(devices[value])}</Button>)}
                </div>
                <span className="mcs-viewport-size">{width} px</span>
                {attrs && <SelectControl aria-label={t('Preview mode')} value={mode} options={[
                    { value: 'module', label: t('Focus module') }, { value: 'page', label: t('Full page') },
                ]} onChange={setMode} />}
                {attrs && <ToggleControl label={t('Auto refresh')} checked={auto} onChange={setAuto} />}
                <Button className="mcs-button" onClick={() => refresh((value) => value + 1)}>{t('Refresh')}</Button>
            </div>
            {error && <Notice status="error" isDismissible={false}>{error}</Notice>}
            <div className="mcs-preview-viewport" ref={viewport}>
                <div style={{ width: width * scale, height, marginInline: 'auto', overflow: 'hidden' }}>
                    <iframe name={frameName.current} ref={frame} title={t('Frontend page preview')} sandbox="allow-scripts" referrerPolicy="no-referrer"
                        width={width} height={Math.round(height / scale)}
                        style={{ width, height: Math.round(height / scale), transform: `scale(${scale})` }} />
                </div>
            </div>
            <p className="mcs-preview-status" role="status">{status}</p>
        </section>
    );
}
