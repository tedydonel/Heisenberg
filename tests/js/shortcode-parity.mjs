// Shortcode parity harness: runs tests/Fixtures/shortcode/*.txt through the JAVASCRIPT
// dialect (live/code-editor.blade.php, exposed as window.hbCodeView) and asserts each one
// round-trips byte for byte.
//
// Its PHP twin is tests/Ai/ShortcodeParityTest.php, which runs the SAME files through
// ShortcodeParser + ShortcodeSerializer. The dialect exists twice because the editor parses
// in the browser and the MCP server parses on the server; these two harnesses over one
// fixture corpus are what stop the pair from drifting.
//
// Usage:
//   HB_DUMP_PATH=/tmp/editor.html vendor/bin/phpunit --filter test_dump tests/Editor/DumpEditorHtmlTest.php
//   npm install jsdom          (in any scratch dir — jsdom resolves from the CWD you run in)
//   node <repo>/tests/js/shortcode-parity.mjs /tmp/editor.html [<repo>/tests/Fixtures/shortcode]
//
// Every line must read PASS.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';
const { JSDOM } = createRequire(process.cwd() + '/')('jsdom');

const htmlPath = process.argv[2];
if (!htmlPath) {
    console.error('usage: node shortcode-parity.mjs <dumped-editor.html> [fixtures-dir]');
    process.exit(2);
}
const fixturesDir = process.argv[3]
    || path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'Fixtures', 'shortcode');

const dom = new JSDOM(fs.readFileSync(htmlPath, 'utf8'), {
    runScripts: 'dangerously', url: 'http://localhost/editor', pretendToBeVisual: true,
    beforeParse(window) {
        window.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
        window.matchMedia = window.matchMedia || ((q) => ({ matches: false, media: q, onchange: null, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {}, dispatchEvent: () => false }));
        window.PointerEvent = window.PointerEvent || window.MouseEvent;
        window.Element.prototype.setPointerCapture = window.Element.prototype.setPointerCapture || (() => {});
        window.Element.prototype.scrollIntoView = window.Element.prototype.scrollIntoView || (() => {});
        // Nothing here should reach the network; a pending promise is the safest stub.
        window.fetch = () => new Promise(() => {});
    },
});
const { window } = dom;
await new Promise((r) => setTimeout(r, 300));

const report = [];
const ok = (label, cond, detail = '') => report.push(`${cond ? 'PASS' : 'FAIL'}  ${label}${detail ? '  — ' + detail : ''}`);

ok('window.hbCodeView is exported by the code view', !!(window.hbCodeView && window.hbCodeView.parse));
ok('window.hbEditor is available', !!(window.hbEditor && window.hbEditor.replaceDoc));

if (window.hbCodeView && window.hbEditor) {
    const files = fs.readdirSync(fixturesDir).filter((f) => f.endsWith('.txt')).sort();
    ok('fixture corpus is non-empty', files.length > 0, `${files.length} files`);

    for (const file of files) {
        const source = fs.readFileSync(path.join(fixturesDir, file), 'utf8');
        const parsed = window.hbCodeView.parse(source);

        ok(`${file}: parses without errors`, parsed.errors.length === 0,
            parsed.errors.length ? JSON.stringify(parsed.errors[0]) : '');
        ok(`${file}: produces blocks`, parsed.blocks.length > 0);

        // serialize() reads the live doc, so the parsed models go through the runtime's own
        // hydration first — the same path a real Code-view apply takes.
        window.hbEditor.replaceDoc(parsed.blocks);
        const out = window.hbCodeView.serialize();

        ok(`${file}: round-trips byte for byte`, out === source,
            out === source ? '' : `got ${JSON.stringify(out.slice(0, 120))} want ${JSON.stringify(source.slice(0, 120))}`);

        // Idempotence: re-serializing canonical output must be a no-op.
        window.hbEditor.replaceDoc(window.hbCodeView.parse(out).blocks);
        ok(`${file}: serialization is idempotent`, window.hbCodeView.serialize() === out);
    }
}

console.log(report.join('\n'));
const failed = report.filter((line) => line.startsWith('FAIL')).length;
console.log(`\n${report.length - failed}/${report.length} passed`);
process.exit(failed ? 1 : 0);
