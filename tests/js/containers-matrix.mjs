// Containers matrix (real Chromium): group/columns/column nesting end-to-end — child
// selection/editing, the wired EXTRACTED Flex Layout composition (mode segmented, 3×3
// grid, spacing radios), the Alignment section on containers, embed URL normalization,
// and the code-view round trip. API-driven inserts (hbEditor.*), so it is independent
// of the appender's quick-inserter. Same usage as the other matrices:
//   vendor/bin/testbench serve --port=8787
//   node <repo>/tests/js/containers-matrix.mjs [http://127.0.0.1:8787]
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

// ── 1: a group renders as a real flex container ──
const groupId = await page.evaluate(() => {
    const el = window.hbEditor.insertBlock('heisenberg/group');
    return el ? el.getAttribute('data-block') : null;
});
await page.waitForTimeout(200);
const groupState = await page.evaluate((id) => {
    const root = document.querySelector('.hb-blk[data-block="' + id + '"] [data-block-id]');
    return root ? {
        classes: root.className,
        display: getComputedStyle(root).display,
    } : null;
}, groupId);
ok('inserting a group paints a flex container with the capability classes',
    !!groupState && groupState.display === 'flex'
    && groupState.classes.indexOf('hb-supports') !== -1 && groupState.classes.indexOf('hb-flex-layout') !== -1,
    JSON.stringify(groupState));

// ── 2: inserting while the group is selected nests INTO it; the child is editable ──
const childId = await page.evaluate(() => {
    const el = window.hbEditor.insertBlock('heisenberg/paragraph'); // group is selected
    return el ? el.getAttribute('data-block') : null;
});
await page.waitForTimeout(200);
const nested = await page.evaluate(({ g, c }) => {
    const group = window.hbEditor.getModel(g);
    const wrapper = document.querySelector('.hb-blk--nested[data-block="' + c + '"]');
    const insideGroup = !!wrapper && !!wrapper.closest('.hb-blk[data-block="' + g + '"]');
    const ce = wrapper ? wrapper.querySelector('.hb-ce[contenteditable="true"]') : null;
    return {
        modelNested: group.innerBlocks.length === 1 && group.innerBlocks[0].id === c,
        domNested: insideGroup,
        editable: !!ce,
        display: wrapper ? getComputedStyle(wrapper).display : null,
    };
}, { g: groupId, c: childId });
ok('the paragraph nests into the selected group (model + DOM), wrapper is display:contents',
    nested.modelNested && nested.domNested && nested.display === 'contents', JSON.stringify(nested));

// ── 3: the nested child is REALLY editable — a human click + real keystrokes.
// (The earlier version of this check only asserted contenteditable="true" was present,
// which passed while the docked toolbar sat on top of the text and ate every click.)
const nestedCe = '.hb-blk--nested[data-block="' + childId + '"] .hb-ce';
const uncovered = await page.evaluate((sel) => {
    const ce = document.querySelector(sel);
    const r = ce.getBoundingClientRect();
    const hit = document.elementFromPoint(Math.round(r.left + r.width / 2), Math.round(r.top + r.height / 2));
    return { clear: hit === ce || ce.contains(hit), blockedBy: hit === ce ? null : (hit.className || hit.tagName) };
}, nestedCe);
ok('nothing overlays the nested child’s text (the toolbar must not cover it)',
    uncovered.clear, JSON.stringify(uncovered));
let typed = { clicked: false, err: '' };
try { await page.click(nestedCe, { timeout: 4000 }); typed.clicked = true; }
catch (e) { typed.err = String(e).slice(0, 80); }
await page.keyboard.type('typed-inside');
await page.waitForTimeout(300);
const typedResult = await page.evaluate((c) => ({
    model: window.hbEditor.getModel(c).attributes.content,
    dom: document.querySelector('.hb-blk--nested[data-block="' + c + '"] .hb-ce').textContent,
}), childId);
ok('clicking and typing in the nested child writes ITS model and paints',
    typed.clicked && typedResult.model.indexOf('typed-inside') !== -1 && typedResult.dom.indexOf('typed-inside') !== -1,
    JSON.stringify({ ...typed, ...typedResult }));

// The toolbar for a nested selection anchors to the TOP-LEVEL ancestor — never inside the
// container, where it would cover sibling content.
await page.evaluate((c) => { document.activeElement?.blur(); window.hbEditor.selectById(c); }, childId);
await page.waitForTimeout(200);
const docked = await page.evaluate((g) => {
    const tb = document.querySelector('[data-hb-block-toolbar]');
    const host = tb ? tb.closest('.hb-blk') : null;
    return {
        selected: window.hbEditor.getSelectedId(),
        hostBlock: host ? host.getAttribute('data-block') : null,
        hostIsNested: host ? host.classList.contains('hb-blk--nested') : null,
        anchoredOnAncestor: !!host && host.getAttribute('data-block') === g,
    };
}, groupId);
ok('a nested selection anchors the toolbar on its top-level ancestor',
    docked.selected === childId && docked.anchoredOnAncestor && docked.hostIsNested === false,
    JSON.stringify(docked));

// ── 4: the EXTRACTED flex composition is present and WIRED ──
await page.evaluate((g) => window.hbEditor.selectById(g), groupId);
await page.waitForTimeout(150);
await page.locator('[data-hb-inspector] [data-hb-tablist] [data-hb-tab="style"]').first().click();
await page.waitForTimeout(200);
const panel = page.locator('[data-hb-subpanel="style"] [data-hb-block-panel="heisenberg/group"]');
const flexUi = await panel.evaluate((el) => ({
    mode: !!el.querySelector('[data-hb-style-flexmode]'),
    grid: el.querySelectorAll('[data-hb-style-alignment-grid] [data-hb-style-alignment]').length,
    radios: el.querySelectorAll('[data-hb-flex-spacing]').length,
    selects: el.querySelectorAll('[data-hb-control="layout.justify"], [data-hb-control="layout.align"], [data-hb-control="layout.direction"]').length,
}));
ok('the extracted composition renders: mode segmented, 3×3 grid, spacing radios — no substituted selects',
    flexUi.mode && flexUi.grid === 9 && flexUi.radios === 3 && flexUi.selects === 0, JSON.stringify(flexUi));

await panel.evaluate((el) => {
    el.querySelector('[data-hb-flex-justify="end"][data-hb-flex-align="center"]').click();
});
await page.waitForTimeout(250);
const afterGrid = await page.evaluate((g) => {
    const layout = window.hbEditor.getModel(g).supports.layout || {};
    const root = document.querySelector('.hb-blk[data-block="' + g + '"] [data-block-id]');
    const cs = getComputedStyle(root);
    return { justify: layout.justify, align: layout.align, cJustify: cs.justifyContent, cAlign: cs.alignItems };
}, groupId);
ok('one grid dot writes justify AND align, and the canvas computes them',
    afterGrid.justify === 'end' && afterGrid.align === 'center'
    && afterGrid.cJustify === 'end' && afterGrid.cAlign === 'center', JSON.stringify(afterGrid));

await panel.evaluate((el) => el.querySelector('[data-hb-flex-spacing="space-between"]').click());
await page.waitForTimeout(250);
const afterRadio = await page.evaluate((g) => {
    const root = document.querySelector('.hb-blk[data-block="' + g + '"] [data-block-id]');
    return { justify: window.hbEditor.getModel(g).supports.layout.justify, computed: getComputedStyle(root).justifyContent };
}, groupId);
ok('the Space Between radio owns justify', afterRadio.justify === 'space-between' && afterRadio.computed === 'space-between', JSON.stringify(afterRadio));

await panel.evaluate((el) => {
    const tab = el.querySelector('[data-hb-style-flexmode] [data-hb-tab="wrap"]');
    tab.click();
});
await page.waitForTimeout(250);
const afterMode = await page.evaluate((g) => {
    const layout = window.hbEditor.getModel(g).supports.layout || {};
    const root = document.querySelector('.hb-blk[data-block="' + g + '"] [data-block-id]');
    return { direction: layout.direction, wrap: layout.wrap, computed: getComputedStyle(root).flexWrap };
}, groupId);
ok('the wrap mode segment writes direction=row + flex-wrap=wrap and paints',
    afterMode.direction === 'row' && afterMode.wrap === 'wrap' && afterMode.computed === 'wrap', JSON.stringify(afterMode));

// ── 4b: Fill on a CONTAINER is its background, not its text colour ──
// (A frame paints no text of its own, so writing color.text tinted nothing — the section
// looked broken. The path is declared per panel and the layer stack reads it.)
const fillPath = await panel.evaluate((el) =>
    el.querySelector('[data-hb-style-layer-list="fill"]')?.dataset.hbLayerPath || null);
ok('the container’s Fill section targets color.background', fillPath === 'color.background', JSON.stringify(fillPath));
await panel.locator('[data-hb-style-add="fill"]').click();
await page.waitForTimeout(250);
await panel.evaluate((el) => {
    const hex = el.querySelector('[data-hb-style-layer-list="fill"] .hb-colorlayer .hb-colorlayer__hex');
    hex.value = '#22aa55';
    hex.dispatchEvent(new Event('input', { bubbles: true }));
    hex.dispatchEvent(new Event('change', { bubbles: true }));
});
await page.waitForTimeout(400);
const filled = await page.evaluate((g) => {
    const root = document.querySelector('.hb-blk[data-block="' + g + '"] [data-block-id]');
    return {
        model: (window.hbEditor.getModel(g).supports.color || {}).background,
        painted: getComputedStyle(root).backgroundColor,
    };
}, groupId);
ok('adding a Fill layer paints the container’s BACKGROUND on the canvas',
    filled.model === '#22aa55' && filled.painted === 'rgb(34, 170, 85)', JSON.stringify(filled));

// ── 4c: Stroke — the extracted section mounts for containers and PAINTS ──
const strokeUi = await panel.evaluate((el) => ({
    section: [...el.querySelectorAll('.hb-section__title')].some((s) => s.textContent === 'Stroke'),
    perSide: el.querySelectorAll('[data-hb-control^="border.width."]').length,
    corners: el.querySelectorAll('[data-hb-control^="border.radius."]').length,
    // Removed at the user's request; the contracts default border-style to solid instead.
    cap: el.querySelectorAll('[data-hb-control="border.style"]').length,
}));
ok('the extracted Stroke section mounts for a container with its per-side + corner fields',
    strokeUi.section && strokeUi.perSide === 4 && strokeUi.corners === 4 && strokeUi.cap === 0,
    JSON.stringify(strokeUi));
// Type into the Weight "all" field — it must fan out to all four sides through their hooks.
await panel.evaluate((el) => {
    const all = el.querySelector('[data-hb-style-all-value="stroke-sides"]');
    const input = all.querySelector('input');
    input.value = '3px';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
});
await page.waitForTimeout(400);
await page.evaluate((g) => window.hbEditor.setSupport(g, 'border.color', '#ff0000'), groupId);
await page.waitForTimeout(300);
const strokePaint = await page.evaluate((g) => {
    const m = window.hbEditor.getModel(g).supports.border || {};
    const cs = getComputedStyle(document.querySelector('.hb-blk[data-block="' + g + '"] [data-block-id]'));
    return {
        widths: m.width,
        color: m.color,
        computedWidth: cs.borderTopWidth,
        computedColor: cs.borderTopColor,
        computedStyle: cs.borderTopStyle,
    };
}, groupId);
ok('the Weight aggregate fans out to four sides and the canvas paints the stroke',
    strokePaint.widths && ['top', 'right', 'bottom', 'left'].every((s) => strokePaint.widths[s] === '3px')
    && strokePaint.computedWidth === '3px' && strokePaint.computedColor === 'rgb(255, 0, 0)'
    && strokePaint.computedStyle === 'solid',
    JSON.stringify(strokePaint));

// ── 4d: a container's border must NOT bleed onto its children ──
// (Custom properties inherit, and every child is a block root reading the same var names,
// so a 3px border on the group used to draw one around every block inside it.)
const bleed = await page.evaluate(({ g, c }) => {
    const child = document.querySelector('.hb-blk--nested[data-block="' + c + '"] [data-block-id]');
    const parent = document.querySelector('.hb-blk[data-block="' + g + '"] [data-block-id]');
    const cs = getComputedStyle(child);
    return {
        parentWidth: getComputedStyle(parent).borderTopWidth,
        childWidth: cs.borderTopWidth,
        childColor: cs.borderTopColor,
        childShadow: cs.boxShadow,
    };
}, { g: groupId, c: childId });
ok('the container’s border paints on IT and not on its children',
    bleed.parentWidth === '3px' && bleed.childWidth === '0px', JSON.stringify(bleed));

// ── 5: containers mount the EXISTING Alignment section (block placement) ──
const alignUi = await panel.evaluate((el) =>
    !!el.querySelector('[data-hb-control="align"][data-hb-control-type="segmented"]'));
ok('the group panel mounts the extracted Alignment section', alignUi);
await page.evaluate((g) => window.hbEditor.setSupport(g, 'align', 'center'), groupId);
await page.waitForTimeout(200);
const aligned = await page.evaluate((g) =>
    document.querySelector('.hb-blk[data-block="' + g + '"] [data-block-id]').classList.contains('hb-align-center'), groupId);
ok('supports.align classes the container root (hb-align-center)', aligned);

// ── 6: columns seed two columns; a paragraph inserts INTO a column ──
await page.mouse.click(40, 400); // empty canvas — deselect so the insert lands top-level
await page.waitForTimeout(150);
const colsState = await page.evaluate(() => {
    const el = window.hbEditor.insertBlock('heisenberg/columns');
    const id = el ? el.getAttribute('data-block') : null;
    const model = id ? window.hbEditor.getModel(id) : null;
    return {
        id: id,
        seeded: model ? model.innerBlocks.map((b) => b.name) : [],
        colId: model && model.innerBlocks[0] ? model.innerBlocks[0].id : null,
    };
});
await page.waitForTimeout(200);
ok('a fresh columns block seeds two column children',
    colsState.seeded.length === 2 && colsState.seeded.every((n) => n === 'heisenberg/column'), JSON.stringify(colsState.seeded));
const colsPaint = await page.evaluate((id) => {
    const root = document.querySelector('.hb-blk[data-block="' + id + '"] [data-block-id]');
    return { display: getComputedStyle(root).display, direction: getComputedStyle(root).flexDirection, nestedCols: root.querySelectorAll(':scope > .hb-blk--nested').length };
}, colsState.id);
ok('columns paints as a flex row holding both column roots',
    colsPaint.display === 'flex' && colsPaint.direction === 'row' && colsPaint.nestedCols === 2, JSON.stringify(colsPaint));
const colChild = await page.evaluate((colId) => {
    const el = window.hbEditor.insertInto(colId, 'heisenberg/paragraph');
    if (!el) return null;
    const id = el.getAttribute('data-block');
    window.hbEditor.setAttribute(id, 'content', 'col-one');
    return id;
}, colsState.colId);
await page.waitForTimeout(200);
const colPaints = await page.evaluate(({ colId, childId }) => {
    const colWrap = document.querySelector('.hb-blk--nested[data-block="' + colId + '"]');
    return !!colWrap && colWrap.textContent.indexOf('col-one') !== -1
        && !!colWrap.querySelector('.hb-blk--nested[data-block="' + childId + '"]');
}, { colId: colsState.colId, childId: colChild });
ok('insertInto lands a paragraph at depth 2 and it paints', colPaints);

// ── 7: embed normalizes pasted video URLs in the canvas (lockstep with PHP) ──
await page.mouse.click(40, 400);
await page.waitForTimeout(150);
const embedState = await page.evaluate(() => {
    const el = window.hbEditor.insertBlock('heisenberg/embed');
    const id = el.getAttribute('data-block');
    window.hbEditor.setAttribute(id, 'url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42');
    const src = document.querySelector('.hb-blk[data-block="' + id + '"] iframe')?.getAttribute('src') || null;
    window.hbEditor.setAttribute(id, 'url', 'https://evil.com/watch?v=dQw4w9WgXcQ');
    const badSrc = document.querySelector('.hb-blk[data-block="' + id + '"] iframe')?.getAttribute('src') || null;
    window.hbEditor.setAttribute(id, 'url', 'https://youtu.be/dQw4w9WgXcQ');
    const shortSrc = document.querySelector('.hb-blk[data-block="' + id + '"] iframe')?.getAttribute('src') || null;
    return { src, badSrc, shortSrc };
});
// The pasted link carries &t=42, and the start offset is PRESERVED (same as PHP) — the
// canvas mirror producing exactly this proves the two normalizers agree.
ok('a watch URL normalizes to the privacy-enhanced embed, keeping its start time',
    embedState.src === 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?start=42', JSON.stringify(embedState.src));
ok('a foreign host fails closed (no iframe src)', embedState.badSrc === null, JSON.stringify(embedState.badSrc));
ok('a youtu.be short link normalizes identically',
    embedState.shortSrc === 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');

// A non-text block is "edited" by SELECTING it and using the inspector — so the canvas
// must never let embedded third-party content swallow the click. A loaded iframe owns its
// own document: without pointer-events:none the editor never sees a mousedown and the
// block is unselectable (and therefore uneditable). Checked nested, the tightest case.
const nestedEmbed = await page.evaluate(() => {
    document.querySelector('.hb-canvas').dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
    const g = window.hbEditor.insertBlock('heisenberg/group').getAttribute('data-block');
    const e = window.hbEditor.insertInto(g, 'heisenberg/embed').getAttribute('data-block');
    window.hbEditor.setAttribute(e, 'url', 'https://vimeo.com/76979871');
    window.hbEditor.selectById(null);
    return { g: g, e: e };
});
await page.waitForTimeout(300);
const embedSel = '.hb-blk--nested[data-block="' + nestedEmbed.e + '"] [data-block-id]';
const embedCover = await page.evaluate((sel) => {
    const el = document.querySelector(sel);
    const r = el.getBoundingClientRect();
    const hit = document.elementFromPoint(Math.round(r.left + r.width / 2), Math.round(r.top + r.height / 2));
    return { tag: hit ? hit.tagName : null, inBlock: !!hit && !!hit.closest('.hb-blk') };
}, embedSel);
ok('embedded content never takes the click on the canvas (iframe is inert)',
    embedCover.tag !== 'IFRAME' && embedCover.inBlock, JSON.stringify(embedCover));
let embedClicked = true;
try { await page.click(embedSel, { timeout: 4000 }); } catch (e) { embedClicked = false; }
await page.waitForTimeout(250);
const embedEdit = await page.evaluate((id) => {
    const selected = window.hbEditor.getSelectedId();
    // ...and the inspector's own Content field is what edits it.
    const panel = document.querySelector('[data-hb-subpanel="content"] [data-hb-block-panel="heisenberg/embed"]');
    const field = panel ? panel.querySelector('[data-hb-control="url"]') : null;
    const input = field ? (field.matches('input') ? field : field.querySelector('input')) : null;
    if (input) {
        input.value = 'https://youtu.be/abc123XYZ';
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }
    return {
        selected: selected,
        panelShown: !!panel && !panel.hidden,
        model: window.hbEditor.getModel(id).attributes.url,
        src: document.querySelector('.hb-blk--nested[data-block="' + id + '"] iframe')?.getAttribute('src') || null,
    };
}, nestedEmbed.e);
ok('clicking a nested embed selects it and the inspector edits its URL live',
    embedClicked && embedEdit.selected === nestedEmbed.e && embedEdit.panelShown
    && embedEdit.model === 'https://youtu.be/abc123XYZ'
    && embedEdit.src === 'https://www.youtube-nocookie.com/embed/abc123XYZ',
    JSON.stringify(embedEdit));

// ── 8: the code view round-trips the container tree ──
await page.click('[data-hb="code-editor"]');
await page.waitForTimeout(250);
const code = await page.evaluate(() => document.querySelector('[data-hb-cv-input]').value);
ok('containers serialize as nested shortcodes',
    code.indexOf('[group') !== -1 && code.indexOf('[columns') !== -1
    && (code.match(/\[column[\s\]]/g) || []).length >= 2 && code.indexOf('col-one') !== -1,
    JSON.stringify(code.slice(0, 140)));
await page.click('[data-hb="code-editor"]');
await page.waitForTimeout(200);

// ── 9: the whole build is undoable ──
const undone = await page.evaluate(() => {
    const before = window.hbEditor.getDoc().blocks.length;
    window.hbEditor.undo();
    return { before, after: window.hbEditor.getDoc().blocks.length, canRedo: window.hbEditor.canRedo() };
});
ok('undo steps back across container edits', undone.after <= undone.before && undone.canRedo, JSON.stringify(undone));

// ── 10: SAVE → RELOAD keeps a nested column's styling (reported: "edits vanish
// after save and refresh, so they never reach render"). This walks the whole
// persistence path: buildSavePayload → POST → DB → hydration via replaceDoc.
await page.evaluate(() => { window.__states = []; document.addEventListener('hb:save-state', (e) => window.__states.push(e.detail.state)); });
const saveTree = await page.evaluate(() => {
    document.querySelector('.hb-canvas').dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
    const cols = window.hbEditor.insertBlock('heisenberg/columns').getAttribute('data-block');
    const col = window.hbEditor.getModel(cols).innerBlocks[0].id;
    window.hbEditor.setSupport(col, 'color.background', '#22aa55');
    window.hbEditor.setSupport(col, 'spacing.padding.top', '24px');
    window.hbEditor.setSupport(col, 'border.width.top', '5px');
    const p = window.hbEditor.insertInto(col, 'heisenberg/paragraph').getAttribute('data-block');
    window.hbEditor.setAttribute(p, 'content', 'inside the column');
    return { cols, col };
});
await page.waitForTimeout(400);
await page.click('.hb-topbar__save');
await page.waitForFunction(() => (window.__states || []).some((s) => ['saved', 'error', 'conflict'].includes(s)), null, { timeout: 20000 }).catch(() => {});
const saveState = await page.evaluate(() => (window.__states || []).slice(-1)[0] || null);
const savedId = await page.evaluate(() => document.querySelector('[data-hb-revisions-open]')?.dataset.hbPostId || null);
ok('saving a document containing a styled column succeeds', saveState === 'saved' && /^\d+$/.test(savedId || ''), JSON.stringify({ saveState, savedId }));

// domcontentloaded, not networkidle: once a post exists the editor holds connections
// open (autosave), so networkidle never settles on the single-threaded dev server.
await page.goto(BASE + '/editor/' + savedId, { waitUntil: 'domcontentloaded' });
await page.waitForFunction(() => window.hbEditor && window.hbEditor.getDoc().blocks.length > 0, null, { timeout: 20000 });
await page.waitForTimeout(400);
const reloaded = await page.evaluate(() => {
    // Locate the columns block by NAME: by this point the document holds several
    // top-level blocks, so blocks[0] is the group from section 1, not this one.
    const cols = window.hbEditor.getDoc().blocks.find((b) => b.name === 'heisenberg/columns');
    const col = cols && cols.innerBlocks ? cols.innerBlocks[0] : null;
    const root = col ? document.querySelector('[data-block-id="' + col.id + '"]') : null;
    const cs = root ? getComputedStyle(root) : null;
    return {
        children: cols ? (cols.innerBlocks || []).length : 0,
        supports: col ? col.supports : null,
        grandchildren: col ? (col.innerBlocks || []).length : 0,
        bg: cs ? cs.backgroundColor : null,
        borderTop: cs ? cs.borderTopWidth : null,
        padTop: cs ? cs.paddingTop : null,
    };
});
ok('after reload the column keeps its styling in the MODEL',
    reloaded.children === 2 && reloaded.grandchildren === 1
    && reloaded.supports?.color?.background === '#22aa55'
    && reloaded.supports?.spacing?.padding?.top === '24px'
    && reloaded.supports?.border?.width?.top === '5px',
    JSON.stringify(reloaded.supports));
ok('after reload the column still PAINTS its styling',
    reloaded.bg === 'rgb(34, 170, 85)' && reloaded.borderTop === '5px' && reloaded.padTop === '24px',
    JSON.stringify({ bg: reloaded.bg, borderTop: reloaded.borderTop, padTop: reloaded.padTop }));

report.push('JS ERRORS: ' + (errors.length ? errors.join(' || ') : 'none'));
console.log(report.join('\n'));
await browser.close();
process.exit(report.some((l) => l.startsWith('FAIL')) ? 1 : 0);
