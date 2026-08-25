<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('heisenberg::editor.comments.title') }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #fafafa; color: #0a0a0a; font: 14px/1.5 Rubik, -apple-system, sans-serif; }
        .hb-comments-page { max-width: 900px; margin: 0 auto; padding: 32px 24px 64px; }
        .hb-comments-page__head { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
        .hb-comments-page__head h1 { font-size: 20px; margin: 0; }
        .hb-comments-page__search input {
            height: 32px; padding: 0 10px; border: 1px solid #e4e4e4; border-radius: 5px; background: #fff; font: inherit; min-width: 220px;
        }
        .hb-comments-page__tabs { display: flex; gap: 6px; margin-bottom: 18px; flex-wrap: wrap; }
        .hb-comments-page__tab {
            display: inline-flex; align-items: center; gap: 6px;
            height: 30px; padding: 0 12px; border: 1px solid #e4e4e4; border-radius: 999px;
            background: #fff; color: #5a5a5a; font: inherit; font-size: 13px; cursor: pointer;
        }
        .hb-comments-page__tab[aria-selected="true"] { background: #0a0a0a; color: #fff; border-color: #0a0a0a; }
        .hb-comments-page__tab-count {
            font-size: 11px; min-width: 18px; padding: 0 5px; border-radius: 999px;
            background: rgba(0,0,0,.08); color: inherit; text-align: center; line-height: 16px;
        }
        .hb-comments-page__tab[aria-selected="true"] .hb-comments-page__tab-count { background: rgba(255,255,255,.2); }
        .hb-comments-page__list { display: flex; flex-direction: column; gap: 10px; }
        .hb-comments-page__row { background: #fff; border: 1px solid #e4e4e4; border-radius: 6px; padding: 14px 16px; }
        .hb-comments-page__row-top { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .hb-comments-page__author { font-weight: 600; }
        .hb-comments-page__email { color: #9a9a9a; font-weight: 400; font-size: 12px; margin-left: 6px; }
        .hb-comments-page__date { color: #9a9a9a; font-size: 12px; white-space: nowrap; }
        .hb-comments-page__reply-marker { color: #9a9a9a; font-size: 12px; margin-top: 2px; }
        .hb-comments-page__body {
            margin: 8px 0; white-space: pre-wrap; word-break: break-word;
            display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; cursor: pointer;
        }
        .hb-comments-page__body.hb-is-expanded { -webkit-line-clamp: unset; overflow: visible; }
        .hb-comments-page__meta { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; color: #5a5a5a; font-size: 12px; margin-bottom: 8px; }
        .hb-comments-page__meta a { color: #5a5a5a; }
        .hb-comments-page__actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .hb-comments-page__actions button {
            height: 28px; padding: 0 10px; border: 1px solid #e4e4e4; border-radius: 5px;
            background: #fff; color: #0a0a0a; font: inherit; font-size: 12px; cursor: pointer;
        }
        .hb-comments-page__actions button:hover { background: #f4f4f4; }
        .hb-comments-page__actions button[data-hb-danger] { color: #b3261e; border-color: #f0d2d0; }
        .hb-comments-page__confirm { display: flex; align-items: center; gap: 8px; margin-top: 8px; font-size: 12px; }
        .hb-comments-page__confirm button[data-hb-danger] {
            height: 28px; padding: 0 10px; border: 1px solid #b3261e; border-radius: 5px; background: #b3261e; color: #fff; font: inherit; font-size: 12px; cursor: pointer;
        }
        .hb-comments-page__reply-box { margin-top: 10px; display: flex; flex-direction: column; gap: 8px; }
        .hb-comments-page__reply-box textarea {
            width: 100%; min-height: 70px; padding: 8px 10px; border: 1px solid #e4e4e4; border-radius: 5px; font: inherit; resize: vertical;
        }
        .hb-comments-page__reply-actions { display: flex; gap: 8px; }
        .hb-comments-page__reply-actions button[data-hb-primary] {
            height: 28px; padding: 0 12px; border: 0; border-radius: 5px; background: #0a0a0a; color: #fff; font: inherit; font-size: 12px; cursor: pointer;
        }
        .hb-comments-page__empty, .hb-comments-page__loading { padding: 64px 0; text-align: center; color: #9a9a9a; }
        .hb-comments-page__pager { display: flex; align-items: center; justify-content: center; gap: 14px; margin-top: 24px; font-size: 12px; color: #5a5a5a; }
        .hb-comments-page__pager button {
            height: 28px; padding: 0 12px; border: 1px solid #e4e4e4; border-radius: 5px; background: #fff; font: inherit; font-size: 12px; cursor: pointer;
        }
        .hb-comments-page__pager button:disabled { opacity: .4; cursor: default; }
        [hidden] { display: none !important; }
    </style>
</head>
<body>
    <div class="hb-comments-page" data-hb-comments
        data-data-url="{{ $dataUrl }}"
        data-update-url-template="{{ $updateUrlTemplate }}"
        data-destroy-url-template="{{ $destroyUrlTemplate }}"
        data-reply-url-template="{{ $replyUrlTemplate }}"
        data-post-preview-url-template="{{ $postPreviewUrlTemplate }}"
    >
        <div class="hb-comments-page__head">
            <h1>{{ __('heisenberg::editor.comments.title') }}</h1>
            <div class="hb-comments-page__search">
                <input type="search" data-hb-search placeholder="{{ __('heisenberg::editor.comments.search_ph') }}">
            </div>
        </div>

        <div class="hb-comments-page__tabs" role="tablist" data-hb-tabs>
            <button type="button" class="hb-comments-page__tab" data-hb-tab="pending" role="tab" aria-selected="true">
                {{ __('heisenberg::editor.comments.tab_pending') }} <span class="hb-comments-page__tab-count" data-hb-count="pending">0</span>
            </button>
            <button type="button" class="hb-comments-page__tab" data-hb-tab="approved" role="tab" aria-selected="false">
                {{ __('heisenberg::editor.comments.tab_approved') }} <span class="hb-comments-page__tab-count" data-hb-count="approved">0</span>
            </button>
            <button type="button" class="hb-comments-page__tab" data-hb-tab="spam" role="tab" aria-selected="false">
                {{ __('heisenberg::editor.comments.tab_spam') }} <span class="hb-comments-page__tab-count" data-hb-count="spam">0</span>
            </button>
            <button type="button" class="hb-comments-page__tab" data-hb-tab="trash" role="tab" aria-selected="false">
                {{ __('heisenberg::editor.comments.tab_trash') }} <span class="hb-comments-page__tab-count" data-hb-count="trash">0</span>
            </button>
            <button type="button" class="hb-comments-page__tab" data-hb-tab="all" role="tab" aria-selected="false">
                {{ __('heisenberg::editor.comments.tab_all') }} <span class="hb-comments-page__tab-count" data-hb-count="all">0</span>
            </button>
        </div>

        <div class="hb-comments-page__loading" data-hb-loading>{{ __('heisenberg::editor.comments.loading') }}</div>
        <div class="hb-comments-page__empty" data-hb-empty hidden>{{ __('heisenberg::editor.comments.empty') }}</div>
        <div class="hb-comments-page__list" data-hb-list hidden></div>
        <div class="hb-comments-page__pager" data-hb-pager hidden>
            <button type="button" data-hb-prev>&larr; {{ __('heisenberg::editor.comments.pager_prev') }}</button>
            <span data-hb-page-label></span>
            <button type="button" data-hb-next>{{ __('heisenberg::editor.comments.pager_next') }} &rarr;</button>
        </div>
    </div>

    <template data-hb-row-template>
        <div class="hb-comments-page__row" data-hb-row>
            <div class="hb-comments-page__row-top">
                <div>
                    <span class="hb-comments-page__author" data-hb-author></span>
                    <span class="hb-comments-page__email" data-hb-email></span>
                </div>
                <span class="hb-comments-page__date" data-hb-date></span>
            </div>
            <div class="hb-comments-page__reply-marker" data-hb-reply-marker hidden></div>
            <div class="hb-comments-page__body" data-hb-body></div>
            <div class="hb-comments-page__meta">
                <a data-hb-post-link href="#" target="_blank" rel="noopener"></a>
                <span data-hb-reply-count hidden></span>
            </div>
            <div class="hb-comments-page__actions" data-hb-actions></div>
            <div data-hb-confirm-slot></div>
            <div data-hb-reply-slot></div>
        </div>
    </template>

    @php
        $hbCommentsStrings = [
            'empty' => __('heisenberg::editor.comments.empty'),
            'empty_pending' => __('heisenberg::editor.comments.empty_pending'),
            'empty_approved' => __('heisenberg::editor.comments.empty_approved'),
            'empty_spam' => __('heisenberg::editor.comments.empty_spam'),
            'empty_trash' => __('heisenberg::editor.comments.empty_trash'),
            'load_error' => __('heisenberg::editor.comments.load_error'),
            'author_anonymous' => __('heisenberg::editor.comments.author_anonymous'),
            'untitled' => __('heisenberg::editor.common.untitled'),
            'reply_marker' => __('heisenberg::editor.comments.reply_marker'),
            'reply_count_one' => __('heisenberg::editor.comments.reply_count_one'),
            'reply_count_other' => __('heisenberg::editor.comments.reply_count_other'),
            'action_approve' => __('heisenberg::editor.comments.action_approve'),
            'action_spam' => __('heisenberg::editor.comments.action_spam'),
            'action_trash' => __('heisenberg::editor.comments.action_trash'),
            'action_delete' => __('heisenberg::editor.comments.action_delete'),
            'action_reply' => __('heisenberg::editor.comments.action_reply'),
            'confirm_delete_text' => __('heisenberg::editor.comments.confirm_delete_text'),
            'confirm_delete_button' => __('heisenberg::editor.comments.confirm_delete_button'),
            'cancel' => __('heisenberg::editor.comments.cancel'),
            'reply_placeholder' => __('heisenberg::editor.comments.reply_placeholder'),
            'send' => __('heisenberg::editor.common.send'),
            'pager_label' => __('heisenberg::editor.comments.pager_label'),
        ];
    @endphp
    <script type="application/json" data-hb-comments-strings>@json($hbCommentsStrings)</script>

    <script>
    (function () {
        'use strict';

        var root = document.querySelector('[data-hb-comments]');
        if (!root) { return; }

        var hbCommentsStrings = (function () {
            var tag = document.querySelector('[data-hb-comments-strings]');
            if (!tag) { return {}; }
            try { return JSON.parse(tag.textContent || '{}'); } catch (e) { return {}; }
        })();
        var t = function (key, fallback) { return (hbCommentsStrings && hbCommentsStrings[key]) || fallback; };

        var dataUrl = root.getAttribute('data-data-url');
        var updateUrlTemplate = root.getAttribute('data-update-url-template');
        var destroyUrlTemplate = root.getAttribute('data-destroy-url-template');
        var replyUrlTemplate = root.getAttribute('data-reply-url-template');
        var postPreviewUrlTemplate = root.getAttribute('data-post-preview-url-template');

        var searchInput = root.querySelector('[data-hb-search]');
        var tabsWrap = root.querySelector('[data-hb-tabs]');
        var loadingEl = root.querySelector('[data-hb-loading]');
        var emptyEl = root.querySelector('[data-hb-empty]');
        var listEl = root.querySelector('[data-hb-list]');
        var pagerEl = root.querySelector('[data-hb-pager]');
        var prevBtn = root.querySelector('[data-hb-prev]');
        var nextBtn = root.querySelector('[data-hb-next]');
        var pageLabel = root.querySelector('[data-hb-page-label]');
        var rowTemplate = document.querySelector('[data-hb-row-template]');

        var state = {
            status: 'pending',
            q: '',
            page: 1,
            loaded: false,
        };
        var searchTimer = null;

        function csrf() {
            var meta = document.querySelector('meta[name="csrf-token"]');
            return meta ? meta.getAttribute('content') : '';
        }

        function fillUrl(template, id) {
            return template.replace('__ID__', encodeURIComponent(String(id)));
        }

        function jsonFetch(url, options) {
            options = options || {};
            options.headers = Object.assign({
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            }, options.headers || {});
            return fetch(url, options).then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (body) {
                    if (!response.ok) {
                        var error = new Error('Request failed');
                        error.body = body;
                        throw error;
                    }
                    return body;
                });
            });
        }

        function formatDate(iso) {
            if (!iso) { return ''; }
            var date = new Date(iso);
            if (isNaN(date.getTime())) { return ''; }
            return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
                + ' ' + date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
        }

        function statusActions(status) {
            var actions = [];
            if (status !== 'approved') { actions.push({ action: 'approved', label: t('action_approve', 'Approve') }); }
            if (status !== 'spam') { actions.push({ action: 'spam', label: t('action_spam', 'Spam') }); }
            if (status !== 'trash') { actions.push({ action: 'trash', label: t('action_trash', 'Trash') }); }
            if (status === 'trash') { actions.push({ action: 'delete', label: t('action_delete', 'Delete permanently'), danger: true }); }
            return actions;
        }

        function emptyMessageFor(status) {
            switch (status) {
                case 'pending': return t('empty_pending', 'No pending comments.');
                case 'approved': return t('empty_approved', 'No approved comments.');
                case 'spam': return t('empty_spam', 'No spam.');
                case 'trash': return t('empty_trash', 'Trash is empty.');
                default: return t('empty', 'No comments.');
            }
        }

        function setTab(status) {
            state.status = status;
            state.page = 1;
            Array.prototype.forEach.call(tabsWrap.querySelectorAll('[data-hb-tab]'), function (btn) {
                btn.setAttribute('aria-selected', btn.getAttribute('data-hb-tab') === status ? 'true' : 'false');
            });
            load();
        }

        tabsWrap.addEventListener('click', function (event) {
            var btn = event.target.closest('[data-hb-tab]');
            if (!btn) { return; }
            setTab(btn.getAttribute('data-hb-tab'));
        });

        searchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                state.q = searchInput.value.trim();
                state.page = 1;
                load();
            }, 300);
        });

        prevBtn.addEventListener('click', function () {
            if (state.page > 1) { state.page -= 1; load(); }
        });
        nextBtn.addEventListener('click', function () {
            state.page += 1;
            load();
        });

        function buildQuery() {
            var params = new URLSearchParams();
            params.set('status', state.status);
            if (state.q) { params.set('q', state.q); }
            params.set('page', String(state.page));
            return params.toString();
        }

        function updateCounts(counts) {
            var all = (counts.pending || 0) + (counts.approved || 0) + (counts.spam || 0) + (counts.trash || 0);
            ['pending', 'approved', 'spam', 'trash'].forEach(function (key) {
                var el = root.querySelector('[data-hb-count="' + key + '"]');
                if (el) { el.textContent = String(counts[key] || 0); }
            });
            var allEl = root.querySelector('[data-hb-count="all"]');
            if (allEl) { allEl.textContent = String(all); }
        }

        function renderRow(comment) {
            var node = rowTemplate.content.firstElementChild.cloneNode(true);
            node.setAttribute('data-hb-comment-id', String(comment.id));

            node.querySelector('[data-hb-author]').textContent = comment.author_name || t('author_anonymous', 'Anonymous');
            var emailEl = node.querySelector('[data-hb-email]');
            if (comment.author_email) {
                emailEl.textContent = comment.author_email;
            } else {
                emailEl.remove();
            }
            node.querySelector('[data-hb-date]').textContent = formatDate(comment.created_at);

            var markerEl = node.querySelector('[data-hb-reply-marker]');
            if (comment.parent_id) {
                markerEl.textContent = t('reply_marker', 'reply to #:id').replace(':id', String(comment.parent_id));
                markerEl.hidden = false;
            } else {
                markerEl.remove();
            }

            var bodyEl = node.querySelector('[data-hb-body]');
            bodyEl.textContent = comment.body || '';
            bodyEl.addEventListener('click', function () {
                bodyEl.classList.toggle('hb-is-expanded');
            });

            var postLink = node.querySelector('[data-hb-post-link]');
            postLink.textContent = comment.post_title || t('untitled', 'Untitled');
            postLink.href = fillUrl(postPreviewUrlTemplate, comment.post_id);

            var replyCountEl = node.querySelector('[data-hb-reply-count]');
            if (comment.reply_count > 0) {
                var replyCountTmpl = comment.reply_count === 1
                    ? t('reply_count_one', ':count reply')
                    : t('reply_count_other', ':count replies');
                replyCountEl.textContent = replyCountTmpl.replace(':count', String(comment.reply_count));
                replyCountEl.hidden = false;
            } else {
                replyCountEl.remove();
            }

            var actionsEl = node.querySelector('[data-hb-actions]');
            statusActions(comment.status).forEach(function (item) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = item.label;
                if (item.danger) { btn.setAttribute('data-hb-danger', ''); }
                btn.addEventListener('click', function () {
                    if (item.action === 'delete') {
                        showDeleteConfirm(node, comment.id);
                    } else {
                        changeStatus(comment.id, item.action);
                    }
                });
                actionsEl.appendChild(btn);
            });
            var replyBtn = document.createElement('button');
            replyBtn.type = 'button';
            replyBtn.textContent = t('action_reply', 'Reply');
            replyBtn.addEventListener('click', function () {
                showReplyBox(node, comment.id);
            });
            actionsEl.appendChild(replyBtn);

            return node;
        }

        function showDeleteConfirm(node, id) {
            var slot = node.querySelector('[data-hb-confirm-slot]');
            slot.innerHTML = '';
            var wrap = document.createElement('div');
            wrap.className = 'hb-comments-page__confirm';
            var text = document.createElement('span');
            text.textContent = t('confirm_delete_text', 'Delete this comment permanently?');
            var confirmBtn = document.createElement('button');
            confirmBtn.type = 'button';
            confirmBtn.setAttribute('data-hb-danger', '');
            confirmBtn.textContent = t('confirm_delete_button', 'Delete');
            confirmBtn.addEventListener('click', function () { destroyComment(id); });
            var cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.textContent = t('cancel', 'Cancel');
            cancelBtn.addEventListener('click', function () { slot.innerHTML = ''; });
            wrap.appendChild(text);
            wrap.appendChild(confirmBtn);
            wrap.appendChild(cancelBtn);
            slot.appendChild(wrap);
        }

        function showReplyBox(node, id) {
            var slot = node.querySelector('[data-hb-reply-slot]');
            if (slot.childNodes.length) { slot.innerHTML = ''; return; }
            var wrap = document.createElement('div');
            wrap.className = 'hb-comments-page__reply-box';
            var textarea = document.createElement('textarea');
            textarea.placeholder = t('reply_placeholder', 'Write a reply…');
            var actions = document.createElement('div');
            actions.className = 'hb-comments-page__reply-actions';
            var sendBtn = document.createElement('button');
            sendBtn.type = 'button';
            sendBtn.setAttribute('data-hb-primary', '');
            sendBtn.textContent = t('send', 'Send');
            sendBtn.addEventListener('click', function () {
                var body = textarea.value.trim();
                if (!body) { return; }
                sendBtn.disabled = true;
                jsonFetch(fillUrl(replyUrlTemplate, id), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ body: body }),
                }).then(function () {
                    slot.innerHTML = '';
                    load();
                }).catch(function () {
                    sendBtn.disabled = false;
                });
            });
            var cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.textContent = t('cancel', 'Cancel');
            cancelBtn.addEventListener('click', function () { slot.innerHTML = ''; });
            actions.appendChild(sendBtn);
            actions.appendChild(cancelBtn);
            wrap.appendChild(textarea);
            wrap.appendChild(actions);
            slot.appendChild(wrap);
            textarea.focus();
        }

        function changeStatus(id, status) {
            jsonFetch(fillUrl(updateUrlTemplate, id), {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ status: status }),
            }).then(function () {
                load();
            });
        }

        function destroyComment(id) {
            jsonFetch(fillUrl(destroyUrlTemplate, id), { method: 'DELETE' }).then(function () {
                load();
            });
        }

        function render(payload) {
            updateCounts(payload.counts || {});

            var rows = payload.data || [];
            listEl.innerHTML = '';
            if (rows.length === 0) {
                emptyEl.textContent = emptyMessageFor(state.status);
                emptyEl.hidden = false;
                listEl.hidden = true;
            } else {
                emptyEl.hidden = true;
                listEl.hidden = false;
                rows.forEach(function (comment) {
                    listEl.appendChild(renderRow(comment));
                });
            }

            var meta = payload.meta || { current_page: 1, last_page: 1 };
            pagerEl.hidden = meta.last_page <= 1;
            pageLabel.textContent = t('pager_label', 'Page :current of :total')
                .replace(':current', String(meta.current_page))
                .replace(':total', String(meta.last_page));
            prevBtn.disabled = meta.current_page <= 1;
            nextBtn.disabled = meta.current_page >= meta.last_page;
        }

        function load() {
            if (!state.loaded) {
                loadingEl.hidden = false;
                listEl.hidden = true;
                emptyEl.hidden = true;
            }
            jsonFetch(dataUrl + '?' + buildQuery()).then(function (payload) {
                state.loaded = true;
                loadingEl.hidden = true;
                render(payload);
            }).catch(function () {
                state.loaded = true;
                loadingEl.hidden = true;
                emptyEl.textContent = t('load_error', 'Unable to load comments.');
                emptyEl.hidden = false;
                listEl.hidden = true;
            });
        }

        load();
    })();
    </script>
</body>
</html>
