import { autocompletion, closeBrackets, closeBracketsKeymap, completionKeymap } from '@codemirror/autocomplete';
import { defaultKeymap, history, historyKeymap, indentWithTab } from '@codemirror/commands';
import { css } from '@codemirror/lang-css';
import { html } from '@codemirror/lang-html';
import { javascript } from '@codemirror/lang-javascript';
import { bracketMatching, defaultHighlightStyle, foldGutter, foldKeymap, indentOnInput, indentUnit, syntaxHighlighting } from '@codemirror/language';
import { linter, lintGutter, lintKeymap } from '@codemirror/lint';
import { highlightSelectionMatches, openSearchPanel, searchKeymap } from '@codemirror/search';
import { EditorState, Transaction } from '@codemirror/state';
import { oneDark } from '@codemirror/theme-one-dark';
import { drawSelection, EditorView, highlightActiveLine, highlightActiveLineGutter, highlightSpecialChars, keymap, lineNumbers, rectangularSelection } from '@codemirror/view';
import { parse } from 'acorn';
import { useEffect, useRef, useState } from '@wordpress/element';
import { config } from './api';

const mcsLanguages = {
    html: html({ autoCloseTags: config.closeBrackets }),
    css: css(),
    js: javascript(),
};
const mcsKeys = Object.keys(mcsLanguages);
const mcsPrefix = '(function(window, document, root, instanceId) {\n';

function lintJavaScript(view) {
    try {
        parse(mcsPrefix + view.state.doc + '\n});', { ecmaVersion: 'latest' });
        return [];
    } catch (error) {
        const from = Math.max(0, Math.min(error.pos - mcsPrefix.length, view.state.doc.length));
        return [{ from, to: Math.min(from + 1, view.state.doc.length), severity: 'error', message: error.message.replace(/\(\d+:\d+\)$/, '') }];
    }
}

function makeState(key, value, change) {
    return EditorState.create({
        doc: value,
        extensions: [
            lineNumbers(), highlightActiveLineGutter(), highlightSpecialChars(), history(), foldGutter(),
            drawSelection(), rectangularSelection(), highlightActiveLine(), highlightSelectionMatches(),
            EditorState.allowMultipleSelections.of(true), bracketMatching(), autocompletion(),
            syntaxHighlighting(defaultHighlightStyle, { fallback: true }), mcsLanguages[key],
            EditorState.tabSize.of(config.tabSize), indentUnit.of(' '.repeat(config.tabSize)),
            EditorView.contentAttributes.of({ 'aria-label': { html: 'HTML', css: 'CSS', js: 'JavaScript' }[key] + ' source' }),
            EditorView.updateListener.of((event) => { if (event.docChanged) change(key, event.state.doc.toString(), event.transactions); }),
            keymap.of([...closeBracketsKeymap, ...defaultKeymap, ...searchKeymap, ...historyKeymap, ...foldKeymap, ...completionKeymap, ...lintKeymap, indentWithTab]),
            config.indent ? indentOnInput() : [],
            config.closeBrackets ? closeBrackets() : [],
            config.wrap ? EditorView.lineWrapping : [],
            config.theme === 'dark' ? oneDark : [],
            key === 'js' ? [lintGutter(), linter(lintJavaScript)] : [],
        ],
    });
}

export function CodeEditor({ controls, active, visible, attrs, onChange }) {
    const host = useRef(null);
    const view = useRef(null);
    const states = useRef({});
    const scroll = useRef({});
    const current = useRef(active);
    const callback = useRef(onChange);
    const [overview, setOverview] = useState('');
    callback.current = onChange;

    function updateOverview() {
        if (config.minimap && view.current) setOverview(view.current.state.doc.sliceString(0, 24000));
    }

    useEffect(() => {
        const change = (key, text, transactions) => {
            if (!transactions.every((tr) => tr.annotation(Transaction.userEvent) === 'mcs.external')) callback.current(key, text);
            updateOverview();
        };
        states.current = Object.fromEntries(mcsKeys.map((key) => [key, makeState(key, attrs[key], change)]));
        view.current = new EditorView({ state: states.current[active], parent: host.current });
        updateOverview();
        controls.current = {
            search: () => openSearchPanel(view.current),
            text: (key) => (current.current === key ? view.current.state : states.current[key]).doc.toString(),
            async format(key, source) {
                const { formatSource } = await import(/* webpackChunkName: "mcs-format" */ './format');
                const text = await formatSource(key, source);
                const state = current.current === key ? view.current?.state : states.current[key];
                if (!view.current || !state || state.doc.toString() !== source || text === source) return;
                const tx = state.update({ changes: { from: 0, to: state.doc.length, insert: text }, annotations: Transaction.userEvent.of('input.format') });
                if (current.current === key) view.current.dispatch(tx);
                else {
                    states.current[key] = tx.state;
                    callback.current(key, text);
                }
            },
        };
        return () => {
            view.current.destroy();
            view.current = null;
            controls.current = null;
        };
    }, []);

    useEffect(() => {
        const editor = view.current;
        if (!editor || active === current.current) return;
        const previous = current.current;
        states.current[previous] = editor.state;
        scroll.current[previous] = [editor.scrollDOM.scrollTop, editor.scrollDOM.scrollLeft];
        current.current = active;
        editor.setState(states.current[active]);
        [editor.scrollDOM.scrollTop, editor.scrollDOM.scrollLeft] = scroll.current[active] || [0, 0];
        updateOverview();
    }, [active]);

    useEffect(() => {
        if (!view.current) return;
        for (const key of mcsKeys) {
            const isActive = key === current.current;
            const state = isActive ? view.current.state : states.current[key];
            if (state.doc.toString() === attrs[key]) continue;
            const transaction = state.update({
                changes: { from: 0, to: state.doc.length, insert: attrs[key] },
                annotations: [Transaction.userEvent.of('mcs.external'), Transaction.addToHistory.of(false)],
            });
            if (isActive) view.current.dispatch(transaction);
            else states.current[key] = transaction.state;
        }
    }, [attrs.html, attrs.css, attrs.js]);

    useEffect(() => {
        if (visible) view.current?.requestMeasure();
    }, [visible]);

    return (
        <div className="mcs-workspace" style={{ '--mcs-editor-height': `${config.height}px`, '--mcs-font-size': `${config.fontSize}px` }}>
            <div ref={host} className="mcs-code-host" />
            {config.minimap && <pre className="mcs-minimap" aria-hidden="true">{overview}</pre>}
        </div>
    );
}
