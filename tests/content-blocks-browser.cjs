const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require(process.env.MCS_PLAYWRIGHT || 'playwright-core');

const base = process.env.MCS_TEST_ORIGIN || 'http://127.0.0.1:9484';
assert.ok(['localhost', '127.0.0.1'].includes(new URL(base).hostname));

async function main() {
    const browser = await chromium.launch({
        executablePath: process.env.MCS_BROWSER || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
        headless: true,
    });
    const context = await browser.newContext();
    const page = await context.newPage();
    page.setDefaultNavigationTimeout(90000);
    page.setDefaultTimeout(45000);
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    const ids = [];
    let nonce;
    async function api(route, method = 'GET', data, expected = 200) {
        const response = await context.request.fetch(base + '/?rest_route=' + route, {
            method, data, headers: { 'X-WP-Nonce': nonce },
        });
        const text = await response.text();
        assert.equal(response.status(), expected, text.slice(0, 1200));
        return JSON.parse(text);
    }
    try {
        await page.goto(base + '/wp-login.php');
        await page.locator('#user_login').fill('admin');
        await page.locator('#user_pass').fill('mcs-local-fixture-admin');
        await page.locator('#wp-submit').click();
        await page.waitForURL(/wp-admin/);
        await page.goto(base + '/wp-admin/plugin-install.php?tab=upload');
        await page.locator('input[type=file][name=pluginzip]').setInputFiles(path.resolve(__dirname, '../../output/modular-code-studio.zip'));
        await page.getByRole('button', { name: 'Install Now' }).click();
        await page.getByRole('link', { name: /Replace current with uploaded/ }).click({ timeout: 30000 });
        await page.goto(base + '/wp-admin/post-new.php?post_type=ct_content_block');
        await page.waitForFunction(() => window.MCS?.editor?.supported && window.wp?.data?.select('core/block-editor'));
        nonce = await page.evaluate(() => window.MCS.editor.nonce);
        assert.equal(await page.evaluate(() => window.MCS.editor.contentBlock), true);
        assert.equal(await page.evaluate(() => window.MCS.editor.preview), false);
        const blockAttrs = {
            moduleName: 'Footer compatibility', instanceId: 'footer-browser',
            html: '<div class="mcs-footer-regression">Footer [mcs_acceptance]</div>',
            css: '.mcs-footer-regression { color: rgb(21, 63, 84); }',
            js: 'root.dataset.ready = "yes"; window.mcsFooterRuns = (window.mcsFooterRuns || 0) + 1;',
            rootSelector: '.mcs-footer-regression',
        };
        await page.waitForFunction(() => window.wp.data.select('core/block-editor').getInserterItems()
            .some((item) => item.name === 'mcs/code-module'));
        await page.evaluate((attrs) => {
            window.wp.data.dispatch('core/editor').editPost({ title: 'MCS Footer compatibility test' });
            window.wp.data.dispatch('core/block-editor').insertBlocks(window.wp.blocks.createBlock('mcs/code-module', attrs));
        }, blockAttrs);
        const canvas = page.frameLocator('iframe[name="editor-canvas"]');
        const surface = await page.locator('iframe[name="editor-canvas"]').count() ? canvas : page;
        const shell = surface.locator('.mcs-shell').first();
        await shell.getByRole('button', { name: 'Open code editor' }).click();
        await shell.locator('.cm-content').waitFor();
        assert.match(await shell.locator('.cm-content').innerText(), /mcs-footer-regression/);
        await page.evaluate(async () => {
            window.wp.data.dispatch('core/editor').editPost({ status: 'publish' });
            await window.wp.data.dispatch('core/editor').savePost();
        });
        await page.waitForFunction(() => !window.wp.data.select('core/editor').isSavingPost()
            && window.wp.data.select('core/editor').getCurrentPost().status === 'publish');
        const id = await page.evaluate(() => window.wp.data.select('core/editor').getCurrentPostId());
        ids.push(['ct_content_block', id]);
        const info = await api('/mcs/v1/posts/' + id + '/status');
        assert.equal(info.counts.all, 1);
        assert.ok(info.css && info.js);
        await page.reload();
        await page.waitForFunction(() => window.wp?.data?.select('core/block-editor')?.getBlocks()
            .some((block) => block.name === 'mcs/code-module' && block.attributes.css.includes('21, 63, 84')));
        console.log('Non-public Content Block inserter, local code editor, normal Gutenberg save/reload and bundles passed.');
        const host = await api('/wp/v2/pages', 'POST', { title: 'MCS Content Block host', status: 'publish' }, 201);
        ids.push(['pages', host.id]);
        // Associate via the fixture's CLI setup, keeping production plugin APIs unchanged.
        const fs = require('node:fs');
        const { spawnSync } = require('node:child_process');
        const setupPath = path.resolve(__dirname, '../../tmp/mcs-native/content-block-host.php');
        fs.writeFileSync(setupPath, '<?php require __DIR__ . "/wordpress/wp-load.php"; update_post_meta('
            + host.id + ', "_mcs_test_content_block", ' + id + ');');
        const setup = spawnSync('php', ['-d', 'extension=mysqli', '-d', 'extension=pdo_sqlite', '-d', 'extension=sqlite3', setupPath], { encoding: 'utf8' });
        assert.equal(setup.status, 0, setup.stderr || setup.stdout);
        await page.goto(host.link);
        assert.equal(await page.locator('.mcs-footer-regression').evaluate((element) => getComputedStyle(element).color), 'rgb(21, 63, 84)');
        assert.equal(await page.locator('.mcs-footer-regression').getAttribute('data-ready'), 'yes');
        assert.equal(await page.evaluate(() => window.mcsFooterRuns), 1);
        assert.equal(await page.locator('.mcs-shortcode').innerText(), 'Shortcode ' + host.id);
        assert.equal(await page.locator('#mcs-page-' + id + '-css').count(), 1);
        assert.equal(await page.locator('#mcs-page-' + id + '-js').count(), 1);
        const other = await api('/wp/v2/pages', 'POST', { title: 'MCS unrelated host', status: 'publish' }, 201);
        ids.push(['pages', other.id]);
        await page.goto(other.link);
        assert.equal(await page.locator('#mcs-page-' + id + '-css,#mcs-page-' + id + '-js').count(), 0);
        assert.deepEqual(errors, []);
        console.log('Selected footer HTML/CSS/JS, retained host shortcode context, single enqueue and unrelated-page isolation passed.');
    } finally {
        for (const [type, id] of ids.reverse()) {
            const rest = type === 'ct_content_block' ? 'ct_content_block' : type;
            await api('/wp/v2/' + rest + '/' + id + '&force=true', 'DELETE').catch(() => {});
        }
        await browser.close();
    }
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
