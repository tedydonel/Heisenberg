// Verifies the Style tab's save bar behaviour after the
// Update-vs-Create / live-inspector-refresh / binding-clarity rounds:
//   1. fresh state shows "Save to Themes" (mode=new)
//   2. clicking a saved-theme card flips the label to "Update :name" (mode=update)
//      AND the matching card gets the hb-saved-theme--active marker
//   3. opening the save form in update mode prefills + locks the name field
//   4. picking a Preset clears activeSavedTheme and reverts the bar to "Save to Themes"
//   5. hand-editing any token row clears activeSavedTheme too
//
// Runs against the testbench server at /editor on port 8787 — see
// saved-theme-update-repro.mjs for the same bootstrapping pattern.

import { createRequire } from 'node:module';
const { chromium } = createRequire(process.cwd() + '/')('playwright');

const BASE = process.env.HB_BASE_URL || 'http://127.0.0.1:8787';

function logHeader(s) {
    console.log('\n=== ' + s + ' ===');
}

const results = [];
function pass(name, payload = {}) {
    const line = 'PASS  ' + name + ' — ' + JSON.stringify(payload);
    console.log(line);
    results.push({ name, ok: true, payload });
}
function fail(name, payload = {}) {
    const line = 'FAIL  ' + name + ' — ' + JSON.stringify(payload);
    console.log(line);
    results.push({ name, ok: false, payload });
}

async function main() {
    // 0. clear any pre-existing saved themes via direct DELETE (name-by-name fallthrough)
    //    so the test starts from a known state. The testbench loads saved themes from
    //    the same disk-backed file as the editor (orchestra/testbench-core/laravel/storage).
    const boot = await fetch(BASE + '/editor', { redirect: 'manual' }).catch(() => null);
    if (!boot || boot.status >= 500) {
        console.log('SKIP  server not reachable at ' + BASE);
        return;
    }

    const browser = await chromium.launch();
    const ctx = await browser.newContext();
    const page = await ctx.newPage();

    // Wipe saved themes so the test starts clean — POST a unique name won't help, we have to
    // fetch the list and DELETE each. There's no public "list" endpoint that returns the
    // theme objects we want to delete; the page itself has them. We rely on the page-level
    // fetch later to learn current state.
    await page.goto(BASE + '/editor', { waitUntil: 'domcontentloaded' });
    // The Style/Themes panel is one of several switchable middle panels — clicking the Style
    // nav item unhides it (see live/sidebar.blade.php PANEL_SELECTOR + showPanel()).
    await page.waitForSelector('[data-hb-nav="style:0"]', { state: 'attached' });
    await page.click('[data-hb-nav="style:0"]');
    await page.waitForSelector('[data-hb-panel-style]:not([hidden])', { timeout: 5000 });

    const initialNames = await page.evaluate(() => {
        return Array.from(document.querySelectorAll('[data-hb-saved-theme]'))
            .map((el) => el.dataset.hbSavedThemeName);
    });
    const csrf = await page.evaluate(() => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
    const destroyUrl = await page.evaluate(() => document.querySelector('[data-hb-panel-style]')?.dataset.hbThemesDestroyUrl || '');
    for (const n of initialNames) {
        await page.evaluate(async ({ url, name, token }) => {
            await fetch(url, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                credentials: 'same-origin',
                body: JSON.stringify({ name }),
            });
        }, { url: destroyUrl, name: n, token: csrf });
    }
    // Reload to pick up the empty saved list.
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector('[data-hb-nav="style:0"]', { state: 'attached' });
    await page.click('[data-hb-nav="style:0"]');
    await page.waitForSelector('[data-hb-panel-style]:not([hidden])', { timeout: 5000 });

    // 1. fresh state — bar reads "Save to Themes", mode = new
    logHeader('1. fresh state shows Save to Themes');
    const fresh = await page.evaluate(() => {
        const root = document.querySelector('[data-hb-panel-style]');
        const btn = root?.querySelector('[data-hb-theme-save-open]');
        return {
            mode: btn?.dataset.hbThemeSaveMode || null,
            label: btn?.querySelector('.hb-token-add__label')?.textContent?.trim() || null,
            aria: btn?.getAttribute('aria-label') || null,
            formHidden: !!root?.querySelector('[data-hb-theme-saveform]')?.hidden,
            anyActive: !!document.querySelector('[data-hb-saved-theme].hb-saved-theme--active'),
        };
    });
    if (fresh.mode === 'new' && /Save to Themes/i.test(fresh.label) && fresh.formHidden && !fresh.anyActive) {
        pass('fresh state shows Save to Themes', fresh);
    } else {
        fail('fresh state shows Save to Themes', fresh);
    }

    // 2. open the save form, save a theme named "Brand"
    logHeader('2. save a theme named Brand');
    await page.click('[data-hb-theme-save-open]');
    await page.fill('[data-hb-theme-save-name] input', 'Brand');
    await page.click('[data-hb-theme-save-confirm]');
    // Wait for the saved-themes grid to show "Brand"
    await page.waitForFunction(() => {
        return Array.from(document.querySelectorAll('[data-hb-saved-theme]'))
            .some((el) => el.dataset.hbSavedThemeName === 'Brand');
    }, null, { timeout: 5000 });

    const afterSave = await page.evaluate(() => {
        const root = document.querySelector('[data-hb-panel-style]');
        const btn = root?.querySelector('[data-hb-theme-save-open]');
        return {
            mode: btn?.dataset.hbThemeSaveMode || null,
            label: btn?.querySelector('.hb-token-add__label')?.textContent?.trim() || null,
            aria: btn?.getAttribute('aria-label') || null,
            formHidden: !!root?.querySelector('[data-hb-theme-saveform]')?.hidden,
            cardNames: Array.from(document.querySelectorAll('[data-hb-saved-theme]')).map((el) => el.dataset.hbSavedThemeName),
        };
    });
    if (afterSave.mode === 'update'
        && /Update\s+["“]?Brand/.test(afterSave.label)
        && afterSave.formHidden
        && afterSave.cardNames.length === 1) {
        pass('save flips bar to Update Brand', afterSave);
    } else {
        fail('save flips bar to Update Brand', afterSave);
    }

    // 3. open the save form in update mode — name field should be prefilled + readonly
    logHeader('3. update mode locks the name field');
    await page.click('[data-hb-theme-save-open]');
    const updateForm = await page.evaluate(() => {
        const form = document.querySelector('[data-hb-theme-saveform]');
        const input = form?.querySelector('[data-hb-theme-save-name] input');
        return {
            hidden: !!form?.hidden,
            value: input?.value || '',
            readonly: input?.hasAttribute('readonly'),
            ariaReadonly: input?.getAttribute('aria-readonly'),
        };
    });
    if (!updateForm.hidden && updateForm.value === 'Brand' && updateForm.readonly && updateForm.ariaReadonly === 'true') {
        pass('update mode locks name to active theme', updateForm);
    } else {
        fail('update mode locks name to active theme', updateForm);
    }

    // 4. close the form, switch to Themes tab, click the Brand card — should set active marker
    logHeader('4. clicking saved-theme card sets active marker');
    await page.click('[data-hb-theme-save-cancel]');
    // Switch to Themes tab (scope to the Style/Themes panel's tablist — every panel has its own)
    await page.click('[data-hb-panel-style] [data-hb-tab]:nth-child(2)');
    await page.waitForSelector('[data-hb-saved-theme][data-hb-saved-theme-name="Brand"]', { state: 'visible' });
    await page.click('[data-hb-saved-theme][data-hb-saved-theme-name="Brand"]');
    // Wait for the Style tab to be active again (saved-card click switches back)
    await page.waitForFunction(() => {
        const style = document.querySelector('[data-hb-panel-style-style]');
        return style && !style.hidden;
    });
    const afterClick = await page.evaluate(() => {
        const root = document.querySelector('[data-hb-panel-style]');
        const btn = root?.querySelector('[data-hb-theme-save-open]');
        const cards = Array.from(document.querySelectorAll('[data-hb-saved-theme]'));
        return {
            mode: btn?.dataset.hbThemeSaveMode || null,
            label: btn?.querySelector('.hb-token-add__label')?.textContent?.trim() || null,
            activeNames: cards.filter((c) => c.classList.contains('hb-saved-theme--active')).map((c) => c.dataset.hbSavedThemeName),
        };
    });
    if (afterClick.mode === 'update'
        && /Update\s+["“]?Brand/.test(afterClick.label)
        && afterClick.activeNames.length === 1
        && afterClick.activeNames[0] === 'Brand') {
        pass('saved-card click sets active marker + Update label', afterClick);
    } else {
        fail('saved-card click sets active marker + Update label', afterClick);
    }

    // 5. pick a Preset — should clear active and revert label
    logHeader('5. picking a Preset reverts to Save to Themes');
    await page.click('[data-hb-panel-style] [data-hb-tab]:nth-child(2)');
    await page.click('[data-hb-theme-preset]');
    const afterPreset = await page.evaluate(() => {
        const root = document.querySelector('[data-hb-panel-style]');
        const btn = root?.querySelector('[data-hb-theme-save-open]');
        const cards = Array.from(document.querySelectorAll('[data-hb-saved-theme]'));
        return {
            mode: btn?.dataset.hbThemeSaveMode || null,
            label: btn?.querySelector('.hb-token-add__label')?.textContent?.trim() || null,
            activeCount: cards.filter((c) => c.classList.contains('hb-saved-theme--active')).length,
        };
    });
    if (afterPreset.mode === 'new'
        && /Save to Themes/i.test(afterPreset.label)
        && afterPreset.activeCount === 0) {
        pass('preset clears active + reverts label', afterPreset);
    } else {
        fail('preset clears active + reverts label', afterPreset);
    }

    // 6. apply Brand again, then hand-edit a token — bar should revert to Save to Themes
    logHeader('6. hand-editing a token clears active');
    // Switch to Themes tab and click Brand to re-apply
    await page.click('[data-hb-panel-style] [data-hb-tab]:nth-child(2)');
    await page.waitForSelector('[data-hb-saved-theme][data-hb-saved-theme-name="Brand"]', { state: 'visible' });
    await page.click('[data-hb-saved-theme][data-hb-saved-theme-name="Brand"]');
    await page.waitForFunction(() => {
        const btn = document.querySelector('[data-hb-theme-save-open]');
        return btn?.dataset?.hbThemeSaveMode === 'update';
    });
    // Hand-edit a label field — typing into the first Colors row's label input
    await page.evaluate(() => {
        const first = document.querySelector('[data-hb-token-section-body="colors"] [data-hb-token-field="label"] input');
        if (first) {
            first.value = (first.value || '') + 'X';
            first.dispatchEvent(new Event('input', { bubbles: true }));
        }
    });
    const afterEdit = await page.evaluate(() => {
        const root = document.querySelector('[data-hb-panel-style]');
        const btn = root?.querySelector('[data-hb-theme-save-open]');
        const cards = Array.from(document.querySelectorAll('[data-hb-saved-theme]'));
        return {
            mode: btn?.dataset.hbThemeSaveMode || null,
            label: btn?.querySelector('.hb-token-add__label')?.textContent?.trim() || null,
            activeCount: cards.filter((c) => c.classList.contains('hb-saved-theme--active')).length,
        };
    });
    if (afterEdit.mode === 'new'
        && /Save to Themes/i.test(afterEdit.label)
        && afterEdit.activeCount === 0) {
        pass('hand-edit clears active + reverts label', afterEdit);
    } else {
        fail('hand-edit clears active + reverts label', afterEdit);
    }

    await browser.close();

    const failed = results.filter((r) => !r.ok);
    console.log('\nSummary: ' + (results.length - failed.length) + '/' + results.length + ' passed');
    if (failed.length) {
        process.exit(1);
    }
}

main().catch((e) => {
    console.error(e);
    process.exit(2);
});
