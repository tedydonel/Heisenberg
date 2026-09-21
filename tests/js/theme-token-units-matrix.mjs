// Theme-token unit matrix (real Chromium): the Style/Themes panel edits token values WITHOUT
// their unit, and the unit still reaches the CSS.
//
// Token values are stored as `16px` but the panel is all-px, so showing the unit is noise the
// user has to retype. ThemeRepository::validate() already promotes a bare number back to
// `<n>px` on save and its comment names the display side as this panel's job — it just never
// did it, so every radius/spacing/font-size field read "16px".
//
// Stripping the unit for display is only half a fix: applyThemeVars() writes the field value
// straight into a <style> element, so a bare `16` emits `--hb-t-radius-md: 16`, which is not a
// length and silently kills every rule that uses it. This pins both halves, plus the round trip
// through a theme switch (buildRow) and the non-px units that must NOT be touched.
//
//   vendor/bin/testbench serve --port=8998
//   node <repo>/tests/js/theme-token-units-matrix.mjs [http://127.0.0.1:8998]
import { createRequire } from 'node:module';
const { chromium } = createRequire(process.cwd() + '/')('playwright');
const BASE = process.argv[2] || 'http://127.0.0.1:8998';

const report = [];
const ok = (label, cond, detail = '') => report.push(`${cond ? 'PASS' : 'FAIL'}  ${label}${detail ? '  — ' + detail : ''}`);

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1600, height: 1000 } });
const errors = [];
page.on('pageerror', (e) => errors.push(String(e).slice(0, 200)));
await page.goto(BASE + '/editor', { waitUntil: 'networkidle' });
await page.waitForFunction(() => window.hbEditor);

// Open the Style panel the way a user does — the sidebar nav item for `style:0`. The token rows
// are in the DOM either way, but reading them from a collapsed panel would not prove the user
// ever sees these values.
await page.click('[data-hb-nav="style:0"]');
await page.waitForSelector('[data-hb-token-row][data-hb-token-section="radii"]', { state: 'visible', timeout: 10000 });

// SNAPSHOT FIRST. This file edits token fields and clicks a saved-theme card, and both paths
// call the panel's own saveNow() — which PUTs to the live theme and overwrites whatever the
// developer running it had configured. (It did exactly that once, and the original was not
// recoverable.) Capture the theme the way collectTheme() builds it, and put it back at the end
// no matter how the run finishes.
const snapshot = await page.evaluate(() => {
    const inputOf = (el) => (el ? (el.matches('input') ? el : el.querySelector('input')) : null);
    // Values are DISPLAYED without their unit, so the snapshot has to re-add it — otherwise
    // "restoring" would itself rewrite every px token as a unitless one.
    const withPx = (v) => (/^\d+(\.\d+)?$/.test(String(v).trim()) ? String(v).trim() + 'px' : String(v).trim());
    const theme = { colors: [], fontSizes: [], spaces: [], radii: [], fonts: [] };

    document.querySelectorAll('[data-hb-token-row]').forEach((row) => {
        const section = row.dataset.hbTokenSection;
        if (!theme[section]) return;
        const name = row.dataset.hbTokenName || '';
        if (section === 'colors') {
            theme.colors.push({ name, label: inputOf(row.querySelector('[data-hb-token-field="label"]'))?.value || '', value: row.dataset.hbTokenColor || '#000000' });
        } else if (section === 'fonts') {
            const family = row.querySelector('[data-hb-token-field="family"]')?.dataset.value || '';
            let weights = [400];
            try { weights = JSON.parse(row.dataset.hbTokenWeights || '[400]'); } catch (e) { /* default */ }
            theme.fonts.push({ name, label: family, family, weights });
        } else {
            theme[section].push({
                name,
                label: inputOf(row.querySelector('[data-hb-token-field="label"]'))?.value || '',
                value: withPx(inputOf(row.querySelector('[data-hb-token-field="value"]'))?.value || ''),
            });
        }
    });

    const url = document.querySelector('[data-hb-theme-update-url]')?.dataset.hbThemeUpdateUrl || '';
    return { theme, url };
});

const restoreTheme = async () => {
    if (!snapshot.url) { report.push('WARN  theme not restored — no update URL found'); return; }
    // scheduleSave() debounces saveNow() by 600ms, and applyTheme() fires input events of its
    // own. Restoring immediately loses the race: the PUT lands first and the panel's pending
    // save then writes the probe theme straight back over it (observed — the restore looked
    // like it worked and the file still held the probe values). Let the debounce drain first.
    await page.waitForTimeout(1500);
    const done = await page.evaluate(async ({ theme, url }) => {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const res = await window.fetch(url, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: JSON.stringify(theme),
        });
        return res.status;
    }, snapshot);
    report.push(`${done === 200 ? 'PASS' : 'FAIL'}  theme restored to its pre-test state  — HTTP ${done}`);
};

// ---- 1. No unit is displayed in any numeric token field ----------------------
const shown = await page.evaluate(() => {
    const out = {};
    ['radii', 'spaces', 'fontSizes', 'colors'].forEach((section) => {
        out[section] = Array.from(document.querySelectorAll(`[data-hb-token-row][data-hb-token-section="${section}"]`))
            .map((row) => {
                const f = row.querySelector('[data-hb-token-field="value"]');
                const input = f ? (f.matches('input') ? f : f.querySelector('input')) : null;
                return input ? input.value : null;
            })
            .filter((v) => v !== null);
    });
    return out;
});

for (const section of ['radii', 'spaces', 'fontSizes']) {
    const values = shown[section];
    ok(`${section}: rows present`, values.length > 0, `${values.length} rows`);
    ok(
        `${section}: no px shown`,
        values.every((v) => !/px/i.test(v)),
        JSON.stringify(values),
    );
    ok(
        `${section}: values are bare numbers`,
        values.every((v) => /^\d+(\.\d+)?$/.test(v)),
        JSON.stringify(values),
    );
}

// ---- 2. The unit still reaches the CSS --------------------------------------
// This is the half that a display-only change would have broken.
const cssVars = await page.evaluate(() => {
    // Nudge a radius field and let the panel re-emit its <style> block.
    const row = document.querySelector('[data-hb-token-row][data-hb-token-section="radii"]');
    const f = row.querySelector('[data-hb-token-field="value"]');
    const input = f.matches('input') ? f : f.querySelector('input');
    input.value = '14';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));

    const name = row.dataset.hbTokenName;
    const text = (document.getElementById('hb-theme-vars') || {}).textContent || '';
    const line = text.split('\n').find((l) => l.indexOf('--hb-t-' + name + ':') !== -1) || '';
    return {
        tokenName: name,
        line: line.trim(),
        // What a consumer actually resolves — the real test of validity.
        resolved: getComputedStyle(document.documentElement).getPropertyValue('--hb-t-' + name).trim(),
    };
});

ok('edited radius emits a px length into CSS', /:\s*14px;?$/.test(cssVars.line), `line="${cssVars.line}"`);
ok('the CSS variable resolves to a length', cssVars.resolved === '14px', `resolved="${cssVars.resolved}"`);

// ---- 3. A non-px unit survives a round trip ---------------------------------
// Dropping the unit from `0.75rem` would make validate() read 0.75 and store `0.75px`.
const rem = await page.evaluate(() => {
    const row = document.querySelector('[data-hb-token-row][data-hb-token-section="fontSizes"]');
    const f = row.querySelector('[data-hb-token-field="value"]');
    const input = f.matches('input') ? f : f.querySelector('input');
    input.value = '0.75rem';
    input.dispatchEvent(new Event('input', { bubbles: true }));

    const name = row.dataset.hbTokenName;
    const text = (document.getElementById('hb-theme-vars') || {}).textContent || '';
    const line = text.split('\n').find((l) => l.indexOf('--hb-t-' + name + ':') !== -1) || '';
    return { line: line.trim(), fieldAfter: input.value };
});

ok('a rem value is not given a px suffix', /:\s*0\.75rem;?$/.test(rem.line), `line="${rem.line}"`);
ok('a rem value is left alone in the field', rem.fieldAfter === '0.75rem', rem.fieldAfter);

// ---- 4. Applying a saved theme rebuilds the rows without units ---------------
// A different code path from the server-rendered rows: applyTheme() → buildRow() repopulates
// every field from STORED tokens, which carry the unit. Miss it and the px the panel just
// stopped showing comes back the moment a theme is applied. Driven through the real click
// handler, with a synthetic card so the test does not depend on what the dev box has saved.
const afterApply = await page.evaluate(() => {
    const themes = document.querySelector('[data-hb-panel-style-themes]');
    if (!themes) return { error: 'themes container not found' };

    const payload = {
        colors: [{ name: 'ink', label: 'Ink', value: '#111111' }],
        fontSizes: [{ name: 'fs-sm', label: 'Small', value: '11px' }],
        spaces: [{ name: 'sp-1', label: 'Small', value: '7px' }],
        radii: [{ name: 'radius-xs', label: 'XS', value: '9px' }, { name: 'radius-rem', label: 'Rem', value: '0.5rem' }],
        fonts: [],
    };

    const card = document.createElement('div');
    card.setAttribute('data-hb-saved-theme', '');
    card.dataset.hbSavedThemeName = 'unit-probe';
    card.dataset.hbSavedThemePayload = JSON.stringify(payload);
    themes.appendChild(card);
    card.click();
    card.remove();

    const read = (section) => Array.from(document.querySelectorAll(`[data-hb-token-row][data-hb-token-section="${section}"]`))
        .map((row) => {
            const f = row.querySelector('[data-hb-token-field="value"]');
            const input = f ? (f.matches('input') ? f : f.querySelector('input')) : null;
            return input ? input.value : null;
        })
        .filter((v) => v !== null);

    return { radii: read('radii'), spaces: read('spaces'), fontSizes: read('fontSizes') };
});

if (afterApply.error) {
    ok('applying a saved theme', false, afterApply.error);
} else {
    ok(
        'applying a theme does not reintroduce px',
        ['radii', 'spaces', 'fontSizes'].every((s) => afterApply[s].every((v) => !/px/i.test(v))),
        JSON.stringify(afterApply),
    );
    ok('applied px values arrive bare', afterApply.spaces.includes('7') && afterApply.fontSizes.includes('11'), JSON.stringify(afterApply));
    ok('applied rem value keeps its unit', afterApply.radii.includes('0.5rem'), JSON.stringify(afterApply.radii));
}

ok('no page errors', errors.length === 0, errors.join(' | '));

await restoreTheme();

await browser.close();
console.log(report.join('\n'));
const failed = report.filter((r) => r.startsWith('FAIL')).length;
console.log(`\n${report.length - failed}/${report.length} passed`);
process.exit(failed ? 1 : 0);
