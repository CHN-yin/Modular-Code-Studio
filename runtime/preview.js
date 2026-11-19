(() => {
    const errors = [];
    window.addEventListener('error', (event) => {
        const message = event.message || event.target?.src || event.target?.href;
        if (message && errors.length < 20) errors.push(String(message).slice(0, 800));
    }, true);
    window.addEventListener('unhandledrejection', (event) => {
        if (errors.length < 20) errors.push(String(event.reason).slice(0, 800));
    });
    window.MCSPreview = {
        error(message) {
            if (errors.length < 20) errors.push(String(message).slice(0, 800));
        },
        finish(data, code) {
            if (code) {
                const script = document.createElement('script');
                script.id = 'mcs-preview-js';
                script.nonce = document.currentScript?.nonce || '';
                script.textContent = code;
                document.body.appendChild(script);
            }
            const report = () => {
                if (data.mode === 'module') {
                    const key = data.target + ':1';
                    const wrapper = Array.from(document.querySelectorAll('[data-mcs-instance]'))
                        .find((element) => element.dataset.mcsInstance === key);
                    const start = Array.from(document.querySelectorAll('template[data-mcs-start]'))
                        .find((element) => element.dataset.mcsStart === key);
                    const target = wrapper || start?.nextElementSibling;
                    if (target && target.dataset.mcsEnd !== key) target.scrollIntoView({ block: 'start' });
                    else errors.push('The theme did not render a visible target module.');
                }
                const resources = Array.from(document.querySelectorAll('link[rel="stylesheet"], style, script[src]'))
                    .slice(0, 300)
                    .map((element) => ({ id: element.id, url: element.href || element.src || '', tag: element.localName }));
                window.parent.postMessage({ type: 'mcs-preview', ...data, resources, errors, width: innerWidth }, '*');
            };
            if (document.readyState === 'complete') report();
            else {
                window.addEventListener('load', report, { once: true });
                setTimeout(() => {
                    if (document.readyState !== 'complete') report();
                }, 5000);
            }
        },
    };
})();
