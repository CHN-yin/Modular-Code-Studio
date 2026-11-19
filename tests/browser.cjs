const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require(process.env.MCS_PLAYWRIGHT || 'playwright-core');

const base = process.env.MCS_TEST_ORIGIN || 'http://127.0.0.1:9483';
const bundle = process.env.MCS_TEST_ZIP || path.resolve(__dirname, '../../output/modular-code-studio.zip');
const origin = new URL(base);
const cf7 = process.env.MCS_TEST_CF7_ID;
assert.ok(['localhost', '127.0.0.1'].includes(origin.hostname), 'Run the fixture on a disposable local WordPress site.');

const code = (attrs) => '<!-- wp:mcs/code-module ' + JSON.stringify(attrs)
    .replaceAll('<', '\\u003c').replaceAll('>', '\\u003e').replaceAll('&', '\\u0026')
    .replaceAll('--', '\\u002d\\u002d') + ' /-->';
const moduleData = (index) => ({
    instanceId: 'fixture-' + index,
    moduleName: 'Fixture ' + index,
    html: `<div class="mcs-test-global mcs-test-conditional mcs-item-${index}">Module ${index} ${index === 1 ? '[mcs_acceptance]' : ''}</div>`
        + (index === 1 && cf7 ? `[contact-form-7 id="${cf7}"]` : ''),
    css: `.mcs-item-${index}{padding:${index}px}`,
    js: `(window.mcsSequence ||= []).push(${index});`,
    dependencies: 'jquery, mcs-fixture-script',
});

async function main() {
    const browser = await chromium.launch({
        executablePath: process.env.MCS_BROWSER || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
        headless: true,
        args: ['--disable-dev-shm-usage'],
    });
    const context = await browser.newContext();
    const page = await context.newPage();
    const failures = [];
    page.on('pageerror', (error) => failures.push(error.message));
    page.on('console', (msg) => { if (msg.type() === 'error') console.log('browser console:', msg.text().slice(0, 600)); });
    page.on('requestfailed', (req) => {
        if (req.failure()?.errorText !== 'net::ERR_ABORTED') console.log('request failed:', req.url(), req.failure()?.errorText);
    });
    page.setDefaultTimeout(20000);
    let nonce;

    async function api(route, method = 'GET', data, allowedStatus = 200) {
        const response = await context.request.fetch(`${base}/?rest_route=${route}`, {
            method, headers: { 'X-WP-Nonce': nonce }, data,
        });
        const text = await response.text();
        assert.equal(response.status(), allowedStatus, text.slice(0, 1500));
        return JSON.parse(text);
    }

    try {
        await page.goto(base + '/wp-login.php');
        await page.locator('#user_login').fill('admin');
        await page.locator('#user_pass').fill('mcs-local-fixture-admin');
        await page.locator('#wp-submit').click();
        await page.waitForURL(/wp-admin/);
        await page.goto(base + '/wp-admin/plugin-install.php?tab=upload');
        await page.locator('input[type=file][name=pluginzip]').setInputFiles(bundle);
        await page.getByRole('button', { name: 'Install Now' }).click();
        const replace = page.getByRole('link', { name: /Replace current with uploaded/ });
        const activate = page.getByRole('link', { name: 'Activate Plugin', exact: true });
        await Promise.race([replace.first().waitFor(), activate.first().waitFor()]);
        if (await replace.count()) await replace.first().click();
        if (await activate.count()) await activate.first().click();
        await page.goto(base + '/wp-admin/post-new.php?post_type=page');
        await page.waitForFunction(() => window.MCS?.editor?.canCode);
        nonce = await page.evaluate(() => window.MCS.editor.nonce);
        console.log('ZIP installed and activated; editor bootstrap and REST nonce loaded.');

        for (const slug of ['mcs-acceptance', 'mcs-acceptance-other']) {
            const existing = await api('/wp/v2/pages&slug=' + slug);
            for (const item of existing) await api('/wp/v2/pages/' + item.id + '&force=true', 'DELETE');
        }
        const modules = Array.from({ length: 5 }, (_, i) => moduleData(i + 1));
        const body = (items) => '<!-- wp:group --><div class="wp-block-group">\n' + items.map(code).join('\n') + '\n</div><!-- /wp:group -->';
        const first = await api('/wp/v2/pages', 'POST', { title: 'MCS Acceptance', slug: 'mcs-acceptance', status: 'publish', content: body(modules) }, 201);
        const second = await api('/wp/v2/pages', 'POST', { title: 'MCS Acceptance Other', slug: 'mcs-acceptance-other', status: 'publish', content: code(moduleData(9)) }, 201);
        const info = () => api(`/mcs/v1/posts/${first.id}/status`);
        const original = await info();
        const other = await api(`/mcs/v1/posts/${second.id}/status`);
        assert.equal(original.error, '');
        assert.deepEqual(original.counts, { all: 5, html: 5, css: 5, js: 5 });
        assert.ok(original.css.url.includes('/page-' + first.id + '/'));
        assert.ok(other.css.url.includes('/page-' + second.id + '/'));
        if (process.env.MCS_TEST_LITESPEED) {
            assert.equal(original.integrations.litespeed.version, process.env.MCS_TEST_LITESPEED);
            assert.equal(original.integrations.litespeed.active, true);
            const options = { optimize: true, defer: true, delay: false, guest: true };
            assert.deepEqual(await api(`/mcs/v1/posts/${first.id}/litespeed`, 'POST', options), options);
            assert.deepEqual((await info()).integrations.litespeed.options, options);
            assert.deepEqual(other.integrations.litespeed.options, { optimize: true, defer: false, delay: false, guest: false });
            console.log('LiteSpeed plugin version and page-local exclusion settings verified.');
        }
        for (const asset of [original.css, original.js, other.css, other.js]) {
            assert.equal((await context.request.get(asset.url)).status(), 200);
        }
        await page.goto(first.link);
        assert.equal(await page.locator('.mcs-shortcode').innerText(), 'Shortcode ' + first.id);
        assert.deepEqual(await page.evaluate(() => window.mcsSequence), [1, 2, 3, 4, 5]);
        assert.equal(await page.evaluate(() => window.mcsFixtureDependency), true);
        assert.equal(await page.locator('link[id^=mcs-page-]').count(), 1);
        assert.equal(await page.locator('script[id^=mcs-page-]').count(), 1);
        assert.equal(await page.locator('script[id=jquery-core-js]').count(), 1);
        assert.equal(await page.locator('.mcs-item-1').evaluate((e) => getComputedStyle(e).color), 'rgb(25, 46, 69)');
        assert.equal(await page.locator('.mcs-item-1').evaluate((e) => getComputedStyle(e).fontWeight), '700');
        if (cf7) {
            assert.equal(await page.locator('form.wpcf7-form').count(), 1);
            assert.equal(await page.locator('script#contact-form-7-js').count(), 1);
        }
        if (process.env.MCS_TEST_BLOCKSY) {
            assert.equal(await page.locator('header#header').count(), 1);
            assert.equal(await page.locator('footer#footer').count(), 1);
            assert.equal(await page.locator('link#mcs-test-child-css').count(), 1);
        }
        await page.goto(second.link);
        assert.deepEqual(await page.evaluate(() => window.mcsSequence), [9]);
        assert.notEqual(await page.locator('.mcs-item-9').evaluate((e) => getComputedStyle(e).fontWeight), '700');
        assert.equal(await page.locator(`[src*="page-${first.id}/"],[href*="page-${first.id}/"]`).count(), 0);
        console.log('Five nested modules, shortcode query context, registered dependency deduplication, and two-page isolation passed.');

        modules[0].css = '.mcs-item-1{padding:17px}';
        await api(`/wp/v2/pages/${first.id}`, 'POST', { content: body(modules) });
        const cssEdit = await info();
        assert.notEqual(cssEdit.css.hash, original.css.hash);
        assert.equal(cssEdit.js.hash, original.js.hash);
        modules[0].js = '(window.mcsSequence ||= []).push(100);';
        await api(`/wp/v2/pages/${first.id}`, 'POST', { content: body(modules) });
        const jsEdit = await info();
        assert.notEqual(jsEdit.js.hash, cssEdit.js.hash);
        assert.equal(jsEdit.css.hash, cssEdit.css.hash);
        modules.pop();
        await api(`/wp/v2/pages/${first.id}`, 'POST', { content: body(modules) });
        const removed = await info();
        assert.equal(removed.counts.all, 4);
        assert.doesNotMatch(await (await context.request.get(removed.css.url)).text(), /mcs-item-5/);
        assert.doesNotMatch(await (await context.request.get(removed.js.url)).text(), /fixture-5/);
        assert.equal((await context.request.get(original.css.url)).status(), 200);
        console.log('Independent content hashes, module removal and retired-file retention passed.');

        const previewRoute = `/mcs/v1/posts/${first.id}/preview`;
        const anon = await browser.newContext();
        try {
            assert.ok([401, 403].includes((await anon.request.post(`${base}/?rest_route=${previewRoute}`, { data: {} })).status()));
        } finally {
            await anon.close();
        }
        const session = await api(previewRoute, 'POST', { module: modules[0], mode: 'page' }, 201);
        assert.equal(session.url, first.link);
        const match = (await context.cookies()).find((cookie) => cookie.name === 'mcs_preview_' + session.channel);
        assert.ok(match?.httpOnly);
        assert.equal(match.sameSite, 'Strict');
        const response = await context.request.post(session.url, { form: { mcs_preview: session.channel } });
        assert.equal(response.status(), 200, (await response.text()).slice(0, 400));
        assert.match(response.headers()['cache-control'], /private, no-store/);
        assert.match(response.headers()['content-security-policy'], /sandbox allow-scripts/);
        assert.equal((await context.request.post(session.url, { form: { mcs_preview: session.channel } })).status(), 403);
        assert.equal((await context.request.post(second.link, { form: { mcs_preview: '0'.repeat(32) } })).status(), 403);
        console.log('Guest denial, canonical URL, Strict HttpOnly proof, no-store sandbox response and spent-token rejection passed.');

        await page.goto(`${base}/wp-admin/post.php?post=${first.id}&action=edit`);
        await page.waitForFunction(() => window.wp?.data?.select('core/block-editor')?.getBlocks().length > 0);
        const canvas = page.frameLocator('iframe[name="editor-canvas"]');
        const isFramed = await page.locator('iframe[name="editor-canvas"]').count();
        const surface = isFramed ? canvas : page;
        const shell = surface.locator('.mcs-shell').first();
        await shell.getByRole('button', { name: 'Open code editor' }).click();
        const editor = shell.locator('.cm-content');
        await editor.waitFor();
        assert.match(await editor.innerText(), /\[mcs_acceptance\]/);
        await shell.getByRole('tab', { name: 'CSS', exact: true }).click();
        assert.match(await editor.innerText(), /17px/);
        const newCss = '.mcs-item-1{color:rgb(34,66,99)} @media(max-width:500px){.mcs-item-1{color:rgb(200,30,20)}}';
        await editor.fill(newCss);
        await shell.getByRole('button', { name: 'Format', exact: true }).click();
        await page.waitForFunction(() => {
            const blocks = window.wp.data.select('core/block-editor').getBlocks();
            return blocks[0]?.innerBlocks[0]?.attributes.css?.includes('rgb(200, 30, 20)');
        }, null, { timeout: 20000 });
        assert.match(await editor.innerText(), /color: rgb\(200, 30, 20\)/);
        await shell.getByRole('button', { name: 'Find / replace' }).click();
        await shell.locator('.cm-search').waitFor();
        await shell.getByRole('tab', { name: 'JavaScript', exact: true }).click();
        assert.match(await editor.innerText(), /100/);
        await shell.getByRole('tab', { name: 'CSS', exact: true }).click();
        assert.match(await editor.innerText(), /200, 30, 20/);
        await shell.getByRole('button', { name: 'Full screen', exact: true }).click();
        assert.equal(await shell.evaluate((node) => getComputedStyle(node).position), 'fixed');
        await shell.getByRole('button', { name: 'Exit full screen' }).click();
        const previewResponse = page.waitForResponse((res) => res.url() === first.link && res.request().method() === 'POST');
        await shell.getByRole('button', { name: 'Preview', exact: true }).click();
        const browserResponse = await previewResponse;
        assert.equal(browserResponse.status(), 200, (await browserResponse.text()).slice(0, 800));
        await shell.getByText(/^Preview loaded(?: with warnings)?$/).waitFor();
        const frame = shell.frameLocator('iframe[title="Frontend page preview"]');
        assert.equal(await frame.locator('.mcs-shortcode').innerText(), 'Shortcode ' + first.id);
        assert.equal(await frame.locator('body').evaluate(() => innerWidth), 1440);
        assert.equal(await frame.locator('.mcs-item-1').evaluate((e) => getComputedStyle(e).color), 'rgb(34, 66, 99)');
        assert.equal(await frame.locator('.mcs-item-1').evaluate((e) => getComputedStyle(e).fontWeight), '700');
        assert.deepEqual(await frame.locator('body').evaluate(() => window.mcsSequence), [100, 2, 3, 4]);
        if (cf7) {
            assert.equal(await frame.locator('form.wpcf7-form').count(), 1);
            assert.equal(await frame.locator('script#contact-form-7-js').count(), 1);
        }
        if (process.env.MCS_TEST_BLOCKSY) {
            assert.equal(await frame.locator('header#header').count(), 1);
            assert.equal(await frame.locator('footer#footer').count(), 1);
            assert.equal(await frame.locator('link#mcs-test-child-css').count(), 1);
        }
        await shell.getByRole('button', { name: 'Tablet', exact: true }).click();
        assert.equal(await frame.locator('body').evaluate(() => innerWidth), 768);
        await shell.getByRole('button', { name: 'Mobile', exact: true }).click();
        assert.equal(await frame.locator('body').evaluate(() => innerWidth), 390);
        assert.equal(await frame.locator('.mcs-item-1').evaluate((e) => getComputedStyle(e).color), 'rgb(200, 30, 20)');
        assert.equal(await frame.locator('body').evaluate(() => {
            try { return parent.document.location.href; } catch (error) { return error.name; }
        }), 'SecurityError');
        assert.equal((await info()).css.hash, removed.css.hash);
        console.log('Editor tabs, local Prettier, search, full screen, real canonical preview, conditional and temporary CSS, 390px media query, and sandbox isolation passed.');
        if (cf7) console.log('Contact Form 7 displayed the native form and enqueued its JavaScript on the public page and in preview.');
        if (process.env.MCS_TEST_BLOCKSY) console.log('Blocksy header/footer and the active child stylesheet loaded on the public page and in preview.');

        const instance = await page.evaluate(() => {
            const store = window.wp.data.select('core/block-editor');
            const first = store.getBlocks()[0].innerBlocks[0];
            window.wp.data.dispatch('core/block-editor').duplicateBlocks([first.clientId]);
            return first.attributes.instanceId;
        });
        await page.waitForFunction((original) => {
            const [first, copy] = window.wp.data.select('core/block-editor').getBlocks()[0].innerBlocks;
            return first.attributes.instanceId === original && copy.attributes.css === first.attributes.css
                && copy.attributes.instanceId && copy.attributes.instanceId !== original;
        }, instance);
        await page.evaluate(async () => {
            const blocks = window.wp.data.select('core/block-editor').getBlocks()[0].innerBlocks;
            window.wp.data.dispatch('core/block-editor').removeBlock(blocks[1].clientId);
            await window.wp.data.dispatch('core/editor').savePost();
        });
        assert.notEqual((await info()).css.hash, removed.css.hash);
        await page.reload();
        await page.waitForFunction(() => window.wp?.data?.select('core/block-editor')?.getBlocks()[0]?.innerBlocks[0]?.attributes.css?.includes('rgb(200, 30, 20)'));
        console.log('Gutenberg duplicate gets its own instance ID, and formatted code survives the normal save and reload.');

        const author = await browser.newContext();
        try {
            const authorPage = await author.newPage();
            await authorPage.goto(base + '/wp-login.php');
            await authorPage.locator('#user_login').fill('mcs_fixture_author');
            await authorPage.locator('#user_pass').fill('mcs-local-fixture-author');
            await authorPage.locator('#wp-submit').click();
            await authorPage.waitForURL(/wp-admin/);
            await authorPage.goto(base + '/wp-admin/post-new.php');
            await authorPage.waitForFunction(() => window.MCS?.editor?.nonce);
            const authorNonce = await authorPage.evaluate(() => window.MCS.editor.nonce);
            const unauthorized = await author.request.post(base + '/?rest_route=/wp/v2/posts', {
                headers: { 'X-WP-Nonce': authorNonce },
                data: { title: 'MCS restricted editor', status: 'publish', content: code(moduleData(42)) },
            });
            assert.equal(unauthorized.status(), 201, (await unauthorized.text()).slice(0, 1000));
            const authorPost = await unauthorized.json();
            assert.doesNotMatch(authorPost.content.rendered, /mcs-item-42/);
            assert.equal((await author.request.post(`${base}/?rest_route=/mcs/v1/posts/${authorPost.id}/preview`, {
                headers: { 'X-WP-Nonce': authorNonce }, data: {},
            })).status(), 403);
        } finally {
            await author.close();
        }
        assert.deepEqual(failures, []);
        console.log('Restricted WordPress author cannot persist executable module source or create a preview.');
    } catch (error) {
        console.error('Browser errors:', failures);
        console.error('Current page:', page.url());
        const screenshot = process.env.MCS_TEST_SCREENSHOT;
        if (screenshot) await page.screenshot({ path: screenshot, fullPage: true, timeout: 4000 }).catch(() => {});
        throw error;
    } finally {
        await browser.close();
    }
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
