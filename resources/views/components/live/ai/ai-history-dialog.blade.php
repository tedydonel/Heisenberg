@once
<style nonce="{{ heisenberg_csp_nonce() }}">
    /* Wider than the media dialog: this is a data table, and the old 480px forced the title,
       message count and date to fight over one flex row. */
    .hb-aihistdialog { width: 680px; max-width: 94vw; height: 560px; }
    .hb-aihistdialog__body { position: relative; flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; }
    .hb-aihistdialog__scrollwrap { position: relative; flex: 1 1 auto; min-height: 0; }
    .hb-aihistdialog__scroll { height: 100%; box-sizing: border-box; padding: 0; }
    .hb-aihistdialog__empty { padding: 48px 0; text-align: center; color: var(--hb-text-muted); font-size: var(--hb-fs-base, 13px); font-family: var(--hb-font-sans, Rubik, sans-serif); }
    .hb-aihistdialog__empty[hidden] { display: none; }

    /* The table owns the full modal width; column widths are fixed so rows line up and a long
       title truncates instead of shoving the date column off the edge. */
    .hb-aihistdialog__table { width: 100%; border-collapse: collapse; table-layout: fixed; font-family: var(--hb-font-sans, Rubik, sans-serif); }
    .hb-aihistdialog__table[hidden] { display: none; }
    .hb-aihistdialog__table th, .hb-aihistdialog__table td { text-align: left; padding: 9px var(--hb-space-3, 12px); border-bottom: 1px solid var(--hb-border); vertical-align: middle; }
    .hb-aihistdialog__table thead th {
        position: sticky; top: 0; z-index: 1;
        background: var(--hb-bg-subtle);
        font-size: var(--hb-fs-xs, 11px); font-weight: 600; letter-spacing: .04em; text-transform: uppercase;
        color: var(--hb-text-muted);
    }
    .hb-aihistdialog__col-check { width: 38px; }
    .hb-aihistdialog__col-msgs { width: 96px; }
    .hb-aihistdialog__col-date { width: 168px; }
    .hb-aihistdialog__col-act { width: 84px; }
    .hb-aihistdialog__table tbody tr { cursor: pointer; }
    .hb-aihistdialog__table tbody tr:hover { background: var(--hb-surface-hover); }
    .hb-aihistdialog__table tbody tr.is-selected { background: var(--hb-bg-subtle); }
    .hb-aihistdialog__check { width: 14px; height: 14px; accent-color: var(--hb-accent); cursor: pointer; }
    .hb-aihistdialog__title { display: block; font-size: var(--hb-fs-sm, 12px); font-weight: 600; color: var(--hb-text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .hb-aihistdialog__cell-muted { font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .hb-aihistdialog__open {
        border: 0; cursor: pointer;
        padding: 5px 11px; border-radius: var(--hb-radius-control, 6px);
        background: var(--hb-accent); color: var(--hb-accent-fg);
        font-family: inherit; font-size: var(--hb-fs-sm, 12px); font-weight: 600;
    }
    .hb-aihistdialog__open:hover { opacity: .85; }

    .hb-aihistdialog__foot {
        flex: none; display: flex; align-items: center; gap: var(--hb-space-3, 12px);
        padding: 10px var(--hb-space-4, 16px);
        border-top: 1px solid var(--hb-border);
        font-family: var(--hb-font-sans, Rubik, sans-serif);
    }
    .hb-aihistdialog__foot[hidden] { display: none; }
    .hb-aihistdialog__count { font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-secondary); flex: 1 1 auto; }
    .hb-aihistdialog__delete, .hb-aihistdialog__cancel, .hb-aihistdialog__clear {
        border: 0; cursor: pointer;
        padding: 6px 12px; border-radius: var(--hb-radius-control, 6px);
        font-family: inherit; font-size: var(--hb-fs-sm, 12px); font-weight: 600;
    }
    .hb-aihistdialog__delete { background: var(--hb-danger-subtle); color: var(--hb-danger); }
    .hb-aihistdialog__delete.is-armed { background: var(--hb-danger); color: var(--hb-text-inverse); }
    .hb-aihistdialog__cancel, .hb-aihistdialog__clear { background: var(--hb-bg-muted); color: var(--hb-text-secondary); }
    .hb-aihistdialog__cancel[hidden] { display: none; }
</style>
<script nonce="{{ heisenberg_csp_nonce() }}">
    (() => {
        const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        const boot = () => {
            document.querySelectorAll('[data-hb-ai-history]').forEach((scrim) => {
                if (scrim.__hbAiHistory) return;
                scrim.__hbAiHistory = true;

                const table = scrim.querySelector('[data-hb-aihist-table]');
                const body = scrim.querySelector('[data-hb-aihist-body]');
                const all = scrim.querySelector('[data-hb-aihist-all]');
                const empty = scrim.querySelector('[data-hb-aihist-empty]');
                const foot = scrim.querySelector('[data-hb-aihist-foot]');
                const count = scrim.querySelector('[data-hb-aihist-count]');
                const del = scrim.querySelector('[data-hb-aihist-delete]');
                const cancel = scrim.querySelector('[data-hb-aihist-cancel]');
                const clear = scrim.querySelector('[data-hb-aihist-clear]');
                const msg = (key) => scrim.dataset[key] || '';

                const panel = () => document.querySelector('[data-hb-panel-ai]');
                const baseUrl = () => panel()?.dataset.conversationsUrl || '';
                const selected = new Set();
                let rows = [];

                const showEmpty = (text) => {
                    rows = [];
                    body.innerHTML = '';
                    table.hidden = true;
                    empty.textContent = text;
                    empty.hidden = false;
                };

                const disarm = () => {
                    del.classList.remove('is-armed');
                    del.textContent = msg('msgDelete');
                    cancel.hidden = true;
                };

                /**
                 * Keep the header checkbox honest: checked only when every row is selected,
                 * indeterminate on a partial selection. Without the indeterminate state a
                 * half-selected table reads as "nothing selected", which is how people end up
                 * deleting the wrong thing.
                 */
                const syncFoot = () => {
                    foot.hidden = selected.size === 0;
                    count.textContent = msg('msgSelected').replace(':count', String(selected.size));
                    if (all) {
                        all.checked = rows.length > 0 && selected.size === rows.length;
                        all.indeterminate = selected.size > 0 && selected.size < rows.length;
                    }
                    disarm();
                };

                const setRow = (entry, on) => {
                    entry.check.checked = on;
                    entry.tr.classList.toggle('is-selected', on);
                    on ? selected.add(entry.id) : selected.delete(entry.id);
                };

                const render = (conversations) => {
                    selected.clear();
                    rows = [];
                    if (!conversations.length) { showEmpty(msg('msgEmpty')); syncFoot(); return; }

                    empty.hidden = true;
                    table.hidden = false;
                    body.innerHTML = '';

                    conversations.forEach((c) => {
                        const tr = document.createElement('tr');

                        const tdCheck = document.createElement('td');
                        const check = document.createElement('input');
                        check.type = 'checkbox';
                        check.className = 'hb-aihistdialog__check';
                        check.setAttribute('aria-label', msg('msgSelect'));
                        tdCheck.appendChild(check);

                        const tdTitle = document.createElement('td');
                        const title = document.createElement('span');
                        title.className = 'hb-aihistdialog__title';
                        title.textContent = c.title || msg('msgUntitled');
                        title.title = title.textContent;
                        tdTitle.appendChild(title);

                        const tdMsgs = document.createElement('td');
                        tdMsgs.className = 'hb-aihistdialog__cell-muted';
                        tdMsgs.textContent = String(c.message_count ?? 0);

                        const tdDate = document.createElement('td');
                        tdDate.className = 'hb-aihistdialog__cell-muted';
                        tdDate.textContent = c.updated_at ? new Date(c.updated_at).toLocaleString() : '—';

                        const tdAct = document.createElement('td');
                        const open = document.createElement('button');
                        open.type = 'button';
                        open.className = 'hb-aihistdialog__open';
                        open.textContent = msg('msgOpen');
                        tdAct.appendChild(open);

                        const entry = { id: c.id, tr: tr, check: check };
                        rows.push(entry);

                        const openIt = () => {
                            document.dispatchEvent(new CustomEvent('hb:ai-open-conversation', { detail: { id: c.id } }));
                            scrim.hbClose ? scrim.hbClose() : (scrim.hidden = true);
                        };
                        open.addEventListener('click', (event) => { event.stopPropagation(); openIt(); });
                        check.addEventListener('click', (event) => event.stopPropagation());
                        check.addEventListener('change', () => { setRow(entry, check.checked); syncFoot(); });
                        tr.addEventListener('click', () => { setRow(entry, !check.checked); syncFoot(); });

                        tr.append(tdCheck, tdTitle, tdMsgs, tdDate, tdAct);
                        body.appendChild(tr);
                    });

                    syncFoot();
                    document.dispatchEvent(new CustomEvent('hb:refresh'));
                };

                const load = () => {
                    const base = baseUrl();
                    if (!base) { showEmpty(msg('msgError')); return; }
                    showEmpty(msg('msgLoading'));
                    const pid = panel()?.dataset.postId || '';
                    window.fetch(base + (pid ? '?post_id=' + encodeURIComponent(pid) : ''), {
                        headers: { Accept: 'application/json' }, credentials: 'same-origin',
                    })
                        .then((r) => { if (!r.ok) throw new Error('http-' + r.status); return r.json(); })
                        .then((data) => render(Array.isArray(data.conversations) ? data.conversations : []))
                        .catch(() => showEmpty(msg('msgError')));
                };

                if (all) {
                    all.addEventListener('change', () => {
                        const on = all.checked;
                        rows.forEach((entry) => setRow(entry, on));
                        syncFoot();
                    });
                }

                if (clear) {
                    clear.addEventListener('click', () => {
                        rows.forEach((entry) => setRow(entry, false));
                        syncFoot();
                    });
                }

                del.addEventListener('click', () => {
                    if (!selected.size) return;
                    if (!del.classList.contains('is-armed')) {
                        del.classList.add('is-armed');
                        del.textContent = msg('msgConfirmDelete').replace(':count', String(selected.size));
                        cancel.hidden = false;
                        return;
                    }
                    del.disabled = true;
                    window.fetch(baseUrl(), {
                        method: 'DELETE',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf(),
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({ ids: Array.from(selected) }),
                    })
                        .then((r) => { if (!r.ok) throw new Error('http-' + r.status); return r.json(); })
                        .then(() => { del.disabled = false; load(); })
                        .catch(() => { del.disabled = false; showEmpty(msg('msgError')); });
                });
                cancel.addEventListener('click', disarm);

                if (!document.__hbAiHistoryOpen) {
                    document.__hbAiHistoryOpen = true;
                    document.addEventListener('click', (event) => {
                        const opener = event.target.closest('[data-hb-ai-history-open]');
                        if (!opener) return;
                        if (scrim.hbOpen) scrim.hbOpen(opener); else scrim.hidden = false;
                        load();
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

<div class="hb-mediadialog__scrim" data-hb-ai-history hidden
    data-msg-empty="{{ __('heisenberg::editor.ai_history.empty') }}"
    data-msg-loading="{{ __('heisenberg::editor.ai_history.loading') }}"
    data-msg-error="{{ __('heisenberg::editor.ai_history.error') }}"
    data-msg-untitled="{{ __('heisenberg::editor.ai_history.untitled') }}"
    data-msg-messages="{{ __('heisenberg::editor.ai_history.messages_count') }}"
    data-msg-open="{{ __('heisenberg::editor.ai_history.open') }}"
    data-msg-select="{{ __('heisenberg::editor.ai_history.select') }}"
    data-msg-selected="{{ __('heisenberg::editor.ai_history.selected_count') }}"
    data-msg-delete="{{ __('heisenberg::editor.ai_history.delete') }}"
    data-msg-confirm-delete="{{ __('heisenberg::editor.ai_history.confirm_delete') }}">
    <div class="hb-mediadialog hb-aihistdialog" role="dialog" aria-modal="true" aria-label="{{ __('heisenberg::editor.ai_history.title') }}" tabindex="-1">
        <div class="hb-mediadialog__top">
            <span class="hb-mediadialog__title">{{ __('heisenberg::editor.ai_history.title') }}</span>
            <button type="button" class="hb-mediadialog__close" aria-label="{{ __('heisenberg::editor.common.close') }}">
                @include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 16])
            </button>
        </div>
        <div class="hb-aihistdialog__body">
            <div class="hb-aihistdialog__scrollwrap">
                <div class="hb-aihistdialog__scroll" data-hb-aihist-scroll>
                    <div class="hb-aihistdialog__empty" data-hb-aihist-empty hidden></div>
                    <table class="hb-aihistdialog__table" data-hb-aihist-table hidden>
                        <thead>
                            <tr>
                                <th class="hb-aihistdialog__col-check" scope="col">
                                    <input type="checkbox" class="hb-aihistdialog__check" data-hb-aihist-all
                                        aria-label="{{ __('heisenberg::editor.ai_history.select_all') }}" />
                                </th>
                                <th scope="col">{{ __('heisenberg::editor.ai_history.col_title') }}</th>
                                <th class="hb-aihistdialog__col-msgs" scope="col">{{ __('heisenberg::editor.ai_history.col_messages') }}</th>
                                <th class="hb-aihistdialog__col-date" scope="col">{{ __('heisenberg::editor.ai_history.col_updated') }}</th>
                                <th class="hb-aihistdialog__col-act" scope="col">{{ __('heisenberg::editor.ai_history.col_actions') }}</th>
                            </tr>
                        </thead>
                        <tbody data-hb-aihist-body></tbody>
                    </table>
                </div>
                <x-heisenberg::ui.custom-scrollbar container="[data-hb-aihist-scroll]" />
            </div>
            <div class="hb-aihistdialog__foot" data-hb-aihist-foot hidden>
                <span class="hb-aihistdialog__count" data-hb-aihist-count></span>
                <button type="button" class="hb-aihistdialog__clear" data-hb-aihist-clear>{{ __('heisenberg::editor.ai_history.clear_selection') }}</button>
                <button type="button" class="hb-aihistdialog__cancel" data-hb-aihist-cancel hidden>{{ __('heisenberg::editor.ai_history.cancel') }}</button>
                <button type="button" class="hb-aihistdialog__delete" data-hb-aihist-delete>{{ __('heisenberg::editor.ai_history.delete') }}</button>
            </div>
        </div>
    </div>
</div>
