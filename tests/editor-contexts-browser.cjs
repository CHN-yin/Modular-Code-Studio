const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const { spawnSync } = require('node:child_process');
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
    page.setDefaultTimeout(45000);
    page.setDefaultNavigationTimeout(90000);
    page.on('dialog', (dialog) => dialog.accept());
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    const helper = path.resolve(__dirname, '../../tmp/mcs-native/context-theme.php');
    function theme(name) {
        fs.writeFileSync(helper, '<?php require __DIR__ . "/wordpress/wp-load.php"; switch_theme(' + JSON.stringify(name) + ');');
        const result = spawnSync('php', ['-d', 'extension=mysqli', '-d', 'extension=pdo_sqlite', '-d', 'extension=sqlite3', helper], { encoding: 'utf8' });
        assert.equal(result.status, 0, result.stderr || result.stdout);
    }
    try {
        await context.request.get(base + '/wp-login.php');
        await context.request.post(base + '/wp-login.php', {
            form: { log: 'admin', pwd: 'mcs-local-fixture-admin', testcookie: '1', redirect_to: base + '/wp-admin/' },
        });
        await page.goto(base + '/wp-admin/post-new.php?post_type=mcs_internal_test');
        await page.waitForFunction(() => window.MCS?.editor?.canCode);
        assert.equal(await page.evaluate(() => window.MCS.editor.supported), true);
        await page.waitForFunction(() => window.wp?.data?.select('core/block-editor')?.getInserterItems()
            .some((item) => item.name === 'mcs/code-module'));
        console.log('Additional private custom post type exposes the block automatically.');

        await page.goto(base + '/wp-admin/widgets.php');
        await page.waitForFunction(() => window.MCS?.editor?.canCode);
        assert.equal(await page.evaluate(() => window.MCS.editor.postAssets), false);
        await page.waitForFunction(() => {
            const editor = window.wp?.data?.select('core/block-editor');
            const area = editor?.getBlocks().find((block) => block.name === 'core/widget-area');
            return area && editor.getInserterItems(area.clientId).some((item) => item.name === 'mcs/code-module');
        });
        const widgetClientId = await page.evaluate(() => {
            const editor = window.wp.data.select('core/block-editor');
            const area = editor.getBlocks().find((block) => block.name === 'core/widget-area');
            const block = window.wp.blocks.createBlock('mcs/code-module', { html: '<div>Widget editor acceptance</div>' });
            window.wp.data.dispatch('core/block-editor').insertBlocks([block], 0, area.clientId);
            return block.clientId;
        });
        await page.waitForFunction((id) => window.wp.data.select('core/block-editor').getBlockAttributes(id)?.instanceId, widgetClientId);
        const closeGuide = page.getByRole('button', { name: /^Close(?: dialog)?$/ });
        if (await closeGuide.count()) await closeGuide.first().click();
        assert.ok(await page.locator('.mcs-shell').count());
        await page.locator('.mcs-shell').first().getByRole('button', { name: 'Open code editor' }).click();
        await page.locator('.mcs-shell').first().locator('.cm-content').waitFor();
        console.log('Block widget inserter and inserted module UI work in the native Widgets editor.');

        theme('twentytwentyfive');
        await page.goto(base + '/wp-admin/site-editor.php?postType=wp_template&postId=twentytwentyfive%2F%2Fpage&canvas=edit');
        await page.waitForFunction(() => window.MCS?.editor?.canCode);
        assert.equal(await page.evaluate(() => window.MCS.editor.postAssets), false);
        const welcome = page.getByRole('button', { name: 'Get started', exact: true });
        if (await welcome.count()) await welcome.click();
        await page.waitForFunction(() => window.wp?.data?.select('core/block-editor')?.getBlocks().length > 0);
        await page.waitForFunction(() => window.wp?.data?.select('core/block-editor')?.getInserterItems()
            .some((item) => item.name === 'mcs/code-module'));
        const siteClientId = await page.evaluate(() => {
            const block = window.wp.blocks.createBlock('mcs/code-module', { html: '<div>Site editor acceptance</div>' });
            window.wp.data.dispatch('core/block-editor').insertBlocks([block]);
            return block.clientId;
        });
        await page.waitForFunction((id) => window.wp.data.select('core/block-editor').getBlockAttributes(id)?.instanceId, siteClientId);
        const canvas = page.frameLocator('iframe[name="editor-canvas"]');
        await canvas.locator('.mcs-shell').first().getByRole('button', { name: 'Open code editor' }).click();
        await canvas.locator('.mcs-shell').first().locator('.cm-content').waitFor();
        assert.match(await canvas.locator('.mcs-shell').first().locator('.cm-content').innerText(), /Site editor acceptance/);
        assert.deepEqual(errors, []);
        console.log('Native Site editor exposes the inserter, inserts the module and opens the local code editor in its canvas.');
    } catch (error) {
        console.error('URL:', page.url(), 'Page errors:', errors);
        console.error('Editor block state:', await page.evaluate(() => window.wp?.data?.select('core/block-editor')?.getBlocks()).catch(() => null));
        await page.screenshot({ path: path.resolve(__dirname, '../../tmp/mcs-context-failure.png'), timeout: 4000 }).catch(() => {});
        throw error;
    } finally {
        theme('mcs-blocksy-test-child');
        await browser.close();
    }
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
