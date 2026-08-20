{{-- live/toc-dialog — the Post tab's AUTHORED table of contents, in the media-dialog's modal
     shell (same scrim classes on purpose: live/media/media-dialog's @once script wires every
     `.hb-mediadialog__scrim` with hbOpen()/hbClose(), Escape, backdrop-close and the focus trap,
     so this dialog inherits all of that for free — exactly like live/revisions-dialog and
     live/ai/ai-history-dialog do).

     Opened by the Post tab's [data-hb-toc-open] Edit button (inspector.blade.php), which also
     carries the current post id, the toc URL template, and the post's CURRENT entries as JSON
     (data-hb-toc-entries) — the dialog is stateless between opens, seeded fresh from the opener's
     dataset every time (same "read off the opener" posture as live/revisions-dialog, just with a
     bigger payload since this dialog edits rather than just lists). A post with no id yet can
     still compose entries in-memory (including Load from headings, which needs no server round
     trip); only Save is gated on a real post id.

     Save PUTs the full {entries} list with replace-all semantics (PostSettingsController::
     updateToc()) and writes the server's canonical response back onto the opener's dataset AND
     the Post tab's summary line, so a reload-free re-open sees exactly what was persisted.

     Load from headings walks window.hbEditor.getDoc().blocks for heisenberg/heading instances,
     derives {label, anchor} from each, and — when a heading has no anchor of its own yet — writes
     the generated slug BACK onto the block via window.hbEditor.setAttribute(id, 'anchor', ...) so
     the heading's rendered `id` actually matches the TOC link BlockRenderer emits for it (see
     heading.json's render.template.attributes.id). Matching is by anchor, so re-running it never
     duplicates a heading already in the list and never touches hand-added rows.

     Rows are built by cloning a Blade-rendered `<template>` (same trick as inspector.blade.php's
     Categories/Tags "add item" template) rather than assembling icon SVGs from JS strings — every
     icon in this dialog is server-rendered once, up front. --}}
@once
<style>
    .hb-tocdialog { width: 600px; height: 560px; }
    .hb-tocdialog__body { position: relative; flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; }
    .hb-tocdialog__scrollwrap { position: relative; flex: 1 1 auto; min-height: 0; }
    .hb-tocdialog__scroll { height: 100%; box-sizing: border-box; padding: var(--hb-space-4, 16px); }
    .hb-tocdialog__empty { padding: 48px 0; text-align: center; color: var(--hb-text-muted); font-size: var(--hb-fs-base, 13px); font-family: var(--hb-font-sans, Rubik, sans-serif); }
    .hb-tocdialog__empty[hidden] { display: none; }
    .hb-tocdialog__list { display: flex; flex-direction: column; gap: 6px; }
    .hb-tocdialog__row {
        display: flex; align-items: center; gap: var(--hb-space-3, 12px);
        padding: 8px 10px;
        border: 1px solid var(--hb-border);
        border-radius: var(--hb-radius-md, 5px);
    }
    .hb-tocdialog__reorder { display: flex; flex-direction: column; gap: 2px; flex: none; }
    .hb-tocdialog__reorder button {
        display: inline-flex; align-items: center; justify-content: center;
        width: 20px; height: 15px; border: 0; background: none; cursor: pointer; padding: 0;
        color: var(--hb-text-secondary); border-radius: 3px;
    }
    .hb-tocdialog__reorder button:hover:not(:disabled) { background: var(--hb-surface-hover); color: var(--hb-text-primary); }
    .hb-tocdialog__reorder button:disabled { opacity: .3; cursor: default; }
    .hb-tocdialog__fields { flex: 1 1 auto; min-width: 0; display: flex; gap: 8px; }
    .hb-tocdialog__label, .hb-tocdialog__anchor {
        box-sizing: border-box; height: 30px; padding: 0 10px;
        border: 1px solid var(--hb-border); border-radius: var(--hb-radius-md, 5px);
        background: var(--hb-bg); font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-primary);
    }
    .hb-tocdialog__label:focus, .hb-tocdialog__anchor:focus { outline: 0; border-color: var(--hb-border-focus); }
    .hb-tocdialog__label { flex: 1 1 60%; min-width: 0; }
    .hb-tocdialog__anchor { flex: 1 1 40%; min-width: 0; font-family: var(--hb-font-mono, ui-monospace, monospace); }
    .hb-tocdialog__remove {
        flex: none; display: inline-flex; align-items: center; justify-content: center;
        width: 28px; height: 28px; border: 0; background: none; cursor: pointer; padding: 0;
        color: var(--hb-text-muted); border-radius: var(--hb-radius-sm, 3px);
    }
    .hb-tocdialog__remove:hover { background: var(--hb-danger-subtle); color: var(--hb-danger); }

    .hb-tocdialog__foot {
        flex: none; display: flex; align-items: center; gap: 8px;
        padding: 10px var(--hb-space-4, 16px);
        border-top: 1px solid var(--hb-border);
        font-family: var(--hb-font-sans, Rubik, sans-serif);
    }
    .hb-tocdialog__add, .hb-tocdialog__loadheadings {
        flex: none; border: 1px solid var(--hb-border); cursor: pointer;
        padding: 6px 10px; border-radius: var(--hb-radius-control, 6px);
        background: var(--hb-bg); color: var(--hb-text-secondary);
        font-family: inherit; font-size: var(--hb-fs-sm, 12px); font-weight: 600;
    }
    .hb-tocdialog__add:hover, .hb-tocdialog__loadheadings:hover { background: var(--hb-surface-hover); }
    {{-- ONE flexible message slot between the buttons. The note and the status
         used to be two separate flex items — the transient status (flex:none)
         claimed its full text width and crushed the note (flex:1, min-width:0)
         into a one-word-per-line sliver, with both visible at once. Now they
         share this container and the script guarantees only one shows. --}}
    .hb-tocdialog__msgs { flex: 1 1 auto; min-width: 0; overflow: hidden; text-align: right; }
    .hb-tocdialog__note, .hb-tocdialog__status {
        font-size: var(--hb-fs-xs, 11px); line-height: 1.35; color: var(--hb-text-muted);
        display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
    }
    .hb-tocdialog__note[hidden], .hb-tocdialog__status[hidden] { display: none; }
    .hb-tocdialog__save {
        flex: none; border: 0; cursor: pointer;
        padding: 6px 14px; border-radius: var(--hb-radius-control, 6px);
        background: var(--hb-accent); color: var(--hb-accent-fg);
        font-family: inherit; font-size: var(--hb-fs-sm, 12px); font-weight: 600;
    }
    .hb-tocdialog__save:hover:not(:disabled) { opacity: .85; }
    .hb-tocdialog__save:disabled { opacity: .4; cursor: default; }
    .hb-tocdialog__close {
        flex: none; border: 0; cursor: pointer;
        padding: 6px 12px; border-radius: var(--hb-radius-control, 6px);
        background: var(--hb-bg-muted); color: var(--hb-text-secondary);
        font-family: inherit; font-size: var(--hb-fs-sm, 12px); font-weight: 600;
    }
    .hb-tocdialog__close:hover { background: var(--hb-surface-hover); }
</style>
<script>
    (() => {
        const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        const stripTags = (html) => {
            const div = document.createElement('div');
            div.innerHTML = String(html || '');
            return (div.textContent || div.innerText || '').replace(/\s+/g, ' ').trim();
        };

        // The anchor a fresh slug must satisfy — the same shape the server enforces
        // (PostSettingsController::updateToc()'s `regex:/^[A-Za-z][\w-]*$/`).
        const slugify = (text) => {
            let s = String(text || '').toLowerCase().trim()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
            if (!/^[a-z]/.test(s)) s = 's-' + s;
            return s || 's-section';
        };

        const uniqueSlug = (base, used) => {
            let candidate = base;
            let n = 2;
            while (used.has(candidate)) { candidate = base + '-' + n; n++; }
            return candidate;
        };

        const boot = () => {
            document.querySelectorAll('[data-hb-toc]').forEach((scrim) => {
                if (scrim.__hbToc) return;
                scrim.__hbToc = true;

                const list = scrim.querySelector('[data-hb-toc-list]');
                const empty = scrim.querySelector('[data-hb-toc-empty]');
                const rowTemplate = scrim.querySelector('template[data-hb-toc-row-template]');
                const addBtn = scrim.querySelector('[data-hb-toc-add]');
                const loadBtn = scrim.querySelector('[data-hb-toc-load]');
                const saveBtn = scrim.querySelector('[data-hb-toc-save]');
                const closeBtn = scrim.querySelector('[data-hb-toc-close]');
                const status = scrim.querySelector('[data-hb-toc-status]');
                const note = scrim.querySelector('[data-hb-toc-note]');
                const msg = (key) => scrim.dataset[key] || '';
                if (!list || !rowTemplate) return;

                let entries = []; // [{label, anchor}]
                let postId = '';
                let urlTemplate = '';
                let opener = null;
                let saveEnabled = false;

                // One message at a time: a fresh status ALWAYS wins the slot;
                // the save-the-post-first note only shows while save is gated
                // AND nothing transient is being said. Two messages at once is
                // exactly the crushed-footer bug this replaces.
                const paintMessages = (statusText) => {
                    if (status) {
                        status.textContent = statusText || '';
                        status.hidden = !statusText;
                    }
                    if (note) note.hidden = saveEnabled || !!statusText;
                };

                const setStatus = (text) => paintMessages(text);

                const setSaveEnabled = (enabled) => {
                    saveEnabled = enabled;
                    if (saveBtn) saveBtn.disabled = !enabled;
                    paintMessages(status && !status.hidden ? status.textContent : '');
                };

                const render = () => {
                    list.innerHTML = '';
                    empty.hidden = entries.length > 0;
                    entries.forEach((entry, index) => {
                        const node = rowTemplate.content.firstElementChild.cloneNode(true);
                        const up = node.querySelector('[data-hb-toc-up]');
                        const down = node.querySelector('[data-hb-toc-down]');
                        const labelInput = node.querySelector('[data-hb-toc-label]');
                        const anchorInput = node.querySelector('[data-hb-toc-anchor]');
                        const removeBtn = node.querySelector('[data-hb-toc-remove]');

                        up.disabled = index === 0;
                        down.disabled = index === entries.length - 1;
                        up.addEventListener('click', () => {
                            if (index === 0) return;
                            const tmp = entries[index - 1];
                            entries[index - 1] = entries[index];
                            entries[index] = tmp;
                            render();
                        });
                        down.addEventListener('click', () => {
                            if (index === entries.length - 1) return;
                            const tmp = entries[index + 1];
                            entries[index + 1] = entries[index];
                            entries[index] = tmp;
                            render();
                        });

                        labelInput.value = entry.label || '';
                        anchorInput.value = entry.anchor || '';
                        labelInput.addEventListener('input', () => { entries[index].label = labelInput.value; });
                        anchorInput.addEventListener('input', () => { entries[index].anchor = anchorInput.value; });
                        // Auto-suggest an anchor once the label has real content and the anchor
                        // is still untouched — never overwrites a value the user typed themselves.
                        labelInput.addEventListener('blur', () => {
                            if (anchorInput.value.trim() !== '') return;
                            const used = new Set(entries.map((e, i) => (i !== index ? e.anchor : null)).filter(Boolean));
                            const slug = labelInput.value.trim() ? uniqueSlug(slugify(labelInput.value), used) : '';
                            if (slug) { anchorInput.value = slug; entries[index].anchor = slug; }
                        });

                        removeBtn.addEventListener('click', () => { entries.splice(index, 1); render(); });

                        list.appendChild(node);
                    });
                    // The custom scrollbar tracks content height — a fresh row set changes it
                    // (and the bar booted while the dialog was [hidden]), so re-measure.
                    document.dispatchEvent(new CustomEvent('hb:refresh'));
                };

                addBtn?.addEventListener('click', () => {
                    entries.push({ label: '', anchor: '' });
                    render();
                    const inputs = list.querySelectorAll('[data-hb-toc-label]');
                    inputs[inputs.length - 1]?.focus();
                });

                // Walk the live document for heisenberg/heading instances, recursing into
                // innerBlocks — headings can live inside a group/columns/column.
                const collectHeadings = () => {
                    const out = [];
                    const walk = (blocks) => {
                        (blocks || []).forEach((b) => {
                            if (b && b.name === 'heisenberg/heading') out.push(b);
                            if (b && Array.isArray(b.innerBlocks) && b.innerBlocks.length) walk(b.innerBlocks);
                        });
                    };
                    if (window.hbEditor && typeof window.hbEditor.getDoc === 'function') {
                        walk(window.hbEditor.getDoc().blocks);
                    }
                    return out;
                };

                loadBtn?.addEventListener('click', () => {
                    const headings = collectHeadings();
                    if (!headings.length) { setStatus(msg('msgNoHeadings')); return; }

                    const used = new Set(entries.map((e) => e.anchor).filter(Boolean));
                    let added = 0;
                    let usable = 0; // headings with real text — an empty heading can't be an entry
                    headings.forEach((block) => {
                        const label = stripTags(block.attributes && block.attributes.content);
                        if (!label) return;
                        usable++;
                        let anchor = (block.attributes && block.attributes.anchor) || '';
                        if (!anchor) {
                            anchor = uniqueSlug(slugify(label), used);
                            if (window.hbEditor && typeof window.hbEditor.setAttribute === 'function') {
                                window.hbEditor.setAttribute(block.id, 'anchor', anchor);
                            }
                        }
                        if (used.has(anchor)) return; // already represented — never duplicate
                        used.add(anchor);
                        entries.push({ label: label, anchor: anchor });
                        added++;
                    });
                    render();
                    // Say what actually happened: "already in the table" is only
                    // true when usable headings were all matched — text-less
                    // headings mean there was nothing to load in the first place.
                    setStatus(added ? '' : (usable ? msg('msgNoNewHeadings') : msg('msgNoHeadings')));
                });

                const applySaved = (saved) => {
                    entries = saved.map((e) => ({ label: e.label || '', anchor: e.anchor || '' }));
                    if (opener) opener.dataset.hbTocEntries = JSON.stringify(entries);
                    const field = opener ? opener.closest('[data-hb-post-toc-field]') : null;
                    const summary = field ? field.querySelector('[data-hb-post-toc-summary]') : null;
                    if (summary) {
                        summary.textContent = entries.length
                            ? msg('msgSummaryCount').replace(':count', String(entries.length))
                            : msg('msgSummaryEmpty');
                    }
                };

                saveBtn?.addEventListener('click', () => {
                    if (!postId || !urlTemplate) return;
                    const payload = entries.map((e) => ({ label: (e.label || '').trim(), anchor: (e.anchor || '').trim() }));
                    if (payload.some((e) => !e.label || !e.anchor)) { setStatus(msg('msgIncomplete')); return; }
                    if (payload.some((e) => !/^[A-Za-z][\w-]*$/.test(e.anchor))) { setStatus(msg('msgInvalidAnchor')); return; }

                    saveBtn.disabled = true;
                    const url = urlTemplate.replace('__ID__', encodeURIComponent(postId));
                    window.fetch(url, {
                        method: 'PUT',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf(),
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({ entries: payload }),
                    })
                        .then((r) => { if (!r.ok) throw new Error('http-' + r.status); return r.json(); })
                        .then((data) => {
                            saveBtn.disabled = false;
                            applySaved(Array.isArray(data.entries) ? data.entries : payload);
                            render();
                            setStatus(msg('msgSaved'));
                        })
                        .catch(() => { saveBtn.disabled = false; setStatus(msg('msgError')); });
                });

                closeBtn?.addEventListener('click', () => {
                    if (scrim.hbClose) scrim.hbClose(); else scrim.hidden = true;
                });
                scrim.querySelector('.hb-mediadialog__close')?.addEventListener('click', () => {
                    if (scrim.hbClose) scrim.hbClose(); else scrim.hidden = true;
                });

                if (!document.__hbTocOpen) {
                    document.__hbTocOpen = true;
                    document.addEventListener('click', (event) => {
                        const trigger = event.target.closest('[data-hb-toc-open]');
                        if (!trigger) return;
                        opener = trigger;
                        postId = trigger.dataset.hbPostId || '';
                        urlTemplate = trigger.dataset.hbTocUrlTemplate || '';
                        let seeded = [];
                        try { seeded = JSON.parse(trigger.dataset.hbTocEntries || '[]'); } catch (e) { seeded = []; }
                        entries = Array.isArray(seeded) ? seeded.map((e) => ({ label: String((e && e.label) || ''), anchor: String((e && e.anchor) || '') })) : [];
                        setStatus('');
                        setSaveEnabled(postId !== '');
                        render();
                        if (scrim.hbOpen) scrim.hbOpen(trigger); else scrim.hidden = false;
                    });
                    // A never-saved document's first save mints a post id — the (single) TOC
                    // trigger picks it up, same contract as live/revisions-dialog's own
                    // hb:post-id listener; if the dialog happens to be open already, the live
                    // state is updated too so Save enables without a re-open.
                    document.addEventListener('hb:post-id', (event) => {
                        const id = event.detail && event.detail.id != null ? String(event.detail.id) : '';
                        if (!id) return;
                        document.querySelectorAll('[data-hb-toc-open]').forEach((row) => { row.dataset.hbPostId = id; });
                        if (!scrim.hidden) {
                            postId = id;
                            setSaveEnabled(true);
                        }
                    });
                }
            });
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce

<div class="hb-mediadialog__scrim" data-hb-toc hidden
    data-msg-no-headings="{{ __('heisenberg::editor.toc.no_headings') }}"
    data-msg-no-new-headings="{{ __('heisenberg::editor.toc.no_new_headings') }}"
    data-msg-incomplete="{{ __('heisenberg::editor.toc.incomplete') }}"
    data-msg-invalid-anchor="{{ __('heisenberg::editor.toc.invalid_anchor') }}"
    data-msg-error="{{ __('heisenberg::editor.toc.error') }}"
    data-msg-saved="{{ __('heisenberg::editor.common.saved') }}"
    data-msg-summary-count="{{ __('heisenberg::editor.toc.summary_count') }}"
    data-msg-summary-empty="{{ __('heisenberg::editor.toc.summary_empty') }}">
    <div class="hb-mediadialog hb-tocdialog" role="dialog" aria-modal="true" aria-label="{{ __('heisenberg::editor.toc.title') }}" tabindex="-1">
        <div class="hb-mediadialog__top">
            <span class="hb-mediadialog__title">{{ __('heisenberg::editor.toc.title') }}</span>
            <button type="button" class="hb-mediadialog__close" aria-label="{{ __('heisenberg::editor.common.close') }}">
                @include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 16])
            </button>
        </div>
        <div class="hb-tocdialog__body">
            <div class="hb-tocdialog__scrollwrap">
                <div class="hb-tocdialog__scroll" data-hb-toc-scroll>
                    <div class="hb-tocdialog__empty" data-hb-toc-empty>{{ __('heisenberg::editor.toc.empty_list') }}</div>
                    <div class="hb-tocdialog__list" data-hb-toc-list></div>
                </div>
                <x-ui.custom-scrollbar container="[data-hb-toc-scroll]" />
            </div>
            {{-- Clone target for each entry row — icons are server-rendered once here, never
                 assembled from a JS string (same convention as inspector.blade.php's Categories/
                 Tags item template). --}}
            <template data-hb-toc-row-template>
                <div class="hb-tocdialog__row">
                    <div class="hb-tocdialog__reorder">
                        <button type="button" data-hb-toc-up aria-label="{{ __('heisenberg::editor.toc.move_up') }}">
                            @include('heisenberg::components.ui.icon', ['name' => 'caret-up', 'size' => 12])
                        </button>
                        <button type="button" data-hb-toc-down aria-label="{{ __('heisenberg::editor.toc.move_down') }}">
                            @include('heisenberg::components.ui.icon', ['name' => 'caret-down', 'size' => 12])
                        </button>
                    </div>
                    <div class="hb-tocdialog__fields">
                        <input type="text" class="hb-tocdialog__label" data-hb-toc-label
                            placeholder="{{ __('heisenberg::editor.toc.label_ph') }}" autocomplete="off" spellcheck="false">
                        <input type="text" class="hb-tocdialog__anchor" data-hb-toc-anchor
                            placeholder="{{ __('heisenberg::editor.toc.anchor_ph') }}" autocomplete="off" spellcheck="false">
                    </div>
                    <button type="button" class="hb-tocdialog__remove" data-hb-toc-remove aria-label="{{ __('heisenberg::editor.toc.remove') }}">
                        @include('heisenberg::components.ui.icon', ['name' => 'trash', 'size' => 14])
                    </button>
                </div>
            </template>
            <div class="hb-tocdialog__foot">
                <button type="button" class="hb-tocdialog__add" data-hb-toc-add>
                    {{ __('heisenberg::editor.toc.add_entry') }}
                </button>
                <button type="button" class="hb-tocdialog__loadheadings" data-hb-toc-load>
                    {{ __('heisenberg::editor.toc.load_headings') }}
                </button>
                <span class="hb-tocdialog__msgs">
                    <span class="hb-tocdialog__note" data-hb-toc-note>{{ __('heisenberg::editor.toc.needs_save') }}</span>
                    <span class="hb-tocdialog__status" data-hb-toc-status hidden></span>
                </span>
                <button type="button" class="hb-tocdialog__save" data-hb-toc-save disabled>
                    {{ __('heisenberg::editor.common.save') }}
                </button>
                <button type="button" class="hb-tocdialog__close" data-hb-toc-close>
                    {{ __('heisenberg::editor.common.close') }}
                </button>
            </div>
        </div>
    </div>
</div>
