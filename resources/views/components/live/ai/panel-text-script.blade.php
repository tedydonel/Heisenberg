<script nonce="{{ heisenberg_csp_nonce() }}">
    // The assistant panel's pure text helpers: markdown rendering, reasoning/reply splitting and
    // the markup/prose partition. No DOM state and no panel state — panel-script.blade.php reads
    // them from window.hbAiText.
    (() => {
        const mdInline = (s) => s
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/(^|[^*])\*([^*\n]+)\*(?!\*)/g, '$1<em>$2</em>')
            .replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');

        const renderMarkdown = (el, raw) => {
            const escaped = String(raw)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            let html = '';
            let list = '';
            let para = [];
            const flushPara = () => {
                if (para.length) { html += '<p>' + para.map(mdInline).join('<br>') + '</p>'; para = []; }
            };
            const flushList = () => {
                if (list) { html += list + (list.indexOf('<ul') === 0 ? '</ul>' : '</ol>'); list = ''; }
            };
            escaped.split(/\r?\n/).forEach((line) => {
                const heading = line.match(/^#{1,4}\s+(.*)$/);
                const bullet = line.match(/^\s*[-*]\s+(.*)$/);
                const numbered = line.match(/^\s*\d+[.)]\s+(.*)$/);
                if (heading) { flushPara(); flushList(); html += '<p class="hb-ai-md-h">' + mdInline(heading[1]) + '</p>'; }
                else if (bullet) { flushPara(); if (list.indexOf('<ul') !== 0) { flushList(); list = '<ul>'; } list += '<li>' + mdInline(bullet[1]) + '</li>'; }
                else if (numbered) { flushPara(); if (list.indexOf('<ol') !== 0) { flushList(); list = '<ol>'; } list += '<li>' + mdInline(numbered[1]) + '</li>'; }
                else if (!line.trim()) { flushPara(); flushList(); }
                else { flushList(); para.push(line); }
            });
            flushPara();
            flushList();
            el.innerHTML = html;
        };

        const TAGS = '(think|thinking|reasoning|reflection)';
        const splitReasoning = (raw) => {
            let reasoning = '';
            let visible = raw;
            const paired = new RegExp('<' + TAGS + '\\b[^>]*>([\\s\\S]*?)<\\/\\1\\s*>', 'gi');
            visible = visible.replace(paired, (m, tag, body) => { reasoning += body; return ''; });
            const openTail = new RegExp('<' + TAGS + '\\b[^>]*>([\\s\\S]*)$', 'i');
            visible = visible.replace(openTail, (m, tag, body) => { reasoning += body; return ''; });
            const closerHead = new RegExp('^([\\s\\S]*?)<\\/' + TAGS + '\\s*>', 'i');
            visible = visible.replace(closerHead, (m, body) => { reasoning += body; return ''; });
            visible = visible.replace(new RegExp('<\\/?' + TAGS + '\\b[^>]*>', 'gi'), '');

            // Not every model uses XML-ish tags. These are the other delimiters seen in
            // the wild - bracketed ([THINK]...[/THINK]), pipe-fenced (<|think|>) and the
            // unicode-bracket style some models emit. Without them the reasoning is just
            // part of the visible reply, which is what "thinking leaks into normal chat"
            // looks like.
            const ALT = [
                /\[\s*(?:think|thinking|reasoning)\s*\]([\s\S]*?)\[\s*\/\s*(?:think|thinking|reasoning)\s*\]/gi,
                /<\|\s*(?:think|thinking|reasoning)\s*\|>([\s\S]*?)<\|\s*\/?\s*(?:end_?)?(?:think|thinking|reasoning)\s*\|>/gi,
                /◁\s*(?:think|thinking)\s*▷([\s\S]*?)◁\s*\/\s*(?:think|thinking)\s*▷/gi,
            ];
            ALT.forEach((re) => {
                visible = visible.replace(re, (m, body) => { reasoning += (reasoning ? '\n' : '') + body; return ''; });
            });
            // An unterminated bracketed opener: everything after it is still reasoning.
            visible = visible.replace(/\[\s*(?:think|thinking|reasoning)\s*\]([\s\S]*)$/i,
                (m, body) => { reasoning += (reasoning ? '\n' : '') + body; return ''; });
            return { reasoning: reasoning.trim(), visible: visible.trim() };
        };

        const extractMarkup = (text) => {
            const fenced = [];
            const fence = /```[a-zA-Z0-9_-]*\r?\n([\s\S]*?)```/g;
            let match;
            while ((match = fence.exec(text)) !== null) fenced.push(match[1].trim());
            if (fenced.length) return fenced.join('\n\n');
            const open = text.match(/```[a-zA-Z0-9_-]*\r?\n/);
            if (open) return text.slice(open.index + open[0].length).trim();
            const first = text.indexOf('[');
            const last = text.lastIndexOf(']');
            if (first === -1 || last <= first) return '';
            return text.slice(first, last + 1).trim();
        };

        const proseOf = (visible) => {
            // Prose is whatever extractMarkup does NOT claim as block markup, on
            // EITHER side of it. This used to cut at the first shortcode, which threw
            // away the closing line a model writes after the blocks ("Done — three
            // blocks are on the canvas."), so those turns rendered an empty reply.
            // The boundaries below deliberately mirror extractMarkup's so that prose
            // and markup partition the same text the same way.
            const closed = /```[a-zA-Z0-9_-]*\r?\n[\s\S]*?```/g;
            if (closed.test(visible)) {
                closed.lastIndex = 0;
                return visible.replace(closed, '\n\n').trim();
            }
            const open = visible.match(/```[a-zA-Z0-9_-]*\r?\n/);
            if (open) return visible.slice(0, open.index).trim();
            const first = visible.indexOf('[');
            if (first === -1) return visible.trim();
            const last = visible.lastIndexOf(']');
            // An unterminated '[' is a shortcode still streaming in — nothing from it
            // onward is prose yet, or half-written markup flashes in the chat bubble.
            if (last <= first) return visible.slice(0, first).trim();
            return (visible.slice(0, first) + '\n\n' + visible.slice(last + 1)).trim();
        };

        window.hbAiText = { renderMarkdown, splitReasoning, extractMarkup, proseOf };
    })();
</script>
