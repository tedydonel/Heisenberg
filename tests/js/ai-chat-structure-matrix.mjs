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
// 1. A SLOW model: each block is followed by more than 5s of reasoning with no block, so each
//    thought is cut into its own section, live. (A block cuts the thought; the reasoning after it
//    only becomes a NEW section once 5s pass without another block.)
// ---------------------------------------------------------------------------
{
    const r = await runTurn([
        [0, { type: 'reasoning_delta', text: 'PHASE-A ' }],
        [5300, { type: 'reasoning_delta', text: 'reasoning. ' }],
        [300, { type: 'text_delta', text: '[h2]Alpha[/h2]\n' }],
        [200, { type: 'reasoning_delta', text: 'PHASE-B ' }],
        [5300, { type: 'reasoning_delta', text: 'reasoning. ' }],
        [300, { type: 'text_delta', text: '[p]Beta paragraph.[/p]\n' }],
        [200, { type: 'reasoning_delta', text: 'PHASE-C ' }],
        [5300, { type: 'reasoning_delta', text: 'reasoning. ' }],
        [300, { type: 'text_delta', text: '[button text="Gamma" url="https://example.com" /]\n' }],
        [400, { type: 'text_delta', text: 'Done — three blocks are on the canvas.' }],
        [200, { type: 'done', data: { stopReason: 'end_turn' } }],
    ], { probeAt: 11400 });

    ok('three long thoughts split into three sections', r.sections === 3, `got ${r.sections}`);
    ok('each section holds exactly its own thought',
        JSON.stringify(r.thoughts) === JSON.stringify(['PHASE-A reasoning.', 'PHASE-B reasoning.', 'PHASE-C reasoning.']),
        JSON.stringify(r.thoughts));
    ok('sections appear live, mid-stream', r.live === 2, `at t=11.4s saw ${r.live}`);
    ok('every section is sealed with a real duration (>= 4s)',
        r.labels.length === 3 && r.labels.every((l) => /^Thought for ([4-9]|\d\d)s$/.test(l)),
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

// ---------------------------------------------------------------------------
// 4. A FAST model: eight quick think -> write alternations, ~150ms of reasoning each. Every
//    canvas write used to seal the current thought and the next delta opened a fresh block, so a
//    model that finishes in a few seconds produced a wall of "Thought for 1s" sections.
// ---------------------------------------------------------------------------
{
    const frames = [];
    for (let i = 1; i <= 8; i++) {
        frames.push([i === 1 ? 0 : 60, { type: 'reasoning_delta', text: `quick thought ${i}. ` }]);
        frames.push([150, { type: 'text_delta', text: `[p]Paragraph ${i}.[/p]\n` }]);
    }
    frames.push([200, { type: 'done', data: { stopReason: 'end_turn' } }]);
    const r = await runTurn(frames);

    ok('fast model: eight sub-second thoughts do not become eight blocks', r.sections <= 2, `got ${r.sections}: ${JSON.stringify(r.labels)}`);
    ok('fast model: no thought is lost when they fold together',
        Array.from({ length: 8 }, (_, i) => `quick thought ${i + 1}.`).every((t) => r.thoughts.join(' ').includes(t)),
        JSON.stringify(r.thoughts));
    ok('fast model: the block that remains reads one running total', r.labels.every((l) => /^Thought for \d+s$/.test(l)), JSON.stringify(r.labels));
    ok('fast model: every block still reached the canvas', r.docLen === 8, `docLen ${r.docLen}`);
    ok('fast model: no page errors', r.errors.length === 0, r.errors[0] || '');
}

// ---------------------------------------------------------------------------
// 5. Reasoning I\nINED in the text stream (<think>...</think>), which is how many hosted models
//    deliver it instead of a separate channel. splitReasoning() returns the WHOLE reasoning so
//    far on every paint, and each paint after a seal opened a fresh block holding a copy of it:
//    one long thought, then a column of identical "Thought for 1s" blocks.
// ---------------------------------------------------------------------------
{
    const frames = [
        [0, { type: 'text_delta', text: '<think>Planning the email: a header, one hero line, a button' }],
        [400, { type: 'text_delta', text: ' and a quiet footer.</think>' }],
    ];
    for (let i = 1; i <= 10; i++) frames.push([120, { type: 'text_delta', text: `[p]Line ${i}.[/p]\n` }]);
    frames.push([200, { type: 'done', data: { stopReason: 'end_turn' } }]);
    const r = await runTurn(frames);

    ok('inline think: one thought is one block, not a block per text chunk', r.sections === 1, `got ${r.sections}: ${JSON.stringify(r.labels)}`);
    ok('inline think: the reasoning appears once, not repeated',
        r.thoughts.join(' ').split('Planning the email').length - 1 === 1, JSON.stringify(r.thoughts).slice(0, 200));
    ok('inline think: every block still reached the canvas', r.docLen === 10, `docLen ${r.docLen}`);
    ok('inline think: no page errors', r.errors.length === 0, r.errors[0] || '');
}

// ---------------------------------------------------------------------------
// 6. The timeline from a real fast-model session: a long first thought, a run of blocks each
//    preceded by a moment of reasoning, then a genuinely long pause. Two sections, no more: the
//    opening thought (with the quick ones folded into it) and the late one after 5s of quiet.
// ---------------------------------------------------------------------------
{
    const frames = [
        [0, { type: 'reasoning_delta', text: 'OPENING-THOUGHT ' }],
        [5500, { type: 'reasoning_delta', text: 'about the layout. ' }],
        [200, { type: 'text_delta', text: '[h2]Hello[/h2]\n' }],
    ];
    for (let i = 1; i <= 6; i++) {
        frames.push([80, { type: 'reasoning_delta', text: `quick-${i} ` }]);
        frames.push([250, { type: 'text_delta', text: `[p]Row ${i}.[/p]\n` }]);
    }
    frames.push([200, { type: 'reasoning_delta', text: 'LATE-THOUGHT ' }]);
    frames.push([5400, { type: 'reasoning_delta', text: 'after a real pause. ' }]);
    frames.push([300, { type: 'text_delta', text: '[button text="Go" url="https://example.com" /]\n' }]);
    frames.push([200, { type: 'done', data: { stopReason: 'end_turn' } }]);
    const r = await runTurn(frames);

    ok('mixed session: exactly two sections', r.sections === 2, `got ${r.sections}: ${JSON.stringify(r.labels)}`);
    ok('mixed session: the quick thoughts fold into the opening section',
        r.thoughts[0] && r.thoughts[0].includes('OPENING-THOUGHT') && r.thoughts[0].includes('quick-1') && r.thoughts[0].includes('quick-6'),
        JSON.stringify(r.thoughts).slice(0, 200));
    ok('mixed session: the late thought is its own section',
        r.thoughts[1] && r.thoughts[1].includes('LATE-THOUGHT') && !r.thoughts[1].includes('quick'),
        JSON.stringify(r.thoughts).slice(0, 240));
    ok('mixed session: no duration below a second of real thinking is stacked',
        r.labels.length === 2 && r.labels.every((l) => /^Thought for ([2-9]|\d\d)s$/.test(l)), JSON.stringify(r.labels));
    ok('mixed session: every block reached the canvas', r.docLen === 8, `docLen ${r.docLen}`);
    ok('mixed session: no page errors', r.errors.length === 0, r.errors[0] || '');
}

// ---------------------------------------------------------------------------
// 7. Real tool-loop latency. write_canvas is announced only AFTER the model finishes a round, and
//    the next round then waits on the network and the model's first token (several seconds)
//    before any reasoning starts. Only ~1s of that is THINKING. A clock that runs from the last
//    block counts the wait as quiet time and gives every round its own "Thought for 1s".
// ---------------------------------------------------------------------------
{
    const frames = [
        [0, { type: 'reasoning_delta', text: 'ROUND-1 planning the whole email. ' }],
        [900, { type: 'tool_use', data: { name: 'heisenberg__write_canvas', ok: true, arguments: { mode: 'append', code: '[h2]One[/h2]\n' } } }],
    ];
    for (let i = 2; i <= 4; i++) {
        frames.push([6200, { type: 'reasoning_delta', text: `ROUND-${i} next piece. ` }]);   // 6.2s of latency, then a thought
        frames.push([900, { type: 'tool_use', data: { name: 'heisenberg__write_canvas', ok: true, arguments: { mode: 'append', code: `[p]Row ${i}.[/p]\n` } } }]);
    }
    frames.push([300, { type: 'text_delta', text: 'All done.' }]);
    frames.push([200, { type: 'done', data: { stopReason: 'end_turn' } }]);
    const r = await runTurn(frames);

    ok('tool-loop latency: four quick rounds stay in one section', r.sections === 1, `got ${r.sections}: ${JSON.stringify(r.labels)}`);
    ok('tool-loop latency: the thought from each round is kept', [1, 2, 3, 4].every((n) => r.thoughts.join(' ').includes(`ROUND-${n}`)), JSON.stringify(r.thoughts).slice(0, 200));
    ok('tool-loop latency: every block reached the canvas', r.docLen === 4, `docLen ${r.docLen}`);
    ok('tool-loop latency: no page errors', r.errors.length === 0, r.errors[0] || '');
}

await browser.close();

console.log(report.join('\n'));
const failed = report.filter((l) => l.startsWith('FAIL')).length;
console.log(`\n${report.length - failed}/${report.length} passed`);
process.exit(failed ? 1 : 0);
