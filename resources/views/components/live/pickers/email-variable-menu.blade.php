@props(['entries' => [], 'allTargets' => []])
@php
    // The picker is intentionally a SEPARATE component from the theme-token
    // `variable-menu.blade.php` (resources/views/components/live/pickers/variable-menu.blade.php).
    // Two products: theme CSS tokens (color/number, swatches, `varselect` event with {name,value})
    // and email merge tags (groups, types, targets, insert literal `{{ dotted.key }}` text). Sharing
    // one component would silently cross-contaminate the Style-panel picker the moment either
    // grows, so the plan documents the split and we honour it here.
    //
    // The mount point is the ROOT container; its data attributes are the public contract the
    // picker's vanilla JS reads. Anchors:
    //   data-hb-email-variable-picker        — root
    //   data-hb-email-variable-targets       — comma-joined distinct targets across all entries
    //   data-hb-email-variable-key           — per-entry dotted key
    //   data-hb-email-variable-type          — per-entry formatter type (text|email|url|…)
    //   data-hb-email-variable-targets       — per-entry comma-joined compatible targets
    //   data-hb-email-variable-sample        — per-entry formatted SAMPLE string
    //   data-hb-email-variable-label         — per-entry localized label
    //   data-hb-email-variable-group         — per-entry group (empty string when absent)
    //   data-hb-email-variable-description   — per-entry description (empty string when absent)
    //   data-hb-email-variable-options        — JSON-encoded options array (for formatter-specific UI)
    //   data-hb-email-variable-trigger        — on the trigger button, the insertion target kind
    //                                            (`subject`, `text`, `url`)
    //
    // Insertion uses the native `input` event so hbEditor's setAttribute path persists the
    // resulting text into the document exactly the same way typing does — no new persistence
    // path, no second save endpoint.
@endphp
<div class="hb-pop hb-evinj" data-hb-email-variable-picker
     data-hb-email-variable-targets="{{ implode(',', array_map('strval', $allTargets)) }}"
     data-hb-email-variable-trigger-label="{{ __('heisenberg::editor.email_variable_menu.trigger_label') }}"
     data-hb-email-variable-trigger-aria="{{ __('heisenberg::editor.email_variable_menu.trigger_aria') }}"
     hidden
     role="dialog"
     aria-label="{{ __('heisenberg::editor.email_variable_menu.label') }}">
    <div class="hb-varmenu__search">
        <input type="search" placeholder="{{ __('heisenberg::editor.email_variable_menu.search_placeholder') }}"
               aria-label="{{ __('heisenberg::editor.email_variable_menu.search_label') }}"
               data-hb-email-variable-search>
    </div>
    <div class="hb-varmenu__list" data-hb-email-variable-list role="listbox"
         aria-label="{{ __('heisenberg::editor.email_variable_menu.list_label') }}">
        @foreach ($entries as $entry)
            @php
                $key = (string) ($entry['key'] ?? '');
                if ($key === '') { continue; }
                $targets = array_values(array_filter(
                    array_map('strval', (array) ($entry['targets'] ?? [])),
                    static fn (string $t): bool => $t !== ''
                ));
            @endphp
            <button type="button" class="hb-vmi hb-evinj__item" role="option"
                    data-hb-email-variable-entry
                    data-hb-email-variable-key="{{ $key }}"
                    data-hb-email-variable-type="{{ (string) ($entry['type'] ?? '') }}"
                    data-hb-email-variable-targets="{{ implode(',', $targets) }}"
                    data-hb-email-variable-label="{{ (string) ($entry['label'] ?? '') }}"
                    data-hb-email-variable-sample="{{ (string) ($entry['sample'] ?? '') }}"
                    data-hb-email-variable-group="{{ (string) ($entry['group'] ?? '') }}"
                    data-hb-email-variable-description="{{ (string) ($entry['description'] ?? '') }}"
                    data-hb-email-variable-options="{{ json_encode((array) ($entry['options'] ?? [])) }}">
                <span class="hb-vmi__l">
                    <span class="hb-vmi__check" aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'check', 'size' => 13])</span>
                    <span class="hb-evinj__main">
                        <span class="hb-vmi__name">{{ (string) ($entry['label'] ?? '') }}</span>
                        <span class="hb-evinj__key">{{ $key }}</span>
                        @if (! empty($entry['sample']))
                            <span class="hb-evinj__sample">{{ (string) $entry['sample'] }}</span>
                        @endif
                    </span>
                </span>
                @if (! empty($entry['group']))
                    <span class="hb-evinj__group">{{ (string) $entry['group'] }}</span>
                @endif
            </button>
        @endforeach
        <div class="hb-evinj__empty" data-hb-email-variable-empty hidden>
            {{ __('heisenberg::editor.email_variable_menu.empty') }}
        </div>
    </div>
</div>

@once
<style>
    .hb-evinj { position: fixed; z-index: 90; min-width: 280px; max-width: 360px; max-height: 360px; display: flex; flex-direction: column; }
    .hb-evinj__main { display: flex; flex-direction: column; gap: 2px; }
    .hb-evinj__key {
        font-family: var(--hb-font-mono, ui-monospace, SFMono-Regular, Menlo, monospace);
        font-size: 11px; color: var(--hb-text-muted); line-height: 1.2;
    }
    .hb-evinj__sample {
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: 11px; color: var(--hb-text-secondary); line-height: 1.2;
        margin-top: 2px;
    }
    .hb-evinj__group {
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: 10px; color: var(--hb-text-muted);
        text-transform: uppercase; letter-spacing: .04em;
        align-self: center;
    }
    .hb-evinj__item { align-items: flex-start; }
    .hb-evinj__item[hidden] { display: none !important; }
    .hb-evinj__empty {
        padding: 12px; font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: 12px; color: var(--hb-text-muted);
    }
    .hb-evinj__trigger {
        display: inline-flex; align-items: center; gap: 4px;
        margin-left: 6px; padding: 2px 6px;
        background: transparent; border: 1px solid var(--hb-border); border-radius: var(--hb-radius-md, 5px);
        color: var(--hb-text-secondary);
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        font-size: 11px; line-height: 1;
        cursor: pointer;
    }
    .hb-evinj__trigger:hover { color: var(--hb-editing); border-color: var(--hb-editing); }
    .hb-evinj__trigger:focus { outline: 2px solid var(--hb-editing); outline-offset: 1px; }
    .hb-evinj__trigger svg { display: block; }
</style>
@endonce

@once
<script>
    (() => {
        // The picker uses package-native vanilla JS only — no new dependencies, no new build
        // step, no global registry. It re-uses the existing `hbEditor` surface (window.hbEditor
        // = block-runtime.blade.php's public API) to persist inserted text into the document;
        // for the inspector title mirror (an <input>), it sets .value and fires `input` so the
        // existing hb:doc-title listener re-mirrors it into the canvas title.
        const TARGET_SUBJECT = 'subject';
        const TARGET_TEXT = 'text';
        const TARGET_URL = 'url';
        const ENTRY_SELECTOR = '[data-hb-email-variable-entry]';

        // Tracks the LAST eligible insertion target the picker latched onto. When the picker is
        // docked next to a subject trigger the targets collapse to {subject}; when docked next to
        // a rich-text editable they collapse to {text}; etc. The picker filters entries by this
        // set so the rich-text editing surface never offers a URL-only variable.
        let lastEligibleTargets = null;
        let lastTrigger = null;
        let lastInsertionTarget = null;
        let lastRange = null;

        function getPicker() {
            return document.querySelector('[data-hb-email-variable-picker]');
        }

        function getEntries(picker) {
            return Array.from(picker.querySelectorAll(ENTRY_SELECTOR));
        }

        function pickerVisible(picker) {
            return picker && ! picker.hidden;
        }

        function restoreInsertionFocus() {
            const field = lastInsertionTarget && lastInsertionTarget.field;
            if (field && field.isConnected) {
                field.focus();
                if (lastRange && field.isContentEditable) {
                    const selection = window.getSelection();
                    if (selection) {
                        try {
                            selection.removeAllRanges();
                            selection.addRange(lastRange);
                        } catch (err) { }
                    }
                }
                return;
            }
            if (lastTrigger && lastTrigger.isConnected) lastTrigger.focus();
        }

        function closePicker(restoreFocus = true) {
            const picker = getPicker();
            if (! picker) return;
            picker.hidden = true;
            document.removeEventListener('mousedown', onOutside, true);
            document.removeEventListener('keydown', onKey, true);
            if (restoreFocus) restoreInsertionFocus();
        }

        function filterEntries(picker, term) {
            const entries = getEntries(picker);
            const t = (term || '').trim().toLowerCase();
            let visible = 0;
            entries.forEach((entry) => {
                const hay = [
                    entry.getAttribute('data-hb-email-variable-label') || '',
                    entry.getAttribute('data-hb-email-variable-key') || '',
                    entry.getAttribute('data-hb-email-variable-group') || '',
                ].join(' ').toLowerCase();
                const match = t === '' || hay.indexOf(t) !== -1;
                const targetMatch = matchesTargetFilter(entry);
                const show = match && targetMatch;
                entry.hidden = ! show;
                if (show) visible += 1;
            });
            const empty = picker.querySelector('[data-hb-email-variable-empty]');
            if (empty) empty.hidden = visible !== 0;
            // Update aria-selected state so screen readers follow the visible items.
            let firstVisible = null;
            entries.forEach((e) => { if (! e.hidden && ! firstVisible) firstVisible = e; });
            entries.forEach((e) => { e.setAttribute('aria-selected', e === firstVisible ? 'true' : 'false'); });
            return visible;
        }

        function matchesTargetFilter(entry) {
            if (! lastEligibleTargets || lastEligibleTargets.length === 0) return true;
            const targets = (entry.getAttribute('data-hb-email-variable-targets') || '')
                .split(',').map((s) => s.trim()).filter((s) => s !== '');
            return targets.some((t) => lastEligibleTargets.indexOf(t) !== -1);
        }

        // Discover the insertion target kind for a given trigger button. The trigger's
        // data-hb-email-variable-trigger attribute names the target directly (`subject`,
        // `text`, or `url`); the trigger sits next to an actual input/editable that the
        // insertion step will write into.
        function targetsForTrigger(trigger) {
            const kind = trigger.getAttribute('data-hb-email-variable-trigger') || TARGET_TEXT;
            if (kind === TARGET_SUBJECT) return [TARGET_TEXT];
            if (kind === TARGET_URL) return [TARGET_URL];
            return [TARGET_TEXT];
        }

        function positionPicker(picker, trigger) {
            const rect = trigger.getBoundingClientRect();
            const pop = picker;
            pop.style.left = Math.max(8, Math.min(window.innerWidth - pop.offsetWidth - 8, rect.left)) + 'px';
            pop.style.top = (rect.bottom + 6) + 'px';
        }

        function openPicker(trigger) {
            const picker = getPicker();
            if (! picker) return;
            lastTrigger = trigger;
            lastInsertionTarget = insertionTargetForTrigger(trigger);
            lastRange = null;
            if (lastInsertionTarget.field && lastInsertionTarget.field.isContentEditable) {
                const selection = window.getSelection();
                if (selection && selection.rangeCount > 0) {
                    const range = selection.getRangeAt(0);
                    if (lastInsertionTarget.field.contains(range.commonAncestorContainer)) {
                        lastRange = range.cloneRange();
                    }
                }
            }
            lastEligibleTargets = targetsForTrigger(trigger);
            const search = picker.querySelector('[data-hb-email-variable-search]');
            if (search) search.value = '';
            filterEntries(picker, '');
            picker.hidden = false;
            positionPicker(picker, trigger);
            setTimeout(() => search ? search.focus() : null, 0);
            document.addEventListener('mousedown', onOutside, true);
            document.addEventListener('keydown', onKey, true);
        }

        function triggerForEvent(e) {
            const t = e.target.closest('[data-hb-email-variable-trigger]');
            return t || null;
        }

        function insertionTargetForTrigger(trigger) {
            // Walk up to the nearest anchor field:
            //  - `[data-hb-title]` (the canvas subject or its inspector mirror) → subject target
            //  - a `.hb-ce[data-hb-rt]` → text target
            //  - a settings input (e.g. `[data-hb-control-kind="attributes"][data-hb-control-type="url"]`) → url target
            //    (the URL case is only chosen when the trigger's own data-hb-email-variable-trigger
            //    says `url`; the script below honours that explicit signal rather than re-walking
            //    the DOM to "guess" the target).
            const kind = trigger.getAttribute('data-hb-email-variable-trigger') || TARGET_TEXT;
            if (trigger.__hbEmailVariableField && trigger.__hbEmailVariableField.isConnected) {
                return { kind: kind, field: trigger.__hbEmailVariableField };
            }
            const anchor = trigger.parentElement;
            if (! anchor) return { kind: kind, field: null };
            // The picker trigger lives inside the same wrapping row as the field it targets.
            const field = anchor.querySelector('[data-hb-title]')
                || anchor.querySelector('.hb-ce[data-hb-rt]')
                || anchor.querySelector('[data-hb-control-kind]');
            return { kind: kind, field: field };
        }

        function insertToken(trigger, key) {
            const token = '@{{ ' + key + ' }}';
            const target = lastTrigger === trigger && lastInsertionTarget
                ? lastInsertionTarget
                : insertionTargetForTrigger(trigger);
            if (! target.field) {
                // Fallback: try the globally-focused element.
                insertIntoActive(token);
                return;
            }
            if (target.kind === TARGET_SUBJECT) {
                insertIntoSubject(target.field, token);
            } else if (target.kind === TARGET_URL) {
                insertIntoTextField(target.field, token);
            } else {
                // .hb-ce[data-hb-rt] (contenteditable rich text) — setRangeText or Range text node.
                insertIntoRichText(target.field, token, lastRange);
            }
        }

        function insertIntoActive(token) {
            // Last-resort insertion: paste into whatever's focused.
            const ae = document.activeElement;
            if (! ae) return;
            if (ae.tagName === 'INPUT' || ae.tagName === 'TEXTAREA') {
                insertIntoTextField(ae, token);
            } else if (ae.isContentEditable) {
                insertIntoRichText(ae, token);
            }
        }

        function insertIntoTextField(field, token) {
            // setRangeText preserves selection/caret semantics on <input> elements — works on
            // textarea too. Falls back to .value manipulation if setRangeText is unavailable.
            try {
                const start = field.selectionStart != null ? field.selectionStart : field.value.length;
                const end = field.selectionEnd != null ? field.selectionEnd : field.value.length;
                if (typeof field.setRangeText === 'function') {
                    field.setRangeText(token, start, end, 'end');
                } else {
                    field.value = field.value.substring(0, start) + token + field.value.substring(end);
                }
                field.dispatchEvent(new Event('input', { bubbles: true }));
            } catch (err) {
                field.value = (field.value || '') + token;
                field.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }

        function insertIntoSubject(field, token) {
            // Subject target: the field is `[data-hb-title]` (an <input> for the inspector mirror
            // or a contenteditable <h1> for the canvas). Both cases feed the existing hb:doc-title
            // event chain so the canvas/inspector mirrors stay in sync.
            if (field.tagName === 'INPUT') {
                insertIntoTextField(field, token);
            } else if (field.isContentEditable) {
                insertIntoRichText(field, token);
            }
        }

        function insertIntoRichText(field, token, savedRange = null) {
            // The block runtime's editable surface (.hb-ce[data-hb-rt]) listens to the `input`
            // event and persists via hbEditor.setAttribute(). Inserting text via setRangeText OR
            // a Range of text nodes preserves caret/selection semantics without innerHTML.
            field.focus();
            try {
                const sel = window.getSelection();
                const liveRange = sel && sel.rangeCount > 0 ? sel.getRangeAt(0) : null;
                const range = savedRange && field.contains(savedRange.commonAncestorContainer)
                    ? savedRange
                    : (liveRange && field.contains(liveRange.commonAncestorContainer) ? liveRange : null);
                if (range && sel) {
                    const textNode = document.createTextNode(token);
                    range.deleteContents();
                    range.insertNode(textNode);
                    range.setStartAfter(textNode);
                    range.collapse(true);
                    sel.removeAllRanges();
                    sel.addRange(range);
                    lastRange = range.cloneRange();
                } else {
                    field.appendChild(document.createTextNode(token));
                }
                const InputCtor = typeof InputEvent === 'function' ? InputEvent : Event;
                field.dispatchEvent(new InputCtor('input', { bubbles: true }));
            } catch (err) {
                field.appendChild(document.createTextNode(token));
                field.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }

        function onOutside(e) {
            const picker = getPicker();
            if (! picker || picker.hidden) return;
            if (picker.contains(e.target)) return;
            if (e.target.closest('[data-hb-email-variable-trigger]')) return;
            closePicker();
        }

        function onKey(e) {
            const picker = getPicker();
            if (! picker || picker.hidden) return;
            if (e.key === 'Escape') {
                e.preventDefault();
                closePicker();
            } else if (e.key === 'Enter') {
                const entry = picker.querySelector(ENTRY_SELECTOR + '[aria-selected="true"]:not([hidden])')
                    || picker.querySelector(ENTRY_SELECTOR + ':not([hidden])');
                if (entry) {
                    e.preventDefault();
                    const key = entry.getAttribute('data-hb-email-variable-key') || '';
                    const trigger = document.querySelector('[data-hb-email-variable-trigger][data-hb-last-opened]');
                    if (trigger && key !== '') insertToken(trigger, key);
                    closePicker();
                }
            } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                moveSelection(picker, e.key === 'ArrowDown' ? 1 : -1);
            }
        }

        function moveSelection(picker, delta) {
            const visible = getEntries(picker).filter((e) => ! e.hidden);
            if (visible.length === 0) return;
            let idx = visible.findIndex((e) => e.getAttribute('aria-selected') === 'true');
            if (idx === -1) idx = delta > 0 ? -1 : 0;
            idx = (idx + delta + visible.length) % visible.length;
            visible.forEach((e, i) => e.setAttribute('aria-selected', i === idx ? 'true' : 'false'));
            visible[idx].focus();
        }

        // Trigger buttons: each one is a small `[data-hb-email-variable-trigger]` button that
        // opens the picker positioned next to its host field. The picker JS only ever opens on
        // a real click of a trigger — typing into a field NEVER opens it.
        function wireTriggerButtons() {
            document.querySelectorAll('[data-hb-email-variable-trigger]').forEach((btn) => {
                if (btn.__hbEvinj) return; btn.__hbEvinj = true;
                btn.addEventListener('mousedown', (e) => e.preventDefault()); // keep focus/caret
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    const picker = getPicker();
                    if (! picker) return;
                    if (pickerVisible(picker)) {
                        closePicker();
                        return;
                    }
                    document.querySelectorAll('[data-hb-email-variable-trigger]').forEach((b) => b.removeAttribute('data-hb-last-opened'));
                    btn.setAttribute('data-hb-last-opened', 'true');
                    openPicker(btn);
                });
            });
        }

        function makeTrigger(kind, field) {
            const picker = getPicker();
            if (! picker) return null;
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'hb-evinj__trigger';
            button.setAttribute('data-hb-email-variable-trigger', kind);
            button.setAttribute('aria-label', picker.getAttribute('data-hb-email-variable-trigger-aria') || 'Insert email variable here');
            button.textContent = picker.getAttribute('data-hb-email-variable-trigger-label') || 'Insert variable';
            button.__hbEmailVariableField = field || null;
            return button;
        }

        function syncToolbarTrigger(event = null) {
            const button = document.querySelector('[data-hb-email-variable-toolbar-trigger]');
            if (! button) return;
            const id = event && event.detail && event.detail.id
                ? event.detail.id
                : (window.hbEditor && window.hbEditor.getSelectedId ? window.hbEditor.getSelectedId() : null);
            const block = id ? document.querySelector('.hb-blk[data-block="' + id + '"]') : null;
            const field = block ? block.querySelector('.hb-ce[data-hb-rt]') : null;
            button.__hbEmailVariableField = field;
            button.hidden = ! field;
        }

        function wireDynamicTriggers() {
            document.querySelectorAll('[data-hb-title]').forEach((field) => {
                const parent = field.parentElement;
                if (! parent || parent.querySelector(':scope > [data-hb-email-variable-trigger="subject"]')) return;
                const button = makeTrigger(TARGET_SUBJECT, field);
                if (button) field.insertAdjacentElement('afterend', button);
            });

            document.querySelectorAll('[data-hb-control-kind="attributes"]').forEach((field) => {
                const type = field.getAttribute('data-hb-control-type') || '';
                const key = field.getAttribute('data-hb-control') || '';
                const kind = type === 'url' ? TARGET_URL : TARGET_TEXT;
                const eligible = type === 'url'
                    || ((type === 'text' || type === 'textarea' || type === 'rich-text')
                        && key !== 'anchor' && key !== 'extraClasses');
                if (! eligible || field.tagName === 'SELECT' || field.disabled) return;
                const host = field.closest('.hb-icol') || field.parentElement;
                if (! host || host.querySelector('[data-hb-email-variable-trigger="' + kind + '"]')) return;
                const button = makeTrigger(kind, field);
                if (button) host.appendChild(button);
            });

            const toolbar = document.querySelector('[data-hb-block-toolbar]');
            if (toolbar && ! toolbar.querySelector('[data-hb-email-variable-toolbar-trigger]')) {
                const button = makeTrigger(TARGET_TEXT, null);
                if (button) {
                    button.setAttribute('data-hb-email-variable-toolbar-trigger', 'true');
                    toolbar.appendChild(button);
                }
            }
            syncToolbarTrigger();
        }

        function wireEntryClicks() {
            const picker = getPicker();
            if (! picker) return;
            getEntries(picker).forEach((entry) => {
                if (entry.__hbEvinj) return; entry.__hbEvinj = true;
                entry.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    const key = entry.getAttribute('data-hb-email-variable-key') || '';
                    const trigger = document.querySelector('[data-hb-email-variable-trigger][data-hb-last-opened]');
                    if (trigger && key !== '') insertToken(trigger, key);
                    closePicker();
                });
            });
        }

        function wireSearch() {
            const picker = getPicker();
            if (! picker) return;
            const search = picker.querySelector('[data-hb-email-variable-search]');
            if (! search || search.__hbEvinj) return;
            search.__hbEvinj = true;
            search.addEventListener('input', () => filterEntries(picker, search.value));
        }

        const boot = () => {
            const picker = getPicker();
            if (! picker) return;
            wireDynamicTriggers();
            wireTriggerButtons();
            wireEntryClicks();
            wireSearch();
            // Locale re-renders: every hb:refresh (canvas-blade's boot also fires it) rewires.
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
        document.addEventListener('hb:block-selected', (event) => {
            wireDynamicTriggers();
            syncToolbarTrigger(event);
            wireTriggerButtons();
        });

        // Locale switch: rebind so any localized label updates flow through. The picker itself
        // re-bootstraps on hb:refresh above; nothing else here.
        document.addEventListener('hb:editing-locale-change', () => {
            setTimeout(boot, 0);
        });
    })();
</script>
@endonce
