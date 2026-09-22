// Inspector-sync matrix (real Chromium): canvas font loading, real per-family weight
// options, spacing-aggregate var triggers + token fan-out, and fill-layer UI persistence
// (toolbar path + block switching). Companion to browser-matrix.mjs — same usage:
//   vendor/bin/testbench serve --port=8787
//   npm install playwright && npx playwright install chromium   (any scratch dir)
//   node <repo>/tests/js/inspector-sync-matrix.mjs [http://127.0.0.1:8787]
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
// A fixed wait here is a race: the panel swap can still be mid-flight (the [hidden]
// wrapper toggles asynchronously), and every click issued against a not-yet-unhidden
// root is silently ignored by mountedStyleRoot()'s "no hidden ancestor" guard — so a
// slow environment makes every write-path assertion below fail while reads (which don't
// go through that guard) keep passing. Poll for the real, un-hidden root instead.
await page.waitForFunction(() => {
    const root = document.querySelector('[data-hb-subpanel="style"] [data-hb-block-panel="heisenberg/paragraph"] .hb-blockstyle');
    return !!root && !root.closest('[hidden]');
}, null, { timeout: 5000 });
const sp = page.locator('[data-hb-subpanel="style"] [data-hb-block-panel="heisenberg/paragraph"] .hb-blockstyle');

// ── 1+2: pick a real font → canvas link appears, weights become the family's real set ──
await page.evaluate((i) => window.hbEditor.setSupport(i, 'typography.fontFamily', 'Roboto'), id);
await page.waitForFunction(() => !!document.getElementById('hb-canvas-fonts'), null, { timeout: 8000 }).catch(() => {});
const fontLink = await page.evaluate(() => document.getElementById('hb-canvas-fonts')?.href || null);
ok('canvas font <link> appears for Roboto', !!fontLink && fontLink.includes('Roboto'), String(fontLink));

// A nested text component must load its own face. Previously hbDocFontFamilies() inspected only
// doc.blocks, so this button stayed on its fallback until a top-level heading happened to select
// the same family and caused the link to be rebuilt.
const nestedFont = await page.evaluate(() => {
    document.querySelector('.hb-canvas').dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
    const group = window.hbEditor.insertBlock('heisenberg/group');
    const groupId = group?.getAttribute('data-block') || null;
    const button = groupId ? window.hbEditor.insertInto(groupId, 'heisenberg/button') : null;
    const buttonId = button?.getAttribute('data-block') || null;
    if (buttonId) window.hbEditor.setSupport(buttonId, 'typography.fontFamily', 'Press Start 2P');
    return { groupId, buttonId };
});
await page.waitForFunction(
    () => document.getElementById('hb-canvas-fonts')?.href.includes('Press+Start+2P'),
    null,
    { timeout: 8000 },
).catch(() => {});
const nestedFontLink = await page.evaluate(() => document.getElementById('hb-canvas-fonts')?.href || null);
ok(
    'nested button loads Press Start 2P without a top-level heading using it',
    !!nestedFont.buttonId && !!nestedFontLink && nestedFontLink.includes('Press+Start+2P'),
    JSON.stringify({ nestedFont, nestedFontLink }),
);
const catalogWeights = await page.evaluate(async () => {
    const url = document.querySelector('[data-hb-inspector]').dataset.hbFontsSearchUrl;
    const body = await (await fetch(url + '?q=Roboto&limit=8')).json();
    return (body.fonts.find((f) => f.family === 'Roboto') || {}).weights || null;
});
const weightOptions = await sp.evaluate((el) =>
    [...el.querySelectorAll('[data-hb-control="typography.fontWeight"] [data-hb-select-option]')].map((o) => o.dataset.hbSelectOption).filter((v) => v !== ''));
ok('weight options equal the catalog weights', JSON.stringify(weightOptions) === JSON.stringify((catalogWeights || []).map(String)), `options=${JSON.stringify(weightOptions)} catalog=${JSON.stringify(catalogWeights)}`);
// picking a real weight still writes + paints
await sp.evaluate((el) => {
    const select = el.querySelector('[data-hb-control="typography.fontWeight"]');
    const option = [...select.querySelectorAll('[data-hb-select-option]')].find((o) => o.dataset.hbSelectOption === '700');
    if (option) select.__hbSelect.select(option);
});
await page.waitForTimeout(200);
const fw = await page.evaluate(() => getComputedStyle(document.querySelector('.hb-blk [data-block-id]')).fontWeight);
ok('picking Bold from rebuilt options paints font-weight 700', fw === '700', 'computed=' + fw);

// ── 2b: binding fontFamily (a combobox control) to a theme token actually applies ──
// setValue() on the combobox only repaints its text (it's shared with the model->DOM sync
// path, which must stay silent) — it never dispatches an event, so picking a token here
// used to leave the model, and the canvas, on the old value even though the field showed
// the token's name correctly.
// Real (trusted) clicks, not a synthetic .click() from inside evaluate() — this app's
// click handling is delegated off `document`, and a couple of untrusted, same-tick
// synthetic dispatches upstream of it (elsewhere in the popup stack) don't reliably
// reach it, which would make this section fail (and look unrelated to the bug) even
// with the fix in place.
const fontTrigger = sp.locator('[data-hb-style-var-for="typography.fontFamily"]');
const hasFontTrigger = (await fontTrigger.count()) > 0;
if (hasFontTrigger) await fontTrigger.click();
await page.waitForTimeout(200);
const fontVarBind = hasFontTrigger ? await (async () => {
    const item = sp.locator('[data-hb-style-popup="var-font"] .hb-vmi[data-vm-value^="var("]').first();
    if ((await item.count()) === 0) return { skipped: true };
    const value = await item.getAttribute('data-vm-value');
    await item.click();
    return { skipped: false, value };
})() : { skipped: true };
await page.waitForTimeout(200);
if (!fontVarBind.skipped) {
    const modelFamily = await page.evaluate((i) => window.hbEditor.getModel(i).supports?.typography?.fontFamily, id);
    const computedFamily = await page.evaluate(() => getComputedStyle(document.querySelector('.hb-blk [data-block-id]')).fontFamily);
    ok('binding fontFamily to a theme token writes the model', modelFamily === fontVarBind.value, `model=${modelFamily} expected=${fontVarBind.value}`);
    ok('binding fontFamily to a theme token paints the canvas', computedFamily !== 'Roboto' && computedFamily.length > 0, 'computed=' + computedFamily);
} else { ok('fontFamily token binding (skipped — no font tokens in theme)', true, 'no var tokens available'); }

// ── 3: aggregate fields carry the var trigger and open the token menu ──
const aggState = await sp.evaluate((el) => {
    const one = el.querySelector('[data-hb-style-all-value="padding"]');
    const axis = el.querySelector('[data-hb-style-padding-axis="horizontal"]');
    return {
        oneHasTrigger: !!one?.querySelector('[data-hb-style-var-trigger]'),
        axisHasTrigger: !!axis?.querySelector('[data-hb-style-var-trigger]'),
    };
});
ok('One-value field has a var trigger', aggState.oneHasTrigger);
ok('H/V axis field has a var trigger', aggState.axisHasTrigger);
await sp.evaluate((el) => {
    // switch to One mode the way setPaddingMode does, then click the aggregate's trigger
    el.querySelectorAll('[data-hb-style-padding-mode]').forEach((r) => { r.hidden = r.dataset.hbStylePaddingMode !== 'one'; });
    el.dataset.hbStylePaddingMode = 'one';
    el.querySelector('[data-hb-style-all-value="padding"] [data-hb-style-var-trigger]').click();
});
await page.waitForTimeout(200);
const popupOpen = await sp.evaluate((el) => {
    const popup = el.querySelector('[data-hb-style-popup="var-number"]');
    return { exists: !!popup, open: popup ? !popup.hidden : false, target: el.__hbVarTarget ? el.__hbVarTarget.getAttribute('data-hb-style-all-value') : null };
});
ok('aggregate trigger opens the token menu targeting the aggregate', popupOpen.open && popupOpen.target === 'padding', JSON.stringify(popupOpen));
// bind the first real token if one exists
const bound = await sp.evaluate((el) => {
    const popup = el.querySelector('[data-hb-style-popup="var-number"]');
    const item = popup && [...popup.querySelectorAll('.hb-vmi[data-vm-value]')].find((i) => /^var\(/.test(i.dataset.vmValue || ''));
    if (!item) return { skipped: true };
    item.click();
    return { skipped: false, value: item.dataset.vmValue };
});
await page.waitForTimeout(250);
if (!bound.skipped) {
    const after = await page.evaluate((i) => window.hbEditor.getModel(i).supports?.spacing?.padding, id);
    const display = await sp.evaluate((el) => {
        const one = el.querySelector('[data-hb-style-all-value="padding"]');
        return { value: one.querySelector('input').value, mixed: one.dataset.hbStyleMixed, bound: one.dataset.hbVarBound || null };
    });
    ok('token fans into all four padding sides', after && Object.values(after).every((v) => v === bound.value), JSON.stringify(after));
    ok('aggregate shows the token name, not Mixed', display.mixed !== 'true' && display.bound === bound.value, JSON.stringify(display));
} else { ok('token binding on aggregate (skipped — no number tokens in theme)', true, 'no var tokens available'); }

// ── 4: fill layer UI persistence ──
// toolbar-style write (scalar only, no layer stack) must surface as a row.
// Blur first: in the real toolbar flow focus sits in the canvas, and syncControls
// deliberately never repaints a panel the user is focused inside.
await page.evaluate(() => document.activeElement?.blur());
await page.evaluate((i) => window.hbEditor.setSupport(i, 'color.text', '#123456'), id);
await page.waitForTimeout(250);
const rowState = await sp.evaluate((el) => {
    const rows = [...el.querySelectorAll('[data-hb-style-layer-list="fill"] .hb-colorlayer')];
    return { count: rows.length, hex: rows[0]?.querySelector('.hb-colorlayer__hex')?.value || null };
});
ok('toolbar colour write surfaces as a fill layer row', rowState.count === 1 && rowState.hex === '#123456', JSON.stringify(rowState));

// switching to another block and back keeps rows in sync with each block's own model
await page.click('[data-hb-insert]');
await page.waitForTimeout(120);
await page.click('[data-hb-qi-block="heisenberg/paragraph"]');
await page.waitForTimeout(200);
const id2 = await page.evaluate(() => window.hbEditor.getDoc().blocks[1].id);
await page.click('.hb-blk[data-block="' + id2 + '"]');
await page.waitForTimeout(250);
const freshRows = await sp.evaluate((el) => el.querySelectorAll('[data-hb-style-layer-list="fill"] .hb-colorlayer').length);
ok('new block shows NO stale fill rows', freshRows === 0, 'rows=' + freshRows);
await page.evaluate((i) => window.hbEditor.selectById(i), id);
await page.waitForTimeout(250);
const backRows = await sp.evaluate((el) => {
    const rows = [...el.querySelectorAll('[data-hb-style-layer-list="fill"] .hb-colorlayer')];
    return { count: rows.length, hex: rows[0]?.querySelector('.hb-colorlayer__hex')?.value || null };
});
ok('reselecting restores the block\'s own fill row', backRows.count === 1 && backRows.hex === '#123456', JSON.stringify(backRows));

// ── guard: a look-alike change can never retarget the State tabs ──
const stateGuard = await sp.evaluate((el) => {
    const tabs = el.querySelector('[data-hb-style-state]');
    tabs.dispatchEvent(new CustomEvent('change', { bubbles: true, detail: { value: '300' } }));
    return el.dataset.hbStyleState || 'default';
});
ok('a bogus detail.value never becomes an interaction state', stateGuard !== '300', 'state=' + stateGuard);

report.push('JS ERRORS: ' + (errors.length ? errors.join(' || ') : 'none'));
console.log(report.join('\n'));
await browser.close();
process.exit(report.some((l) => l.startsWith('FAIL')) ? 1 : 0);
