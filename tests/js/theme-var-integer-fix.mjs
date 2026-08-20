// Focused Playwright verification for the theme-variable integer fix.
// Run with the testbench server up:
//   vendor/bin/testbench serve --port=8787
//   node <repo>/tests/js/theme-var-integer-fix.mjs [http://127.0.0.1:8787]
import { createRequire } from 'node:module';
const { chromium } = createRequire(process.cwd() + '/')('playwright');
const BASE = process.argv[2] || 'http://127.0.0.1:8787';

const report = [];
const ok = (label, cond, detail = '') => report.push(`${cond ? 'PASS' : 'FAIL'}  ${label}${detail ? '  — ' + detail : ''}`);

const browser = await chromium.launch();
const page = await browser.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(String(e).slice(0, 200)));
await page.goto(BASE + '/editor', { waitUntil: 'networkidle' });
await page.click('[data-hb-insert]');
await page.waitForTimeout(120);
await page.click('[data-hb-qi-block="heisenberg/paragraph"]');
await page.waitForTimeout(150);
const id = await page.evaluate(() => window.hbEditor.getDoc().blocks[0].id);
await page.click('.hb-blk[data-block="' + id + '"]');
await page.waitForTimeout(150);
await page.locator('[data-hb-inspector] [data-hb-tablist] [data-hb-tab="style"]').first().click();
await page.waitForTimeout(200);
const sp = page.locator('[data-hb-subpanel="style"] [data-hb-block-panel="heisenberg/paragraph"] .hb-blockstyle');

// ── 1: variable-menu trailing slot shows the integer (not 16px) ──
// Follow inspector-sync-matrix.mjs: switch padding mode to "one" (fans four sides into
// the aggregate field) and click the aggregate's own var trigger.
const expanded = await sp.evaluate((el) => {
    const expandBtn = el.querySelector('[data-hb-style-expand]');
    if (expandBtn && expandBtn.getAttribute('aria-expanded') !== 'true') expandBtn.click();
    return expandBtn ? expandBtn.getAttribute('aria-expanded') : null;
});
await page.waitForTimeout(120);
await sp.evaluate((el) => {
    el.querySelectorAll('[data-hb-style-padding-mode]').forEach((r) => { r.hidden = r.dataset.hbStylePaddingMode !== 'one'; });
    el.dataset.hbStylePaddingMode = 'one';
    el.querySelector('[data-hb-style-all-value="padding"] [data-hb-style-var-trigger]').click();
});
await page.waitForTimeout(200);
const menuValues = await page.evaluate(() => {
    const popup = document.querySelector('[data-hb-style-popup="var-number"]');
    if (!popup) return null;
    return [...popup.querySelectorAll('.hb-vmi__val')].map((el) => el.textContent.trim());
});
ok('variable-menu trailing slot shows integer, not 16px',
    menuValues !== null && menuValues.length > 0 && menuValues.every((v) => v === '' || /^\d+(\.\d+)?$/.test(v)),
    JSON.stringify({ expanded, menuValues }));

// ── 2: picking a token displays the integer in the aggregate field ──
// Capture before AND click in one evaluate (matching the inspector-sync-matrix pattern),
// since intermediate reads of the input sometimes coincide with focus events that close
// the popup. Reading inside the same tick as the click keeps the popup open.
const result = await sp.evaluate((el) => {
    const one = el.querySelector('[data-hb-style-all-value="padding"]');
    const before = one ? { value: one.querySelector('input').value, mixed: one.dataset.hbStyleMixed, bound: one.dataset.hbVarBound || null } : null;
    const popup = el.querySelector('[data-hb-style-popup="var-number"]');
    if (!popup || popup.hidden) return { before, popupOpen: false, clicked: false };
    const item = [...popup.querySelectorAll('.hb-vmi[data-vm-value]')].find((i) => /sp-3/.test(i.dataset.vmValue || ''));
    if (!item) return { before, popupOpen: true, clicked: false };
    item.click();
    return { before, popupOpen: true, clicked: true, value: item.dataset.vmValue };
});
await page.waitForTimeout(250);
const after = await sp.evaluate((el) => {
    const one = el.querySelector('[data-hb-style-all-value="padding"]');
    return { value: one ? one.querySelector('input').value : null, mixed: one?.dataset.hbStyleMixed, bound: one?.dataset.hbVarBound || null };
});
const modelValue = await page.evaluate((i) => window.hbEditor.getModel(i).supports?.spacing?.padding, id);
ok('aggregate field shows the integer, not the label',
    result.clicked && after.value !== null && /^\d+(\.\d+)?$/.test(after.value) && after.bound === result.value && after.mixed !== 'true',
    JSON.stringify({ before: result.before, after, modelValue, result }));

// ── 3: the model still holds the CSS reference, not the integer ──
const modelHoldsRef = await page.evaluate((i) => {
    const p = window.hbEditor.getModel(i).supports?.spacing?.padding;
    return p && Object.values(p).every((v) => typeof v === 'string' && v.startsWith('var(--hb-t-'));
}, id);
ok('model still holds CSS reference, not the displayed integer',
    modelHoldsRef,
    JSON.stringify(modelValue));

// ── 4: typing over the field clears the binding ──
await sp.evaluate((el) => {
    const one = el.querySelector('[data-hb-style-all-value="padding"]');
    const input = one.querySelector('input');
    input.value = '5';
    input.dispatchEvent(new Event('input', { bubbles: true }));
});
await page.waitForTimeout(120);
const cleared = await sp.evaluate((el) => {
    const one = el.querySelector('[data-hb-style-all-value="padding"]');
    return { bound: one.dataset.hbVarBound || null, value: one.querySelector('input').value };
});
ok('typing over a bound field clears the binding',
    cleared.bound === null && cleared.value === '5',
    JSON.stringify(cleared));

// ── 5: theme-token entry accepts a bare integer ──
// PUT /editor/theme with JSON body, exercising the new validate() that auto-promotes
// bare integers to "16px".
const saved = await page.evaluate(async () => {
    const payload = {
        colors: [{ name: 'ink', label: 'Ink', value: '#0a0a0a' }],
        fontSizes: [{ name: 'fs-sm', label: 'Small', value: '13' }],
        spaces: [{ name: 'sp-3', label: 'Large', value: '16' }],
        radii: [{ name: 'radius-md', label: 'Medium', value: '5' }],
        fonts: [{ name: 'font-sans', label: 'Sans', family: 'Rubik', weights: [400, 500] }],
    };
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const res = await fetch('/editor/theme', {
        method: 'PUT',
        body: JSON.stringify(payload),
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
        },
    });
    let body = null;
    try { body = await res.json(); } catch (e) {}
    return { ok: res.ok, status: res.status, body };
});
ok('theme save accepts bare integers (no px)',
    saved.ok,
    JSON.stringify(saved));

// And confirm the saved theme stored "16" as "16px"
const reloaded = await page.evaluate(async () => {
    const res = await fetch('/editor/theme', { headers: { 'Accept': 'application/json' } });
    const body = await res.json();
    return body.theme?.spaces?.find((s) => s.name === 'sp-3');
});
ok('saved theme stored the bare integer with auto-px',
    reloaded && reloaded.value === '16px',
    JSON.stringify(reloaded));

await browser.close();

console.log('\n=== Theme variable integer fix ===');
report.forEach((l) => console.log(l));
console.log('=== Page errors ===');
errors.forEach((e) => console.log(e));
const failed = report.filter((l) => l.startsWith('FAIL')).length;
process.exit(failed === 0 && errors.length === 0 ? 0 : 1);