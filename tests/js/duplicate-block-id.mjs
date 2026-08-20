// Focused Playwright verification for the duplicate-block id collision fix.
//
// Run with the testbench server up:
//   vendor/bin/testbench serve --port=8787
//   node <repo>/tests/js/duplicate-block-id.mjs [http://127.0.0.1:8787]
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
await page.click('[data-hb-qi-block="heisenberg/heading"]');
await page.waitForTimeout(150);

// Source heading id + content
const sourceId = await page.evaluate(() => window.hbEditor.getDoc().blocks[0].id);
await page.click(`.hb-blk[data-block="${sourceId}"]`);
await page.waitForTimeout(120);
const initial = await page.evaluate((i) => {
    const el = document.querySelector(`.hb-blk[data-block="${i}"]`);
    const content = el?.querySelector('.hb-block-heading__text')?.textContent || '';
    window.hbEditor.setAttribute(i, 'content', 'Source heading');
    return { content, id: i };
}, sourceId);
await page.waitForTimeout(80);

// Duplicate
const dupId = await page.evaluate((i) => window.hbEditor.duplicateBlock(i), sourceId);

// 1. duplicate id is different from source id
ok('duplicate has its own id', dupId && dupId !== sourceId, JSON.stringify({ sourceId, dupId }));

// 2. selecting the duplicate resolves by its OWN id (findModel hits the duplicate)
const afterDup = await page.evaluate((i) => {
    const dupModel = window.hbEditor.getModel(i);
    const sourceModel = window.hbEditor.getModel(window.hbEditor.getDoc().blocks[0].id);
    return {
        dupId: i,
        dupContent: dupModel?.attributes?.content ?? null,
        dupIsDistinct: dupModel !== sourceModel,
        blocks: window.hbEditor.getDoc().blocks.length,
    };
}, dupId);
ok('duplicate carries the cloned content and is a distinct model',
    afterDup.dupContent === 'Source heading' && afterDup.dupIsDistinct && afterDup.blocks === 2,
    JSON.stringify(afterDup));

// 3. edit the duplicate's content via the model API; source MUST stay unchanged
const afterEdit = await page.evaluate(({ di, si }) => {
    window.hbEditor.setAttribute(di, 'content', 'Edited duplicate');
    const source = document.querySelector(`.hb-blk[data-block="${si}"] .hb-block-heading__text`);
    const dup = document.querySelector(`.hb-blk[data-block="${di}"] .hb-block-heading__text`);
    return {
        sourceText: source?.textContent || '',
        dupText: dup?.textContent || '',
    };
}, { di: dupId, si: sourceId });
ok('editing the duplicate does NOT change the source',
    afterEdit.sourceText === 'Source heading' && afterEdit.dupText === 'Edited duplicate',
    JSON.stringify(afterEdit));

// 4. typing via the contenteditable also writes only to the duplicate — this is the path a
//    user actually walks. Select the duplicate, focus its contenteditable, type, blur.
await page.click(`.hb-blk[data-block="${dupId}"]`);
await page.waitForTimeout(120);
await page.evaluate(({ di }) => {
    const dup = document.querySelector(`.hb-blk[data-block="${di}"]`);
    const editable = dup?.querySelector('.hb-block-heading__text');
    if (!editable) return;
    editable.focus();
    // Replace the content with a fresh string and dispatch the input event the runtime listens to.
    editable.textContent = 'Typed into duplicate';
    editable.dispatchEvent(new InputEvent('input', { bubbles: true }));
    editable.dispatchEvent(new Event('blur', { bubbles: true }));
}, { di: dupId });
await page.waitForTimeout(150);
const typed = await page.evaluate(({ di, si }) => {
    const source = document.querySelector(`.hb-blk[data-block="${si}"] .hb-block-heading__text`);
    const dup = document.querySelector(`.hb-blk[data-block="${di}"] .hb-block-heading__text`);
    return {
        sourceText: source?.textContent || '',
        sourceModel: window.hbEditor.getModel(si)?.attributes?.content ?? null,
        dupModel: window.hbEditor.getModel(di)?.attributes?.content ?? null,
        dupText: dup?.textContent || '',
    };
}, { di: dupId, si: sourceId });
ok('typing in the duplicate keeps the source intact',
    typed.sourceText === 'Source heading'
        && typed.sourceModel === 'Source heading'
        && typed.dupText === 'Typed into duplicate'
        && typed.dupModel === 'Typed into duplicate',
    JSON.stringify(typed));

await browser.close();

console.log('\n=== Duplicate-block id collision fix ===');
report.forEach((l) => console.log(l));
console.log('=== Page errors ===');
errors.forEach((e) => console.log(e));
const failed = report.filter((l) => l.startsWith('FAIL')).length;
process.exit(failed === 0 && errors.length === 0 ? 0 : 1);
