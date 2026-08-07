// REAL browser matrix: drives the live editor in headless Chromium and reads
// getComputedStyle — the actual pixels, not just attributes. This is the check that
// caught the poisoned-CSS-comment bug that file reading and jsdom both missed.
//
// Usage:
//   vendor/bin/testbench serve --port=8787     (from the repo root)
//   npm install playwright && npx playwright install chromium   (any scratch dir)
//   node <repo>/tests/js/browser-matrix.mjs [http://127.0.0.1:8787]
import { createRequire } from 'node:module';
const { chromium } = createRequire(process.cwd() + '/')('playwright');
const BASE = process.argv[2] || 'http://127.0.0.1:8787';

const report = [];
const ok = (label, cond, detail = '') => report.push(`${cond ? 'PASS' : 'FAIL'}  ${label}${detail ? '  — ' + detail : ''}`);

const browser = await chromium.launch();
const page = await browser.newPage();
const errors = [];
page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text().slice(0, 200)); });
page.on('pageerror', (e) => errors.push('pageerror: ' + String(e).slice(0, 300)));

await page.goto(BASE + '/editor', { waitUntil: 'networkidle' });
await page.waitForTimeout(500);

ok('page loads without JS errors', errors.length === 0, errors.slice(0, 5).join(' || '));
ok('window.hbEditor exists', await page.evaluate(() => !!window.hbEditor));

// Insert a paragraph the way a user does: click the canvas appender, then pick Paragraph in
// the quick inserter popup it opens (live/quick-inserter claims the runtime's hb:quick-insert).
await page.click('[data-hb-insert]');
await page.waitForTimeout(120);
await page.click('[data-hb-qi-block="heisenberg/paragraph"]');
await page.waitForTimeout(200);
const id = await page.evaluate(() => window.hbEditor.getDoc().blocks[0]?.id);
ok('appender click inserts a block', !!id, 'id=' + id);

// Select it by clicking the block.
await page.click('.hb-blk[data-block="' + id + '"]');
await page.waitForTimeout(200);
ok('clicking the block selects it', await page.evaluate(() => window.hbEditor.getSelectedId()) === id);

const rootSel = '.hb-blk[data-block="' + id + '"] [data-block-id]';
const computed = (prop) => page.evaluate(([sel, p]) => getComputedStyle(document.querySelector(sel)).getPropertyValue(p), [rootSel, prop]);

// Open the Style sub-tab (real click on the sub-tabs inside the paragraph's block panel).
const styleTab = page.locator('[data-hb-block-panel="heisenberg/paragraph"] [data-hb-tablist] [data-hb-tab="style"], [data-hb-inspector] [data-hb-tablist] [data-hb-tab="style"]').first();
ok('Style sub-tab found', await styleTab.count() > 0);
await styleTab.click();
await page.waitForTimeout(300);
const sp = page.locator('[data-hb-subpanel="style"] [data-hb-block-panel="heisenberg/paragraph"] .hb-blockstyle');
ok('style panel visible after clicking Style tab', await sp.isVisible());

// ── FILL: + → type hex → computed color must change ─────────────────────────
await sp.locator('[data-hb-style-add="fill"]').click();
await page.waitForTimeout(150);
const hexInput = sp.locator('[data-hb-style-layer-list="fill"] .hb-colorlayer__hex').first();
ok('fill layer row appears', await hexInput.count() > 0);
await hexInput.fill('#ff0080');
await hexInput.dispatchEvent('input');
await page.waitForTimeout(200);
ok('model gets color.text', await page.evaluate((i) => window.hbEditor.getModel(i).supports?.color?.text, id) === '#ff0080');
const colorNow = await computed('color');
ok('COMPUTED color is the fill colour', colorNow.trim() === 'rgb(255, 0, 128)', 'computed color=' + colorNow);

// ── OPACITY ──────────────────────────────────────────────────────────────────
const op = sp.locator('[data-hb-control="appearance.opacity"] input, input[data-hb-control="appearance.opacity"]').first();
if (await op.count()) {
    await op.fill('37');
    await op.dispatchEvent('input');
    await page.waitForTimeout(200);
    const opacityNow = await computed('opacity');
    ok('COMPUTED opacity is 0.37', Math.abs(parseFloat(opacityNow) - 0.37) < 0.01, 'computed opacity=' + opacityNow);
} else { ok('opacity control found', false); }

// ── FONT SIZE ────────────────────────────────────────────────────────────────
const fsCtl = sp.locator('[data-hb-control="typography.fontSize"] input, input[data-hb-control="typography.fontSize"]').first();
if (await fsCtl.count()) {
    await fsCtl.fill('28');
    await fsCtl.dispatchEvent('input');
    await page.waitForTimeout(200);
    const fsNow = await computed('font-size');
    ok('COMPUTED font-size is 28px', fsNow.trim() === '28px', 'computed=' + fsNow);
} else { ok('fontSize control found', false); }

// ── PADDING ──────────────────────────────────────────────────────────────────
const padCtl = sp.locator('[data-hb-control="spacing.padding.top"] input, input[data-hb-control="spacing.padding.top"]').first();
if (await padCtl.count()) {
    await padCtl.fill('24');
    await padCtl.dispatchEvent('input');
    await page.waitForTimeout(200);
    const padNow = await computed('padding-top');
    ok('COMPUTED padding-top is 24px', padNow.trim() === '24px', 'computed=' + padNow);
} else { ok('padding control found', false); }

// ── TEXT ALIGN via typography segmented ──────────────────────────────────────
const alignTab = sp.locator('[data-hb-control="typography.textAlign"] [data-hb-tab="center"]').first();
if (await alignTab.count()) {
    await alignTab.click();
    await page.waitForTimeout(200);
    const taNow = await computed('text-align');
    ok('COMPUTED text-align is center', taNow.trim() === 'center', 'computed=' + taNow);
} else { ok('textAlign segmented found', false); }

// ── STATES: Hover tab retargets + previews ───────────────────────────────────
const hoverTab = sp.locator('[data-hb-style-state] [data-hb-tab="hover"]').first();
if (await hoverTab.count()) {
    await hoverTab.click();
    await page.waitForTimeout(200);
    ok('hover tab flips state', await sp.evaluate((el) => el.dataset.hbStyleState) === 'hover');
    await hexInput.fill('#0000ff');
    await hexInput.dispatchEvent('input');
    await page.waitForTimeout(200);
    const st = await page.evaluate((i) => window.hbEditor.getModel(i).supports?.states, id);
    ok('hover write lands under states.hover', st?.hover?.color?.text === '#0000ff', JSON.stringify(st || {}).slice(0, 120));
    const hoverColor = await computed('color');
    ok('COMPUTED color previews the hover override', hoverColor.trim() === 'rgb(0, 0, 255)', 'computed=' + hoverColor);
} else { ok('hover state tab found', false); }

report.push('JS ERRORS: ' + (errors.length ? errors.join(' || ') : 'none'));
await page.screenshot({ path: 'browser-matrix.png', fullPage: false });
await browser.close();
console.log(report.join('\n'));
process.exit(report.some((l) => l.startsWith('FAIL')) ? 1 : 0);
