// Code-view matrix (real Chromium): the footer chip toggles Visual ⇄ Code, the shortcode
// dialect serializes the doc (attributes + dotted supports paths), edits parse back and
// PAINT after returning to Visual, invalid code blocks the switch, and the round trip is
// stable. Companion to browser-matrix.mjs — same usage:
//   vendor/bin/testbench serve --port=8787
//   npm install playwright && npx playwright install chromium   (any scratch dir)
//   node <repo>/tests/js/code-editor-matrix.mjs [http://127.0.0.1:8787]
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

// Seed: a paragraph with supports the inspector would write.
await page.click('[data-hb-insert]');
await page.waitForTimeout(120);
await page.click('[data-hb-qi-block="heisenberg/paragraph"]');
await page.waitForTimeout(150);
const seedId = await page.evaluate(() => window.hbEditor.getDoc().blocks[0].id);
await page.evaluate((i) => {
    window.hbEditor.setSupport(i, 'typography.fontSize', '28px');
    window.hbEditor.setSupport(i, 'states.hover.color.text', '#112233');
}, seedId);
await page.waitForTimeout(150);

// ── 1: toggle into Code — canvas hides, chip presses, doc serializes ──
await page.click('[data-hb="code-editor"]');
await page.waitForTimeout(200);
const entered = await page.evaluate(() => ({
    codeVisible: !document.querySelector('[data-hb-codeview]').hidden,
    canvasHidden: getComputedStyle(document.querySelector('.hb-canvas')).display === 'none',
    pressed: document.querySelector('[data-hb="code-editor"]').getAttribute('aria-pressed'),
    text: document.querySelector('[data-hb-cv-input]').value,
}));
ok('footer chip opens the Code view and hides the canvas',
    entered.codeVisible && entered.canvasHidden && entered.pressed === 'true');
ok('the doc serializes in the short dialect ([p], aliases, hover: prefix)',
    entered.text.includes('[p ')
    && entered.text.includes('font-size=28px')
    && entered.text.includes('hover:color=#112233'),
    JSON.stringify(entered.text.slice(0, 120)));

// ── 2: author a new document in code, apply, and PAINT it in Visual ──
const CODE = [
    '[heading level="3" typography.fontSize="40px"]',
    '  Hello <em>world</em>',
    '[/heading]',
    '',
    '[paragraph color.text="#ff0000"]',
    '  Body text',
    '[/paragraph]',
    '',
].join('\n');
await page.evaluate((code) => {
    const input = document.querySelector('[data-hb-cv-input]');
    input.value = code;
    input.dispatchEvent(new Event('input', { bubbles: true }));
}, CODE);
await page.waitForTimeout(800); // debounce (500ms) + apply
const applied = await page.evaluate(() => {
    const blocks = window.hbEditor.getDoc().blocks;
    return {
        count: blocks.length,
        names: blocks.map((b) => b.name),
        level: blocks[0] && blocks[0].attributes.level,
        content: blocks[0] && blocks[0].attributes.content,
        size: blocks[0] && blocks[0].supports.typography && blocks[0].supports.typography.fontSize,
        color: blocks[1] && blocks[1].supports.color && blocks[1].supports.color.text,
        statusHidden: document.querySelector('[data-hb-cv-status]').hidden,
    };
});
ok('a clean parse replaces the doc (attributes, body, supports)',
    applied.count === 2 && applied.names[0] === 'heisenberg/heading' && applied.level === 3
    && applied.content === 'Hello <em>world</em>' && applied.size === '40px' && applied.color === '#ff0000'
    && applied.statusHidden,
    JSON.stringify(applied));
await page.click('[data-hb="code-editor"]');
await page.waitForTimeout(250);
const painted = await page.evaluate(() => {
    const roots = document.querySelectorAll('.hb-blk [data-block-id]');
    return {
        canvasBack: getComputedStyle(document.querySelector('.hb-canvas')).display !== 'none',
        blocks: document.querySelectorAll('.hb-blk').length,
        headingSize: getComputedStyle(roots[0]).fontSize,
        paragraphColor: getComputedStyle(roots[1]).color,
        em: !!roots[0].querySelector('em'),
    };
});
ok('switching back to Visual paints the authored code',
    painted.canvasBack && painted.blocks === 2 && painted.headingSize === '40px'
    && painted.paragraphColor === 'rgb(255, 0, 0)' && painted.em,
    JSON.stringify(painted));

// ── 3: the round trip is stable ──
await page.click('[data-hb="code-editor"]');
await page.waitForTimeout(200);
const roundTrip = await page.evaluate(() => document.querySelector('[data-hb-cv-input]').value);
ok('re-entering Code re-serializes the same document (short form)',
    roundTrip.includes('[h3 font-size=40px]')
    && roundTrip.includes('Hello <em>world</em>')
    && roundTrip.includes('[p color=#ff0000]'),
    JSON.stringify(roundTrip.slice(0, 120)));

// ── 4: errors surface with line numbers and block the switch ──
await page.evaluate(() => {
    const input = document.querySelector('[data-hb-cv-input]');
    input.value = '[headng level="9"]typo[/headng]\n';
    input.dispatchEvent(new Event('input', { bubbles: true }));
});
await page.waitForTimeout(800);
const errored = await page.evaluate(() => ({
    statusShown: !document.querySelector('[data-hb-cv-status]').hidden,
    items: document.querySelectorAll('[data-hb-cv-status-list] [data-line]').length,
    gutterErr: !!document.querySelector('[data-hb-cv-nums] .is-err'),
    docIntact: window.hbEditor.getDoc().blocks.length === 2,
}));
ok('invalid code lights the gutter + error strip and leaves the doc untouched',
    errored.statusShown && errored.items >= 1 && errored.gutterErr && errored.docIntact,
    JSON.stringify(errored));
await page.click('[data-hb="code-editor"]');
await page.waitForTimeout(200);
const blocked = await page.evaluate(() => ({
    stillCode: !document.querySelector('[data-hb-codeview]').hidden,
}));
ok('the switch back to Visual is blocked while errors remain', blocked.stillCode);
await page.click('[data-hb-cv-revert]');
await page.waitForTimeout(150);
const reverted = await page.evaluate(() => ({
    text: document.querySelector('[data-hb-cv-input]').value,
    statusHidden: document.querySelector('[data-hb-cv-status]').hidden,
}));
ok('Revert restores the canvas state into the editor',
    reverted.text.includes('[h3 font-size=40px]') && reverted.statusHidden);
await page.click('[data-hb="code-editor"]');
await page.waitForTimeout(200);
const exited = await page.evaluate(() => !document.querySelector('[data-hb-codeview]').hidden);
ok('after Revert the switch back to Visual works', exited === false);

// ── 5: the short dialect — tag aliases, CSS box shorthands, state prefixes ──
await page.evaluate(() => {
    const id = window.hbEditor.getDoc().blocks[0].id;
    ['top', 'right', 'bottom', 'left'].forEach((s) => window.hbEditor.setSupport(id, 'spacing.padding.' + s, '12px'));
    window.hbEditor.setSupport(id, 'states.hover.color.text', '#123456');
});
await page.waitForTimeout(150);
await page.click('[data-hb="code-editor"]');
await page.waitForTimeout(200);
const short = await page.evaluate(() => document.querySelector('[data-hb-cv-input]').value);
ok('headings ride the tag (h3) and simple values go unquoted',
    short.includes('[h3 ') && short.includes('font-size=40px') && !short.includes('level='),
    JSON.stringify(short.slice(0, 100)));
ok('four equal sides collapse to one CSS shorthand value',
    short.includes('padding=12px') && !short.includes('spacing.padding'),
    JSON.stringify(short.slice(0, 130)));
ok('state overrides use the hover: prefix', short.includes('hover:color=#123456'));

// two-value CSS padding expands back to sides
await page.evaluate(() => {
    const input = document.querySelector('[data-hb-cv-input]');
    input.value = input.value.replace('padding=12px', 'padding="4px 8px"');
    input.dispatchEvent(new Event('input', { bubbles: true }));
});
await page.waitForTimeout(800);
const pads = await page.evaluate(() => window.hbEditor.getDoc().blocks[0].supports.spacing.padding);
ok('CSS two-value padding expands to top/bottom + left/right',
    pads && pads.top === '4px' && pads.bottom === '4px' && pads.right === '8px' && pads.left === '8px',
    JSON.stringify(pads));

// ── 6: long tags still break one aliased attribute per line; long form still parses ──
await page.evaluate(() => {
    const id = window.hbEditor.getDoc().blocks[0].id;
    window.hbEditor.setSupport(id, 'size.width', '520px');
    window.hbEditor.setSupport(id, 'size.maxWidth', '720px');
    window.hbEditor.setSupport(id, 'appearance.opacity', '0.9');
    window.hbEditor.setSupport(id, 'position.rotation', '3deg');
    window.hbEditor.setSupport(id, 'typography.letterSpacing', '0.5px');
});
await page.waitForTimeout(300); // pristine code view re-serializes on external change
const pretty = await page.evaluate(() => document.querySelector('[data-hb-cv-input]').value);
ok('a long tag breaks one aliased attribute per line',
    pretty.includes('[h3\n') && pretty.includes('\n  w=520px\n') && pretty.includes('\n  rotate=3deg\n') && pretty.includes('\n]\n  Hello'),
    JSON.stringify(pretty.slice(0, 150)));

// long-form dotted paths remain valid; an invalid attribute reports its OWN line
await page.evaluate(() => {
    const input = document.querySelector('[data-hb-cv-input]');
    input.value = '[heading\n  level=9\n  typography.fontSize=40px\n]\n  x\n[/heading]\n';
    input.dispatchEvent(new Event('input', { bubbles: true }));
});
await page.waitForTimeout(800);
const precise = await page.evaluate(() => {
    const item = document.querySelector('[data-hb-cv-status-list] [data-line]');
    return item ? Number(item.dataset.line) : null;
});
ok('long form parses and an invalid attribute reports its own line', precise === 2, `reported=${precise} expected=2`);
await page.click('[data-hb-cv-revert]');
await page.waitForTimeout(150);
await page.click('[data-hb="code-editor"]');
await page.waitForTimeout(200);
const finalVisual = await page.evaluate(() => !document.querySelector('[data-hb-codeview]').hidden);
ok('back to Visual after the short-dialect round', finalVisual === false);

// ── 6b: the footer chip names the DESTINATION surface ──
const labelInVisual = await page.evaluate(() => document.querySelector('[data-hb="code-editor"] span').textContent);
await page.click('[data-hb="code-editor"]');
await page.waitForTimeout(200);
const labelInCode = await page.evaluate(() => document.querySelector('[data-hb="code-editor"] span').textContent);
ok('the footer chip reads Code Editor in Visual and Visual Editor in Code',
    labelInVisual === 'Code Editor' && labelInCode === 'Visual Editor',
    JSON.stringify({ labelInVisual, labelInCode }));

// ── 6c: bogus state names are rejected at every layer ──
await page.evaluate(() => {
    const input = document.querySelector('[data-hb-cv-input]');
    input.value = '[p states.300.color.text=#fff]x[/p]\n';
    input.dispatchEvent(new Event('input', { bubbles: true }));
});
await page.waitForTimeout(800);
const bogus = await page.evaluate(() => ({
    errored: !document.querySelector('[data-hb-cv-status]').hidden,
    runtimeRejected: window.hbEditor.setSupport(window.hbEditor.getDoc().blocks[0].id, 'states.300.color.text', '#fff') === false,
    modelClean: JSON.stringify(window.hbEditor.getDoc().blocks[0].supports.states || {}).indexOf('300') === -1,
}));
ok('states.300 errors in the parser and is refused by the runtime', bogus.errored && bogus.runtimeRejected && bogus.modelClean, JSON.stringify(bogus));
await page.click('[data-hb-cv-revert]');
await page.waitForTimeout(150);

// ── 6d: long rich-text bodies wrap and split at block boundaries ──
await page.evaluate(() => {
    const input = document.querySelector('[data-hb-cv-input]');
    const prose = 'word '.repeat(60).trim();
    input.value = '[p]\n  <div>' + prose + '</div><div>second paragraph of text</div>\n[/p]\n';
    input.dispatchEvent(new Event('input', { bubbles: true }));
});
await page.waitForTimeout(800);
// leave and re-enter Code — serialization of the applied doc shows the wrapping
await page.click('[data-hb="code-editor"]');
await page.waitForTimeout(200);
await page.click('[data-hb="code-editor"]');
await page.waitForTimeout(200);
const wrapped = await page.evaluate(() => document.querySelector('[data-hb-cv-input]').value);
const longest = Math.max(...wrapped.split('\n').map((l) => l.length));
ok('adjacent block-level siblings split onto their own lines', wrapped.includes('</div>\n  <div>second'), JSON.stringify(wrapped.slice(0, 80)));
ok('long prose word-wraps near 90 columns', longest <= 100, 'longest line=' + longest);
await page.click('[data-hb="code-editor"]');
await page.waitForTimeout(200);
ok('back to Visual after the formatting round', await page.evaluate(() => document.querySelector('[data-hb-codeview]').hidden));

// ── 7: both axes ride ui/custom-scrollbar, native bars hidden ──
await page.click('[data-hb="code-editor"]');
await page.waitForTimeout(200);
const LONG = Array.from({ length: 60 }, () => '[paragraph]\n  ' + 'long text '.repeat(40).trim() + '\n[/paragraph]').join('\n\n') + '\n';
await page.evaluate((code) => {
    const input = document.querySelector('[data-hb-cv-input]');
    input.value = code;
    input.dispatchEvent(new Event('input', { bubbles: true }));
}, LONG);
await page.waitForTimeout(800);
const bars = await page.evaluate(async () => {
    const input = document.querySelector('[data-hb-cv-input]');
    const all = [...document.querySelectorAll('[data-hb-codeview] [data-hb-custom-scrollbar]')];
    const yBar = all.find((b) => b.dataset.axis === 'y');
    const xBar = all.find((b) => b.dataset.axis === 'x');
    input.scrollTop = 400;
    input.scrollLeft = 300;
    await new Promise((r) => setTimeout(r, 150));
    return {
        count: all.length,
        yHidden: yBar ? yBar.hidden : null,
        xHidden: xBar ? xBar.hidden : null,
        yThumb: yBar ? yBar.querySelector('[data-hb-scrollbar-thumb]').style.transform : '',
        xThumb: xBar ? xBar.querySelector('[data-hb-scrollbar-thumb]').style.transform : '',
        nativeHidden: input.classList.contains('hb-scroll-container'),
        overlaySynced: document.querySelector('[data-hb-cv-hl]').scrollTop === input.scrollTop
            && document.querySelector('[data-hb-cv-hl]').scrollLeft === input.scrollLeft,
    };
});
ok('both custom scrollbars mount and unhide with two-axis overflow',
    bars.count === 2 && bars.yHidden === false && bars.xHidden === false && bars.nativeHidden,
    JSON.stringify({ count: bars.count, yHidden: bars.yHidden, xHidden: bars.xHidden, nativeHidden: bars.nativeHidden }));
ok('vertical thumb tracks scrollTop', /translate3d\(0(px)?, [1-9]/.test(bars.yThumb), bars.yThumb);
ok('horizontal thumb tracks scrollLeft', /translate3d\([1-9][0-9.]*px, 0/.test(bars.xThumb), bars.xThumb);
ok('the highlight overlay stays in sync under custom scrolling', bars.overlaySynced);

report.push('JS ERRORS: ' + (errors.length ? errors.join(' || ') : 'none'));
console.log(report.join('\n'));
await browser.close();
process.exit(report.some((l) => l.startsWith('FAIL')) ? 1 : 0);
