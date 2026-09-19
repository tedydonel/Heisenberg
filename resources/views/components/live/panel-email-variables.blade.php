@props(['entries' => []])
<div data-hb-email-variables-panel data-hb-panel-email-variables {{ $attributes->merge(['class' => 'hb-panel-email-variables']) }}>
    <div class="hb-panel-email-variables__heading">Email variables</div>
    @forelse ($entries as $entry)
        @php($token = '{' . '{ ' . $entry['key'] . ' }' . '}')
        <button type="button" class="hb-panel-email-variables__item" data-hb-email-variable
            data-hb-email-variable-key="{{ $entry['key'] }}" title="{{ $entry['description'] }}">
            <span>{{ $entry['label'] }}</span>
            <code>{{ $token }}</code>
        </button>
    @empty
        <p class="hb-panel-email-variables__empty">The host has not supplied any email variables.</p>
    @endforelse
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
        try {
            if (range.startContainer.nodeType === Node.TEXT_NODE && range.startOffset >= activeTokenLength) {
                range.setStart(range.startContainer, range.startOffset - activeTokenLength);
                range.deleteContents();
                range.insertNode(document.createTextNode(open + ' ' + key + ' ' + close));
                range.collapse(false);
                const selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(range);
            } else {
                document.execCommand('insertText', false, open + ' ' + key + ' ' + close);
            }
        } catch (error) {
            document.execCommand('insertText', false, open + ' ' + key + ' ' + close);
        }
        activeTarget.dispatchEvent(new InputEvent('input', { bubbles: true }));
        hide();
    };

    const ensurePopup = () => {
        if (popup) return popup;
        popup = document.createElement('div');
        popup.className = 'hb-email-variable-autocomplete';
        popup.hidden = true;
        popup.setAttribute('role', 'listbox');
        popup.dataset.hbEmailVariableAutocomplete = '';
        document.body.appendChild(popup);
        return popup;
    };

    const show = (target, range, query, tokenLength) => {
        const matches = variables().filter((entry) => entry.key.toLowerCase().startsWith(query.toLowerCase())).slice(0, 8);
        const menu = ensurePopup();
        menu.replaceChildren();
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
            menu.appendChild(button);
        });
        const rect = range.getBoundingClientRect();
        menu.style.left = Math.max(8, rect.left) + 'px';
        menu.style.top = (rect.bottom + 6) + 'px';
        menu.hidden = false;
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
                document.execCommand('insertText', false, open + ' ' + key + ' ' + close);
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
})();
</script>
<style nonce="{{ heisenberg_csp_nonce() }}">
.hb-panel-email-variables { padding: 12px; display:flex; flex-direction:column; gap:8px; overflow:auto; }
.hb-email-variable-token { display:inline-block; padding:1px 5px; margin:0 1px; border:1px solid #1d4ed8; border-radius:4px; background:#1e3a8a; color:#fff; font:600 0.9em/1.3 var(--hb-font-mono, ui-monospace, monospace); }
.hb-panel-email-variables__heading { font-weight:600; font-size:12px; color:var(--hb-text-primary); }
.hb-panel-email-variables__item { display:flex; flex-direction:column; align-items:flex-start; gap:3px; border:1px solid var(--hb-border); border-radius:6px; background:var(--hb-bg); padding:8px; text-align:left; cursor:pointer; }
.hb-panel-email-variables__item:hover { border-color:var(--hb-accent, #3D68F5); }
.hb-panel-email-variables__item code { color:var(--hb-text-muted); font-size:11px; }
.hb-panel-email-variables__empty { color:var(--hb-text-muted); font-size:12px; line-height:1.4; }
.hb-email-variable-autocomplete { position:fixed; z-index:9999; min-width:210px; max-width:280px; max-height:240px; overflow:auto; padding:4px; border:1px solid var(--hb-border); border-radius:8px; background:var(--hb-bg, #fff); box-shadow:0 8px 24px rgba(0,0,0,.18); }
.hb-email-variable-autocomplete__item { display:flex; width:100%; flex-direction:column; align-items:flex-start; gap:2px; border:0; border-radius:5px; background:transparent; padding:7px 8px; text-align:left; cursor:pointer; }
.hb-email-variable-autocomplete__item:hover, .hb-email-variable-autocomplete__item:focus { background:color-mix(in srgb, var(--hb-accent, #3D68F5) 12%, transparent); outline:none; }
.hb-email-variable-autocomplete__item strong { color:var(--hb-text-primary); font-size:12px; }
.hb-email-variable-autocomplete__item code { color:var(--hb-text-muted); font-size:11px; }
</style>
