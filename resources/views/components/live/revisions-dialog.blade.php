@once
<style>
    .hb-revdialog { width: 560px; height: 520px; }
    .hb-revdialog__body { position: relative; flex: 1 1 auto; min-height: 0; }
    .hb-revdialog__scroll { height: 100%; box-sizing: border-box; padding: var(--hb-space-4, 16px); }
    .hb-revdialog__empty { padding: 48px 0; text-align: center; color: var(--hb-text-muted); font-size: var(--hb-fs-base, 13px); }
    .hb-revdialog__empty[hidden] { display: none; }
    .hb-revdialog__list { display: flex; flex-direction: column; gap: 6px; }
    .hb-revdialog__row {
        display: flex; align-items: center; gap: var(--hb-space-3, 12px);
        padding: 10px 12px;
        border: 1px solid var(--hb-border);
        border-radius: var(--hb-radius-md, 5px);
        font-family: var(--hb-font-sans, Rubik, sans-serif);
    }
    .hb-revdialog__row:hover { background: var(--hb-surface-hover); }
    .hb-revdialog__meta { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
    .hb-revdialog__when { font-size: var(--hb-fs-sm, 12px); font-weight: 600; color: var(--hb-text-primary); }
    .hb-revdialog__sub { font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .hb-revdialog__type {
        flex: none; padding: 2px 8px; border-radius: 999px;
        font-size: 11px; font-weight: 600;
        background: var(--hb-bg-muted); color: var(--hb-text-secondary);
    }
    .hb-revdialog__restore {
        flex: none; border: 0; cursor: pointer;
        padding: 6px 12px; border-radius: var(--hb-radius-control, 6px);
        background: var(--hb-accent); color: var(--hb-accent-contrast, #fff);
        font-family: inherit; font-size: var(--hb-fs-sm, 12px); font-weight: 600;
    }
    .hb-revdialog__restore:hover { opacity: .85; }
    .hb-revdialog__restore:disabled { opacity: .4; cursor: default; }
</style>
<script>
    (() => {
        const boot = () => {
            document.querySelectorAll('[data-hb-revisions]').forEach((scrim) => {
                if (scrim.__hbRevisions) return;
                scrim.__hbRevisions = true;

                const list = scrim.querySelector('[data-hb-rev-list]');
                const empty = scrim.querySelector('[data-hb-rev-empty]');
                const msg = (key) => scrim.dataset[key] || '';
                let urls = null;

                const showEmpty = (text) => {
                    list.innerHTML = '';
                    empty.textContent = text;
                    empty.hidden = false;
                };

                const typeLabel = (type) => (
                    type === 'auto_save' ? msg('msgTypeAuto') : type === 'restore' ? msg('msgTypeRestore') : msg('msgTypeManual')
                );

                const render = (revisions) => {
                    if (!revisions.length) { showEmpty(msg('msgEmpty')); return; }
                    empty.hidden = true;
                    list.innerHTML = '';
                    revisions.forEach((rev) => {
                        const row = document.createElement('div');
                        row.className = 'hb-revdialog__row';
                        const meta = document.createElement('div');
                        meta.className = 'hb-revdialog__meta';
                        const when = document.createElement('span');
                        when.className = 'hb-revdialog__when';
                        when.textContent = rev.created_at ? new Date(rev.created_at).toLocaleString() : '—';
                        const sub = document.createElement('span');
                        sub.className = 'hb-revdialog__sub';
                        sub.textContent = (rev.title ? rev.title + ' · ' : '') + msg('msgBlocks').replace(':count', String(rev.blocks_count));
                        meta.append(when, sub);
                        const type = document.createElement('span');
                        type.className = 'hb-revdialog__type';
                        type.textContent = typeLabel(rev.revision_type);
                        const restore = document.createElement('button');
                        restore.type = 'button';
                        restore.className = 'hb-revdialog__restore';
                        restore.textContent = msg('msgRestore');
                        restore.addEventListener('click', () => {
                            restore.disabled = true;
                            window.fetch(urls.index + '/' + rev.id, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                                .then((r) => { if (!r.ok) throw new Error('http-' + r.status); return r.json(); })
                                .then((data) => {
                                    window.hbEditor.replaceDoc(Array.isArray(data.blocks) ? data.blocks : []);
                                    scrim.hbClose ? scrim.hbClose() : (scrim.hidden = true);
                                })
                                .catch(() => { restore.disabled = false; showEmpty(msg('msgError')); });
                        });
                        row.append(meta, type, restore);
                        list.appendChild(row);
                    });
                    document.dispatchEvent(new CustomEvent('hb:refresh'));
                };

                const load = () => {
                    if (!urls) { showEmpty(msg('msgNeedsSave')); return; }
                    showEmpty(msg('msgLoading'));
                    window.fetch(urls.index, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                        .then((r) => { if (!r.ok) throw new Error('http-' + r.status); return r.json(); })
                        .then((data) => render(Array.isArray(data.revisions) ? data.revisions : []))
                        .catch(() => showEmpty(msg('msgError')));
                };

                if (!document.__hbRevisionsOpen) {
                    document.__hbRevisionsOpen = true;
                    document.addEventListener('click', (event) => {
                        const opener = event.target.closest('[data-hb-revisions-open]');
                        if (!opener) return;
                        const postId = opener.dataset.hbPostId || '';
                        const template = opener.dataset.hbRevisionsUrlTemplate || '';
                        urls = postId && template ? { index: template.replace('__ID__', postId) } : null;
                        if (scrim.hbOpen) scrim.hbOpen(opener); else scrim.hidden = false;
                        load();
                    });
                    document.addEventListener('hb:post-id', (event) => {
                        const id = event.detail && event.detail.id != null ? String(event.detail.id) : '';
                        if (!id) return;
                        document.querySelectorAll('[data-hb-revisions-open]').forEach((row) => { row.dataset.hbPostId = id; });
                    });
                }

                scrim.querySelector('.hb-mediadialog__close')?.addEventListener('click', () => {
                    if (scrim.hbClose) scrim.hbClose(); else scrim.hidden = true;
                });
            });
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce

<div class="hb-mediadialog__scrim" data-hb-revisions hidden
    data-msg-empty="{{ __('heisenberg::editor.revisions.empty') }}"
    data-msg-loading="{{ __('heisenberg::editor.revisions.loading') }}"
    data-msg-error="{{ __('heisenberg::editor.revisions.error') }}"
    data-msg-needs-save="{{ __('heisenberg::editor.revisions.needs_save') }}"
    data-msg-restore="{{ __('heisenberg::editor.revisions.restore') }}"
    data-msg-blocks="{{ __('heisenberg::editor.revisions.blocks_count') }}"
    data-msg-type-manual="{{ __('heisenberg::editor.revisions.type_manual') }}"
    data-msg-type-auto="{{ __('heisenberg::editor.revisions.type_auto') }}"
    data-msg-type-restore="{{ __('heisenberg::editor.revisions.type_restore') }}">
    <div class="hb-mediadialog hb-revdialog" role="dialog" aria-modal="true" aria-label="{{ __('heisenberg::editor.revisions.title') }}" tabindex="-1">
        <div class="hb-mediadialog__top">
            <span class="hb-mediadialog__title">{{ __('heisenberg::editor.revisions.title') }}</span>
            <button type="button" class="hb-mediadialog__close" aria-label="{{ __('heisenberg::editor.common.close') }}">
                @include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 16])
            </button>
        </div>
        <div class="hb-revdialog__body">
            <div class="hb-revdialog__scroll" data-hb-rev-scroll>
                <div class="hb-revdialog__empty" data-hb-rev-empty hidden></div>
                <div class="hb-revdialog__list" data-hb-rev-list></div>
            </div>
            <x-ui.custom-scrollbar container="[data-hb-rev-scroll]" />
        </div>
    </div>
</div>
