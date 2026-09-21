// AI chat structure matrix (real Chromium): the assistant message must read as a sequence of
// finished pieces of work. A reasoning burst opens a collapsible "Thought for Ns" section, and
// that section SEALS the moment the blocks it was reasoning about land on the canvas — live, while
// the stream is still running, so the next thought opens a fresh section the author can watch
// arrive. Three things used to break that and each is pinned below:
//
//   * the 250ms apply throttle DROPPED a call instead of deferring it, so when a model went
//     straight back to reasoning after emitting blocks the canvas write (and the section boundary
//     riding on it) waited for the end of the turn and every later thought piled into one block;
//   * proseOf() cut the reply at the FIRST shortcode, so the closing line a model writes after the
//     blocks was thrown away and the bubble rendered empty;
//   * the final apply returned early when the blocks were unchanged, leaving the run labelled
//     "Building on the page…" after it had finished.
//
// The stream is injected by patching window.fetch with a PACED ReadableStream. Pacing is the whole
// point: an SSE body delivered in one synchronous chunk never lets the trailing timer run, and the
// bug hides. Same usage as the other matrices:
//   vendor/bin/testbench serve --port=8787
//   node <repo>/tests/js/ai-chat-structure-matrix.mjs [http://127.0.0.1:8787]
import { createRequire } from 'node:module';
const { chromium } = createRequire(process.cwd() + '/')('playwright');
const BASE = process.argv[2] || 'http://127.0.0.1:8787';

const report = [];
const ok = (label, cond, detail = '') => report.push(`${cond ? 'PASS' : 'FAIL'}  ${label}${detail ? '  — ' + detail : ''}`);

const browser = await chromium.launch();

// Drive one turn end to end and hand back what the last assistant message actually shows.
// `probeAt` is a mid-stream instant (ms after send) at which the visible section count is
// sampled — that sample is what proves the sections arrive live rather than on completion.
const runTurn = async (frames, { probeAt = 0, suggestions = null } = {}) => {
    const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
    // testbench serve is a single-threaded PHP dev server; when the PHP suite is running
    // alongside it the editor can take well over Playwright's 30s default to answer.
    page.setDefaultNavigationTimeout(120000);
    page.setDefaultTimeout(120000);
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e).slice(0, 200)));

    await page.addInitScript(([frames, suggestions]) => {
        const real = window.fetch;
        window.fetch = (u, o) => {
            const url = typeof u === 'string' ? u : (u && u.url) || '';
            if (suggestions && /\/ai\/suggest/.test(url)) {
                return Promise.resolve(new Response(JSON.stringify({ suggestions }), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                }));
            }
            if (!/\/ai\/stream/.test(url)) return real(u, o);
            const enc = new TextEncoder();
            return Promise.resolve(new Response(new ReadableStream({
                async start(c) {
                    for (const [delay, frame] of frames) {
                        await new Promise((r) => setTimeout(r, delay));
                        c.enqueue(enc.encode('data: ' + JSON.stringify(frame) + '\n\n'));
                    }
                    c.close();
                },
            }), { status: 200, headers: { 'Content-Type': 'text/event-stream' } }));
        };
    }, [frames, suggestions]);

    await page.goto(BASE + '/editor', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3000);
    const nav = page.locator('[data-hb-nav^="ai"]').first();
    if (await nav.count()) await nav.click();
    await page.waitForTimeout(700);

    const prompt = page.locator('[data-hb-ai-prompt]').first();
    await prompt.fill('build it');
    await prompt.press('Enter');

    let live = null;
    if (probeAt) {
        await page.waitForTimeout(probeAt);
        live = await page.evaluate(() => document
            .querySelectorAll('.hb-ai-msg:last-child .hb-ai-think:not([hidden])').length);
    }

    const total = frames.reduce((n, [d]) => n + d, 0);
    await page.waitForTimeout(total + 3500 - (probeAt || 0));

    const out = await page.evaluate(() => {
        const last = [...document.querySelectorAll('.hb-ai-msg')].pop();
        const secs = [...last.querySelectorAll('.hb-ai-think')].filter((e) => !e.hidden);
        const textEl = last.querySelector('[data-hb-ai-text]');
        const reply = textEl ? textEl.textContent.trim() : '';
        const doc = window.hbEditor && window.hbEditor.getDoc && window.hbEditor.getDoc();
        return {
            sections: secs.length,
            thoughts: secs.map((e) => e.querySelector('[data-hb-ai-think-text]').textContent.trim()),
            labels: secs.map((e) => e.querySelector('[data-hb-ai-think-label]').textContent.trim()),
            reply,
            applied: [...last.querySelectorAll('[data-hb-ai-applied-text]')].map((e) => e.textContent.trim()),
            chips: [...last.querySelectorAll('.hb-ai-suggest__chip')].map((e) => e.textContent.trim()),
            docLen: doc && doc.blocks ? doc.blocks.length : 0,
        };
    });

    await page.close();
    return { ...out, live, errors };
};

// ---------------------------------------------------------------------------
// 1. Three reasoning bursts around three block writes → three sections, live.
// ---------------------------------------------------------------------------
{
    const r = await runTurn([
        [0, { type: 'reasoning_delta', text: 'PHASE-A reasoning. ' }],
        [400, { type: 'text_delta', text: '[h2]Alpha[/h2]\n' }],
        [200, { type: 'reasoning_delta', text: 'PHASE-B reasoning. ' }],
        [400, { type: 'text_delta', text: '[p]Beta paragraph.[/p]\n' }],
        [200, { type: 'reasoning_delta', text: 'PHASE-C reasoning. ' }],
        [400, { type: 'text_delta', text: '[button text="Gamma" url="https://example.com" /]\n' }],
        [400, { type: 'text_delta', text: 'Done — three blocks are on the canvas.' }],
        [200, { type: 'done', data: { stopReason: 'end_turn' } }],
    ], { probeAt: 1600 });

    ok('three bursts split into three sections', r.sections === 3, `got ${r.sections}`);
    ok('each section holds exactly its own burst',
        JSON.stringify(r.thoughts) === JSON.stringify(['PHASE-A reasoning.', 'PHASE-B reasoning.', 'PHASE-C reasoning.']),
        JSON.stringify(r.thoughts));
    ok('sections appear live, mid-stream', r.live === 3, `at t=1.6s saw ${r.live}`);
    ok('every section is sealed with a duration',
        r.labels.length === 3 && r.labels.every((l) => /^Thought for \d+s$/.test(l)),
        JSON.stringify(r.labels));
    ok('reasoning never leaks into the visible reply', !/PHASE-[ABC]/.test(r.reply), r.reply);
    ok('closing prose after the blocks survives',
        r.reply === 'Done — three blocks are on the canvas.', r.reply);
    ok('raw shortcode never shows in the bubble', !/\[h2\]|\[button/.test(r.reply), r.reply);
    ok('all three blocks reached the canvas', r.docLen === 3, `docLen ${r.docLen}`);
    ok('the finished run stops reading as "Building…"',
        r.applied.some((l) => /^Built 3 blocks/.test(l)), JSON.stringify(r.applied));
    ok('no page errors', r.errors.length === 0, r.errors[0] || '');
}

// ---------------------------------------------------------------------------
// 2. A prose-only turn: the burst still closes, and nothing reads "Thinking…".
// ---------------------------------------------------------------------------
{
    const r = await runTurn([
        [0, { type: 'reasoning_delta', text: 'Just answering. ' }],
        [400, { type: 'text_delta', text: 'Heisenberg is a block engine. ' }],
        [300, { type: 'text_delta', text: 'No blocks needed here.' }],
        [200, { type: 'done', data: { stopReason: 'end_turn' } }],
    ]);

    ok('prose-only turn keeps one section', r.sections === 1, `got ${r.sections}`);
    ok('prose-only turn is not stuck thinking', !r.labels.some((l) => /Thinking/i.test(l)),
        JSON.stringify(r.labels));
    ok('prose-only reply renders in full',
        r.reply === 'Heisenberg is a block engine. No blocks needed here.', r.reply);
    ok('prose-only turn applies nothing', r.applied.length === 0, JSON.stringify(r.applied));
    ok('no page errors (prose-only)', r.errors.length === 0, r.errors[0] || '');
}

// ---------------------------------------------------------------------------
// 3. Prose BEFORE the markup is kept, and one block reads "1 block", not "1 blocks".
// ---------------------------------------------------------------------------
{
    const r = await runTurn([
        [0, { type: 'text_delta', text: 'Sure — here is a heading.\n\n' }],
        [400, { type: 'text_delta', text: '[h2]Only[/h2]\n' }],
        [400, { type: 'done', data: { stopReason: 'end_turn' } }],
    ], { suggestions: ['Add a hero image', 'Write a closing CTA'] });

    ok('leading prose is kept', r.reply === 'Sure — here is a heading.', r.reply);
    ok('leading-prose turn shows no raw markup', !/\[h2\]/.test(r.reply), r.reply);
    ok('a single block reads in the singular',
        r.applied.some((l) => /Built 1 block on the page\./.test(l)), JSON.stringify(r.applied));
    ok('suggestions render after a build', r.chips.length === 2, JSON.stringify(r.chips));
    ok('no page errors (leading prose)', r.errors.length === 0, r.errors[0] || '');
}

await browser.close();

console.log(report.join('\n'));
const failed = report.filter((l) => l.startsWith('FAIL')).length;
console.log(`\n${report.length - failed}/${report.length} passed`);
process.exit(failed ? 1 : 0);
