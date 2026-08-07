// History + revisions matrix (real Chromium): the undo/redo stack (debounced snapshots,
// topbar button enablement, Ctrl+Z / Ctrl+Y at document level) and the post-revisions
// dialog end to end (needs-save state, list after a second save, restore through
// replaceDoc — and that a restore is itself undoable). Companion to browser-matrix.mjs —
// same usage:
//   vendor/bin/testbench serve --port=8787
//   npm install playwright && npx playwright install chromium   (any scratch dir)
//   node <repo>/tests/js/history-revisions-matrix.mjs [http://127.0.0.1:8787]
// Starts from the blank /editor every run and saves its own post, so it never depends on
// (nor cleans up) the dev database's contents.
import { createRequire } from 'node:module';
const { chromium } = createRequire(process.cwd() + '/')('playwright');
const BASE = process.argv[2] || 'http://127.0.0.1:8787';

const report = [];
const ok = (label, cond, detail = '') => report.push(`${cond ? 'PASS' : 'FAIL'}  ${label}${detail ? '  — ' + detail : ''}`);

// Snapshots commit on a 400ms debounce after hb:blocks-changed / hb:block-updated.
const DEBOUNCE = 700;

const browser = await chromium.launch();
const page = await browser.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(String(e).slice(0, 200)));
await page.goto(BASE + '/editor', { waitUntil: 'networkidle' });

// Record every hb:save-state up front: autosave fires ~3s after the post exists, so the only
// reliable way to sequence around a save is to wait for its terminal state, not a timeout.
await page.evaluate(() => {
    window.__states = [];
    document.addEventListener('hb:save-state', (e) => {
        window.__states.push({ state: e.detail.state, message: e.detail.message || '' });
    });
});
const saveMark = () => page.evaluate(() => window.__states.length);
// Waits for the first terminal save-state pushed after `from`, and returns it.
const waitSave = async (from) => {
    await page.waitForFunction(
        (i) => window.__states.slice(i).some((s) => ['saved', 'error', 'conflict'].includes(s.state)),
        from,
        { timeout: 20000 },
    );
    return page.evaluate((i) => window.__states.slice(i).find((s) => ['saved', 'error', 'conflict'].includes(s.state)), from);
};
const colorOf = () => page.evaluate(() => {
    const b = window.hbEditor.getDoc().blocks[0];
    return { len: window.hbEditor.getDoc().blocks.length, text: b && b.supports && b.supports.color ? b.supports.color.text : undefined };
});

// ── 1: undo/redo start disabled on a blank document ─────────────────────────
const initial = await page.evaluate(() => {
    const u = document.querySelector('[data-hb-undo]');
    const r = document.querySelector('[data-hb-redo]');
    return { hasBoth: !!u && !!r, undo: u ? u.disabled : null, redo: r ? r.disabled : null, canUndo: window.hbEditor.canUndo(), canRedo: window.hbEditor.canRedo() };
});
ok('undo/redo buttons start disabled on a blank document',
    initial.hasBoth && initial.undo === true && initial.redo === true && initial.canUndo === false && initial.canRedo === false,
    JSON.stringify(initial));

// ── 2: revisions dialog, needs-save state ───────────────────────────────────
// The Post tab is the inspector's default panel; only fall back to clicking it if something
// (persisted panel state, a narrow viewport) left the Block panel showing instead.
if (!(await page.locator('[data-hb-revisions-open]').first().isVisible())) {
    await page.evaluate(() => {
        const tab = document.querySelector('[data-hb-inspector] [data-hb-tablist] [role="tab"]');
        if (tab) tab.click();
    });
    await page.waitForTimeout(200);
}
await page.click('[data-hb-revisions-open]');
await page.waitForTimeout(300);
const needsSave = await page.evaluate(() => {
    const scrim = document.querySelector('[data-hb-revisions]');
    const empty = scrim ? scrim.querySelector('[data-hb-rev-empty]') : null;
    return {
        open: !!scrim && !scrim.hidden,
        emptyShown: !!empty && !empty.hidden,
        text: empty ? (empty.textContent || '').trim() : '',
        rows: scrim ? scrim.querySelectorAll('.hb-revdialog__row').length : -1,
    };
});
ok('Post-tab Revisions row opens the dialog scrim', needsSave.open, JSON.stringify(needsSave));
ok('an unsaved document shows the needs-save message, no rows',
    needsSave.emptyShown && needsSave.text.length > 0 && needsSave.rows === 0, JSON.stringify(needsSave));
await page.keyboard.press('Escape');
await page.waitForTimeout(250);
const escaped = await page.evaluate(() => document.querySelector('[data-hb-revisions]').hidden);
ok('Escape closes the revisions dialog', escaped === true, 'hidden=' + escaped);

// ── 3: history — insert, style, undo twice, redo twice ──────────────────────
await page.click('[data-hb-insert]');
await page.waitForTimeout(120);
await page.click('[data-hb-qi-block="heisenberg/paragraph"]');
await page.waitForTimeout(DEBOUNCE);
const afterInsert = await page.evaluate(() => ({
    canUndo: window.hbEditor.canUndo(),
    undoBtn: document.querySelector('[data-hb-undo]').disabled,
    len: window.hbEditor.getDoc().blocks.length,
}));
ok('inserting a block flips canUndo after the debounce', afterInsert.canUndo === true && afterInsert.len === 1, JSON.stringify(afterInsert));
ok('hb:history enables the topbar undo button', afterInsert.undoBtn === false, 'disabled=' + afterInsert.undoBtn);

const id = await page.evaluate(() => window.hbEditor.getDoc().blocks[0].id);
await page.evaluate((i) => window.hbEditor.setSupport(i, 'color.text', '#ff0000'), id);
await page.waitForTimeout(DEBOUNCE);
const styled = await colorOf();
ok('setSupport writes the colour into the doc', styled.text === '#ff0000', JSON.stringify(styled));

await page.evaluate(() => window.hbEditor.undo());
await page.waitForTimeout(200);
const undo1 = await colorOf();
ok('undo #1 rolls back the colour and keeps the block',
    undo1.len === 1 && (undo1.text === undefined || undo1.text === null || undo1.text === ''), JSON.stringify(undo1));

await page.evaluate(() => window.hbEditor.undo());
await page.waitForTimeout(200);
const undo2 = await page.evaluate(() => ({
    len: window.hbEditor.getDoc().blocks.length,
    domBlocks: document.querySelectorAll('.hb-blk').length,
    canRedo: window.hbEditor.canRedo(),
    redoBtn: document.querySelector('[data-hb-redo]').disabled,
}));
ok('undo #2 rolls the document back to empty — model and canvas',
    undo2.len === 0 && undo2.domBlocks === 0, JSON.stringify(undo2));
ok('canRedo is true and the topbar redo button is enabled',
    undo2.canRedo === true && undo2.redoBtn === false, JSON.stringify(undo2));

await page.evaluate(() => { window.hbEditor.redo(); window.hbEditor.redo(); });
await page.waitForTimeout(300);
const redone = await page.evaluate(() => {
    const b = window.hbEditor.getDoc().blocks[0];
    const el = document.querySelector('.hb-blk [data-block-id]');
    return {
        len: window.hbEditor.getDoc().blocks.length,
        text: b && b.supports && b.supports.color ? b.supports.color.text : undefined,
        color: el ? getComputedStyle(el).color : null,
    };
});
ok('redo x2 restores the block with its colour in the model', redone.len === 1 && redone.text === '#ff0000', JSON.stringify(redone));
ok('the restored colour is actually painted on the canvas', redone.color === 'rgb(255, 0, 0)', 'computed=' + redone.color);

// ── 4: Ctrl+Z / Ctrl+Y at document level ────────────────────────────────────
// Blur first so the keydown lands on <body>, i.e. the document-level path the shortcut is
// meant to serve (inputs/textareas/selects keep native undo; .hb-ce contenteditable is ours).
await page.evaluate(() => { if (document.activeElement) document.activeElement.blur(); });
await page.keyboard.press('Control+z');
await page.waitForTimeout(300);
const kbUndo = await colorOf();
ok('Ctrl+Z undoes one step from the document body',
    kbUndo.len === 1 && (kbUndo.text === undefined || kbUndo.text === null || kbUndo.text === ''), JSON.stringify(kbUndo));
await page.keyboard.press('Control+y');
await page.waitForTimeout(300);
const kbRedo = await colorOf();
ok('Ctrl+Y redoes it', kbRedo.len === 1 && kbRedo.text === '#ff0000', JSON.stringify(kbRedo));

// ── 5: revisions end to end — two saves, list, restore, undo the restore ────
await page.evaluate((i) => window.hbEditor.setAttribute(i, 'content', 'version-one'), id);
await page.waitForTimeout(DEBOUNCE);
let mark = await saveMark();
await page.click('.hb-topbar__save');
const firstSave = await waitSave(mark);
ok('first Save creates the post', firstSave.state === 'saved', JSON.stringify(firstSave));
await page.waitForFunction(
    () => /^\d+$/.test(document.querySelector('[data-hb-revisions-open]').dataset.hbPostId || ''),
    null,
    { timeout: 10000 },
);
const postId = await page.evaluate(() => document.querySelector('[data-hb-revisions-open]').dataset.hbPostId);
ok('hb:post-id teaches the Revisions row its post id', /^\d+$/.test(postId), 'postId=' + postId);

await page.evaluate((i) => window.hbEditor.setAttribute(i, 'content', 'version-two'), id);
await page.waitForTimeout(DEBOUNCE);
mark = await saveMark();
await page.click('.hb-topbar__save');
const secondSave = await waitSave(mark);
ok('second Save (PUT) succeeds — no 409 against autosave', secondSave.state === 'saved', JSON.stringify(secondSave));

await page.click('[data-hb-revisions-open]');
await page.waitForSelector('.hb-revdialog__row', { timeout: 8000 });
const rowCount = await page.locator('.hb-revdialog__row').count();
ok('the saved post lists at least one revision', rowCount >= 1, 'rows=' + rowCount);

// Rows are newest-first, so the newest snapshot is the pre-save tree ('version-one').
await page.locator('.hb-revdialog__row').first().locator('.hb-revdialog__restore').click();
// The restore is a second round trip (fetch the revision, then replaceDoc + close). Wait for
// the dialog to actually close rather than sleeping a fixed amount — the `php -S` dev server
// is single-threaded and a cold route can take well over a second to answer.
await page.waitForFunction(() => document.querySelector('[data-hb-revisions]').hidden === true, null, { timeout: 15000 }).catch(() => {});
await page.waitForTimeout(300);
const restored = await page.evaluate(() => {
    const b = window.hbEditor.getDoc().blocks[0];
    const empty = document.querySelector('[data-hb-rev-empty]');
    return {
        hidden: document.querySelector('[data-hb-revisions]').hidden,
        content: b ? b.attributes.content : null,
        canvas: (document.querySelector('.hb-canvas') || document.body).textContent || '',
        // Only meaningful when the restore failed — the dialog swaps the list for an error line.
        error: empty && !empty.hidden ? (empty.textContent || '').trim() : '',
    };
});
ok('restoring closes the dialog', restored.hidden === true, 'hidden=' + restored.hidden + (restored.error ? ' dialogSays=' + JSON.stringify(restored.error) : ''));
ok('restore applies the pre-save tree (version-one)', restored.content === 'version-one', 'content=' + JSON.stringify(restored.content));
ok('the canvas repaints the restored text', restored.canvas.includes('version-one'), 'canvasHasVersionOne=' + restored.canvas.includes('version-one'));

await page.evaluate(() => window.hbEditor.undo());
await page.waitForTimeout(300);
const undoneRestore = await page.evaluate(() => {
    const b = window.hbEditor.getDoc().blocks[0];
    return { content: b ? b.attributes.content : null, canvas: (document.querySelector('.hb-canvas') || document.body).textContent || '' };
});
ok('a restore is itself undoable', undoneRestore.content === 'version-two' && undoneRestore.canvas.includes('version-two'),
    'content=' + JSON.stringify(undoneRestore.content));

report.push('JS ERRORS: ' + (errors.length ? errors.join(' || ') : 'none'));
console.log(report.join('\n'));
await browser.close();
process.exit(report.some((l) => l.startsWith('FAIL')) ? 1 : 0);
