const studio = window.MCS ||= {};
const initialized = studio.initialized ||= new Set();
const wrappers = new Map(Array.from(document.querySelectorAll('[data-mcs-instance]'),
    (element) => [element.dataset.mcsInstance, element]));
const starts = new Map(Array.from(document.querySelectorAll('template[data-mcs-start]'),
    (element) => [element.dataset.mcsStart, element]));

function findRoot(id, selector, wrapped) {
    if (wrapped) {
        const element = wrappers.get(id);
        if (!element || !selector) return element || null;
        return element.matches(selector) ? element : element.querySelector(selector);
    }
    if (!selector) return document;
    let element = starts.get(id)?.nextElementSibling;
    while (element && element.dataset.mcsEnd !== id) {
        if (element.matches(selector)) return element;
        const match = element.querySelector(selector);
        if (match) return match;
        element = element.nextElementSibling;
    }
    return null;
}

function run(instanceId, selector, wrapped, initialize) {
    const key = pageId + '/' + instanceId;
    if (initialized.has(key)) return;
    initialized.add(key);
    try {
        const result = initialize.call(window, window, document, findRoot(instanceId, selector, wrapped), instanceId);
        if (result && typeof result.then === 'function') {
            Promise.resolve(result).catch((error) => reportError(instanceId, error));
        }
    } catch (error) {
        reportError(instanceId, error);
    }
}

function reportError(instanceId, error) {
    console.error('[MCS]', instanceId, error);
    window.MCSPreview?.error(instanceId + ': ' + error);
}
