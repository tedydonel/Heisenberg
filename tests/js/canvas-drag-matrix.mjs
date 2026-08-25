// Canvas drag & cursor matrix (real Chromium): the block-body/grip drag gesture and its
// cursor language — hover a block → grab, active drag → grabbing (body class, reliable
// under pointer capture), rich text → I-beam — plus nested drags committing through
// moveBlockTo: reorder within a container, lift a nested child OUT to the top level, and
// keep the moved block selected after the container re-renders.
//   vendor/bin/testbench serve --port=8787
//   node <repo>/tests/js/canvas-drag-matrix.mjs [http://127.0.0.1:8787]
import { createRequire } from 'node:module';
const { chromium } = createRequire(process.cwd() + '/')('playwright');
const BASE = process.argv[2] || 'http://127.0.0.1:8787';

const report = [];
const ok = (label, cond, detail = '') => report.push(`${cond ? 'PASS' : 'FAIL'}  ${label}${detail ? '  — ' + detail : ''}`);

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });
const errors = [];
page.on('pageerror', (e) => errors.push(String(e).slice(0, 200)));
await page.goto(BASE + '/editor', { waitUntil: 'networkidle' });

// Seed: two top-level paragraphs, then a group holding one paragraph child.
const ids = await page.evaluate(() => {
    const p1 = window.hbEditor.insertBlock('heisenberg/paragraph').getAttribute('data-block');
    const p2 = window.hbEditor.insertBlock('heisenberg/paragraph').getAttribute('data-block');
    const g = window.hbEditor.insertBlock('heisenberg/group').getAttribute('data-block');
    const c = window.hbEditor.insertBlock('heisenberg/paragraph').getAttribute('data-block'); // nests into the selected group
    window.hbEditor.selectById(p1);
    return { p1, p2, g, c };
});
await page.waitForTimeout(250);

// ── 1: cursor language ──
const cursors = await page.evaluate(({ p1, c }) => {
    const cs = (el) => getComputedStyle(el).cursor;
    const blk1 = document.querySelector('.hb-blk[data-block="' + p1 + '"]');
    const ce1 = blk1.querySelector('.hb-ce');
    const nestedRoot = document.querySelector('.hb-blk--nested[data-block="' + c + '"] > [data-block-id]');
    return {
        block: cs(blk1),
        text: cs(ce1),
        nested: cs(nestedRoot),
    };
}, ids);
ok('hovering a block body reads grab', cursors.block === 'grab', cursors.block);
ok('hovering block rich text reads the I-beam', cursors.text === 'text', cursors.text);
ok('a nested block’s body also reads grab', cursors.nested === 'grab', cursors.nested);

// ── 2: top-level GRIP drag reorders; grabbing cursor shows while active ──
// (A paragraph is all rich text — its body deliberately keeps the I-beam and is not a
// drag source — so the top-level reorder gesture uses the toolbar grip.)
const gripAt = async () => await page.evaluate(() => {
    const grip = document.querySelector('.hb-tb [data-tb-action="drag"]');
    const r = grip.getBoundingClientRect();
    return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
});
const p2Box = await page.evaluate((id) => {
    const r = document.querySelector('.hb-blk[data-block="' + id + '"] > [data-block-id]').getBoundingClientRect();
    return { x: r.left + r.width / 2, y: r.bottom + 4 };
}, ids.p2);
await page.evaluate((id) => window.hbEditor.selectById(id), ids.p1);
await page.waitForTimeout(150);
let g1 = await gripAt();
let grabbingCursor = null;
await page.mouse.move(g1.x, g1.y);
await page.mouse.down();
await page.mouse.move(g1.x, g1.y + 30, { steps: 5 }); // past the threshold
grabbingCursor = await page.evaluate(() => ({
    body: document.body.classList.contains('hb-canvas-drag'),
    cursor: getComputedStyle(document.body).cursor,
    dimmed: !!document.querySelector('.hb-blk.is-dragging'),
}));
await page.mouse.move(p2Box.x, p2Box.y, { steps: 10 });
await page.mouse.up();
await page.waitForTimeout(250);
const order = await page.evaluate(() => window.hbEditor.getDoc().blocks.map((b) => b.id));
ok('while dragging: body grabs the grabbing class + the dragged block dims',
    grabbingCursor.body && grabbingCursor.cursor === 'grabbing' && grabbingCursor.dimmed, JSON.stringify(grabbingCursor));
ok('dragging paragraph A below paragraph B reorders the model',
    order[0] === ids.p2 && order[1] === ids.p1, JSON.stringify(order));

// ── 3: GRIP-drag a NESTED child out of its group to the top level ──
await page.evaluate((c) => window.hbEditor.selectById(c), ids.c);
await page.waitForTimeout(200);
const gripBox = await page.evaluate(() => {
    const grip = document.querySelector('.hb-tb [data-tb-action="drag"]');
    const r = grip.getBoundingClientRect();
    return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
});
const dropBox = await page.evaluate((id) => {
    const r = document.querySelector('.hb-blk[data-block="' + id + '"] > [data-block-id]').getBoundingClientRect();
    return { x: r.left + r.width / 2, y: r.bottom + 6 };
}, ids.p1);
await page.mouse.move(gripBox.x, gripBox.y);
await page.mouse.down();
await page.mouse.move(dropBox.x, dropBox.y, { steps: 12 });
await page.mouse.up();
await page.waitForTimeout(300);
const lifted = await page.evaluate(({ g, c }) => {
    const group = window.hbEditor.getModel(g);
    const top = window.hbEditor.getDoc().blocks.map((b) => b.id);
    const wrapper = document.querySelector('.hb-blk[data-block="' + c + '"]');
    return {
        groupKids: (group.innerBlocks || []).length,
        top: top,
        topLevel: !!wrapper && !wrapper.closest('.hb-blk[data-block="' + g + '"]'),
        selected: window.hbEditor.getSelectedId() === c,
    };
}, ids);
ok('the nested child left the group (model) and landed at top level (DOM)',
    lifted.groupKids === 0 && lifted.topLevel && lifted.top[2] === ids.c, JSON.stringify(lifted));
ok('the moved block stays selected after the container re-renders', lifted.selected, 'selected=' + String(lifted.selected));

// ── 4: drag a top-level paragraph INTO the group, after its new sibling ──
// give the group a fresh child first so the drop has geometry
await page.evaluate((g) => window.hbEditor.selectById(g), ids.g);
await page.waitForTimeout(150);
const newKid = await page.evaluate(() => window.hbEditor.insertBlock('heisenberg/paragraph').getAttribute('data-block'));
await page.waitForTimeout(250);
// p1 is all rich text — its drag source is the toolbar grip.
await page.evaluate((id) => window.hbEditor.selectById(id), ids.p1);
await page.waitForTimeout(200);
const p2DragBox = await gripAt();
const groupDrop = await page.evaluate((id) => {
    const r = document.querySelector('.hb-blk[data-block="' + id + '"] > [data-block-id]').getBoundingClientRect();
    return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
}, ids.g);
await page.mouse.move(p2DragBox.x, p2DragBox.y);
await page.mouse.down();
await page.mouse.move(p2DragBox.x + 30, p2DragBox.y + 10, { steps: 4 });
await page.mouse.move(groupDrop.x, groupDrop.y, { steps: 12 });
await page.mouse.up();
await page.waitForTimeout(300);
const movedIn = await page.evaluate(({ g, c, kid }) => {
    const group = window.hbEditor.getModel(g);
    return {
        kids: (group.innerBlocks || []).map((b) => b.id),
        selected: window.hbEditor.getSelectedId(),
    };
}, { g: ids.g, c: ids.p1, kid: newKid });
ok('a top-level paragraph drops INSIDE the group (model order kept)',
    movedIn.kids.indexOf(ids.p1) !== -1, JSON.stringify(movedIn));
ok('the dropped block stays selected inside the container', movedIn.selected === ids.p1, movedIn.selected);

// ── 5: clicking (no drag) a block still just SELECTS it (caret lands in its text) ──
await page.evaluate(() => window.hbEditor.deselect && window.hbEditor.deselect());
const modelsBefore = await page.evaluate(() => JSON.stringify(window.hbEditor.getDoc().blocks.map((b) => b.id)));
const ceBox = await page.evaluate((id) => {
    const r = document.querySelector('.hb-blk[data-block="' + id + '"] .hb-ce').getBoundingClientRect();
    return { x: r.left + 10, y: r.top + r.height / 2 };
}, ids.p2);
await page.mouse.move(ceBox.x, ceBox.y);
await page.mouse.down();
await page.mouse.up();
await page.waitForTimeout(200);
const afterClick = await page.evaluate((id) => ({
    selected: window.hbEditor.getSelectedId() === id,
    caret: document.activeElement && document.activeElement.classList.contains('hb-ce'),
    top: JSON.stringify(window.hbEditor.getDoc().blocks.map((b) => b.id)),
}), ids.p2);
ok('a no-drag click selects the block and lands the caret in its text (no reorder)',
    afterClick.selected && afterClick.caret && afterClick.top === modelsBefore, JSON.stringify(afterClick));

ok('no page errors across the whole matrix', errors.length === 0, errors.join(' | '));

console.log(report.join('\n'));
const fails = report.filter((r) => r.startsWith('FAIL')).length;
await browser.close();
process.exit(fails ? 1 : 0);
