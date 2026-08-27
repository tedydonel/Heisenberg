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
                const locale = () => root.dataset.locale || 'en';

                let lastPrompt = '';
                let controller = null;
                let lastRun = null;
                let conversationId = null;
                let history = [];

                const postId = () => root.dataset.postId || '';
                document.addEventListener('hb:post-id', (event) => {
                    const id = event.detail && event.detail.id != null ? String(event.detail.id) : '';
                    if (id) root.dataset.postId = id;
                });

                const selectedModel = () => (modelSel ? modelSel.dataset.value || '' : '');

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

                const proseOf = (visible) => {
                    const fence = visible.search(/```/);
                    const tag = visible.search(/\[[a-z][a-z0-9-]*(\s|\]|\/|=)/i);
                    const cut = Math.min(fence === -1 ? Infinity : fence, tag === -1 ? Infinity : tag);
                    return (cut === Infinity ? visible : visible.slice(0, cut)).trim();
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
                        builtEl.textContent = (result.translating ? msg('msgTranslated') : msg('msgBuilt')).replace(':count', String(builtCount));
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
                        if (!final && now - lastApplyAt < 250) return;
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
            });
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
