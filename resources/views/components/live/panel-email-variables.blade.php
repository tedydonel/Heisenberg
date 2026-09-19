@props(['entries' => []])
<div data-hb-email-variables-panel data-hb-panel-email-variables {{ $attributes->merge(['class' => 'hb-panel-email-variables']) }}>
    <div class="hb-panel-email-variables__header">
        <span class="hb-panel-email-variables__heading">{{ __('heisenberg::editor.inspector.email_variables_heading') }}</span>
    </div>
    <div class="hb-panel-email-variables__body" data-hb-panel-email-variables-body>
        <div class="hb-panel-email-variables__list">
            @forelse ($entries as $entry)
                @php($token = '{' . '{ ' . $entry['key'] . ' }' . '}')
                <button type="button" class="hb-panel-email-variables__item" data-hb-email-variable
                    data-hb-email-variable-key="{{ $entry['key'] }}" title="{{ $entry['description'] }}">
                    <span class="hb-panel-email-variables__label">{{ $entry['label'] }}</span>
                    <code>{{ $token }}</code>
                </button>
            @empty
                <p class="hb-panel-email-variables__empty">The host has not supplied any email variables.</p>
            @endforelse
        </div>
    </div>
    <x-heisenberg::ui.custom-scrollbar container="[data-hb-panel-email-variables-body]" />

    <div class="hb-email-variable-autocomplete" data-hb-email-variable-autocomplete hidden role="listbox">
        <div class="hb-email-variable-autocomplete__scroll" data-hb-email-var-popup-scroll>
            <div class="hb-email-variable-autocomplete__list" data-hb-email-var-popup-list></div>
        </div>
        <x-heisenberg::ui.custom-scrollbar container="[data-hb-email-var-popup-scroll]" />
    </div>
</div>
<script nonce="{{ heisenberg_csp_nonce() }}">
(() => {
    const open = '{' + '{';
    const close = '}' + '}';
    let popup;
    let activeTarget;
    let activeRange;
    let activeTokenLength = 0;

    const variables = () => Array.from(document.querySelectorAll('[data-hb-email-variable-key]'))
        .map((button) => ({
            key: button.getAttribute('data-hb-email-variable-key') || '',
            label: button.querySelector('span')?.textContent || button.getAttribute('data-hb-email-variable-key') || '',
        }))
        .filter((entry, index, list) => entry.key && list.findIndex((item) => item.key === entry.key) === index);

    const hide = () => {
        if (popup) popup.hidden = true;
        activeTarget = null;
        activeRange = null;
        activeTokenLength = 0;
    };

    const insert = (key) => {
        if (!activeTarget || !activeRange) return;
        activeTarget.focus();
        const range = activeRange.cloneRange();
        const textToInsert = open + ' ' + key + ' ' + close + ' ';
        try {
            if (range.startContainer.nodeType === Node.TEXT_NODE && range.startOffset >= activeTokenLength) {
                range.setStart(range.startContainer, range.startOffset - activeTokenLength);
                range.deleteContents();
                const node = document.createTextNode(textToInsert);
                range.insertNode(node);
                range.setStart(node, node.length);
                range.collapse(true);
                const selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(range);
            } else {
                document.execCommand('insertText', false, textToInsert);
            }
        } catch (error) {
            document.execCommand('insertText', false, textToInsert);
        }
        activeTarget.dispatchEvent(new InputEvent('input', { bubbles: true }));
        hide();
    };

    const ensurePopup = () => {
        let el = document.querySelector('[data-hb-email-variable-autocomplete]');
        if (el) {
            popup = el;
            return el;
        }
        const panel = document.querySelector('[data-hb-panel-email-variables]');
        if (panel) {
            el = panel.querySelector('[data-hb-email-variable-autocomplete]');
            if (el) {
                popup = el;
                return el;
            }
        }
        el = document.createElement('div');
        el.className = 'hb-email-variable-autocomplete';
        el.hidden = true;
        el.setAttribute('role', 'listbox');
        el.dataset.hbEmailVariableAutocomplete = '';
        el.innerHTML = '<div class="hb-email-variable-autocomplete__scroll" data-hb-email-var-popup-scroll><div class="hb-email-variable-autocomplete__list" data-hb-email-var-popup-list></div></div>';
        document.body.appendChild(el);
        popup = el;
        return el;
    };

    const show = (target, range, query, tokenLength) => {
        const matches = variables().filter((entry) => entry.key.toLowerCase().startsWith(query.toLowerCase())).slice(0, 12);
        const menu = ensurePopup();
        const list = menu.querySelector('[data-hb-email-var-popup-list]') || menu;
        list.replaceChildren();
        if (!matches.length) {
            hide();
            return;
        }
        activeTarget = target;
        activeRange = range.cloneRange();
        activeTokenLength = tokenLength;
        matches.forEach((entry) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'hb-email-variable-autocomplete__item';
            button.setAttribute('role', 'option');
            button.innerHTML = '<strong>' + entry.label.replace(/[&<>]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[char])) + '</strong><code>' + open + ' ' + entry.key + ' ' + close + '</code>';
            button.addEventListener('mousedown', (event) => event.preventDefault());
            button.addEventListener('click', () => insert(entry.key));
            list.appendChild(button);
        });
        const rect = range.getBoundingClientRect();
        menu.style.left = Math.max(8, rect.left) + 'px';
        menu.style.top = (rect.bottom + 6) + 'px';
        menu.hidden = false;
        document.dispatchEvent(new CustomEvent('hb:refresh'));
    };

    const update = (event) => {
        const target = event.target;
        if (!target.matches?.('.hb-ce[data-hb-rt]') || !target.isContentEditable) return hide();
        const selection = window.getSelection();
        if (!selection || !selection.rangeCount || !selection.isCollapsed) return hide();
        const range = selection.getRangeAt(0);
        if (!target.contains(range.startContainer)) return hide();
        const before = range.cloneRange();
        before.selectNodeContents(target);
        before.setEnd(range.startContainer, range.startOffset);
        const text = before.toString();
        const match = text.match(/\{\{\s*([a-z0-9_.]*)$/i);
        if (!match) return hide();
        show(target, range, match[1], match[0].length);
    };

    const boot = () => {
        document.querySelectorAll('[data-hb-email-variable]').forEach((button) => {
            if (button.__hbEmailVariable) return;
            button.__hbEmailVariable = true;
            button.addEventListener('click', () => {
                const key = button.getAttribute('data-hb-email-variable-key');
                const active = document.activeElement;
                const target = active && active.matches('.hb-ce[data-hb-rt]') ? active : document.querySelector('.hb-ce[data-hb-rt]');
                if (!key || !target) return;
                target.focus();
                document.execCommand('insertText', false, open + ' ' + key + ' ' + close + ' ');
                target.dispatchEvent(new InputEvent('input', { bubbles: true }));
                hide();
            });
        });
        document.addEventListener('input', update, true);
        document.addEventListener('keyup', update, true);
        document.addEventListener('click', (event) => {
            if (!event.target.closest('[data-hb-email-variable-autocomplete]')) hide();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') hide();
        });
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true }); else boot();
    document.addEventListener('hb:refresh', boot);
})();
</script>
<style nonce="{{ heisenberg_csp_nonce() }}">
.hb-panel-email-variables {
    display: flex;
    flex-direction: column;
    width: 240px;
    height: 100%;
    background: var(--hb-bg);
    border-right: 1px solid var(--hb-border);
    flex: none;
    position: relative;
    overflow: hidden;
}
.hb-panel-email-variables__header {
    padding: 16px 14px 10px;
    flex: none;
}
.hb-panel-email-variables__heading {
    font-family: var(--hb-font-sans, Rubik, sans-serif);
    font-weight: 600;
    font-size: var(--hb-fs-sm, 12px);
    color: var(--hb-text-primary);
    text-transform: uppercase;
    letter-spacing: .5px;
}
.hb-panel-email-variables__body {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    position: relative;
    padding: 0 12px 16px;
}
.hb-panel-email-variables__list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.hb-panel-email-variables__item {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 3px;
    border: 1px solid var(--hb-border);
    border-radius: var(--hb-radius-md, 6px);
    background: var(--hb-bg-subtle, var(--hb-bg));
    padding: 8px 10px;
    text-align: left;
    cursor: pointer;
    font-family: var(--hb-font-sans, Rubik, sans-serif);
    transition: border-color .12s ease, background-color .12s ease;
}
.hb-panel-email-variables__item:hover {
    border-color: var(--hb-accent, #3D68F5);
    background: var(--hb-surface-hover);
}
.hb-panel-email-variables__label {
    font-size: var(--hb-fs-sm, 12px);
    font-weight: 500;
    color: var(--hb-text-primary);
}
.hb-panel-email-variables__item code {
    color: var(--hb-text-muted);
    font-family: var(--hb-font-mono, monospace);
    font-size: 11px;
}
.hb-panel-email-variables__empty {
    color: var(--hb-text-muted);
    font-size: var(--hb-fs-sm, 12px);
    line-height: 1.4;
    padding: 12px 4px;
}
.hb-email-variable-token {
    display: inline-flex;
    align-items: center;
    vertical-align: baseline;
    user-select: none;
    -webkit-user-select: none;
    cursor: default;
    padding: 1px 6px;
    margin: 0 2px;
    border: 1px solid var(--hb-border-strong, #3D68F5);
    border-radius: var(--hb-radius-sm, 4px);
    background: var(--hb-bg-muted, color-mix(in srgb, var(--hb-accent, #3D68F5) 15%, transparent));
    color: var(--hb-text-primary);
    font-family: var(--hb-font-sans, Rubik, sans-serif);
    font-size: var(--hb-fs-sm, 12px);
    font-weight: 500;
    line-height: 1.4;
}
.hb-email-variable-autocomplete {
    position: fixed;
    z-index: 9999;
    width: 240px;
    max-height: 220px;
    border: 1px solid var(--hb-border);
    border-radius: var(--hb-radius-md, 8px);
    background: var(--hb-bg, #fff);
    box-shadow: var(--hb-shadow-lg, 0 8px 24px rgba(0,0,0,.18));
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.hb-email-variable-autocomplete__scroll {
    flex: 1 1 auto;
    min-height: 0;
    max-height: 220px;
    overflow-y: auto;
    padding: 4px;
    position: relative;
}
.hb-email-variable-autocomplete__list {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.hb-email-variable-autocomplete__item {
    display: flex;
    width: 100%;
    flex-direction: column;
    align-items: flex-start;
    gap: 2px;
    border: 0;
    border-radius: var(--hb-radius-sm, 5px);
    background: transparent;
    padding: 7px 8px;
    text-align: left;
    cursor: pointer;
    font-family: var(--hb-font-sans, Rubik, sans-serif);
}
.hb-email-variable-autocomplete__item:hover,
.hb-email-variable-autocomplete__item:focus {
    background: color-mix(in srgb, var(--hb-accent, #3D68F5) 12%, transparent);
    outline: none;
}
.hb-email-variable-autocomplete__item strong {
    color: var(--hb-text-primary);
    font-size: 12px;
    font-weight: 500;
}
.hb-email-variable-autocomplete__item code {
    color: var(--hb-text-muted);
    font-family: var(--hb-font-mono, monospace);
    font-size: 11px;
}
</style>
