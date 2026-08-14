// Toolbar-follow matrix (real Chromium): the floating block toolbar tracks the SELECTED block at
// any nesting depth. It used to dock in the DOM inside the selection, which a nested child cannot
// do (its wrapper is display:contents and anchors nothing), so a nested selection docked in its
// top-level ANCESTOR instead — the bar was gated for the child but parked on the parent container
// the whole time it was being edited. It is now placed with position:fixed from the selected
// block's own measured box (block-runtime's positionToolbar), which is what this checks: the
// geometry per depth, that it keeps tracking through scroll and re-render, that its buttons still
// act on the child rather than the container, and that deselecting stows it. Same usage as the
// other matrices:
//   vendor/bin/testbench serve --port=8787
//   node <repo>/tests/js/toolbar-follow-matrix.mjs [http://127.0.0.1:8787]
import { createRequire } from 'node:module';
const { chromium } = createRequire(process.cwd() + '/')('playwright');
const BASE = process.argv[2] || 'http://127.0.0.1:8787';

const report = [];
const ok = (label, cond, detail = '') => report.push(`${cond ? 'PASS' : 'FAIL'}  ${label}${detail ? '  — ' + detail : ''}`);

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1500, height: 900 } });
const errors = [];
page.on('pageerror', (e) => errors.push(String(e).slice(0, 200)));
await page.goto(BASE + '/editor', { waitUntil: 'networkidle' });

// The bar's placement relative to whichever block it should be following. `gap` is the distance
// from its bottom edge to the block's top edge (TB_GAP, 2px) and `dx` their left-edge offset; a
// nested wrapper has no box of its own, so both are measured against the block's rendered root.
const placement = (blockId) => page.evaluate((id) => {
    const tb = document.querySelector('[data-hb-block-toolbar]');
    const blk = document.querySelector('.hb-blk[data-block="' + id + '"]');
    const t = tb.getBoundingClientRect();
    const b = (blk.querySelector(':scope > [data-block-id]') || blk).getBoundingClientRect();
    return {
        gap: Math.round(b.top - t.bottom),
        dx: Math.round(t.left - b.left),
        barTop: Math.round(t.top),
        barLeft: Math.round(t.left),
        position: getComputedStyle(tb).position,
        visibility: getComputedStyle(tb).visibility,
        parent: tb.parentElement.className,
        selected: window.hbEditor.getSelectedId(),
    };
}, blockId);
const anchored = (p) => Math.abs(p.gap - 2) <= 3 && Math.abs(p.dx) <= 2;

// ── 1: a container and two children ──
const ids = await page.evaluate(() => {
    const before = window.hbEditor.getDoc().blocks.map((b) => b.id);
    window.hbEditor.insertBlock('heisenberg/group');
    const gid = window.hbEditor.getDoc().blocks.map((b) => b.id).find((id) => !before.includes(id));
    window.hbEditor.insertInto(gid, 'heisenberg/paragraph');
    window.hbEditor.insertInto(gid, 'heisenberg/heading');
    return { gid, kids: window.hbEditor.getModel(gid).innerBlocks.map((b) => b.id) };
});
const [firstChild, secondChild] = ids.kids;

await page.evaluate((id) => window.hbEditor.selectById(id), ids.gid);
await page.waitForTimeout(80);
const onContainer = await placement(ids.gid);
ok('a top-level container anchors to itself', anchored(onContainer), JSON.stringify(onContainer));
ok('the bar floats in the canvas layer, not inside the block',
    onContainer.position === 'fixed' && onContainer.parent.includes('hb-canvas'),
    onContainer.position + ' in ' + onContainer.parent);

// ── 2: the reported bug — a nested child ──
await page.evaluate((id) => window.hbEditor.selectById(id), firstChild);
await page.waitForTimeout(80);
const onFirst = await placement(firstChild);
await page.evaluate((id) => window.hbEditor.selectById(id), secondChild);
await page.waitForTimeout(80);
const onSecond = await placement(secondChild);

ok('a nested child anchors to the CHILD', anchored(onFirst), JSON.stringify(onFirst));
ok('a second nested child anchors to itself, not the container', anchored(onSecond), JSON.stringify(onSecond));
// The regression this whole file exists for: both children docked in the same ancestor, so the bar
// did not move at all between them — and sat at the CONTAINER's top rather than either child's.
ok('the bar actually moves between the two children',
    onFirst.barTop !== onSecond.barTop || onFirst.barLeft !== onSecond.barLeft,
    JSON.stringify({ first: [onFirst.barTop, onFirst.barLeft], second: [onSecond.barTop, onSecond.barLeft] }));
ok('a child bar is not parked at the container bar position',
    onSecond.barTop !== onContainer.barTop || onSecond.barLeft !== onContainer.barLeft,
    JSON.stringify({ container: [onContainer.barTop, onContainer.barLeft], child: [onSecond.barTop, onSecond.barLeft] }));

// ── 3: a toolbar button still acts on the child, and the bar re-measures after the re-render ──
await page.click('[data-hb-block-toolbar] [data-tb-action="move-up"]');
await page.waitForTimeout(120);
const afterMove = await page.evaluate((gid) => ({
    order: window.hbEditor.getModel(gid).innerBlocks.map((b) => b.id),
    selected: window.hbEditor.getSelectedId(),
    topLevel: window.hbEditor.getDoc().blocks.length,
}), ids.gid);
ok('move-up reorders INSIDE the container and keeps the child selected',
    afterMove.order.join() === [secondChild, firstChild].join()
    && afterMove.selected === secondChild
    && afterMove.topLevel === 1,
    JSON.stringify(afterMove));
ok('the bar re-anchors to the moved child', anchored(await placement(secondChild)));

// ── 4: a popover opened from the floating bar still lands under it, on screen ──
await page.click('[data-hb-block-toolbar] [data-tb-popover="type"]');
await page.waitForTimeout(80);
const pop = await page.evaluate(() => {
    const p = document.querySelector('[data-hb-block-toolbar] [data-tb-pop="type"]');
    const r = p.getBoundingClientRect();
    const t = document.querySelector('[data-hb-block-toolbar]').getBoundingClientRect();
    return { hidden: p.hidden, height: Math.round(r.height), below: Math.round(r.top - t.bottom), onScreen: r.left >= 0 && r.right <= window.innerWidth };
});
ok('a popover opens below the floating bar, on screen',
    !pop.hidden && pop.height > 0 && pop.below >= 0 && pop.below < 20 && pop.onScreen,
    JSON.stringify(pop));
await page.keyboard.press('Escape');

// ── 5: scrolling — the bar tracks while the block is in view, hides once it leaves ──
const tall = await page.evaluate(() => {
    for (let i = 0; i < 30; i++) {
        const el = window.hbEditor.insertBlock('heisenberg/paragraph');
        const ce = el && el.querySelector ? el.querySelector('.hb-ce') : null;
        if (ce) ce.innerHTML = 'Line ' + i + '<br>'.repeat(3);
    }
    const blocks = window.hbEditor.getDoc().blocks;
    return blocks[Math.floor(blocks.length / 2)].id;
});
await page.evaluate((id) => {
    window.hbEditor.selectById(id);
    const blk = document.querySelector('.hb-blk[data-block="' + id + '"]');
    (blk.querySelector(':scope > [data-block-id]') || blk).scrollIntoView({ block: 'center' });
}, tall);
await page.waitForTimeout(120);
ok('the bar tracks a block scrolled into view', anchored(await placement(tall)));

await page.evaluate(() => { document.querySelector('.hb-canvas').scrollTop += 900; });
await page.waitForTimeout(120);
const offscreen = await placement(tall);
ok('the bar hides once its block scrolls out of the canvas viewport',
    offscreen.visibility === 'hidden', JSON.stringify(offscreen));

// ── 6: deselect stows it, hidden, with no leftover inline placement ──
const stowed = await page.evaluate(() => {
    document.querySelector('.hb-canvas').dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
    const tb = document.querySelector('[data-hb-block-toolbar]');
    return { hidden: tb.hidden, parent: tb.parentElement.className, style: tb.getAttribute('style') || '', floatClass: tb.classList.contains('hb-tb--float') };
});
ok('deselecting stows the bar hidden in its holder, placement cleared',
    stowed.hidden && stowed.parent.includes('hb-blk-toolbar-holder') && stowed.style.trim() === '' && !stowed.floatClass,
    JSON.stringify(stowed));

report.push('JS ERRORS: ' + (errors.length ? errors.join(' || ') : 'none'));
console.log(report.join('\n'));
await browser.close();
process.exit(report.some((l) => l.startsWith('FAIL')) || errors.length ? 1 : 0);
