// Captures the demo host + editor screenshots referenced in docs/demo.md.
//
// Usage (from the package root, server already running):
//   node workbench/scripts/screenshots.mjs http://127.0.0.1:8972
//
// Uses the root node_modules/playwright install (no root package.json yet —
// see this task's ground rules). Chromium is expected to already be
// installed for that install; if not, try:
//   node node_modules/playwright/cli.js install chromium

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const OUT_DIR = path.resolve(__dirname, '../../docs/screenshots');
mkdirSync(OUT_DIR, { recursive: true });

const base = process.argv[2] || 'http://127.0.0.1:8972';

async function shoot(page, name, { fullPage = true } = {}) {
    const file = path.join(OUT_DIR, `${name}.png`);
    await page.screenshot({ path: file, fullPage });
    console.log('saved', file);
}

async function main() {
    const browser = await chromium.launch();
    const context = await browser.newContext({
        viewport: { width: 1440, height: 900 },
        deviceScaleFactor: 1,
    });
    const page = await context.newPage();
    page.setDefaultTimeout(20000);

    // 1. Public blog post — the package's bundled /posts route, both locales (single
    // row, PostPublicController::resolvePost() fixed 2026-09-19 — see docs/demo.md).
    await page.goto(`${base}/posts/en/getting-started-with-widgets`, { waitUntil: 'networkidle' });
    await shoot(page, 'public-post-bundled');

    await page.goto(`${base}/posts/fr/getting-started-with-widgets`, { waitUntil: 'networkidle' });
    await shoot(page, 'public-post-fr');

    // 2. The workbench's own /blog route — capabilities wired by hand.
    await page.goto(`${base}/blog/en/getting-started-with-widgets`, { waitUntil: 'networkidle' });
    await shoot(page, 'public-post');
    // Viewport-sized (not full-page) shot for side-by-side use with the 1440x900
    // editor shots — header, breadcrumbs, title, reading time, featured image, and
    // the start of the table of contents, scrolled to the very top.
    await page.evaluate(() => window.scrollTo(0, 0));
    await shoot(page, 'public-post-hero', { fullPage: false });

    // 3. Blog index.
    await page.goto(`${base}/blog`, { waitUntil: 'networkidle' });
    await shoot(page, 'blog-index');

    // 4. Editor — media library.
    await page.goto(`${base}/editor/media`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(800); // Livewire hydration
    await shoot(page, 'media-library');

    // 5. Editor — a seeded post loaded (post id 1 on a fresh migrate:fresh +
    // demo:seed — see docs/demo.md for how that id was found: query
    // heisenberg_posts, or open /blog and follow a link, there is no listing UI
    // for "open an existing post" in src/).
    await page.goto(`${base}/editor/1`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500); // canvas hydration
    await shoot(page, 'editor-post');

    // 6. Select a block in the canvas so the inspector/sidebar populates.
    const blockSelectors = [
        '[data-hb-block]',
        '.hb-canvas [data-block-id]',
        'iframe.hb-canvas-frame',
    ];
    let selected = false;
    for (const sel of blockSelectors) {
        const locator = page.locator(sel).first();
        if (await locator.count().catch(() => 0)) {
            try {
                await locator.click({ timeout: 3000 });
                selected = true;
                break;
            } catch {
                // try the next selector
            }
        }
    }
    await page.waitForTimeout(500);
    await shoot(page, 'editor-post-block-selected');
    if (!selected) {
        console.log('warning: could not click a canvas block — inspect editor-post.png to find the right selector');
    }

    // 7. Dark mode, if a simple toggle exists.
    const darkToggleSelectors = [
        'button[aria-label*="dark" i]',
        'button[title*="dark" i]',
        '[data-hb-theme-toggle]',
        'button[aria-label*="theme" i]',
    ];
    let toggled = false;
    for (const sel of darkToggleSelectors) {
        const locator = page.locator(sel).first();
        if (await locator.count().catch(() => 0)) {
            try {
                await locator.click({ timeout: 3000 });
                toggled = true;
                break;
            } catch {
                // try the next selector
            }
        }
    }
    await page.waitForTimeout(500);
    if (toggled) {
        await shoot(page, 'editor-dark-mode');
    } else {
        console.log('warning: no dark-mode toggle found with the selectors tried — skipping editor-dark-mode.png');
    }

    // 8. Editor — email document (post id 4 on a fresh migrate:fresh + demo:seed —
    // the single-row bilingual model means fewer rows than before; see docs/demo.md).
    await page.goto(`${base}/editor/email/4`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
    await shoot(page, 'editor-email');

    // 9. The workbench's own EmailRenderer preview route (HTML + plain text).
    await page.goto(`${base}/demo/email-preview/widgets-weekly-welcome`, { waitUntil: 'networkidle' });
    await shoot(page, 'email-preview');

    await browser.close();
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
