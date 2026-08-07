// Phase-G matrix (real Chromium): the Animate section is catalog-driven and previews on
// the canvas, panel search fields actually filter, the dead AI toolbar trigger is gone,
// and the topbar's save-failure copy is localized via data attributes.
// Companion to browser-matrix.mjs / inspector-sync-matrix.mjs — same usage:
//   vendor/bin/testbench serve --port=8787
//   npm install playwright && npx playwright install chromium   (any scratch dir)
//   node <repo>/tests/js/phase-g-matrix.mjs [http://127.0.0.1:8787]
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
await page.click('[data-hb-qi-block="heisenberg/paragraph"]');
await page.waitForTimeout(150);
const id = await page.evaluate(() => window.hbEditor.getDoc().blocks[0].id);
await page.click('.hb-blk[data-block="' + id + '"]');
await page.waitForTimeout(150);
await page.locator('[data-hb-inspector] [data-hb-tablist] [data-hb-tab="advanced"]').first().click();
await page.waitForTimeout(200);
const panel = page.locator('[data-hb-subpanel="advanced"] [data-hb-block-panel="heisenberg/paragraph"]');

// ── 1: the Animate combobox carries the REAL catalog, not the old hardcoded four ──
const animOptions = await panel.evaluate((el) =>
    [...el.querySelectorAll('[data-hb-control="animate"] [data-hb-combobox-option]')].map((o) => o.dataset.hbComboboxOption));
ok('animate options come from AnimationCatalog (None + full preset list)',
    animOptions.length >= 35 && animOptions[0] === '' && animOptions.includes('fade-up') && animOptions.includes('zoom') && animOptions.includes('pulse'),
    `count=${animOptions.length}`);
ok('the invalid hardcoded "zoom-in" preset is gone', !animOptions.includes('zoom-in'));
const sliders = await panel.evaluate((el) => {
    const dur = el.querySelector('[data-hb-control="animateDuration"]');
    const delay = el.querySelector('[data-hb-control="animateDelay"]');
    return { durMin: dur?.min, durMax: dur?.max, delayMin: delay?.min, delayMax: delay?.max };
});
ok('duration/delay sliders use real millisecond ranges',
    sliders.durMin === '100' && sliders.durMax === '3000' && sliders.delayMin === '0' && sliders.delayMax === '3000',
    JSON.stringify(sliders));
const easingCount = await panel.evaluate((el) =>
    el.querySelectorAll('[data-hb-control="animateEasing"] [data-hb-select-option]').length);
ok('easing select lists the catalog easings', easingCount === 8, 'options=' + easingCount);
const gatedBefore = await panel.evaluate((el) =>
    el.querySelector('[data-hb-control="animateDuration"]').closest('[data-hb-showwhen]').hidden);
ok('animation detail rows hide while no preset is picked', gatedBefore === true);

// ── 1b: the combobox is in static mode — typing filters the catalog it rendered ──
const beforeFilter = await panel.evaluate((el) => {
    const input = el.querySelector('[data-hb-control="animate"] [data-hb-combobox-input]');
    const was = input.value;
    input.value = 'fade';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    return was;
});
await page.waitForTimeout(150);
const fadeOnly = await panel.evaluate((el) =>
    [...el.querySelectorAll('[data-hb-control="animate"] [data-hb-combobox-option]')].map((o) => o.dataset.hbComboboxOption));
ok('typing "fade" filters the catalog down to the five Fade presets',
    fadeOnly.length === 5 && !fadeOnly.some((v) => v.indexOf('zoom') !== -1), JSON.stringify(fadeOnly));
await panel.evaluate((el, was) => {
    const input = el.querySelector('[data-hb-control="animate"] [data-hb-combobox-input]');
    input.value = '';
    input.dispatchEvent(new Event('input', { bubbles: true })); // empty query restores the master list
    input.value = was;
}, beforeFilter);
await page.waitForTimeout(150);

// ── 2: picking a preset writes the model, classes the canvas, and Play animates ──
await panel.evaluate((el) => {
    const combobox = el.querySelector('[data-hb-control="animate"]');
    const option = [...combobox.querySelectorAll('[data-hb-combobox-option]')].find((o) => o.dataset.hbComboboxOption === 'fade-up');
    combobox.__hbCombobox.select(option);
    // select() refocuses the field, and focus re-opens the menu — which would otherwise hang over
    // the rows (and the Play button) this section goes on to exercise.
    combobox.__hbCombobox.close();
});
await page.waitForTimeout(250);
const afterPick = await page.evaluate((i) => ({
    model: window.hbEditor.getModel(i).attributes.animate,
    classed: document.querySelector('.hb-blk[data-block="' + i + '"] [data-block-id]').classList.contains('hb-anim-fade-up'),
}), id);
ok('picking Fade up writes attributes.animate and classes the canvas root',
    afterPick.model === 'fade-up' && afterPick.classed, JSON.stringify(afterPick));
const pickedLabel = await panel.evaluate((el) =>
    el.querySelector('[data-hb-control="animate"] [data-hb-combobox-input]').value);
ok('the combobox field shows the preset LABEL, not the raw key', pickedLabel === 'Fade up', JSON.stringify(pickedLabel));
const gatedAfter = await panel.evaluate((el) =>
    el.querySelector('[data-hb-control="animateDuration"]').closest('[data-hb-showwhen]').hidden);
ok('animation detail rows appear once a preset is picked', gatedAfter === false);
await panel.locator('[data-hb-anim-preview]').click();
await page.waitForTimeout(120);
const playing = await page.evaluate((i) => {
    const root = document.querySelector('.hb-blk[data-block="' + i + '"] [data-block-id]');
    return { play: root.classList.contains('hb-anim-play'), name: getComputedStyle(root).animationName };
}, id);
ok('Play runs the real catalog keyframes on the canvas', playing.play && playing.name === 'hb-fade-up', JSON.stringify(playing));
await page.waitForTimeout(900);
const stopped = await page.evaluate((i) =>
    document.querySelector('.hb-blk[data-block="' + i + '"] [data-block-id]').classList.contains('hb-anim-play'), id);
ok('the preview class is dropped after duration+delay', stopped === false);

// ── 3: the Components search filters the palette ──
const filter = async (query) => page.evaluate((q) => {
    const input = document.querySelector('[data-hb-panel-cb-components] .hb-searchfield__input');
    input.value = q;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    return [...document.querySelectorAll('[data-hb-panel-cb-components] [data-hb-insert-block]')]
        .map((card) => ({ block: card.getAttribute('data-hb-insert-block'), hidden: card.classList.contains('hb-filter-hidden') }));
}, query);
const noMatch = await filter('zzz-no-such-block');
ok('a non-matching query hides every palette card', noMatch.length > 0 && noMatch.every((c) => c.hidden), JSON.stringify(noMatch));
const paraOnly = await filter('para');
ok('"para" keeps the paragraph card and hides the rest',
    paraOnly.some((c) => c.block === 'heisenberg/paragraph' && !c.hidden)
    && paraOnly.filter((c) => c.block !== 'heisenberg/paragraph').every((c) => c.hidden),
    JSON.stringify(paraOnly));
const cleared = await filter('');
ok('clearing the query restores every card', cleared.every((c) => !c.hidden));

// ── 4: dead AI trigger removed; localized save copy rides the topbar ──
const chrome = await page.evaluate(() => ({
    ai: !!document.querySelector('.hb-tb__ai'),
    more: !!document.querySelector('[data-tb-popover="more"]'),
    conflictMsg: document.querySelector('.hb-topbar')?.dataset.hbMsgConflict || '',
    networkMsg: document.querySelector('.hb-topbar')?.dataset.hbMsgNetwork || '',
}));
ok('the AI toolbar trigger is gone; the ⋯ More trigger remains', !chrome.ai && chrome.more, JSON.stringify(chrome));
ok('topbar carries localized save-failure messages', chrome.conflictMsg.length > 0 && chrome.networkMsg.length > 0);

report.push('JS ERRORS: ' + (errors.length ? errors.join(' || ') : 'none'));
console.log(report.join('\n'));
await browser.close();
process.exit(report.some((l) => l.startsWith('FAIL')) ? 1 : 0);
