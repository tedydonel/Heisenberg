<script nonce="{{ heisenberg_csp_nonce() }}">
    (() => {
        const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

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
                // Block counts are read by the author in plain prose, so "1 blocks" is a
                // visible defect. Locales that need no separate singular simply omit the
                // *One string and fall back to the plural form.
                const countMsg = (key, count) =>
                    (count === 1 ? (msg(key + 'One') || msg(key)) : msg(key)).replace(':count', String(count));
                const locale = () => root.dataset.locale || 'en';

                let lastPrompt = '';
                let controller = null;
                let lastRun = null;
                let conversationId = null;
                let history = [];

                // The conversation lives server-side (ensureConversation/saveTurn);
                // sessionStorage only remembers WHICH one was open, so a refresh can
                // re-open it instead of dropping the user back on an empty thread.
                // Key is lazy: the post id may only land later via hb:post-id.
                const SESSION_KEY = () => 'hb:ai:conversation:' + postId();
                const rememberedConversation = () => {
                    try { return window.sessionStorage.getItem(SESSION_KEY()) || ''; } catch (e) { return ''; }
                };
                const rememberConversation = (id) => {
                    try {
                        if (id) window.sessionStorage.setItem(SESSION_KEY(), String(id));
                        else window.sessionStorage.removeItem(SESSION_KEY());
                    } catch (e) { }
                };

                const postId = () => root.dataset.postId || '';
                document.addEventListener('hb:post-id', (event) => {
                    const id = event.detail && event.detail.id != null ? String(event.detail.id) : '';
                    if (id) root.dataset.postId = id;
                });

                const selectedModel = () => (modelSel ? modelSel.dataset.value || '' : '');

                // The composer's picker is the author's own choice, remembered per browser.
                // It already applied to the next message, but a refresh silently dropped the
                // author back on whatever model is marked "in use" in AI settings — so the
                // only change that looked like it stuck was the one made in the settings
                // modal. Deliberately NOT written to global settings: picking a model to
                // chat with is an authoring act, changing the model everyone gets is an
                // admin one, and the chat endpoints only require the authors tier.
                const MODEL_KEY = 'hb:ai:model';
                if (modelSel) {
                    modelSel.addEventListener('change', () => {
                        try { window.localStorage.setItem(MODEL_KEY, selectedModel()); } catch (e) { }
                    });
                    try {
                        const saved = window.localStorage.getItem(MODEL_KEY);
                        // Restore only a model the operator still offers; one removed or
                        // disabled since must fall back to the server-rendered selection.
                        // Matched by reading each option's value rather than building an
                        // attribute selector: a model key is `provider:id` and ids carry
                        // '/' and ':' freely, and CSS.escape is not universally available
                        // (jsdom has no CSS object at all).
                        const opt = saved && saved !== selectedModel()
                            ? Array.prototype.find.call(
                                modelSel.querySelectorAll('[data-hb-select-option]'),
                                (o) => o.dataset.hbSelectOption === saved,
                            )
                            : null;
                        if (opt) {
                            // Drive the component's own select() rather than reproducing it.
                            // It focuses the trigger as it would for a real click, which is
                            // wrong for a restore on load, so hand focus straight back.
                            opt.click();
                            const trigger = modelSel.querySelector('[data-hb-select-trigger]');
                            if (trigger) trigger.blur();
                        }
                    } catch (e) { }
                }

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

                const atBottom = () => !scroller
                    || scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < 40;
                const scrollToEnd = () => { if (scroller) scroller.scrollTop = scroller.scrollHeight; };

                const canvasFollow = (final) => {
                    const canvas = document.querySelector('.hb-canvas');
                    if (!canvas) return;
                    canvas.querySelectorAll('[data-block].hb-ai-writing').forEach((el) => el.classList.remove('hb-ai-writing'));
                    if (final) return;
                    const blocks = canvas.querySelectorAll(':scope [data-block]');
                    const tail = blocks[blocks.length - 1];
                    if (!tail) return;
                    tail.classList.add('hb-ai-writing');
                    try { tail.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (e) { }
                };

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
                        appliedHead: node.querySelector('[data-hb-ai-applied-head]'),
                        appliedLabel: node.querySelector('[data-hb-ai-applied-label]'),
                        activity: node.querySelector('[data-hb-ai-activity]'),
                        activityText: node.querySelector('[data-hb-ai-activity-text]'),
                        suggest: node.querySelector('[data-hb-ai-suggest]'),
                        actions: node.querySelector('[data-hb-ai-actions]'),
                        userToggledThink: false,
                        // Interleaved reasoning bursts (see openThinkSegment).
                        thinkSegments: [],
                        activeThink: null,
                        usedFirstThink: false,
                    };
                    refs.think.querySelector('[data-hb-ai-think-head]').addEventListener('click', () => {
                        refs.userToggledThink = true;
                        refs.think.classList.toggle('is-open');
                    });
                    if (refs.appliedHead) {
                        refs.appliedHead.addEventListener('click', () => refs.applied.classList.toggle('is-open'));
                    }
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

                /**
                 * The live activity row: ONE line that rewrites itself as the assistant moves from
                 * tool to tool. Replaces the old behaviour of appending an applied-list entry per
                 * call, which turned ten icon lookups into ten identical rows.
                 */
                let activityTimer = null;

                // How long each status verb holds before the next one replaces it. At 2.2s the
                // row changed faster than it could be read — the eye catches the movement, not
                // the word. ~4s is long enough to actually finish reading one.
                const ACTIVITY_VERB_MS = 4000;

                /**
                 * A model works in bursts: reason, call a tool, reason again, call another. Piling
                 * every burst into ONE thinking block made a long turn look stuck on a single
                 * section. Each burst now gets its OWN collapsible block, appended in order, so the
                 * transcript reads think -> work -> think -> work. The first burst reuses the block
                 * already in the bubble; later ones clone it.
                 */
                const openThinkSegment = (refs) => {
                    let node, label, text;
                    if (!refs.usedFirstThink) {
                        refs.usedFirstThink = true;
                        node = refs.think; label = refs.thinkLabel; text = refs.thinkText;
                    } else {
                        node = refs.think.cloneNode(true);
                        label = node.querySelector('[data-hb-ai-think-label]');
                        text = node.querySelector('[data-hb-ai-think-text]');
                        text.textContent = '';
                        node.querySelector('[data-hb-ai-think-head]')
                            .addEventListener('click', () => node.classList.toggle('is-open'));
                        refs.textEl.parentNode.insertBefore(node, refs.textEl);
                    }
                    node.hidden = false;
                    node.classList.add('is-open');
                    label.textContent = msg('msgThinkingLabel');
                    const seg = { node: node, label: label, text: text, started: Date.now() };
                    refs.thinkSegments.push(seg);
                    return seg;
                };

                /** Stamp "Thought for Ns" on a finished burst and collapse it, freeing the next one. */
                const closeThinkSegment = (refs) => {
                    const seg = refs.activeThink;
                    if (!seg) return;
                    const secs = Math.max(1, Math.round((Date.now() - seg.started) / 1000));
                    seg.label.textContent = msg('msgThoughtFor').replace(':secs', String(secs));
                    seg.node.classList.remove('is-open');
                    refs.activeThink = null;
                };


                const setActivity = (refs, text) => {
                    if (!refs.activity) return;
                    if (!text) { refs.activity.hidden = true; return; }
                    refs.activity.hidden = false;
                    refs.activityText.textContent = text;
                };

                /**
                 * Rotate playful status verbs while the model is working but has not called a tool
                 * yet — otherwise the whole reasoning phase showed nothing at all. A real tool call
                 * takes over the same row (stopActivity is called first), so the two never fight.
                 */
                const startActivityVerbs = (refs) => {
                    const verbs = String(msg('msgActivityVerbs') || '').split(',').map((v) => v.trim()).filter(Boolean);
                    if (!verbs.length || !refs.activity) return;
                    let i = 0;
                    setActivity(refs, verbs[0]);
                    clearInterval(activityTimer);
                    activityTimer = setInterval(() => {
                        i = (i + 1) % verbs.length;
                        setActivity(refs, verbs[i]);
                    }, ACTIVITY_VERB_MS);
                };

                const stopActivity = (refs) => {
                    clearInterval(activityTimer);
                    activityTimer = null;
                    setActivity(refs, '');
                };

                /**
                 * Collapse the turn's tool calls into "Used N tools", expandable to the full list.
                 * `counts` is an ordered Map of tool label -> times called, so a repeated tool reads
                 * "Searching icons ×3" on one row rather than occupying three.
                 */
                const renderToolSummary = (refs, counts) => {
                    stopActivity(refs);
                    const entries = Array.from(counts.entries());
                    if (!entries.length) return;

                    const total = entries.reduce((n, [, c]) => n + c, 0);
                    if (refs.appliedLabel) {
                        refs.appliedLabel.textContent = msg('msgUsedTools').replace(':count', String(total));
                    }
                    entries.forEach(([label, count]) => {
                        appliedItem(refs, count > 1 ? (label + ' ×' + count) : label);
                    });
                    refs.applied.hidden = false;
                };

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
                    refs.actions.hidden = true;
                };

                const resetThread = () => {
                    if (controller) controller.abort();
                    thread.innerHTML = '';
                    emptyEl.hidden = false;
                    lastPrompt = '';
                    conversationId = null;
                    history = [];
                    rememberConversation(null);
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
                            const collapsed = messages.filter((m, i) =>
                                !(m.role === 'assistant' && messages[i + 1] && messages[i + 1].role === 'assistant'));
                            collapsed.forEach(renderStored);
                            history = collapsed.map((m) => ({ role: m.role, content: m.content }));
                            const last = collapsed.filter((m) => m.role === 'user').pop();
                            lastPrompt = last ? last.content : '';
                            emptyEl.hidden = collapsed.length > 0;
                            rememberConversation(id);
                            scrollToEnd();
                        })
                        .catch(() => { rememberConversation(null); addNote(msg('msgHistoryError'), true); });
                });

                const setBusy = (busy) => {
                    send.hidden = busy;
                    stop.hidden = !busy;
                    input.disabled = false;
                };

                const autoGrow = () => {
                    input.style.height = 'auto';
                    const minHeight = 28;
                    input.style.height = Math.min(160, Math.max(minHeight, input.scrollHeight)) + 'px';
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
                        try { base.document = window.hbCodeView.serialize(); } catch (e) { }
                    }
                    const title = document.querySelector('[data-hb-title]');
                    if (title) base.title = (title.value || title.textContent || '').trim();
                    if (window.hbEditor && window.hbEditor.getEditingLocale) base.editingLocale = window.hbEditor.getEditingLocale();
                    if (window.hbEditor && window.hbEditor.getHomeLocale) base.homeLocale = window.hbEditor.getHomeLocale();
                    base.documentType = root.dataset.documentType || document.querySelector('[data-hb-canvas]')?.dataset.documentType || 'post';
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
                    // No plain "Thinking…" here: the collapsible thinking section shows the real
                    // reasoning, and the activity row below shows live status. A third copy in the
                    // body just read as the word "Thinking" twice.
                    reply.textEl.textContent = '';
                    startActivityVerbs(reply);
                    reply.actions.hidden = true;
                    reply.suggest.hidden = true;
                    const suggestRow = reply.suggest.querySelector('[data-hb-ai-suggest-row]');
                    if (suggestRow) suggestRow.innerHTML = '';
                    setBusy(true);
                    let acc = '';
                    let sawDone = false;
                    let stopReason = '';
                    const stick = atBottom();
                    let tReasonStart = 0;
                    let thoughtSecs = 0;
                    let appliedLines = [];
                    // Reasoning streamed on its own SSE channel (`reasoning_delta`), which is how
                    // every current model sends it — Anthropic `thinking_delta`, OpenAI-compatible
                    // `reasoning_content`/`reasoning`. splitReasoning() below still handles models
                    // that instead type <think> tags into the visible text; the two are additive so
                    // the panel shows reasoning either way.
                    let reasoningAcc = '';
                    // Ordered label -> call-count for this turn; drives the collapsed
                    // "Used N tools" summary rendered when the stream finishes.
                    const toolCounts = new Map();
                    let builtEl = null;
                    let toolBuilt = false;

                    if (replace && lastRun && lastRun.applied && window.hbEditor) {
                        window.hbEditor.replaceDoc(lastRun.baseline);
                    }
                    lastRun = {
                        baseline: window.hbEditor ? JSON.parse(JSON.stringify(window.hbEditor.getDoc().blocks || [])) : [],
                        applied: false,
                    };
                    let lastAppliedStamp = '';
                    let lastApplyAt = 0;
                    let applyTimer = 0;
                    let builtCount = 0;
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
                        builtEl.textContent = result.translating
                            ? msg('msgTranslated').replace(':count', String(builtCount))
                            : countMsg('msgBuilt', builtCount);
                        if (stick) scrollToEnd();
                    };

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
                        appliedLines.push(line);
                        appliedItem(reply, line);
                        if (stick) scrollToEnd();
                    };

                    const liveApply = (final) => {
                        if (toolBuilt) return;
                        if (!window.hbCodeView || !window.hbEditor) return;
                        if (window.hbEditor.getEditingLocale() !== window.hbEditor.getHomeLocale()) return;
                        const now = Date.now();
                        if (applyTimer) { clearTimeout(applyTimer); applyTimer = 0; }
                        if (!final && now - lastApplyAt < 250) {
                            // Throttled, not cancelled. Dropping the call outright only worked
                            // while more text kept arriving to retry it; when the model switches
                            // straight back to reasoning, the next delta is a thought, so the
                            // blocks — and the section boundary sealing them — waited for the end
                            // of the turn and every later thought piled into one block.
                            applyTimer = setTimeout(() => { applyTimer = 0; liveApply(false); }, 250 - (now - lastApplyAt));
                            return;
                        }
                        const markup = extractMarkup(splitReasoning(acc).visible);
                        if (!markup) return;
                        const parsed = window.hbCodeView.parse(markup);
                        if (!parsed || !parsed.blocks.length) return;
                        const stamp = JSON.stringify(parsed.blocks);
                        if (stamp === lastAppliedStamp) {
                            // Nothing new to write. The final pass still has to retire the
                            // "Building…" label, though — the last blocks almost always land
                            // before the stream ends, so a finished turn kept reading as if
                            // it were still running.
                            if (final && builtEl) builtEl.textContent = countMsg('msgBuilt', builtCount);
                            return;
                        }
                        lastAppliedStamp = stamp;
                        lastApplyAt = now;
                        lastRun.applied = true;
                        builtCount = parsed.blocks.length;
                        window.hbEditor.replaceDoc(lastRun.baseline.concat(parsed.blocks));
                        canvasFollow(final);
                        // Blocks just landed on the canvas — that is the boundary the author sees
                        // as "a piece of work finished". Seal the reasoning burst HERE, live, so
                        // the next thought opens a fresh section in the chat as it streams. This
                        // used to only happen on a tool_use event, but most builds arrive as
                        // shortcode in the visible TEXT and never emit one, so the section stayed
                        // open and everything after it piled into the same block.
                        closeThinkSegment(reply);
                        if (!builtEl) builtEl = appliedItem(reply, '');
                        builtEl.textContent = countMsg(final ? 'msgBuilt' : 'msgBuilding', builtCount);
                    };

                    const paint = (finished) => {
                        const parts = splitReasoning(acc);
                        // Models that inline <think> tags instead of using the reasoning channel:
                        // mirror that text into the current burst. When reasoningAcc is non-empty the
                        // dedicated channel is already driving the segments, so do not double-write.
                        // Real prose means the model stopped reasoning and started answering.
                        // Without this the burst never closed on a turn that emitted no tool call
                        // and no blocks, and the panel read "Thinking…" indefinitely.
                        if (reply.activeThink && proseOf(parts.visible)) {
                            closeThinkSegment(reply);
                        }
                        if (!reasoningAcc && parts.reasoning) {
                            if (!tReasonStart) tReasonStart = Date.now();
                            if (!reply.activeThink) reply.activeThink = openThinkSegment(reply);
                            reply.activeThink.text.textContent = parts.reasoning;
                            if (parts.visible || finished) closeThinkSegment(reply);
                        }
                        const prose = proseOf(parts.visible);
                        if (!parts.visible) {
                            reply.textEl.textContent = finished ? (builtCount > 0 ? '' : msg('msgEmptyReply')) : '';
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
                                } else if (event.type === 'reasoning_delta') {
                                    // Append live into the CURRENT burst so the text visibly grows;
                                    // a tool call closes the burst, so the next reasoning opens a
                                    // fresh block rather than extending a stale one.
                                    reasoningAcc += event.text || '';
                                    if (!reply.activeThink) reply.activeThink = openThinkSegment(reply);
                                    reply.activeThink.text.textContent += (event.text || '');
                                    if (stick) scrollToEnd();
                                } else if (event.type === 'tool_use') {
                                    const data = event.data || {};
                                    // A thought closes ONLY when the model actually APPLIED something
                                    // to the document. Read-only calls (search_web, search_icons,
                                    // list_blocks…) are part of the same train of thought — sealing on
                                    // those produced a stack of one-second "Thought for 1s" blocks.
                                    if (String(data.name || '') === 'heisenberg__write_canvas') {
                                        closeThinkSegment(reply);
                                        applyCanvasTool(data);
                                    } else if (String(data.name || '') === 'heisenberg__set_page_title') {
                                        closeThinkSegment(reply);
                                        applyTitleTool(data);
                                    } else {
                                        const tool = String(data.name || '').replace(/^heisenberg__/, '').replace(/_/g, ' ');
                                        const line2 = msg('msgWorking').replace(':tool', tool || '…');
                                        // Live status only while running; the counted summary is
                                        // rendered once the turn finishes (renderToolSummary).
                                        toolCounts.set(line2, (toolCounts.get(line2) || 0) + 1);
                                        clearInterval(activityTimer);
                                        activityTimer = null;
                                        setActivity(reply, line2);
                                        if (stick) scrollToEnd();
                                    }
                                } else if (event.type === 'done') {
                                    sawDone = true;
                                    stopReason = (event.data && event.data.stopReason) || '';
                                } else if (event.type === 'error') {
                                    sawDone = true;
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
                            const turnText = parts.visible
                                || (builtCount > 0 ? countMsg('msgBuilt', builtCount) : '');
                            if (turnText) {
                                if (replace && history.length && history[history.length - 1].role === 'assistant') {
                                    history[history.length - 1] = { role: 'assistant', content: turnText };
                                } else {
                                    history.push({ role: 'assistant', content: turnText });
                                }
                                saveTurn('assistant', turnText, {
                                    reasoning: reply.thinkSegments.map((seg) => seg.text.textContent).filter(Boolean).join(String.fromCharCode(10, 10)).trim() || null,
                                    thoughtSecs: thoughtSecs || null,
                                    applied: appliedLines.concat(builtCount > 0 ? [countMsg('msgBuilt', builtCount)] : []),
                                    regenerated: !!replace,
                                });
                            }
                            closeThinkSegment(reply);
                            renderToolSummary(reply, toolCounts);
                            // Suggestions follow a turn that produced prose OR changed the canvas OR
                            // ran tools — the old gate missed a tool-only turn entirely, which is one
                            // way they silently never appeared.
                            if ((parts.visible || lastRun.applied || toolCounts.size) && !reply.node.classList.contains('hb-ai-msg--error')) {
                                loadSuggestions(reply);
                            }
                        })
                        .catch((error) => {
                            setBusy(false);
                            if (applyTimer) { clearTimeout(applyTimer); applyTimer = 0; }
                            canvasFollow(true);
                            // An abort or a network failure must not leave the animation running
                            // or a reasoning burst open with no duration stamped on it.
                            closeThinkSegment(reply);
                            stopActivity(reply);
                            if (error && error.name === 'AbortError') {
                                const partial = splitReasoning(acc).visible;
                                if (partial) renderMarkdown(reply.textEl, proseOf(partial) || partial);
                                else reply.textEl.textContent = msg('msgStopped');
                                reply.actions.hidden = partial === '';
                                if (partial) { history.push({ role: 'assistant', content: partial }); saveTurn('assistant', partial, { stopped: true }); }
                                return;
                            }
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

                root.querySelectorAll('[data-hb-ai-suggest]').forEach((trigger) => {
                    trigger.addEventListener('click', () => run(trigger.dataset.hbAiSuggest || ''));
                });

                autoGrow();

                // Refresh survival: re-open the conversation this session had on
                // screen. The thread is rendered from the server's stored messages,
                // so nothing the user sent is lost — only the streaming state, which
                // cannot survive a reload.
                const remembered = rememberedConversation();
                if (remembered && convUrl) {
                    document.dispatchEvent(new CustomEvent('hb:ai-open-conversation', { detail: { id: remembered } }));
                }
            });
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
