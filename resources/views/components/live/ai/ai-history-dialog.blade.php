{{-- live/ai/ai-history-dialog — previous AI conversations, in the media-dialog
     modal shell (same scrim classes on purpose: live/media/media-dialog's @once
     script wires every `.hb-mediadialog__scrim` with hbOpen()/hbClose(), Escape,
     backdrop-close and the focus trap — this dialog inherits all of it for free,
     exactly like live/revisions-dialog does).

     Opened by the AI panel header's [data-hb-ai-history-open]. URLs and the
     current post id are read off the panel root's own dataset at open time
     rather than plumbed through as props — the panel is the single source of
     truth for both. "Open" hands the chosen id back to the panel via a
     document-level hb:ai-open-conversation event; the panel does the restoring
     (transcript AND model context).

     Deletion is two-step by design: the Delete button arms into a danger
     "Confirm" state instead of firing — nothing here is silently destructive. --}}
@once
<style>
    .hb-aihistdialog { width: 480px; height: 520px; }
    .hb-aihistdialog__body { position: relative; flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; }
    .hb-aihistdialog__scrollwrap { position: relative; flex: 1 1 auto; min-height: 0; }
    .hb-aihistdialog__scroll { height: 100%; box-sizing: border-box; padding: var(--hb-space-4, 16px); }
    .hb-aihistdialog__empty { padding: 48px 0; text-align: center; color: var(--hb-text-muted); font-size: var(--hb-fs-base, 13px); font-family: var(--hb-font-sans, Rubik, sans-serif); }
    .hb-aihistdialog__empty[hidden] { display: none; }
    .hb-aihistdialog__list { display: flex; flex-direction: column; gap: 6px; }
    .hb-aihistdialog__row {
        display: flex; align-items: center; gap: var(--hb-space-3, 12px);
        padding: 10px 12px;
        border: 1px solid var(--hb-border);
        border-radius: var(--hb-radius-md, 5px);
        font-family: var(--hb-font-sans, Rubik, sans-serif);
        cursor: pointer;
    }
    .hb-aihistdialog__row:hover { background: var(--hb-surface-hover); }
    .hb-aihistdialog__row.is-selected { border-color: var(--hb-border-strong); background: var(--hb-bg-subtle); }
    .hb-aihistdialog__check { flex: none; width: 14px; height: 14px; accent-color: var(--hb-accent); cursor: pointer; }
    .hb-aihistdialog__meta { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
    .hb-aihistdialog__title { font-size: var(--hb-fs-sm, 12px); font-weight: 600; color: var(--hb-text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .hb-aihistdialog__sub { font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .hb-aihistdialog__open {
        flex: none; border: 0; cursor: pointer;
        padding: 6px 12px; border-radius: var(--hb-radius-control, 6px);
        background: var(--hb-accent); color: var(--hb-accent-fg);
        font-family: inherit; font-size: var(--hb-fs-sm, 12px); font-weight: 600;
    }
    .hb-aihistdialog__open:hover { opacity: .85; }

    {{-- Selection footer: appears only when something is checked. --}}
    .hb-aihistdialog__foot {
        flex: none; display: flex; align-items: center; gap: var(--hb-space-3, 12px);
        padding: 10px var(--hb-space-4, 16px);
        border-top: 1px solid var(--hb-border);
        font-family: var(--hb-font-sans, Rubik, sans-serif);
    }
    .hb-aihistdialog__foot[hidden] { display: none; }
    .hb-aihistdialog__count { font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-secondary); flex: 1 1 auto; }
    .hb-aihistdialog__delete, .hb-aihistdialog__cancel {
        border: 0; cursor: pointer;
        padding: 6px 12px; border-radius: var(--hb-radius-control, 6px);
        font-family: inherit; font-size: var(--hb-fs-sm, 12px); font-weight: 600;
    }
    .hb-aihistdialog__delete { background: var(--hb-danger-subtle); color: var(--hb-danger); }
    .hb-aihistdialog__delete.is-armed { background: var(--hb-danger); color: var(--hb-text-inverse); }
    .hb-aihistdialog__cancel { background: var(--hb-bg-muted); color: var(--hb-text-secondary); }
    .hb-aihistdialog__cancel[hidden] { display: none; }
</style>
<script>
    (() => {
        const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        const boot = () => {
            document.querySelectorAll('[data-hb-ai-history]').forEach((scrim) => {
                if (scrim.__hbAiHistory) return;
                scrim.__hbAiHistory = true;

                const list = scrim.querySelector('[data-hb-aihist-list]');
                const empty = scrim.querySelector('[data-hb-aihist-empty]');
                const foot = scrim.querySelector('[data-hb-aihist-foot]');
                const count = scrim.querySelector('[data-hb-aihist-count]');
                const del = scrim.querySelector('[data-hb-aihist-delete]');
                const cancel = scrim.querySelector('[data-hb-aihist-cancel]');
                const msg = (key) => scrim.dataset[key] || '';

                const panel = () => document.querySelector('[data-hb-panel-ai]');
                const baseUrl = () => panel()?.dataset.conversationsUrl || '';
                const selected = new Set();

                const showEmpty = (text) => {
                    list.innerHTML = '';
                    empty.textContent = text;
                    empty.hidden = false;
                };

                const disarm = () => {
                    del.classList.remove('is-armed');
                    del.textContent = msg('msgDelete');
                    cancel.hidden = true;
                };

                const syncFoot = () => {
                    foot.hidden = selected.size === 0;
                    count.textContent = msg('msgSelected').replace(':count', String(selected.size));
                    disarm();
                };

                const render = (conversations) => {
                    selected.clear();
                    syncFoot();
                    if (!conversations.length) { showEmpty(msg('msgEmpty')); return; }
                    empty.hidden = true;
                    list.innerHTML = '';
                    conversations.forEach((c) => {
                        const row = document.createElement('div');
                        row.className = 'hb-aihistdialog__row';

                        const check = document.createElement('input');
                        check.type = 'checkbox';
                        check.className = 'hb-aihistdialog__check';
                        check.setAttribute('aria-label', msg('msgSelect'));
                        check.addEventListener('click', (event) => event.stopPropagation());
                        check.addEventListener('change', () => {
                            check.checked ? selected.add(c.id) : selected.delete(c.id);
                            row.classList.toggle('is-selected', check.checked);
                            syncFoot();
                        });

                        const meta = document.createElement('div');
                        meta.className = 'hb-aihistdialog__meta';
                        const title = document.createElement('span');
                        title.className = 'hb-aihistdialog__title';
                        title.textContent = c.title || msg('msgUntitled');
                        const sub = document.createElement('span');
                        sub.className = 'hb-aihistdialog__sub';
                        sub.textContent = msg('msgMessages').replace(':count', String(c.message_count))
                            + (c.updated_at ? ' · ' + new Date(c.updated_at).toLocaleString() : '');
                        meta.append(title, sub);

                        const open = document.createElement('button');
                        open.type = 'button';
                        open.className = 'hb-aihistdialog__open';
                        open.textContent = msg('msgOpen');

                        const openIt = () => {
                            document.dispatchEvent(new CustomEvent('hb:ai-open-conversation', { detail: { id: c.id } }));
                            scrim.hbClose ? scrim.hbClose() : (scrim.hidden = true);
                        };
                        open.addEventListener('click', (event) => { event.stopPropagation(); openIt(); });
                        // Row click toggles the checkbox — selection is the
                        // dialog's primary gesture; opening is the button's.
                        row.addEventListener('click', () => { check.checked = !check.checked; check.dispatchEvent(new Event('change')); });

                        row.append(check, meta, open);
                        list.appendChild(row);
                    });
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

                // Two-step delete: first click arms, second click fires.
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
                    <div class="hb-aihistdialog__list" data-hb-aihist-list></div>
                </div>
                <x-ui.custom-scrollbar container="[data-hb-aihist-scroll]" />
            </div>
            <div class="hb-aihistdialog__foot" data-hb-aihist-foot hidden>
                <span class="hb-aihistdialog__count" data-hb-aihist-count></span>
                <button type="button" class="hb-aihistdialog__cancel" data-hb-aihist-cancel hidden>{{ __('heisenberg::editor.ai_history.cancel') }}</button>
                <button type="button" class="hb-aihistdialog__delete" data-hb-aihist-delete>{{ __('heisenberg::editor.ai_history.delete') }}</button>
            </div>
        </div>
    </div>
</div>
