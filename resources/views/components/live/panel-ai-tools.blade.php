{{-- live/panel-ai-tools — Middle panel, 240px (same
     240-vs-260 note as live/panel-components-blocks).
     Ai tab: header (badge + title, new small assembly), subtitle, 4 ui/suggestion-row instances (icon
     colors vary per row in the source — editing/success/accent/danger — passed through iconColor,
     which that atom already exposed for exactly this), a response card (new — bg-subtle box with an
     Regenerate action, no existing atom matches this shape), and a prompt input bar with a
     circular send button.
     Tools tab: search + "Writing" category head + an 8-card grid, all real ui/tool-card instances. --}}
@once
<style>
    {{-- width:100% fills the shell's panel track, which is the same width for every middle
         panel. The room a conversation needs is found inside instead: messages are full-bleed
         rather than a padded card nested in a padded section. --}}
    .hb-panel-ai { display: flex; flex-direction: column; width: 100%; height: 100%; background: var(--hb-bg, #fff); border-right: 1px solid var(--hb-border, #E4E4E4); flex: none; }
    .hb-panel-ai__content { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; overflow: hidden; }
    .hb-panel-ai__content[hidden] { display: none; }

    {{-- Scrolls independently of .hb-ai-prompt-wrap below, which stays pinned to the bottom of the
         panel (its own flex:none + normal doc flow after this) rather than scrolling away with it.
         Two-layer scroll shell inside it (see live/panel-components-blocks.blade.php's own note) —
         .hb-ai-body is just the flex slot + position:relative anchor, .hb-ai-scroll is the actual
         JS-tracked scroll region the scrollbar's `container` targets. --}}
    .hb-ai-body { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; overflow: hidden; position: relative; }
    .hb-ai-scroll { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; overflow: hidden; }

    .hb-ai-header { display: flex; flex-direction: column; gap: var(--hb-space-2, 8px); padding: 10px; flex: none; }
    .hb-ai-header__row { display: flex; align-items: center; gap: var(--hb-space-2, 8px); }
    .hb-ai-header__badge { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 14px; background: var(--hb-bg-inset, #EEEEEE); flex: none; }
    .hb-ai-header__badge-icon { display: inline-flex; width: 16px; height: 16px; color: var(--hb-accent, #000); }
    .hb-ai-header__title { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-base, 13px); font-weight: 600; color: var(--hb-text-primary, #0A0A0A); }
    {{-- Opens live/ai/ai-settings-dialog. Added beside the extracted header row rather than
         restructuring it: margin-left:auto pushes it to the trailing edge without touching the
         badge/title pair's own layout. --}}
    .hb-ai-header__settings { margin-left: auto; flex: none; }
    .hb-ai-header__action { flex: none; }
    .hb-ai-header__action:first-of-type { margin-left: auto; }

    .hb-ai-section { display: flex; flex-direction: column; gap: var(--hb-space-1, 4px); padding: 10px; border-top: 1px solid var(--hb-border, #E4E4E4); flex: none; }
    .hb-ai-section__title { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-base, 13px); font-weight: 600; color: var(--hb-text-secondary, #5A5A5A); }

    {{-- The transcript. Messages are full-bleed: the old layout nested a padded card inside a
         padded section inside a 240px rail, which left prose about 200px wide. --}}
    .hb-ai-thread { display: flex; flex-direction: column; gap: var(--hb-space-3, 12px); padding: var(--hb-space-3, 12px) 10px; }
    .hb-ai-msg { display: flex; flex-direction: column; gap: var(--hb-space-1, 4px); width: 100%; }
    .hb-ai-msg__role {
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: 10px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase;
        color: var(--hb-text-muted, #9A9A9A);
    }
    .hb-ai-msg--user .hb-ai-response { background: var(--hb-bg-muted, #F4F4F4); }
    .hb-ai-msg--error .hb-ai-response__text { color: var(--hb-danger, #D4191A); }
    {{-- The reasoning phase streams as a dimmed, italic tail — visibly alive, visually
         subordinate to real prose. --}}
    .hb-ai-msg--reasoning .hb-ai-response__text { color: var(--hb-text-muted, #9A9A9A); font-style: italic; }
    .hb-ai-empty {
        padding: 32px 10px; text-align: center;
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-muted, #9A9A9A);
    }
    .hb-ai-empty[hidden] { display: none; }

    .hb-ai-response { display: flex; flex-direction: column; gap: var(--hb-space-2, 8px); padding: var(--hb-space-3, 12px); border-radius: var(--hb-radius-md, 5px); background: var(--hb-bg-subtle, #FAFAFA); width: 100%; }
    {{-- 13px, not 11px: this is body prose now, not a caption. `pre-wrap` keeps the shortcode
         the model emits readable instead of collapsing it onto one line. --}}
    .hb-ai-response__text {
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-base, 13px); line-height: 1.55;
        color: var(--hb-text-primary, #0A0A0A);
        margin: 0; white-space: pre-wrap; overflow-wrap: anywhere;
    }
    .hb-ai-response__actions[hidden] { display: none; }
    .hb-ai-response__actions { display: flex; align-items: center; gap: var(--hb-space-3, 12px); }
    .hb-ai-action { display: inline-flex; align-items: center; gap: var(--hb-space-1, 4px); border: 0; background: transparent; cursor: pointer; padding: 0; font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-sm, 12px); font-weight: 500; }
    .hb-ai-action__icon { display: inline-flex; width: 14px; height: 14px; }
    .hb-ai-action--regenerate { color: var(--hb-text-secondary, #5A5A5A); }
    .hb-ai-action--regenerate .hb-ai-action__icon { color: var(--hb-text-muted, #9A9A9A); }

    .hb-ai-prompt-wrap { padding: 10px; flex: none; display: flex; flex-direction: column; gap: var(--hb-space-1, 4px); border-top: 1px solid var(--hb-border, #E4E4E4); }
    .hb-ai-prompt-bar {
        display: flex;
        align-items: flex-end;
        gap: var(--hb-space-2, 8px);
        width: 100%;
        /* was a fixed 32px height; the textarea drives it now */
        min-height: 32px;
        padding: var(--hb-space-1, 4px) var(--hb-space-1, 4px) var(--hb-space-1, 4px) var(--hb-space-3, 12px);
        border: 1px solid var(--hb-border, #E4E4E4);
        border-radius: var(--hb-radius-lg, 8px);
        background: var(--hb-bg, #fff);
    }
    .hb-ai-prompt-bar:focus-within { border-color: var(--hb-border-focus, #000); }
    .hb-ai-prompt-bar__input {
        flex: 1 1 auto;
        min-width: 0;
        border: 0;
        outline: 0;
        padding: 5px 0;
        background: transparent;
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-sm, 12px);
        line-height: 1.45;
        color: var(--hb-text-primary, #0A0A0A);
        /* Grows to a cap, then scrolls — set from JS so one line stays one line. */
        resize: none; max-height: 160px; overflow-y: auto;
    }
    .hb-ai-prompt-bar__stop { background: var(--hb-danger, #D4191A); }
    .hb-ai-prompt-bar__send[hidden], .hb-ai-prompt-bar__stop[hidden] { display: none; }
    .hb-ai-prompt-bar__send:disabled { opacity: .4; cursor: default; }
    .hb-ai-prompt-hint {
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: 10px; color: var(--hb-text-muted, #9A9A9A); padding-left: 2px;
    }
    .hb-ai-prompt-bar__input::placeholder { color: var(--hb-text-muted, #9A9A9A); }
    .hb-ai-prompt-bar__send { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border: 0; border-radius: 14px; background: var(--hb-accent, #000); color: var(--hb-accent-fg, #fff); cursor: pointer; flex: none; }

    {{-- Same two-layer shell once more: .hb-panel-ai__toolsbody is the flex slot + position:relative
         anchor, .hb-panel-ai__grid is now purely the CSS grid (no longer also the scroll container
         AND a grid item's sibling at once — the scrollbar bar used to be an actual grid cell). --}}
    .hb-panel-ai__toolsbody { flex: 1 1 auto; min-height: 0; overflow: hidden; position: relative; }
    .hb-panel-ai__grid { display: grid; grid-template-columns: 1fr 1fr; align-content: start; gap: 8px; padding: var(--hb-space-3, 12px); }
</style>
<script>
    (() => {
        const boot = () => {
            document.querySelectorAll('[data-hb-panel-ai]').forEach((root) => {
                if (root.__hbPanelAi) return;
                const tabs = root.querySelector('[data-hb-tablist]');
                const ai = root.querySelector('[data-hb-panel-ai-ai]');
                const tools = root.querySelector('[data-hb-panel-ai-tools]');
                tabs?.addEventListener('change', (event) => {
                    if (ai) ai.hidden = event.detail.index !== 0;
                    if (tools) tools.hidden = event.detail.index !== 1;
                });
                root.__hbPanelAi = true;
            });
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
<script>
    {{-- The assistant itself: prompt bar -> SSE -> response card, with markup replies
         building onto the CANVAS live as they stream (see LIVE BUILD in run()).

         Insert deliberately does NOT parse markup of its own. It hands the reply to
         window.hbCodeView.parse (live/code-editor.blade.php), so an AI-authored block goes
         through the same registry validation, the same line-numbered errors and the same undo
         stack as one typed into the Code view. An unparseable reply inserts nothing. --}}
    (() => {
        const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        const boot = () => {
            document.querySelectorAll('[data-hb-panel-ai]').forEach((root) => {
                if (root.__hbAssistant) return;
                root.__hbAssistant = true;

                const url = root.dataset.streamUrl || '';
                const input = root.querySelector('[data-hb-ai-prompt]');
                const send = root.querySelector('[data-hb-ai-send]');
                const stop = root.querySelector('[data-hb-ai-stop]');
                const thread = root.querySelector('[data-hb-ai-thread]');
                const emptyEl = root.querySelector('[data-hb-ai-empty]');
                const template = root.querySelector('[data-hb-ai-msg-template]');
                const scroller = root.querySelector('[data-hb-ai-scroll]');
                const msg = (key) => root.dataset[key] || '';

                let lastPrompt = '';
                let controller = null;
                // The latest run's live-build state: the document as that run found it, and
                // whether the run actually applied markup (see LIVE BUILD in run()).
                let lastRun = null;

                // Several open-weight models write their chain of thought inline as
                // <think>…</think>. That comes from the serving template, not the model's
                // choice, so prompting does not remove it — filter it here too, not just
                // server-side, because streamed deltas arrive before any server can.
                const stripReasoning = (text) => text
                    .replace(/<(think|thinking|reasoning|reflection)\b[^>]*>[\s\S]*?<\/\1\s*>/gi, '')
                    .replace(/<(think|thinking|reasoning|reflection)\b[^>]*>[\s\S]*$/i, '')
                    .replace(/^[\s\S]*?<\/(think|thinking|reasoning|reflection)\s*>/i, '')
                    // Catch-all: a stray unpaired tag (a round-2 continuation can open with a
                    // bare closer the pair/prefix rules miss) must never reach the transcript.
                    .replace(/<\/?(think|thinking|reasoning|reflection)\b[^>]*>/gi, '')
                    .trim();

                // What the BUBBLE shows for a raw accumulated reply. Markup belongs on the
                // canvas (the live build), never in the chat: the bubble carries the prose
                // around it plus a live build status. While the model is still reasoning
                // (nothing visible yet), the bubble streams a dimmed tail of the reasoning so
                // the work is watchable instead of a frozen "Thinking…".
                const displayFor = (raw) => {
                    const clean = stripReasoning(raw);
                    if (!clean) {
                        const tail = raw.replace(/<\/?[a-z][^>]*>/gi, ' ').replace(/\s+/g, ' ').trim();
                        return { kind: 'reasoning', text: tail.slice(-160) };
                    }
                    const fence = clean.search(/```/);
                    const tag = clean.search(/\[[a-z][a-z0-9-]*(\s|\]|\/|=)/i);
                    const cut = Math.min(fence === -1 ? Infinity : fence, tag === -1 ? Infinity : tag);
                    if (cut === Infinity) return { kind: 'prose', text: clean };
                    return { kind: 'mixed', prose: clean.slice(0, cut).trim() };
                };

                // A real reply is prose *around* markup — "Here's a hero section:", a fenced
                // block, then "Let me know what you think." Handing the whole string to the
                // parser made every one of those an error, so Insert refused answers that were
                // perfectly good. Fenced code wins when present (the model marked the markup
                // itself); otherwise take the span from the first opening tag to the last
                // closing one and let the parser judge that.
                const extractMarkup = (text) => {
                    const fenced = [];
                    const fence = /```[a-zA-Z0-9_-]*\r?\n([\s\S]*?)```/g;
                    let match;
                    while ((match = fence.exec(text)) !== null) fenced.push(match[1].trim());
                    if (fenced.length) return fenced.join('\n\n');

                    // An unterminated fence means the stream stopped inside one; keep the rest.
                    const open = text.match(/```[a-zA-Z0-9_-]*\r?\n/);
                    if (open) return text.slice(open.index + open[0].length).trim();

                    const first = text.indexOf('[');
                    const last = text.lastIndexOf(']');
                    if (first === -1 || last <= first) return '';
                    return text.slice(first, last + 1).trim();
                };

                const atBottom = () => !scroller
                    || scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < 40;
                const scrollToEnd = () => { if (scroller) scroller.scrollTop = scroller.scrollHeight; };

                // One turn appended to the transcript. Returns handles so a streaming reply
                // can keep writing into the message it already created.
                const addMessage = (role, text) => {
                    emptyEl.hidden = true;
                    const node = template.content.firstElementChild.cloneNode(true);
                    node.classList.add('hb-ai-msg--' + role);
                    const roleEl = node.querySelector('[data-hb-ai-msg-role]');
                    // A note is the editor talking about the conversation ("inserted",
                    // "cut off"), not a turn in it — labelling it "Assistant" would put
                    // words in the model's mouth.
                    if (role === 'note') roleEl.hidden = true;
                    else roleEl.textContent = msg(role === 'user' ? 'msgRoleYou' : 'msgRoleAssistant');
                    const textEl = node.querySelector('[data-hb-ai-text]');
                    const actions = node.querySelector('[data-hb-ai-actions]');
                    textEl.textContent = text || '';
                    // Only an assistant turn can be inserted or regenerated.
                    actions.hidden = role !== 'assistant';
                    thread.appendChild(node);
                    scrollToEnd();
                    return { node: node, textEl: textEl, actions: actions };
                };

                const setBusy = (busy) => {
                    send.hidden = busy;
                    stop.hidden = !busy;
                    input.disabled = false; // stays editable so the next prompt can be typed
                };

                // The textarea grows with its content up to the CSS cap, then scrolls.
                const autoGrow = () => {
                    input.style.height = 'auto';
                    input.style.height = Math.min(input.scrollHeight, 160) + 'px';
                };

                // What the model should be told about the current selection. Found via the
                // contract rather than a hardcoded attribute name, so a block whose rich-text
                // attribute isn't called "content" still works.
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

                // The whole page, serialized through the code view's own serializer. Sent on
                // every turn so the assistant can read the document instead of asking the user
                // to paste it — which is exactly what it used to do.
                const documentContext = () => {
                    const base = selectionContext();
                    if (window.hbCodeView && window.hbCodeView.serialize) {
                        try { base.document = window.hbCodeView.serialize(); } catch (e) { /* empty doc */ }
                    }
                    const title = document.querySelector('[data-hb-title]');
                    if (title) base.title = (title.value || title.textContent || '').trim();
                    return base;
                };

                const run = (prompt, replace) => {
                    if (!url || !prompt) return;
                    lastPrompt = prompt;
                    if (controller) controller.abort();
                    controller = new AbortController();

                    // Regenerate rewrites the reply in place; a new prompt appends a turn, so
                    // the whole conversation stays on screen as its own history.
                    if (!replace) addMessage('user', prompt);
                    const reply = replace || addMessage('assistant', '');
                    reply.textEl.textContent = msg('msgThinking');
                    reply.actions.hidden = true;
                    setBusy(true);
                    let acc = '';
                    // A stream that ends without its terminator was cut off, not finished —
                    // the difference is the whole of "why does the answer stop mid-sentence".
                    let sawDone = false;
                    let stopReason = '';
                    const stick = atBottom();

                    // ── LIVE BUILD ────────────────────────────────────────────────────
                    // A reply that is block markup applies to the CANVAS as it streams: each
                    // completed top-level block lands the moment its closing tag arrives, so
                    // the post assembles in front of the user instead of waiting behind an
                    // Insert button. The baseline is the document as this run found it —
                    // markup always APPENDS to it, and regenerating the latest reply first
                    // restores that baseline so the redo replaces the old build rather than
                    // doubling it. Prose answers produce no markup and never touch the page.
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
                    let toolNote = '';
                    const liveApply = (final) => {
                        if (!window.hbCodeView || !window.hbEditor) return;
                        const now = Date.now();
                        if (!final && now - lastApplyAt < 250) return; // replaceDoc rerenders the whole doc — pace it
                        const markup = extractMarkup(stripReasoning(acc));
                        if (!markup) return;
                        const parsed = window.hbCodeView.parse(markup);
                        // Mid-stream the tail is legitimately unfinished — apply whatever
                        // COMPLETE blocks parsed and ignore the errors the ragged edge causes.
                        if (!parsed || !parsed.blocks.length) return;
                        const stamp = JSON.stringify(parsed.blocks);
                        if (stamp === lastAppliedStamp) return;
                        lastAppliedStamp = stamp;
                        lastApplyAt = now;
                        lastRun.applied = true;
                        builtCount = parsed.blocks.length;
                        window.hbEditor.replaceDoc(lastRun.baseline.concat(parsed.blocks));
                    };

                    // One render of the bubble from the current state — prose in full, markup
                    // as a live build count, reasoning as a dimmed streaming tail.
                    const paint = (finished) => {
                        const view = displayFor(acc);
                        reply.node.classList.toggle('hb-ai-msg--reasoning', view.kind === 'reasoning' && !finished);
                        if (view.kind === 'reasoning') {
                            reply.textEl.textContent = finished
                                ? msg('msgEmptyReply')
                                : (toolNote || view.text || msg('msgThinking'));
                            return;
                        }
                        const status = builtCount > 0
                            ? (finished ? msg('msgBuilt') : msg('msgBuilding')).replace(':count', String(builtCount))
                            : (finished || view.kind === 'prose' ? '' : msg('msgThinking'));
                        const prose = view.kind === 'prose' ? view.text : view.prose;
                        reply.textEl.textContent = [prose, status].filter(Boolean).join('\n\n') || msg('msgThinking');
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
                        body: JSON.stringify({ prompt: prompt, context: documentContext() }),
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
                                    toolNote = '';
                                    liveApply(false);
                                    paint(false);
                                    if (stick) scrollToEnd();
                                } else if (event.type === 'tool_use') {
                                    // The loop is off running a tool — narrate it instead of
                                    // sitting silent (the name is our own registry's, made
                                    // readable: heisenberg__list_blocks -> "list blocks").
                                    const tool = String((event.data && event.data.name) || '').replace(/^heisenberg__/, '').replace(/_/g, ' ');
                                    toolNote = msg('msgWorking').replace(':tool', tool || '…');
                                    paint(false);
                                    if (stick) scrollToEnd();
                                } else if (event.type === 'done') {
                                    sawDone = true;
                                    stopReason = (event.data && event.data.stopReason) || '';
                                } else if (event.type === 'error') {
                                    sawDone = true; // an error IS an ending — don't also cry truncation
                                    reply.node.classList.add('hb-ai-msg--error');
                                    reply.textEl.textContent = event.text || msg('msgNetwork');
                                }
                            };

                            // Frames are \n\n-delimited and a chunk can split mid-frame, so only
                            // consume complete ones.
                            const drain = () => {
                                let cut;
                                while ((cut = buffer.indexOf('\n\n')) !== -1) {
                                    handle(buffer.slice(0, cut));
                                    buffer = buffer.slice(cut + 2);
                                }
                            };

                            const pump = () => reader.read().then(({ done, value }) => {
                                if (done) {
                                    // Flush the decoder (a multi-byte character can straddle the
                                    // last chunk) and take whatever whole frame is still buffered,
                                    // plus a final one that never got its blank line.
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
                            // The stream is over — apply the final, complete markup unthrottled
                            // so the last block never waits on the pacing window.
                            liveApply(true);
                            // A reply that was ONLY reasoning leaves nothing to show; say so
                            // rather than presenting an empty card.
                            if (!reply.node.classList.contains('hb-ai-msg--error')) {
                                paint(true);
                                reply.actions.hidden = stripReasoning(acc) === '' && !lastRun.applied;
                            }
                            // Say WHY a reply stopped short instead of leaving the user to
                            // guess whether the model finished.
                            if (!sawDone) addMessage('note', msg('msgTruncated')).node.classList.add('hb-ai-msg--error');
                            else if (stopReason === 'max_tokens' || stopReason === 'length') addMessage('note', msg('msgLengthLimit'));
                            if (stick) scrollToEnd();
                        })
                        .catch((error) => {
                            setBusy(false);
                            if (error && error.name === 'AbortError') {
                                // Keep whatever streamed before the stop — discarding it would
                                // throw away work the user already paid for.
                                const partial = stripReasoning(acc);
                                reply.textEl.textContent = partial || msg('msgStopped');
                                reply.actions.hidden = partial === '';
                                return;
                            }
                            reply.node.classList.add('hb-ai-msg--error');
                            reply.textEl.textContent = msg('msgNetwork');
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
                    // Enter sends, Shift+Enter is a newline — the convention every chat
                    // composer uses, and the reason this is a textarea at all.
                    if (event.key !== 'Enter' || event.shiftKey) return;
                    event.preventDefault();
                    send?.click();
                });

                root.querySelector('[data-hb-ai-new]')?.addEventListener('click', () => {
                    if (controller) controller.abort();
                    thread.innerHTML = '';
                    emptyEl.hidden = false;
                    lastPrompt = '';
                    setBusy(false);
                });

                // Delegated: Regenerate belongs to whichever message it is in, and messages
                // are created as the conversation grows. (There is no Insert — a markup reply
                // builds onto the canvas LIVE while it streams; see LIVE BUILD in run().)
                thread.addEventListener('click', (event) => {
                    const regen = event.target.closest('[data-hb-ai-regenerate]');
                    if (!regen) return;

                    const node = event.target.closest('[data-hb-ai-msg]');
                    const textEl = node?.querySelector('[data-hb-ai-text]');
                    if (!textEl || !lastPrompt) return;

                    run(lastPrompt, {
                        node: node,
                        textEl: textEl,
                        actions: node.querySelector('[data-hb-ai-actions]'),
                    });
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
@endonce

@props(['streamUrl' => null])
@php
    // The four suggestion rows and the header subtitle were removed on request
    // (2026-08-08). Their lang keys are left in place — deleting copy is cheap to
    // reverse, deleting it from both locales is not.
    $toolCards = [
        ['icon' => 'sparkle', 'label' => __('heisenberg::editor.panel_ai_tools.tool_generate_title')],
        ['icon' => 'file-text', 'label' => __('heisenberg::editor.panel_ai_tools.tool_write_summary')],
        ['icon' => 'magic-wand', 'label' => __('heisenberg::editor.panel_ai_tools.tool_improve_writing')],
        ['icon' => 'pencil-simple', 'label' => __('heisenberg::editor.panel_ai_tools.tool_fix_grammar')],
        ['icon' => 'sliders-horizontal', 'label' => __('heisenberg::editor.panel_ai_tools.tool_change_tone')],
        // "Generate Image" is deliberately absent: every other card sends a prompt to a
        // text provider, and neither shipped adapter can produce an image — a card that
        // looks actionable and silently does nothing is worse than no card. Same call
        // TODO 0.1 made for the Components tab's twelve cards, eight of which mapped to
        // no contract. The lang key stays for when an image provider lands.
        ['icon' => 'translate', 'label' => __('heisenberg::editor.panel_ai_tools.tool_translate')],
        ['icon' => 'trend-up', 'label' => __('heisenberg::editor.panel_ai_tools.tool_seo_optimize')],
    ];
@endphp
<div data-hb-panel-ai
    data-stream-url="{{ $streamUrl }}"
    data-msg-thinking="{{ __('heisenberg::editor.panel_ai_tools.ai_thinking') }}"
    data-msg-building="{{ __('heisenberg::editor.panel_ai_tools.ai_building') }}"
    data-msg-built="{{ __('heisenberg::editor.panel_ai_tools.ai_built') }}"
    data-msg-working="{{ __('heisenberg::editor.panel_ai_tools.ai_working_tool') }}"
    data-msg-network="{{ __('heisenberg::editor.ai.network_error', ['provider' => __('heisenberg::editor.ai.settings_title')]) }}"
    data-msg-role-you="{{ __('heisenberg::editor.panel_ai_tools.ai_role_you') }}"
    data-msg-role-assistant="{{ __('heisenberg::editor.panel_ai_tools.ai_role_assistant') }}"
    data-msg-stopped="{{ __('heisenberg::editor.panel_ai_tools.ai_stopped') }}"
    data-msg-empty-reply="{{ __('heisenberg::editor.panel_ai_tools.ai_empty_reply') }}"
    data-msg-truncated="{{ __('heisenberg::editor.panel_ai_tools.ai_truncated') }}"
    data-msg-length-limit="{{ __('heisenberg::editor.panel_ai_tools.ai_length_limit') }}"
    {{ $attributes->merge(['class' => 'hb-panel-ai']) }}>
    <x-ui.panel-tabs :items="[['label' => __('heisenberg::editor.panel_ai_tools.tab_ai')], ['label' => __('heisenberg::editor.panel_ai_tools.tab_tools')]]" :active-index="0" />

    <div class="hb-panel-ai__content" data-hb-panel-ai-ai>
        <div class="hb-ai-body">
        <div class="hb-ai-scroll" data-hb-ai-scroll>
            <div class="hb-ai-header">
                <div class="hb-ai-header__row">
                    <span class="hb-ai-header__badge" aria-hidden="true">
                        <span class="hb-ai-header__badge-icon">@include('heisenberg::components.ui.icon', ['name' => 'sparkle', 'size' => 16])</span>
                    </span>
                    <span class="hb-ai-header__title">{{ __('heisenberg::editor.panel_ai_tools.ai_assistant') }}</span>
                    <x-ui.icon-button icon="arrow-counter-clockwise" class="hb-ai-header__action"
                        :label="__('heisenberg::editor.panel_ai_tools.ai_new_chat')" data-hb-ai-new />
                    <x-ui.icon-button icon="gear" class="hb-ai-header__action"
                        :label="__('heisenberg::editor.ai.settings_open')" data-hb-ai-settings-open />
                </div>
            </div>

            {{-- The transcript. Every turn stays on screen, so the conversation is its own
                 history — the previous build replaced one result card each time, which is why
                 there was nothing to scroll back through. --}}
            <div class="hb-ai-thread" data-hb-ai-thread></div>
            <div class="hb-ai-empty" data-hb-ai-empty>{{ __('heisenberg::editor.panel_ai_tools.ai_empty') }}</div>
        </div>
            <x-ui.custom-scrollbar container="[data-hb-ai-scroll]" />
        </div>

        <div class="hb-ai-prompt-wrap">
            <div class="hb-ai-prompt-bar">
                {{-- A textarea, not an input: prompts are routinely more than one line, and a
                     single-line field silently hides everything past its width. Grows with the
                     content up to a cap, then scrolls. --}}
                <textarea class="hb-ai-prompt-bar__input" data-hb-ai-prompt rows="1"
                    placeholder="{{ __('heisenberg::editor.panel_ai_tools.ai_prompt_ph') }}"></textarea>
                <button type="button" class="hb-ai-prompt-bar__send" data-hb-ai-send aria-label="{{ __('heisenberg::editor.common.send') }}">
                    @include('heisenberg::components.ui.icon', ['name' => 'arrow-up', 'size' => 14])
                </button>
                {{-- Swaps in while a reply is streaming: a long generation with no way to stop
                     it is the composer's worst failure mode. --}}
                <button type="button" class="hb-ai-prompt-bar__send hb-ai-prompt-bar__stop" data-hb-ai-stop hidden
                    aria-label="{{ __('heisenberg::editor.panel_ai_tools.ai_stop') }}">
                    @include('heisenberg::components.ui.icon', ['name' => 'stop', 'size' => 12])
                </button>
            </div>
            <span class="hb-ai-prompt-hint">{{ __('heisenberg::editor.panel_ai_tools.ai_prompt_hint') }}</span>
        </div>
    </div>

    {{-- One turn. Cloned per message so the extracted response-card styling is reused rather
         than rebuilt as a string; the actions only render on assistant turns. --}}
    <template data-hb-ai-msg-template>
        <div class="hb-ai-msg" data-hb-ai-msg>
            <span class="hb-ai-msg__role" data-hb-ai-msg-role></span>
            <div class="hb-ai-response">
                <p class="hb-ai-response__text" data-hb-ai-text></p>
                <div class="hb-ai-response__actions" data-hb-ai-actions hidden>
                    <button type="button" class="hb-ai-action hb-ai-action--regenerate" data-hb-ai-regenerate>
                        <span class="hb-ai-action__icon" aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'arrow-clockwise', 'size' => 14])</span>
                        {{ __('heisenberg::editor.panel_ai_tools.ai_regenerate') }}
                    </button>
                </div>
            </div>
        </div>
    </template>

    <div class="hb-panel-ai__content" data-hb-panel-ai-tools hidden>
        <x-ui.search-field :placeholder="__('heisenberg::editor.panel_ai_tools.search_tools')"
            data-hb-filter="[data-hb-panel-ai-tools]" data-hb-filter-item=".hb-panel-ai__grid > *" />
        <x-ui.category-head :label="__('heisenberg::editor.panel_ai_tools.category_writing')" />
        <div class="hb-panel-ai__toolsbody" data-hb-ai-tools-scroll>
            <div class="hb-panel-ai__grid">
                @foreach ($toolCards as $card)
                    <x-ui.tool-card :icon="$card['icon']" :label="$card['label']"
                        :data-hb-ai-suggest="$card['label']" />
                @endforeach
            </div>
            <x-ui.custom-scrollbar container="[data-hb-ai-tools-scroll]" />
        </div>
    </div>
</div>
