// Email canvas parity: an email document is edited on THE SAME canvas as a post. The only
// differences between the two editors are the palette (blocks with no `email` contract section
// are not offered) and the export (EmailRenderer's table markup, built in PHP) — never the
// canvas DOM, its CSS hooks, or the inspector.
//
// Runs the REAL inline scripts of two dumped editor pages in jsdom — the same blocks saved as an
// email and as a post — and requires the two canvases and the two Style tabs to be identical.
//
// Usage:
//   HB_EMAIL_DUMP_PATH=build/js-harness/email-editor.html vendor/bin/phpunit --filter test_dump_email
//   node tests/js/email-canvas-parity.mjs build/js-harness/email-editor.html
import fs from 'node:fs';
import { createRequire } from 'node:module';
const { JSDOM } = createRequire(process.cwd() + '/')('jsdom');

const htmlPath = process.argv[2];
if (!htmlPath) { console.error('usage: node email-canvas-parity.mjs <dumped-email-editor.html>'); process.exit(2); }

async function boot(file, url) {
    const dom = new JSDOM(fs.readFileSync(file, 'utf8'), {
        runScripts: 'dangerously', url, pretendToBeVisual: true,
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
    await new Promise((r) => setTimeout(r, 400));
    return dom;
}

const email = await boot(htmlPath, 'http://localhost/editor/email/1');
const post = await boot(htmlPath + '.post.html', 'http://localhost/editor/2');
const exported = fs.readFileSync(htmlPath + '.export.html', 'utf8');

const report = [];
const ok = (label, cond, detail = '') => report.push(`${cond ? 'PASS' : 'FAIL'}  ${label}${!cond && detail ? '  — ' + detail : ''}`);
const canvasOf = (dom) => dom.window.document.querySelector('.hb-page__blocks');
const firstDiff = (a, b) => { let i = 0; while (i < a.length && a[i] === b[i]) i++; return `at ${i}:\n        email: …${a.slice(Math.max(0, i - 60), i + 100)}\n        post:  …${b.slice(Math.max(0, i - 60), i + 100)}`; };

ok('email editor: no script errors', email.window.__caught.length === 0, email.window.__caught.join(' | '));
ok('post editor: no script errors', post.window.__caught.length === 0, post.window.__caught.join(' | '));
ok('email document is recognised as an email', email.window.document.querySelector('[data-hb-canvas]').dataset.hbDocumentType === 'email');

// 1. The canvas.
const e = canvasOf(email), p = canvasOf(post);
ok('both canvases rendered every block', !!e && !!p && e.querySelectorAll('.hb-blk').length >= 14 && e.querySelectorAll('.hb-blk').length === p.querySelectorAll('.hb-blk').length, e && p ? `${e.querySelectorAll('.hb-blk').length} vs ${p.querySelectorAll('.hb-blk').length}` : 'missing canvas');
ok('email canvas DOM is identical to the post canvas DOM', e.innerHTML === p.innerHTML, firstDiff(e.innerHTML, p.innerHTML));
ok('email blocks carry the normal CSS hooks (data-block-id + contract class)', e.querySelectorAll('.hb-blk > [data-block-id].hb-supports').length === e.querySelectorAll('.hb-blk').length);
ok('no email table markup on the canvas', e.querySelectorAll('table[role="presentation"], td.hb-email-col').length === 0);

// 2. The inspector.
const panels = (dom) => dom.window.document.querySelector('[data-hb-subpanel="style"]');
const PARTS = '[data-hb-control], [data-hb-style-state], [data-hb-style-flexmode], [data-hb-style-alignment-grid], [data-hb-flex-spacing]';
const controls = (dom, name) => {
    const panel = panels(dom).querySelector(`[data-hb-block-panel="heisenberg/${name}"]`);
    return panel ? Array.from(panel.querySelectorAll(PARTS)).map((el) => el.getAttribute('data-hb-control') || el.getAttribute('data-hb-flex-spacing') || el.tagName).join(',') : '';
};
for (const name of ['paragraph', 'heading', 'button', 'image', 'group', 'columns', 'column', 'list', 'quote', 'separator']) {
    const a = controls(email, name), b = controls(post, name);
    ok(`${name}: Style tab offers exactly the main editor's controls`, a !== '' && a === b, `\n        email: ${a}\n        post:  ${b}`);
}
const grp = panels(email).querySelector('[data-hb-block-panel="heisenberg/group"]');
ok('group: Layout grid and its flexbox controls are together', ['[data-hb-style-alignment-grid]', '[data-hb-style-flexmode]', '[data-hb-control="layout.gap"]', '[data-hb-flex-spacing="space-between"]'].every((sel) => !!grp.querySelector(sel)));

// 3. A live edit paints the same way in both editors.
const blockWith = (root, text) => Array.from(root.querySelectorAll('.hb-blk')).find((b) => b.textContent.trim() === text);
for (const [dom, label] of [[email, 'email'], [post, 'post']]) {
    const blk = blockWith(canvasOf(dom), 'RED');
    dom.window.hbEditor.setSupport(blk.getAttribute('data-block'), 'spacing.padding.top', '21px');
    const root = blockWith(canvasOf(dom), 'RED').querySelector('[data-block-id]');
    ok(`${label}: an inspector write repaints the block root`, /--hb-paragraph-pt:\s*21px/.test(root.getAttribute('style') || ''), root.getAttribute('style'));
}
ok('…and the two canvases are still identical afterwards', canvasOf(email).innerHTML === canvasOf(post).innerHTML);

// 4. What DOES differ: the palette and the export.
const offered = (dom) => Array.from(dom.window.document.querySelectorAll('[data-hb-insert-block]')).map((el) => el.getAttribute('data-hb-insert-block'));
ok('email palette excludes icon and embed', !offered(email).includes('heisenberg/icon') && !offered(email).includes('heisenberg/embed') && offered(email).includes('heisenberg/paragraph'));
ok('post palette still offers them', offered(post).includes('heisenberg/icon') && offered(post).includes('heisenberg/embed'));
ok('the export is table markup with literal styles (no var(), no custom properties)', /<table role="presentation"/.test(exported) && !/var\(/.test(exported) && !/style="[^"]*--hb-/.test(exported));
ok('the export honours the inspector (SOLO padding/background reach the mail)', /padding: 40px 0 0 8px[^"]*background-color: #ff0000/.test(exported));
ok('the export keeps siblings apart (RED and BLUE ship their own colours)', /color: #ff0000">RED</.test(exported) && /color: #0000ff">BLUE</.test(exported));

console.log(report.join('\n'));
const failed = report.filter((l) => l.startsWith('FAIL')).length;
console.log(`\n${report.length - failed}/${report.length} passed`);
process.exit(failed ? 1 : 0);
