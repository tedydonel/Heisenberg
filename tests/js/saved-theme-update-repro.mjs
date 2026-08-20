// Diagnostic: confirm the saved-themes dedupe behavior via the HTTP backend only.
// Replicates "saving under same name twice" exactly the way the UI does and confirms
// the existing case-insensitive upsert.
import { createRequire } from 'node:module';
const { chromium } = createRequire(process.cwd() + '/')('playwright');
const BASE = process.argv[2] || 'http://127.0.0.1:8787';

const browser = await chromium.launch();
const page = await browser.newPage();
await page.goto(BASE + '/editor', { waitUntil: 'networkidle' });

const trace = await page.evaluate(async () => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const destroy = '/editor/themes';
    const store = '/editor/themes';

    const themeA = {
        colors: [{ name: 'ink', label: 'A-Ink', value: '#0a0a0a' }],
    };
    const themeB = {
        colors: [{ name: 'ink', label: 'B-Ink', value: '#101010' }],
    };

    async function saveAs(name, theme) {
        const res = await fetch(store, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify({ name, theme }),
        });
        return { ok: res.ok, status: res.status };
    }

    async function del(name) {
        const res = await fetch(destroy, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ name }),
        });
        return res.ok;
    }

    async function list() {
        const body = await fetch('/editor/themes', { headers: { 'Accept': 'application/json' } }).then((r) => r.json());
        return body.themes || [];
    }

    // Clear state
    for (const t of await list()) await del(t.name);
    const cleared = await list();

    const out = {};
    out.cleared = cleared.map((t) => t.name);

    // 1. save "Brand" with themeA
    out.s1 = await saveAs('Brand', themeA);
    out.l1 = (await list()).map((t) => ({ name: t.name, first_label: t.theme?.colors?.[0]?.label }));

    // 2. save "Brand" again with themeB (same name)
    out.s2 = await saveAs('Brand', themeB);
    out.l2 = (await list()).map((t) => ({ name: t.name, first_label: t.theme?.colors?.[0]?.label }));

    // 3. save with different name "brand 2"
    out.s3 = await saveAs('brand 2', themeA);
    out.l3 = (await list()).map((t) => ({ name: t.name, first_label: t.theme?.colors?.[0]?.label }));

    // 4. save with lowercase "brand" — case-insensitive match should overwrite
    out.s4 = await saveAs('brand', themeA);
    out.l4 = (await list()).map((t) => ({ name: t.name, first_label: t.theme?.colors?.[0]?.label }));

    // 5. save with "  Brand  " — trimming + case-insensitive match
    out.s5 = await saveAs('  Brand  ', themeB);
    out.l5 = (await list()).map((t) => ({ name: t.name, first_label: t.theme?.colors?.[0]?.label }));

    // 6. save with "BRAND-X" — different name, should NOT touch existing
    out.s6 = await saveAs('BRAND-X', themeA);
    out.l6 = (await list()).map((t) => ({ name: t.name, first_label: t.theme?.colors?.[0]?.label }));

    // Cleanup
    for (const t of await list()) await del(t.name);
    return out;
});

console.log(JSON.stringify(trace, null, 2));
await browser.close();
