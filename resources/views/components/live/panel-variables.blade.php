@once
<style nonce="{{ heisenberg_csp_nonce() }}">
    .hb-panel-variables { display: flex; flex-direction: column; width: 240px; height: 100%; background: var(--hb-bg); border-right: 1px solid var(--hb-border); flex: none; }
    .hb-panel-variables__content { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; overflow: hidden; }
    .hb-panel-variables__content[hidden] { display: none; }
    .hb-panel-variables__body { flex: 1 1 auto; min-height: 0; overflow: hidden; position: relative; display: flex; flex-direction: column; }
    .hb-panel-variables__scroll { flex: 1 1 auto; min-height: 0; overflow: hidden; padding: var(--hb-space-3, 12px); }
    .hb-panel-variables__list { display: flex; flex-direction: column; gap: 6px; }
    .hb-panel-variables__item {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 8px;
        padding: 8px 10px;
        background: var(--hb-bg);
        border: 1px solid var(--hb-border);
        border-radius: var(--hb-radius-md, 6px);
        cursor: grab;
        text-align: left;
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        color: var(--hb-text-primary);
    }
    .hb-panel-variables__item:hover { border-color: var(--hb-accent, #3D68F5); background: var(--hb-accent-soft, rgba(61, 104, 245, 0.06)); }
    .hb-panel-variables__item:active { cursor: grabbing; }
    .hb-panel-variables__item.hb-filter-hidden { display: none !important; }
    .hb-panel-variables__main { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1 1 auto; }
    .hb-panel-variables__label { font-weight: 600; font-size: var(--hb-fs-sm, 12px); line-height: 1.3; }
    .hb-panel-variables__key { font-family: var(--hb-font-mono, ui-monospace, SFMono-Regular, Menlo, monospace); font-size: 11px; color: var(--hb-text-muted); line-height: 1.2; word-break: break-all; }
    .hb-panel-variables__sample { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: 11px; color: var(--hb-text-secondary); line-height: 1.3; word-break: break-word; }
    .hb-panel-variables__chip {
        flex: none;
        align-self: flex-start;
        font-size: 10px;
        color: var(--hb-text-muted);
        text-transform: uppercase;
        letter-spacing: 0.04em;
        padding: 2px 6px;
        background: var(--hb-surface);
        border-radius: 999px;
        border: 1px solid var(--hb-border);
        white-space: nowrap;
    }
    .hb-panel-variables__empty { padding: 24px 12px; text-align: center; color: var(--hb-text-muted); font-size: var(--hb-fs-sm, 12px); line-height: 1.45; }

    .hb-ce[data-hb-rt].hb-var-drop-target,
    h1[data-hb-title][contenteditable="true"].hb-var-drop-target {
        outline: 2px dashed var(--hb-accent, #3D68F5);
        outline-offset: 2px;
        border-radius: 4px;
    }
</style>

<script nonce="{{ heisenberg_csp_nonce() }}">
(() => {
    const VAR_MIME = 'application/x-heisenberg-var';
    const ITEM_SELECTOR = '[data-hb-var-item]';
    const DROP_SELECTOR = '.hb-ce[data-hb-rt], h1[data-hb-title][contenteditable="true"]';

    function readPayload(item) {
        return {
            key: item.getAttribute('data-hb-var-key') || '',
            label: item.getAttribute('data-hb-var-label') || (item.getAttribute('data-hb-var-key') || ''),
            type: item.getAttribute('data-hb-var-type') || '',
            sample: item.getAttribute('data-hb-var-sample') || '',
            group: item.getAttribute('data-hb-var-group') || '',
        };
    }

    function payloadFromDt(dt) {
        if (!dt) return null;
        const raw = dt.getData(VAR_MIME) || dt.getData('text/plain');
        if (!raw) return null;
        try {
            const data = JSON.parse(raw);
            return data && data.key ? data : null;
        } catch (err) {
            return null;
        }
    }

    function buildChip(payload) {
        const chip = document.createElement('span');
        chip.className = 'hb-var-token';
        chip.setAttribute('contenteditable', 'false');
        chip.setAttribute('data-hb-var-key', payload.key);
        if (payload.type) chip.setAttribute('data-hb-var-type', payload.type);
        if (payload.sample) chip.setAttribute('data-hb-var-sample', payload.sample);
        if (payload.group) chip.setAttribute('data-hb-var-group', payload.group);
        const tip = [payload.key];
        if (payload.sample) tip.push(payload.sample);
        if (payload.group) tip.push(payload.group);
        chip.setAttribute('title', tip.join(' — '));
        chip.textContent = payload.label || payload.key;
        return chip;
    }

    function insertChipAt(ce, payload) {
        if (!ce || !payload || !payload.key) return;
        const chip = buildChip(payload);
        ce.focus();
        const sel = window.getSelection();
        let range = null;
        if (sel && sel.rangeCount > 0) {
            const candidate = sel.getRangeAt(0);
            if (ce.contains(candidate.commonAncestorContainer)) range = candidate;
        }
        if (!range) {
            range = document.createRange();
            range.selectNodeContents(ce);
            range.collapse(false);
        }
        range.deleteContents();
        range.insertNode(chip);
        const tail = document.createTextNode('');
        chip.parentNode.insertBefore(tail, chip.nextSibling);
        const after = document.createRange();
        after.setStart(tail, 1);
        after.collapse(true);
        sel.removeAllRanges();
        sel.addRange(after);
        const InputCtor = typeof InputEvent === 'function' ? InputEvent : Event;
        ce.dispatchEvent(new InputCtor('input', { bubbles: true }));
    }

    function wireItems(root) {
        root.querySelectorAll(ITEM_SELECTOR).forEach((item) => {
            if (item.__hbVarWired) return;
            item.__hbVarWired = true;
            item.addEventListener('dragstart', (event) => {
                const payload = readPayload(item);
                if (!payload.key) { event.preventDefault(); return; }
                const dt = event.dataTransfer;
                if (!dt) return;
                dt.setData(VAR_MIME, JSON.stringify(payload));
                dt.setData('text/plain', JSON.stringify(payload));
                dt.effectAllowed = 'copy';
                try {
                    const ghost = document.createElement('div');
                    ghost.textContent = payload.label || payload.key;
                    ghost.style.cssText = 'position:fixed;top:-1000px;left:-1000px;padding:2px 8px;background:rgba(61,104,245,.10);color:#3D68F5;border:1px solid #3D68F5;border-radius:999px;font:600 12px/1.4 Rubik,sans-serif;white-space:nowrap';
                    document.body.appendChild(ghost);
                    dt.setDragImage(ghost, 8, 8);
                    setTimeout(() => ghost.remove(), 0);
                } catch (err) {
                    // Some browsers reject setDragImage when not user-initiated enough — fine.
                }
            });
        });
    }

    function clearTargets() {
        document.querySelectorAll('.hb-var-drop-target').forEach((el) => el.classList.remove('hb-var-drop-target'));
    }

    function wireDropTargets() {
        document.addEventListener('dragover', (event) => {
            const target = event.target && event.target.closest ? event.target.closest(DROP_SELECTOR) : null;
            if (!target) return;
            const dt = event.dataTransfer;
            if (!dt || !Array.from(dt.types || []).some((t) => t === VAR_MIME || t === 'text/plain')) return;
            event.preventDefault();
            if (dt.dropEffect !== 'copy') dt.dropEffect = 'copy';
            clearTargets();
            target.classList.add('hb-var-drop-target');
        });
        document.addEventListener('dragleave', (event) => {
            if (event.relatedTarget === null) clearTargets();
        });
        document.addEventListener('drop', (event) => {
            const target = event.target && event.target.closest ? event.target.closest(DROP_SELECTOR) : null;
            if (!target) return;
            const payload = payloadFromDt(event.dataTransfer);
            if (!payload) return;
            event.preventDefault();
            event.stopPropagation();
            clearTargets();
            insertChipAt(target, payload);
        });
    }

    const boot = () => {
        document.querySelectorAll('[data-hb-panel-variables]').forEach((root) => {
            if (root.__hbPanelVar) return;
            root.__hbPanelVar = true;
            wireItems(root);
            wireDropTargets();
        });
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
    else boot();
    document.addEventListener('hb:refresh', boot);
})();
</script>
@endonce

@props(['entries' => [], 'allTargets' => []])

@php
    // Group entries by their registry `group` so the panel surfaces a real taxonomy
    // (Subscriber / Campaign / …) instead of one flat list. An empty-string group is
    // ungrouped and renders after every named group.
    $grouped = [];
    foreach ($entries as $entry) {
        $group = (string) ($entry['group'] ?? '');
        $grouped[$group] = $grouped[$group] ?? [];
        $grouped[$group][] = $entry;
    }
    // Named groups first, ungrouped last.
    uksort($grouped, static fn (string $a, string $b): int => [$a === '' ? 1 : 0, $a] <=> [$b === '' ? 1 : 0, $b]);
@endphp

<div data-hb-panel-variables {{ $attributes->merge(['class' => 'hb-panel-variables']) }}>
    <x-heisenberg::ui.panel-tabs :items="[['label' => __('heisenberg::editor.panel_variables.tab_variables')]]" :active-index="0" />

    <div class="hb-panel-variables__content" data-hb-panel-variables-variables>
        <x-heisenberg::ui.search-field :placeholder="__('heisenberg::editor.panel_variables.search')"
            data-hb-filter="[data-hb-panel-variables-variables]" data-hb-filter-item="[data-hb-var-item]" />
        <div class="hb-panel-variables__body" data-hb-category-body>
            <div class="hb-panel-variables__scroll" data-hb-panel-variables-scroll>
                <div class="hb-panel-variables__list">
                    @forelse ($grouped as $groupName => $groupEntries)
                        @foreach ($groupEntries as $entry)
                            @php
                                $entryKey = (string) ($entry['key'] ?? '');
                                if ($entryKey === '') { continue; }
                                $entryLabel = (string) ($entry['label'] ?? $entryKey);
                                $entryType = (string) ($entry['type'] ?? '');
                                $entrySample = (string) ($entry['sample'] ?? '');
                                $entryDescription = (string) ($entry['description'] ?? '');
                                $entryTargets = array_values(array_filter(
                                    array_map('strval', (array) ($entry['targets'] ?? [])),
                                    static fn (string $t): bool => $t !== ''
                                ));
                                $tooltip = $entryKey . ($entryDescription !== '' ? ' — ' . $entryDescription : '');
                            @endphp
                            <button type="button" class="hb-panel-variables__item" draggable="true"
                                data-hb-var-item
                                data-hb-var-key="{{ $entryKey }}"
                                data-hb-var-label="{{ $entryLabel }}"
                                data-hb-var-type="{{ $entryType }}"
                                data-hb-var-sample="{{ $entrySample }}"
                                data-hb-var-group="{{ $groupName }}"
                                data-hb-var-targets="{{ implode(',', $entryTargets) }}"
                                title="{{ $tooltip }}">
                                <span class="hb-panel-variables__main">
                                    <span class="hb-panel-variables__label">{{ $entryLabel }}</span>
                                    <span class="hb-panel-variables__key">{{ $entryKey }}</span>
                                    @if ($entrySample !== '')
                                        <span class="hb-panel-variables__sample">{{ $entrySample }}</span>
                                    @endif
                                </span>
                                @if ($groupName !== '')
                                    <span class="hb-panel-variables__chip">{{ $groupName }}</span>
                                @endif
                            </button>
                        @endforeach
                    @empty
                        <div class="hb-panel-variables__empty">{{ __('heisenberg::editor.panel_variables.empty') }}</div>
                    @endforelse
                </div>
            </div>
            <x-heisenberg::ui.custom-scrollbar container="[data-hb-panel-variables-scroll]" />
        </div>
    </div>
</div>