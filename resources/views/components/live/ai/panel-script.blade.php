<script>
    {{-- The assistant. SSE contract: text_delta / tool_use / done / error frames. The content
         path is the write_canvas tool: its tool_use frame carries the shortcode as arguments
         (validated server-side), applyCanvasTool lands it in the editor. Bare shortcode in the
         reply text still builds live as a fallback, standing down once a tool build happens.
         Reasoning feeds the thinking block, other tool_use frames feed the applied card,
         finished turns persist to the conversations API, and prior turns ride along as
         `history` so a reopened conversation is remembered. --}}
    (() => {
        const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        // ── markdown ─────────────────────────────────────────────────────
        // Assistant prose arrives as markdown; showing it raw (**bold**, - lists)
        // reads like a diff, not a reply. Tiny renderer, escape-first so model
        // output can never inject markup: bold/italic/code/links inline, plus
        // headings and lists at line level. Links only ever http(s), new tab.
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

        const boot = () => {
            document.querySelectorAll('[data-hb-panel-ai]').forEach((root) => {
                if (root.__hbAssistant) return;
                root.__hbAssistant = true;

                const url = root.dataset.streamUrl || '';
                const convUrl = root.dataset.conversationsUrl || '';
                const suggestUrl = root.dataset.suggestUrl || '';
                const input = root.querySelector('[data-hb-ai-prompt]');
                const send = root.querySelector('[data-hb-ai-send]');
                const stop = root.querySelector('[data-hb-ai-stop]');
                const thread = root.querySelector('[data-hb-ai-thread]');
                const emptyEl = root.querySelector('[data-hb-ai-empty]');
                const userTpl = root.querySelector('[data-hb-ai-user-template]');
                const aiTpl = root.querySelector('[data-hb-ai-assistant-template]');
                const scroller = root.querySelector('[data-hb-ai-scroll]');
                const modelSel = root.querySelector('[data-hb-ai-model]');
                const msg = (key) => root.dataset[key] || '';
                const locale = () => root.dataset.locale || 'en';

                let lastPrompt = '';
                let controller = null;
                let lastRun = null;
                // Conversation state. `history` is the model's memory: the prior
                // user/assistant turns, resent verbatim with every request.
                let conversationId = null;
                let history = [];

                const postId = () => root.dataset.postId || '';
                document.addEventListener('hb:post-id', (event) => {
                    const id = event.detail && event.detail.id != null ? String(event.detail.id) : '';
                    if (id) root.dataset.postId = id;
                });

                // The real ui/select, rendered with the operator's configured models; sent as
                // `model` per request — only configured models exist there, so this widens nothing.
                const selectedModel = () => (modelSel ? modelSel.dataset.value || '' : '');

                // ── reasoning split ──────────────────────────────────────────
                // <think> blocks come from serving templates, not the model's
                // choice. The old panel stripped them to the void; the thinking
                // block needs them, so split rather than strip.
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

                // Prose around the markup — what the bubble shows. The markup
                // itself lives on the canvas, never in the chat.
                const proseOf = (visible) => {
                    const fence = visible.search(/```/);
                    const tag = visible.search(/\[[a-z][a-z0-9-]*(\s|\]|\/|=)/i);
                    const cut = Math.min(fence === -1 ? Infinity : fence, tag === -1 ? Infinity : tag);
                    return (cut === Infinity ? visible : visible.slice(0, cut)).trim();
                };

                const atBottom = () => !scroller
                    || scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < 40;
                const scrollToEnd = () => { if (scroller) scroller.scrollTop = scroller.scrollHeight; };

                // ── canvas follow ────────────────────────────────────────────
                // The building side of work item "watchable builds": pulse the
                // newest landed block and keep it in view while the run lasts.
                const canvasFollow = (final) => {
                    const canvas = document.querySelector('.hb-canvas');
                    if (!canvas) return;
                    canvas.querySelectorAll('[data-block].hb-ai-writing').forEach((el) => el.classList.remove('hb-ai-writing'));
                    if (final) return;
                    const blocks = canvas.querySelectorAll(':scope [data-block]');
                    const tail = blocks[blocks.length - 1];
                    if (!tail) return;
                    tail.classList.add('hb-ai-writing');
                    try { tail.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (e) { /* older engines */ }
                };

                // ── transcript nodes ─────────────────────────────────────────
                const addUser = (text) => {
                    emptyEl.hidden = true;
                    const node = userTpl.content.firstElementChild.cloneNode(true);
                    node.querySelector('[data-hb-ai-msg-role]').textContent = msg('msgRoleYou');
                    node.querySelector('[data-hb-ai-text]').textContent = text;
                    node.querySelector('[data-hb-ai-edit]').addEventListener('click', () => {
                        input.value = text;
                        autoGrow();
                        input.focus();
                    });
                    thread.appendChild(node);
                    scrollToEnd();
                    return node;
                };

                const addNote = (text, isError) => {
                    emptyEl.hidden = true;
                    const node = userTpl.content.firstElementChild.cloneNode(true);
                    node.classList.remove('hb-ai-msg--user');
                    node.classList.add('hb-ai-msg--note');
                    if (isError) node.classList.add('hb-ai-msg--error');
                    node.querySelector('[data-hb-ai-msg-role]').hidden = true;
                    node.querySelector('[data-hb-ai-edit]').remove();
                    node.querySelector('[data-hb-ai-text]').textContent = text;
                    thread.appendChild(node);
                    scrollToEnd();
                    return node;
                };

                const addAssistant = () => {
                    emptyEl.hidden = true;
                    const node = aiTpl.content.firstElementChild.cloneNode(true);
                    node.querySelector('[data-hb-ai-msg-role]').textContent = msg('msgRoleAssistant');
                    const refs = {
                        node: node,
                        textEl: node.querySelector('[data-hb-ai-text]'),
                        think: node.querySelector('[data-hb-ai-think]'),
                        thinkLabel: node.querySelector('[data-hb-ai-think-label]'),
                        thinkText: node.querySelector('[data-hb-ai-think-text]'),
                        applied: node.querySelector('[data-hb-ai-applied]'),
                        appliedList: node.querySelector('[data-hb-ai-applied-list]'),
                        suggest: node.querySelector('[data-hb-ai-suggest]'),
                        actions: node.querySelector('[data-hb-ai-actions]'),
                        userToggledThink: false,
                    };
                    refs.think.querySelector('[data-hb-ai-think-head]').addEventListener('click', () => {
                        refs.userToggledThink = true;
                        refs.think.classList.toggle('is-open');
                    });
                    thread.appendChild(node);
                    scrollToEnd();
                    return refs;
                };

                const appliedItem = (refs, text) => {
                    const tpl = refs.applied.querySelector('template');
                    const item = tpl.content.firstElementChild.cloneNode(true);
                    item.querySelector('[data-hb-ai-applied-text]').textContent = text;
                    refs.appliedList.appendChild(item);
                    refs.applied.hidden = false;
                    return item.querySelector('[data-hb-ai-applied-text]');
                };

                // Fill a finished turn's quick-insert chips from the model's own
                // conversation-aware, language-matched suggestions. Each chip
                // sends its text as the next prompt. Best-effort: no suggestions,
                // no row.
                const renderSuggestions = (refs, suggestions) => {
                    const row = refs.suggest.querySelector('[data-hb-ai-suggest-row]');
                    if (!row) return;
                    row.innerHTML = '';
                    suggestions.forEach((text) => {
                        const chip = document.createElement('button');
                        chip.type = 'button';
                        chip.className = 'hb-ai-suggest__chip';
                        chip.textContent = text;
                        chip.addEventListener('click', () => run(text));
                        row.appendChild(chip);
                    });
                    refs.suggest.hidden = suggestions.length === 0;
                };

                const loadSuggestions = (refs) => {
                    if (!suggestUrl || history.length === 0) return;
                    api(suggestUrl, {
                        method: 'POST',
                        body: JSON.stringify({ history: history, locale: locale(), model: selectedModel() || null }),
                    })
                        .then((r) => (r.ok ? r.json() : null))
                        .then((data) => {
                            const list = (data && Array.isArray(data.suggestions)) ? data.suggestions : [];
                            if (list.length) { renderSuggestions(refs, list); if (atBottom()) scrollToEnd(); }
                        })
                        .catch(() => {});
                };

                // ── persistence ──────────────────────────────────────────────
                // Fire-and-forget: history is a convenience, never a reason to
                // block or fail a turn.
                const api = (path, options) => window.fetch(path, Object.assign({
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                }, options));

                const ensureConversation = () => {
                    if (!convUrl) return Promise.resolve(null);
                    if (conversationId) return Promise.resolve(conversationId);
                    return api(convUrl, { method: 'POST', body: JSON.stringify({ post_id: postId() || null }) })
                        .then((r) => (r.ok ? r.json() : null))
                        .then((data) => { conversationId = data && data.id ? data.id : null; return conversationId; })
                        .catch(() => null);
                };

                const saveTurn = (role, content, meta) => {
                    if (!convUrl || !content) return;
                    ensureConversation().then((id) => {
                        if (!id) return;
                        api(convUrl + '/' + id + '/messages', {
                            method: 'POST',
                            body: JSON.stringify({ role: role, content: content, meta: meta || null, post_id: postId() || null }),
                        }).catch(() => {});
                    });
                };

                // ── restoring a conversation (history dialog → here) ─────────
                const renderStored = (m) => {
                    if (m.role === 'user') { addUser(m.content); return; }
                    const refs = addAssistant();
                    const meta = m.meta || {};
                    if (meta.reasoning) {
                        refs.thinkText.textContent = meta.reasoning;
                        refs.thinkLabel.textContent = meta.thoughtSecs
                            ? msg('msgThoughtFor').replace(':secs', String(meta.thoughtSecs))
                            : msg('msgThinkingLabel');
                        refs.think.hidden = false;
                    }
                    (meta.applied || []).forEach((line) => appliedItem(refs, line));
                    renderMarkdown(refs.textEl, proseOf(m.content) || m.content);
                    refs.actions.hidden = true; // regenerating a historic turn would rebuild against a stale baseline
                };

                const resetThread = () => {
                    if (controller) controller.abort();
                    thread.innerHTML = '';
                    emptyEl.hidden = false;
                    lastPrompt = '';
                    conversationId = null;
                    history = [];
                    setBusy(false);
                };

                document.addEventListener('hb:ai-open-conversation', (event) => {
                    const id = event.detail && event.detail.id;
                    if (!id || !convUrl) return;
                    api(convUrl + '/' + id, { method: 'GET' })
                        .then((r) => (r.ok ? r.json() : Promise.reject(new Error('http'))))
                        .then((data) => {
                            resetThread();
                            conversationId = data.id;
                            const messages = data.messages || [];
                            // A regenerated reply stored both versions; the model
                            // and the transcript only want the final one.
                            const collapsed = messages.filter((m, i) =>
                                !(m.role === 'assistant' && messages[i + 1] && messages[i + 1].role === 'assistant'));
                            collapsed.forEach(renderStored);
                            history = collapsed.map((m) => ({ role: m.role, content: m.content }));
                            const last = collapsed.filter((m) => m.role === 'user').pop();
                            lastPrompt = last ? last.content : '';
                            emptyEl.hidden = collapsed.length > 0;
                            scrollToEnd();
                        })
                        .catch(() => addNote(msg('msgHistoryError'), true));
                });

                const setBusy = (busy) => {
                    send.hidden = busy;
                    stop.hidden = !busy;
                    input.disabled = false;
                };

                const autoGrow = () => {
                    input.style.height = 'auto';
                    input.style.height = Math.min(input.scrollHeight, 160) + 'px';
                };

                const selectionContext = () => {
                    const ed = window.hbEditor;
                    if (!ed || !ed.getSelectedId) return {};
                    const id = ed.getSelectedId();
                    if (!id) return {};
                    const model = ed.getModel(id);
                    if (!model) return {};
                    const contract = ed.getContract ? ed.getContract(model.name) : null;
                    const defs = (contract && contract.attributeDefinitions) || {};
                    let selection = '';
                    Object.keys(defs).forEach((key) => {
                        const def = defs[key] || {};
                        const value = (model.attributes || {})[key];
                        if (def.type === 'rich-text' && typeof value === 'string' && value) selection = value;
                    });
                    return { selection: selection, blockName: model.name || '' };
                };

                const documentContext = () => {
                    const base = selectionContext();
                    if (window.hbCodeView && window.hbCodeView.serialize) {
                        try { base.document = window.hbCodeView.serialize(); } catch (e) { /* empty doc */ }
                    }
                    const title = document.querySelector('[data-hb-title]');
                    if (title) base.title = (title.value || title.textContent || '').trim();
                    // docs/content-translation.md §0/Wave 2 — lets EditorPrompt::user() state the
                    // TRANSLATING rule against the concrete locale pair.
                    if (window.hbEditor && window.hbEditor.getEditingLocale) base.editingLocale = window.hbEditor.getEditingLocale();
                    if (window.hbEditor && window.hbEditor.getHomeLocale) base.homeLocale = window.hbEditor.getHomeLocale();
                    return base;
                };

                const run = (prompt, replace) => {
                    if (!url || !prompt) return;
                    lastPrompt = prompt;
                    if (controller) controller.abort();
                    controller = new AbortController();

                    if (!replace) {
                        addUser(prompt);
                        saveTurn('user', prompt);
                        history.push({ role: 'user', content: prompt });
                    }
                    const reply = replace || addAssistant();
                    reply.textEl.textContent = msg('msgThinking');
                    reply.actions.hidden = true;
                    reply.suggest.hidden = true;
                    // Regenerate reuses the node — clear last run's chips so they
                    // can't linger under a fresh reply.
                    const suggestRow = reply.suggest.querySelector('[data-hb-ai-suggest-row]');
                    if (suggestRow) suggestRow.innerHTML = '';
                    setBusy(true);
                    let acc = '';
                    let sawDone = false;
                    let stopReason = '';
                    const stick = atBottom();
                    // Thinking-block timing: reasoning starts the clock, the
                    // first visible character stops it.
                    let tReasonStart = 0;
                    let thoughtSecs = 0;
                    let appliedLines = [];
                    let builtEl = null; // the applied card's live "built N blocks" row
                    let toolBuilt = false; // a write_canvas call landed — text extraction stands down

                    if (replace && lastRun && lastRun.applied && window.hbEditor) {
                        window.hbEditor.replaceDoc(lastRun.baseline);
                    }
                    lastRun = {
                        baseline: window.hbEditor ? JSON.parse(JSON.stringify(window.hbEditor.getDoc().blocks || [])) : [],
                        applied: false,
                    };
                    let lastAppliedStamp = '';
                    let lastApplyAt = 0;
                    let builtCount = 0;
                    // ── the direct write path ────────────────────────────
                    // write_canvas is the assistant's real write access to the
                    // editor: the tool_use frame arrives with the shortcode as
                    // `arguments.code`, already validated server-side (`ok`).
                    // Parsing through the same hbCodeView parser the code view
                    // uses keeps this the one dialect, one grammar. The locale
                    // decision (replace/append vs. fold-a-translation) lives in
                    // hbEditor.applyCanvasWrite (docs/content-translation.md §0).
                    const applyCanvasTool = (data) => {
                        if (data.ok === false || !window.hbCodeView || !window.hbEditor) return;
                        const args = data.arguments || {};
                        const parsed = window.hbCodeView.parse(String(args.code || ''));
                        if (!parsed || !parsed.blocks.length) return;
                        toolBuilt = true;
                        const result = window.hbEditor.applyCanvasWrite(parsed.blocks, args.mode);
                        if (result.refusedAppend) {
                            lastRun.applied = false;
                            addNote(msg('msgTranslateAppendRefused'), true);
                            return;
                        }
                        if (!result.ok) {
                            lastRun.applied = false;
                            addNote(result.error || msg('msgTranslateMismatch'), true);
                            return;
                        }
                        lastRun.applied = true;
                        builtCount = result.translating ? result.blocks : builtCount + result.appliedCount;
                        canvasFollow(false);
                        if (!builtEl) builtEl = appliedItem(reply, '');
                        builtEl.textContent = (result.translating ? msg('msgTranslated') : msg('msgBuilt')).replace(':count', String(builtCount));
                        if (stick) scrollToEnd();
                    };

                    // set_page_title, same split: validated server-side, landed
                    // in the editor's title field here. Events fire so the
                    // runtime's own title wiring picks the change up.
                    const applyTitleTool = (data) => {
                        if (data.ok === false) return;
                        const title = String(((data.arguments || {}).title || '')).trim();
                        const field = document.querySelector('[data-hb-title]');
                        if (!title || !field) return;
                        if ('value' in field) {
                            field.value = title;
                            field.dispatchEvent(new Event('input', { bubbles: true }));
                            field.dispatchEvent(new Event('change', { bubbles: true }));
                        } else {
                            field.textContent = title;
                        }
                        const line = msg('msgSetTitle').replace(':title', title);
                        appliedLines.push(line); // rides into the stored turn's meta
                        appliedItem(reply, line);
                        if (stick) scrollToEnd();
                    };

                    // Legacy fallback: a model that ignores the tool and streams
                    // bare shortcode in its reply still lands on the canvas —
                    // but never on top of a tool build (double-application), and
                    // never while translating: this path has no fold, so it would
                    // replaceDoc away the home locale's text same as the tool bug above.
                    const liveApply = (final) => {
                        if (toolBuilt) return;
                        if (!window.hbCodeView || !window.hbEditor) return;
                        if (window.hbEditor.getEditingLocale() !== window.hbEditor.getHomeLocale()) return;
                        const now = Date.now();
                        if (!final && now - lastApplyAt < 250) return; // replaceDoc rerenders the whole doc — pace it
                        const markup = extractMarkup(splitReasoning(acc).visible);
                        if (!markup) return;
                        const parsed = window.hbCodeView.parse(markup);
                        if (!parsed || !parsed.blocks.length) return;
                        const stamp = JSON.stringify(parsed.blocks);
                        if (stamp === lastAppliedStamp) return;
                        lastAppliedStamp = stamp;
                        lastApplyAt = now;
                        lastRun.applied = true;
                        builtCount = parsed.blocks.length;
                        window.hbEditor.replaceDoc(lastRun.baseline.concat(parsed.blocks));
                        canvasFollow(final);
                        if (!builtEl) builtEl = appliedItem(reply, '');
                        builtEl.textContent = (final ? msg('msgBuilt') : msg('msgBuilding')).replace(':count', String(builtCount));
                    };

                    const paint = (finished) => {
                        const parts = splitReasoning(acc);
                        // Reasoning streams into its own block, expanded while it
                        // is the only thing happening, folded away once real
                        // output starts (unless the user opened it themselves).
                        if (parts.reasoning) {
                            if (!tReasonStart) tReasonStart = Date.now();
                            reply.think.hidden = false;
                            reply.thinkText.textContent = parts.reasoning;
                            if (!parts.visible && !finished) {
                                reply.thinkLabel.textContent = msg('msgThinkingLabel');
                                if (!reply.userToggledThink) reply.think.classList.add('is-open');
                            } else {
                                if (!thoughtSecs && tReasonStart) thoughtSecs = Math.max(1, Math.round((Date.now() - tReasonStart) / 1000));
                                reply.thinkLabel.textContent = msg('msgThoughtFor').replace(':secs', String(thoughtSecs));
                                if (!reply.userToggledThink) reply.think.classList.remove('is-open');
                            }
                        }
                        const prose = proseOf(parts.visible);
                        if (!parts.visible) {
                            // A turn that built blocks through write_canvas but
                            // said nothing is a finished build, not an empty reply.
                            reply.textEl.textContent = finished ? (builtCount > 0 ? '' : msg('msgEmptyReply')) : msg('msgThinking');
                        } else {
                            renderMarkdown(reply.textEl, prose || (builtCount > 0 ? '' : parts.visible));
                        }
                        if (finished && !prose && builtCount === 0 && !parts.visible) reply.textEl.textContent = msg('msgEmptyReply');
                    };

                    window.fetch(url, {
                        method: 'POST',
                        headers: {
                            'Accept': 'text/event-stream',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf(),
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                        signal: controller.signal,
                        body: JSON.stringify({
                            prompt: prompt,
                            context: documentContext(),
                            // Prior turns (not this prompt — the server appends it)
                            history: history.slice(0, replace ? history.length : -1),
                            conversation_id: conversationId,
                            model: selectedModel() || null,
                        }),
                    })
                        .then((response) => {
                            if (!response.body) throw new Error('no-stream');
                            const reader = response.body.getReader();
                            const decoder = new TextDecoder();
                            let buffer = '';

                            const handle = (frame) => {
                                const line = frame.split('\n').find((l) => l.indexOf('data:') === 0);
                                if (!line) return;
                                let event;
                                try { event = JSON.parse(line.slice(5).trim()); } catch (e) { return; }
                                if (event.type === 'text_delta') {
                                    acc += event.text || '';
                                    liveApply(false);
                                    paint(false);
                                    if (stick) scrollToEnd();
                                } else if (event.type === 'tool_use') {
                                    const data = event.data || {};
                                    if (String(data.name || '') === 'heisenberg__write_canvas') {
                                        // The write itself — apply, don't narrate:
                                        // the "built N blocks" row IS its line.
                                        applyCanvasTool(data);
                                    } else if (String(data.name || '') === 'heisenberg__set_page_title') {
                                        applyTitleTool(data);
                                    } else {
                                        const tool = String(data.name || '').replace(/^heisenberg__/, '').replace(/_/g, ' ');
                                        const line2 = msg('msgWorking').replace(':tool', tool || '…');
                                        appliedLines.push(line2);
                                        appliedItem(reply, line2);
                                        if (stick) scrollToEnd();
                                    }
                                } else if (event.type === 'done') {
                                    sawDone = true;
                                    stopReason = (event.data && event.data.stopReason) || '';
                                } else if (event.type === 'error') {
                                    sawDone = true; // an error IS an ending — don't also cry truncation
                                    // Keep whatever streamed: the error arrives as
                                    // a note under the preserved work, not as a
                                    // replacement for it.
                                    if (splitReasoning(acc).visible || lastRun.applied) {
                                        addNote(event.text || msg('msgNetwork'), true);
                                    } else {
                                        reply.node.classList.add('hb-ai-msg--error');
                                        reply.textEl.textContent = event.text || msg('msgNetwork');
                                    }
                                }
                            };

                            const drain = () => {
                                let cut;
                                while ((cut = buffer.indexOf('\n\n')) !== -1) {
                                    handle(buffer.slice(0, cut));
                                    buffer = buffer.slice(cut + 2);
                                }
                            };

                            const pump = () => reader.read().then(({ done, value }) => {
                                if (done) {
                                    buffer += decoder.decode();
                                    drain();
                                    if (buffer.trim()) handle(buffer);
                                    buffer = '';
                                    return;
                                }
                                buffer += decoder.decode(value, { stream: true });
                                drain();
                                return pump();
                            });

                            return pump();
                        })
                        .then(() => {
                            setBusy(false);
                            liveApply(true);
                            canvasFollow(true);
                            const parts = splitReasoning(acc);
                            if (!reply.node.classList.contains('hb-ai-msg--error')) {
                                paint(true);
                                reply.actions.hidden = parts.visible === '' && !lastRun.applied;
                            }
                            if (!sawDone) addNote(msg('msgTruncated'), true);
                            else if (stopReason === 'max_tokens' || stopReason === 'length') addNote(msg('msgLengthLimit'));
                            if (stick) scrollToEnd();
                            // Remember and persist the finished turn. A turn that
                            // only built blocks (no prose) still happened — both
                            // the model's memory and the stored thread get a
                            // "built N blocks" line for it, or the next request
                            // would replay a user turn with no answer.
                            const turnText = parts.visible
                                || (builtCount > 0 ? msg('msgBuilt').replace(':count', String(builtCount)) : '');
                            if (turnText) {
                                if (replace && history.length && history[history.length - 1].role === 'assistant') {
                                    history[history.length - 1] = { role: 'assistant', content: turnText };
                                } else {
                                    history.push({ role: 'assistant', content: turnText });
                                }
                                saveTurn('assistant', turnText, {
                                    reasoning: parts.reasoning || null,
                                    thoughtSecs: thoughtSecs || null,
                                    applied: appliedLines.concat(builtCount > 0 ? [msg('msgBuilt').replace(':count', String(builtCount))] : []),
                                    regenerated: !!replace,
                                });
                            }
                            // Ask the model what the user might want next — only
                            // once there's a real turn to build on.
                            if ((parts.visible || lastRun.applied) && !reply.node.classList.contains('hb-ai-msg--error')) {
                                loadSuggestions(reply);
                            }
                        })
                        .catch((error) => {
                            setBusy(false);
                            canvasFollow(true);
                            if (error && error.name === 'AbortError') {
                                const partial = splitReasoning(acc).visible;
                                if (partial) renderMarkdown(reply.textEl, proseOf(partial) || partial);
                                else reply.textEl.textContent = msg('msgStopped');
                                reply.actions.hidden = partial === '';
                                if (partial) { history.push({ role: 'assistant', content: partial }); saveTurn('assistant', partial, { stopped: true }); }
                                return;
                            }
                            // Same preservation contract as the error frame.
                            if (splitReasoning(acc).visible || (lastRun && lastRun.applied)) {
                                paint(true);
                                addNote(msg('msgNetwork'), true);
                            } else {
                                reply.node.classList.add('hb-ai-msg--error');
                                reply.textEl.textContent = msg('msgNetwork');
                            }
                        });
                };

                send?.addEventListener('click', () => {
                    const value = (input?.value || '').trim();
                    if (!value) return;
                    input.value = '';
                    autoGrow();
                    run(value);
                });

                stop?.addEventListener('click', () => { if (controller) controller.abort(); });

                input?.addEventListener('input', autoGrow);
                input?.addEventListener('keydown', (event) => {
                    if (event.key !== 'Enter' || event.shiftKey) return;
                    event.preventDefault();
                    send?.click();
                });

                root.querySelectorAll('[data-hb-ai-new]').forEach((btn) => btn.addEventListener('click', resetThread));

                thread.addEventListener('click', (event) => {
                    const regen = event.target.closest('[data-hb-ai-regenerate]');
                    if (!regen || !lastPrompt) return;
                    const node = event.target.closest('[data-hb-ai-msg]');
                    if (!node) return;
                    run(lastPrompt, {
                        node: node,
                        textEl: node.querySelector('[data-hb-ai-text]'),
                        think: node.querySelector('[data-hb-ai-think]'),
                        thinkLabel: node.querySelector('[data-hb-ai-think-label]'),
                        thinkText: node.querySelector('[data-hb-ai-think-text]'),
                        applied: node.querySelector('[data-hb-ai-applied]'),
                        appliedList: node.querySelector('[data-hb-ai-applied-list]'),
                        suggest: node.querySelector('[data-hb-ai-suggest]'),
                        actions: node.querySelector('[data-hb-ai-actions]'),
                        userToggledThink: false,
                    });
                });

                root.querySelectorAll('[data-hb-ai-suggest-canned]').forEach((trigger) => {
                    trigger.addEventListener('click', () => run(trigger.dataset.hbAiSuggestCanned || ''));
                });

                // A tool card is just a canned prompt.
                root.querySelectorAll('[data-hb-ai-suggest]').forEach((trigger) => {
                    trigger.addEventListener('click', () => run(trigger.dataset.hbAiSuggest || ''));
                });

                autoGrow();
            });
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
