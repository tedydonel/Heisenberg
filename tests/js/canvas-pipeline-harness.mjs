// Canvas-pipeline harness: drives every Style control on the REAL rendered editor page the
// way a user would (real inline scripts executing in jsdom) and reports, per control,
// whether the model write happened AND whether the canvas painted. The definitive answer to
// "is the inspector actually wired to the canvas".
//
// Usage:
//   HB_DUMP_PATH=/tmp/editor.html vendor/bin/phpunit --filter test_dump tests/Editor/DumpEditorHtmlTest.php
//   npm install jsdom          (in any scratch dir — jsdom resolves from the CWD you run in)
//   node <repo>/tests/js/canvas-pipeline-harness.mjs /tmp/editor.html
//
// Every line must read PASS. Its PHP twin, tests/Editor/CanvasRenderParityTest.php, proves
// the same payload publishes 1:1 through BlockRenderer.
import fs from 'node:fs';
import { createRequire } from 'node:module';
const { JSDOM } = createRequire(process.cwd() + '/')('jsdom');

const htmlPath = process.argv[2];
if (!htmlPath) { console.error('usage: node canvas-pipeline-harness.mjs <dumped-editor.html>'); process.exit(2); }
const html = fs.readFileSync(htmlPath, 'utf8');
const dom = new JSDOM(html, {
    runScripts: 'dangerously', url: 'http://localhost/editor', pretendToBeVisual: true,
    beforeParse(window) {
        window.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
        window.matchMedia = window.matchMedia || ((q) => ({ matches: false, media: q, onchange: null, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {}, dispatchEvent: () => false }));
        window.PointerEvent = window.PointerEvent || window.MouseEvent;
        window.Element.prototype.setPointerCapture = window.Element.prototype.setPointerCapture || (() => {});
        window.Element.prototype.scrollIntoView = window.Element.prototype.scrollIntoView || (() => {});
        window.fetch = () => new Promise(() => {});
        window.__caught = [];
        window.addEventListener('error', (e) => window.__caught.push(String(e.message)));
    },
});
const { window } = dom; const { document } = window;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
await sleep(300);

const report = [];
const ok = (label, cond, detail = '') => report.push(`${cond ? 'PASS' : 'FAIL'}  ${label}${detail ? '  — ' + detail : ''}`);
const hb = window.hbEditor;
const dg = (obj, path) => path.split('.').reduce((n, k) => (n == null ? n : n[k]), obj);

const el = hb.insertBlock('heisenberg/paragraph');
const id = el.getAttribute('data-block');
hb.selectById(id);
await sleep(50);
const rootStyle = () => document.querySelector('.hb-blk[data-block="' + id + '"] [data-block-id]')?.getAttribute('style') || '';

const panel = document.querySelector('[data-hb-subpanel="style"] [data-hb-block-panel="heisenberg/paragraph"]');
const sp = panel.querySelector('.hb-blockstyle');
sp.closest('[data-hb-subpanel]').hidden = false;
panel.hidden = false;

// helper: drive a generic data-hb-control text/number input
function drive(controlPath, value) {
    const ctl = sp.querySelector('[data-hb-control="' + controlPath.replace(/"/g, '') + '"]');
    if (!ctl) return { found: false };
    const input = ctl.matches('input, textarea') ? ctl : ctl.querySelector('input, textarea');
    if (!input) return { found: true, input: false };
    input.value = value;
    input.dispatchEvent(new window.Event('input', { bubbles: true }));
    input.dispatchEvent(new window.Event('change', { bubbles: true }));
    return { found: true, input: true };
}

// ── generic numeric/text controls ────────────────────────────────────────────
const CASES = [
    ['size.width', '300', '--hb-paragraph-w: 300px'],
    ['size.height', '120', '--hb-paragraph-h: 120px'],
    ['spacing.padding.top', '24', '--hb-paragraph-pt: 24px'],
    ['spacing.margin.bottom', '32', '--hb-paragraph-mb: 32px'],
    ['typography.fontSize', '28', '--hb-paragraph-fs: 28px'],
    ['typography.letterSpacing', '2', '--hb-letter-spacing: 2px'],
    ['position.x', '15', '--hb-tx: 15px'],
    ['position.y', '-8', '--hb-ty: -8px'],
    ['position.rotation', '45', '--hb-rotate: 45deg'],
    ['appearance.opacity', '37', '--hb-opacity: 37%'],
];
for (const [path, value, expect] of CASES) {
    const r = drive(path, value);
    if (!r.found) { ok(`control ${path}`, false, 'CONTROL NOT FOUND'); continue; }
    if (!r.input) { ok(`control ${path}`, false, 'NO INPUT INSIDE'); continue; }
    await sleep(20);
    const modelVal = dg(hb.getModel(id).supports || {}, path);
    const painted = rootStyle().includes(expect);
    ok(`${path} = "${value}"`, modelVal != null && painted, `model=${JSON.stringify(modelVal)} paint=${painted ? 'yes' : 'NO (' + ((rootStyle().match(new RegExp(expect.split(':')[0] + '[^;]*')) || ['missing'])[0]) + ')'}`);
}

// ── segmented: typography.textAlign ──────────────────────────────────────────
{
    const seg = sp.querySelector('[data-hb-control="typography.textAlign"]');
    const tab = seg && seg.querySelector('[data-hb-tab="center"]');
    if (tab) { tab.click(); await sleep(30); }
    ok('typography.textAlign click center', rootStyle().includes('--hb-text-align: center'), (rootStyle().match(/--hb-text-align[^;]*/) || ['missing'])[0]);
}

// ── effects: shadow composer ─────────────────────────────────────────────────
{
    const fx = sp.querySelector('[data-hb-effect]');
    if (!fx) { ok('effects editor exists', false); } else {
        const x = fx.querySelector('[data-hb-fx-x]'); const y = fx.querySelector('[data-hb-fx-y]');
        const blur = fx.querySelector('[data-hb-fx-blur]'); const color = fx.querySelector('[data-hb-fx-color]');
        if (x) { x.value = '2'; }
        if (y) { y.value = '4'; }
        if (blur) { blur.value = '10'; }
        if (color) { color.value = '#000000'; }
        (x || y || blur || color)?.dispatchEvent(new window.Event('input', { bubbles: true }));
        await sleep(30);
        const v = dg(hb.getModel(id).supports || {}, 'effects.shadow');
        ok('effects.shadow composed + painted', !!v && rootStyle().includes('--hb-shadow:'), `model=${JSON.stringify(v)} paint=${(rootStyle().match(/--hb-shadow[^;]*/) || ['missing'])[0]}`);
    }
}

// ── fill via the colour PICKER event path (what the swatch popup emits) ──────
{
    const addFill = sp.querySelector('[data-hb-style-add="fill"]');
    addFill.click(); await sleep(30);
    const row = sp.querySelector('[data-hb-style-layer-list="fill"] .hb-colorlayer');
    const swatch = row.querySelector('[data-hb-style-color-trigger]');
    swatch.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
    await sleep(30);
    const popup = sp.querySelector('[data-hb-style-popup="color"]');
    ok('swatch click opens colour popup', !!popup && !popup.hidden, popup ? 'hidden=' + popup.hidden : 'NO POPUP');
    const picker = popup && popup.querySelector('[data-hb-colorpicker]');
    if (picker) {
        picker.dispatchEvent(new window.CustomEvent('colorchange', { bubbles: true, detail: { r: 255, g: 0, b: 128, a: 1, gradientStop: null } }));
        await sleep(30);
        const v = dg(hb.getModel(id).supports || {}, 'color.text');
        ok('picker colorchange writes + paints color.text', v === '#ff0080' && rootStyle().includes('--hb-paragraph-color: #ff0080'), `model=${JSON.stringify(v)} paint=${(rootStyle().match(/--hb-paragraph-color[^;]*/) || ['missing'])[0]}`);
    } else { ok('colour picker exists in popup', false); }
}

// ── fill via the theme VARIABLE menu ─────────────────────────────────────────
{
    const row = sp.querySelector('[data-hb-style-layer-list="fill"] .hb-colorlayer');
    const varTrigger = row.querySelector('[data-hb-style-var-trigger]');
    varTrigger.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
    await sleep(30);
    // find the variable menu the trigger opened and click its first token row
    const varMenu = [...sp.querySelectorAll('[data-hb-varmenu], [data-hb-style-popup="var"], .hb-varmenu')].find((m) => !m.closest('[hidden]'));
    if (!varMenu) { ok('variable menu opens from fill trigger', false, 'no visible var menu found'); }
    else {
        const item = [...varMenu.querySelectorAll('.hb-vmi[data-vm-value]')].find((i) => /^var\(/.test(i.dataset.vmValue || ''));
        if (!item) { ok('variable menu has token rows with var() values', false, 'rows: ' + [...varMenu.querySelectorAll('.hb-vmi')].map((i) => i.dataset.vmName + '=' + (i.dataset.vmValue || '∅')).slice(0, 6).join(', ')); }
        else {
            item.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
            await sleep(30);
            const v = dg(hb.getModel(id).supports || {}, 'color.text');
            ok('binding a theme token writes a var() color.text', /^var\(--/.test(String(v)), `model=${JSON.stringify(v)} paint=${(rootStyle().match(/--hb-paragraph-color[^;]*/) || ['missing'])[0]}`);
        }
    }
}

// ── states: click the REAL hover tab ─────────────────────────────────────────
{
    const tabs = sp.querySelector('[data-hb-style-state]');
    const hover = tabs.querySelector('[data-hb-tab="hover"]');
    hover.click();
    await sleep(30);
    ok('clicking Hover tab flips dataset.hbStyleState', sp.dataset.hbStyleState === 'hover', 'state=' + sp.dataset.hbStyleState);
    drive('spacing.padding.top', '40');
    await sleep(30);
    const v = dg(hb.getModel(id).supports || {}, 'states.hover.spacing.padding.top');
    ok('hover-scoped write lands under states.hover', v != null, `states=${JSON.stringify(hb.getModel(id).supports?.states || {}).slice(0, 140)}`);
    // back to default so the payload base is clean
    const def = tabs.querySelector('[data-hb-tab="default"]');
    if (def) { def.click(); await sleep(20); }
}

report.push('CAUGHT: ' + (window.__caught.length ? window.__caught.join(' | ') : 'none'));
fs.writeFileSync(htmlPath + '.payload.json', JSON.stringify(hb.buildSavePayload({ title_en: 'Harness', locale: 'en' }), null, 2));
console.log(report.join('\n'));
process.exit(report.some((l) => l.startsWith('FAIL')) ? 1 : 0);
