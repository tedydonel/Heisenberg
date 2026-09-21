// Email-spacer outline matrix (real Chromium): the hover/selection outline on an EMAIL block
// must hug the block's content, and the vertical rhythm between blocks must survive.
//
// The email templates express that rhythm as bottom PADDING on their outermost <td> (16px on
// paragraph/list/image/button, 12px on heading, 24px on separator) because margins are
// unreliable in mail clients. The outline is drawn on the block's rendered root, so that
// padding sat INSIDE the outlined box and every email block appeared to grow a dead band
// underneath it as soon as it was hovered or selected. renderBlockEl's hoistEmailSpacer()
// moves the spacer to margin on the canvas only.
//
// What this pins, per block type:
//   - the outline's bottom edge is flush with the content's bottom edge (the actual complaint)
//   - the block-to-block gap still equals the contract's own spacing (the rhythm is not lost)
//   - the spacer survives inside a `columns` layout, where the wrapper is display:contents
//   - a `columns` block's own cells are untouched (its first <td> belongs to a CHILD block)
//
//   vendor/bin/testbench serve --port=8998
//   node <repo>/tests/js/email-spacer-outline-matrix.mjs [http://127.0.0.1:8998]
import { createRequire } from 'node:module';
const { chromium } = createRequire(process.cwd() + '/')('playwright');
const BASE = process.argv[2] || 'http://127.0.0.1:8998';

const report = [];
const ok = (label, cond, detail = '') => report.push(`${cond ? 'PASS' : 'FAIL'}  ${label}${detail ? '  — ' + detail : ''}`);

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1500, height: 1000 } });
const errors = [];
page.on('pageerror', (e) => errors.push(String(e).slice(0, 200)));
await page.goto(BASE + '/editor/email', { waitUntil: 'networkidle' });
await page.waitForFunction(() => window.hbEditor && document.querySelector('[data-hb-canvas]'));

// The email surface is the whole point of the fixture; fail loudly rather than silently
// measuring the web templates.
const surface = await page.evaluate(() => ({
    documentType: document.querySelector('[data-hb-canvas]').getAttribute('data-hb-document-type'),
    emailClass: document.querySelector('[data-hb-canvas]').classList.contains('hb-canvas--email'),
}));
ok('canvas is the email surface', surface.documentType === 'email' && surface.emailClass, JSON.stringify(surface));

// One measuring function, installed in the page and reused by every case so the fixed and the
// control readings are taken exactly the same way.
await page.addInitScript(() => {
    window.hbSpacerProbe = function (blk, next) {
        // A `display: contents` wrapper generates NO box, so getBoundingClientRect() on it
        // returns an all-zero rect — measuring the wrapper of a nested block silently yields
        // nonsense (a gap of -208px, in the first run of this file). Always measure whichever
        // element actually has the box, which is also the element the outline is drawn on.
        const boxOf = (el) => (getComputedStyle(el).display === 'contents' ? el.firstElementChild : el);

        // The block's rendered table, its outermost cell, and the innermost content box inside
        // that cell. The outline is compared against the CONTENT, not the cell: the cell is the
        // thing whose padding is in question, so measuring against it would assert nothing.
        const partsOf = (el) => {
            const table = el.tagName === 'TABLE' ? el : el.querySelector('table');
            const row = table && (table.tBodies[0] ? table.tBodies[0].rows[0] : table.rows[0]);
            const cell = row && row.cells[0];
            return { table, cell, content: cell && cell.firstElementChild ? cell.firstElementChild : cell };
        };

        const outlined = boxOf(blk);
        const { table, cell, content } = partsOf(blk);

        const ob = outlined.getBoundingClientRect();
        const cb = content.getBoundingClientRect();
        const nb = boxOf(next).getBoundingClientRect();
        const ncb = partsOf(next).content.getBoundingClientRect();

        return {
            outlined, table, cell,
            metrics: {
                overhang: Math.round(ob.bottom - cb.bottom),
                // Outline edge to the next block's box. Expected to CHANGE: that is the point.
                gap: Math.round(nb.top - ob.bottom),
                // Content to the next block's content. Expected NOT to change — the reader's
                // vertical rhythm is identical, the spacer merely sits outside the outline now.
                contentGap: Math.round(ncb.top - cb.bottom),
                cellPaddingBottom: getComputedStyle(cell).paddingBottom,
                rootMarginBottom: getComputedStyle(table).marginBottom,
                outlinedTag: outlined.tagName,
                shadow: getComputedStyle(outlined).boxShadow,
            },
        };
    };
});
await page.reload({ waitUntil: 'networkidle' });
await page.waitForFunction(() => window.hbEditor && window.hbSpacerProbe);

// Each block, with the bottom spacing its own email contract declares.
const CASES = [
    { name: 'heisenberg/paragraph', spacer: 16 },
    { name: 'heisenberg/heading', spacer: 12 },
    { name: 'heisenberg/button', spacer: 16 },
    { name: 'heisenberg/list', spacer: 16 },
    { name: 'heisenberg/separator', spacer: 24 },
];

// Seed one block of each type, then measure. Two blocks of the same type in a row give the
// gap measurement a same-type neighbour, so the number compared against `spacer` is that
// contract's own spacing and not a blend of two.
for (const { name, spacer } of CASES) {
    const measured = await page.evaluate(async ({ name }) => {
        // Fresh doc each time: replaceDoc with just this block twice over. normalizeModel()
        // REASSIGNS ids (hb1, hb2, …), so the ids to measure have to be read back from the
        // doc rather than assumed from what was passed in.
        const mk = () => ({ name, attributes: {}, innerBlocks: [] });
        window.hbEditor.replaceDoc([mk(), mk()]);
        await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));

        const ids = window.hbEditor.getDoc().blocks.map((b) => b.id);
        if (ids.length !== 2) return { error: 'expected 2 blocks, got ' + ids.length };

        window.hbEditor.selectById(ids[0]);
        await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));

        const blk = document.querySelector('.hb-blk[data-block="' + ids[0] + '"]');
        const next = document.querySelector('.hb-blk[data-block="' + ids[1] + '"]');
        if (!blk || !next) return { error: 'blocks did not render' };

        const m = window.hbSpacerProbe(blk, next);

        // In-page CONTROL: put the spacer back exactly where the contract authors it — padding
        // on the cell, no margin on the root — and re-measure. Without this the "overhang≈0"
        // numbers below prove nothing, since they would also hold if the block simply had no
        // spacer at all. The `before` overhang is the bug as the screenshot shows it.
        const cellPadBefore = m.cell.style.paddingBottom;
        const rootMarginBefore = m.table.style.marginBottom;
        m.cell.style.paddingBottom = rootMarginBefore || cellPadBefore;
        m.table.style.marginBottom = '';
        const before = window.hbSpacerProbe(blk, next).metrics;
        m.cell.style.paddingBottom = cellPadBefore;
        m.table.style.marginBottom = rootMarginBefore;

        return {
            after: m.metrics,
            before,
            selectedIsTarget: window.hbEditor.getSelectedId() === ids[0],
        };
    }, { name });

    if (measured.error) { ok(`${name}`, false, measured.error); continue; }

    const { after, before } = measured;
    const short = name.replace('heisenberg/', '');

    // A few px of overhang is the cell's line box, not the spacer, so what matters is the
    // DELTA against the control: the dead band must be gone, not merely small.
    ok(
        `${short}: dead band below content removed`,
        after.overhang <= 4 && before.overhang - after.overhang >= spacer - 2,
        `overhang ${before.overhang}px → ${after.overhang}px  (spacer ${spacer}px)`,
    );
    ok(`${short}: spacer no longer inside the outlined box`, after.cellPaddingBottom === '0px', `cellPadBottom=${after.cellPaddingBottom}`);
    // Rhythm preserved. The outline-edge gap necessarily CHANGES (2px → the spacer) because
    // the spacer moved outside the box; what must not change is the distance the reader sees,
    // content to content. That is the number the control pins.
    ok(
        `${short}: ${spacer}px rhythm kept`,
        Math.abs(after.gap - spacer) <= 3 && Math.abs(after.contentGap - before.contentGap) <= 3,
        `outlineGap ${before.gap}→${after.gap}px  contentGap ${before.contentGap}→${after.contentGap}px`,
    );
    ok(`${short}: still selectable with an outline`, measured.selectedIsTarget && after.shadow !== 'none', after.shadow);
}

// A columns layout: the wrapper of each nested block is display:contents, so the margin has to
// live on the rendered root or the rhythm vanishes inside every column. And `columns` roots at
// `table > tr > [inner-blocks]`, so its first <td> belongs to a CHILD column — hoisting off
// that cell would zero a column's padding and hang the margin on the wrong element.
const nested = await page.evaluate(async () => {
    window.hbEditor.replaceDoc([{
        name: 'heisenberg/columns', attributes: {}, innerBlocks: [
            {
                name: 'heisenberg/column', attributes: {}, innerBlocks: [
                    { name: 'heisenberg/paragraph', attributes: {}, innerBlocks: [] },
                    { name: 'heisenberg/paragraph', attributes: {}, innerBlocks: [] },
                ],
            },
        ],
    }]);
    await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));

    // Ids are reassigned by normalizeModel(), so walk the doc for them.
    const colsModel = window.hbEditor.getDoc().blocks[0];
    const colModel = colsModel && colsModel.innerBlocks[0];
    const innerModels = (colModel && colModel.innerBlocks) || [];
    if (innerModels.length !== 2) return { error: 'expected 2 nested paragraphs, got ' + innerModels.length };

    window.hbEditor.selectById(innerModels[0].id);
    await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));

    const blk = document.querySelector('.hb-blk[data-block="' + innerModels[0].id + '"]');
    const next = document.querySelector('.hb-blk[data-block="' + innerModels[1].id + '"]');
    const colsEl = document.querySelector('.hb-blk[data-block="' + colsModel.id + '"]');
    const colEl = document.querySelector('.hb-blk[data-block="' + colModel.id + '"]');
    if (!blk || !next || !colsEl || !colEl) return { error: 'nested tree did not render' };

    const m = window.hbSpacerProbe(blk, next);

    // Same in-page control as the flat cases.
    const cellPadBefore = m.cell.style.paddingBottom;
    const rootMarginBefore = m.table.style.marginBottom;
    m.cell.style.paddingBottom = rootMarginBefore || cellPadBefore;
    m.table.style.marginBottom = '';
    const before = window.hbSpacerProbe(blk, next).metrics;
    m.cell.style.paddingBottom = cellPadBefore;
    m.table.style.marginBottom = rootMarginBefore;

    return {
        after: m.metrics,
        before,
        wrapperDisplay: getComputedStyle(blk).display,
        selectedIsTarget: window.hbEditor.getSelectedId() === innerModels[0].id,
        // The columns block must not have been given a margin off its child's cell, and the
        // column's own cell padding must be untouched.
        colsMarginBottom: getComputedStyle(colsEl.tagName === 'TABLE' ? colsEl : colsEl.querySelector('table')).marginBottom,
        colPaddingBottom: getComputedStyle(colEl).paddingBottom,
        colIsTd: colEl.tagName === 'TD',
    };
});

if (nested.error) {
    ok('nested in columns', false, nested.error);
} else {
    ok('nested: wrapper is display:contents', nested.wrapperDisplay === 'contents', nested.wrapperDisplay);
    ok(
        'nested: dead band below content removed',
        nested.after.overhang <= 4 && nested.before.overhang - nested.after.overhang >= 14,
        `overhang ${nested.before.overhang}px → ${nested.after.overhang}px on <${nested.after.outlinedTag}>`,
    );
    ok(
        'nested: 16px rhythm kept inside a column',
        Math.abs(nested.after.gap - 16) <= 3 && Math.abs(nested.after.contentGap - nested.before.contentGap) <= 3,
        `outlineGap ${nested.before.gap}→${nested.after.gap}px  contentGap ${nested.before.contentGap}→${nested.after.contentGap}px`,
    );
    ok('nested: outline drawn on the rendered root', nested.after.shadow !== 'none' && nested.selectedIsTarget, nested.after.shadow);
    ok('columns block was not given the child cell\'s margin', nested.colsMarginBottom === '0px', `colsMargin=${nested.colsMarginBottom}`);
    ok('column cell padding untouched', nested.colIsTd && nested.colPaddingBottom === '0px', `isTd=${nested.colIsTd} pad=${nested.colPaddingBottom}`);
}

ok('no page errors', errors.length === 0, errors.join(' | '));

await browser.close();
console.log(report.join('\n'));
const failed = report.filter((r) => r.startsWith('FAIL')).length;
console.log(`\n${report.length - failed}/${report.length} passed`);
process.exit(failed ? 1 : 0);
