const assert = require('node:assert/strict');
const { request } = require(process.env.MCS_PLAYWRIGHT || 'playwright-core');

const base = process.env.MCS_TEST_ORIGIN || 'http://127.0.0.1:9483';
assert.ok(['localhost', '127.0.0.1'].includes(new URL(base).hostname), 'Use a disposable local fixture.');

async function main() {
    const client = await request.newContext();
    try {
        await client.get(base + '/wp-login.php');
        await client.post(base + '/wp-login.php', {
            form: { log: 'admin', pwd: 'mcs-local-fixture-admin', testcookie: '1', redirect_to: base + '/wp-admin/' },
        });
        const editor = await client.get(base + '/wp-admin/post-new.php?post_type=page');
        const configMatch = (await editor.text()).match(/window\.MCS\.editor = (\{[^\n]+?\});/);
        assert.ok(configMatch, 'No authenticated Gutenberg editor configuration.');
        const headers = { 'X-WP-Nonce': JSON.parse(configMatch[1]).nonce };
        const insert = await client.post(base + '/?rest_route=/wp/v2/pages', {
            headers, data: { title: 'MCS security acceptance draft', status: 'draft' },
        });
        assert.equal(insert.status(), 201, await insert.text());
        const post = await insert.json();
        try {
            async function create() {
                const created = await client.post(base + '/?rest_route=/mcs/v1/posts/' + post.id + '/preview', {
                    headers, data: { mode: 'module', module: { instanceId: 'draft-test', html: '<div id="mcs-draft-check">Draft module</div>' } },
                });
                assert.equal(created.status(), 201, await created.text());
                return created.json();
            }
            const expired = await create();
            const started = Date.now();
            const usable = await create();
            const invalidPage = await client.post(base + '/', { form: { mcs_preview: usable.channel } });
            assert.equal(invalidPage.status(), 403);
            const good = await client.post(usable.url, { form: { mcs_preview: usable.channel } });
            assert.equal(good.status(), 200, (await good.text()).slice(0, 600));
            assert.match(await good.text(), /id="mcs-draft-check"/);
            console.log('An unsaved module was injected in the authenticated draft preview, while the same token failed for a different query.');
            await new Promise((done) => setTimeout(done, Math.max(0, 123000 - (Date.now() - started))));
            const denied = await client.post(expired.url, { form: { mcs_preview: expired.channel } });
            assert.equal(denied.status(), 403);
            console.log('The unused preview session expired and was rejected.');
        } finally {
            await client.delete(base + '/?rest_route=/wp/v2/pages/' + post.id + '&force=true', { headers });
        }
    } finally {
        await client.dispose();
    }
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
