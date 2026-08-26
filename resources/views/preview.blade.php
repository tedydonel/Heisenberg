@php
    $seo = $seo ?? [];
    $featured = $featured ?? null;
    $toc = $toc ?? [];
    $comments = $comments ?? null;
    if ($featured === null && isset($seo['featured_image']) && is_array($seo['featured_image'])) {
        $featured = $seo['featured_image'];
    }
    $metaTitle = trim((string) ($seo['title'] ?? '')) ?: $title;
    $metaDescription = trim((string) ($seo['description'] ?? ''));
    $canonical = trim((string) ($seo['canonical'] ?? ''));
    $robots = [];
    if (! empty($seo['noindex'])) $robots[] = 'noindex';
    if (! empty($seo['nofollow'])) $robots[] = 'nofollow';
    $ogTitle = trim((string) ($seo['ogTitle'] ?? '')) ?: $metaTitle;
    $ogDescription = trim((string) ($seo['ogDescription'] ?? '')) ?: $metaDescription;
    $ogImage = trim((string) ($seo['ogImage'] ?? ''));
    $jsonLd = is_array($seo['jsonLd'] ?? null) ? $seo['jsonLd'] : [];
    $alternates = $alternates ?? [];

@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ $metaTitle }}</title>
    @if ($metaDescription !== '')<meta name="description" content="{{ $metaDescription }}" />@endif
    @if ($robots !== [])<meta name="robots" content="{{ implode(', ', $robots) }}" />@endif
    @if ($canonical !== '')<link rel="canonical" href="{{ $canonical }}" />@endif
    @foreach ($alternates as $alternate)<link rel="alternate" hreflang="{{ $alternate['locale'] }}" href="{{ $alternate['url'] }}" />@endforeach
    <meta property="og:type" content="article" />
    <meta property="og:title" content="{{ $ogTitle }}" />
    @if ($ogDescription !== '')<meta property="og:description" content="{{ $ogDescription }}" />@endif
    @if ($ogImage !== '')<meta property="og:image" content="{{ $ogImage }}" />@elseif (! empty($featured['url']))<meta property="og:image" content="{{ $featured['url'] }}" />@endif
    <meta name="twitter:card" content="{{ $ogImage !== '' ? 'summary_large_image' : 'summary' }}" />
    @if ($jsonLd !== [])
        <script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @endif
    <link rel="icon" type="image/svg+xml" href="{{ route('heisenberg.editor.asset.logo') }}" />
    @if (! empty($fontsHref))
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
        <link href="{{ $fontsHref }}" rel="stylesheet" />
    @endif
    <style>
        :root {
            --ink: #0a0a0a; --accent-1: #0a0a0a; --faint: #9a9a9a; --paper: #ffffff;
            --fs-sm: 13px; --fs-md: 14px; --fs-lg: 16px; --fs-xl: 20px;
            --sp-1: 0.25rem; --sp-2: 0.5rem; --sp-3: 1rem; --sp-4: 1.5rem;
            --font-sans: 'Rubik', -apple-system, sans-serif;
            --font-serif: Georgia, 'Times New Roman', serif;
            --font-mono: 'JetBrains Mono', ui-monospace, monospace;
            --fw-regular: 400; --fw-medium: 500; --fw-semibold: 600; --fw-bold: 700;
            --ui: var(--font-sans); --slate: #5a5a5a; --muted: #9a9a9a; --ghost: #c4c4c4;
            --line: #e4e4e4; --line-strong: #c8c8c8; --app: #eeeeee; --app-2: #f4f4f4;
            --bg: #ffffff; --subtle: #fafafa; --r-xs: 2px; --r-sm: 3px; --r-md: 5px; --r-lg: 8px;
            --sh-1: 0 1px 2px rgba(10,10,10,0.04); --speed: 170ms; --ink-soft: #1a1a1a; --on-ink: #ffffff;
        }
    </style>
    <style>{!! $themeCss !!}</style>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--paper, #fff); color: var(--ink, #0a0a0a);
            font-family: var(--font-sans, 'Rubik'), -apple-system, sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        .hb-preview-page { max-width: 760px; margin: 0 auto; padding: 56px 24px 96px; position: relative; z-index: 1; }
        .hb-preview-title { font-size: 40px; font-weight: 700; letter-spacing: -0.02em; line-height: 1.15; margin: 0 0 10px; }
    .hb-preview-featured { display: block; margin: 0 0 28px; }
    .hb-preview-featured img { display: block; width: 100%; height: 300px; object-fit: cover; border-radius: var(--r-md, 5px); }
    .hb-preview-toc {
        margin: 0 0 28px; padding: var(--sp-3, 1rem) var(--sp-4, 1.5rem);
        background: var(--subtle, #fafafa); border: 1px solid var(--line, #e4e4e4); border-radius: var(--r-md, 5px);
    }
    .hb-preview-toc__title { margin: 0 0 8px; font-size: var(--fs-sm, 13px); font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--slate, #5a5a5a); }
    .hb-preview-toc__list { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 4px; }
    .hb-preview-toc__list a { color: var(--ink, #0a0a0a); font-size: var(--fs-md, 14px); text-decoration: none; }
    .hb-preview-toc__list a:hover { text-decoration: underline; }
    .hb-preview-comments { margin: 56px 0 0; padding-top: 32px; border-top: 1px solid var(--line, #e4e4e4); }
    .hb-preview-comments__header { display: flex; align-items: baseline; gap: 8px; margin: 0 0 20px; }
    .hb-preview-comments__title { margin: 0; font-size: 22px; font-weight: 700; letter-spacing: -0.01em; }
    .hb-preview-comments__count { color: var(--muted, #9a9a9a); font-weight: 500; font-size: var(--fs-md, 14px); }
    .hb-preview-comments__empty { margin: 0; padding: 16px 0; color: var(--muted, #9a9a9a); font-size: var(--fs-md, 14px); }
    .hb-preview-comments__closed { margin: 12px 0 0; color: var(--muted, #9a9a9a); font-size: var(--fs-md, 14px); }
    .hb-preview-comment { padding: 14px 0; border-bottom: 1px solid var(--line, #e4e4e4); }
    .hb-preview-comment__replies > .hb-preview-comment:last-child,
    .hb-preview-comments__list > .hb-preview-comment:last-child { border-bottom: none; }
    .hb-preview-comment__meta { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; margin: 0 0 4px; }
    .hb-preview-comment__author { font-weight: 600; color: var(--ink, #0a0a0a); font-size: var(--fs-md, 14px); }
    .hb-preview-comment__date { font-size: var(--fs-sm, 13px); color: var(--muted, #9a9a9a); }
    .hb-preview-comment__body { margin: 0 0 8px; white-space: pre-line; font-size: var(--fs-md, 14px); line-height: 1.6; color: var(--ink, #0a0a0a); }
    .hb-preview-comment__reply-btn { display: inline-block; background: none; border: none; padding: 0; margin: 0 0 4px; color: var(--slate, #5a5a5a); font: inherit; font-size: var(--fs-sm, 13px); cursor: pointer; text-decoration: underline; }
    .hb-preview-comment__reply-btn:hover { color: var(--ink, #0a0a0a); }
    .hb-preview-comment__reply-slot:empty { display: none; }
    .hb-preview-comment__reply-slot { margin-top: 10px; }
    .hb-preview-comment__replies { margin: 10px 0 0 18px; padding-left: 16px; border-left: 1px solid var(--line, #e4e4e4); }
    .hb-preview-comment__replies:empty { display: none; }
    .hb-preview-comment--highlight { animation: hbCommentHighlight 1.8s ease; }
    @keyframes hbCommentHighlight { from { background: var(--app-2, #f4f4f4); } to { background: transparent; } }
    .hb-preview-comment-form { display: flex; flex-direction: column; gap: 10px; margin-top: 14px; padding: 16px; background: var(--subtle, #fafafa); border: 1px solid var(--line, #e4e4e4); border-radius: var(--r-md, 5px); }
    .hb-preview-comment-form__row { display: flex; gap: 12px; flex-wrap: wrap; }
    .hb-preview-comment-form__label { display: flex; flex-direction: column; gap: 4px; flex: 1 1 180px; font-size: var(--fs-sm, 13px); color: var(--slate, #5a5a5a); }
    .hb-preview-comment-form__label--full { flex: 1 1 100%; }
    .hb-preview-comment-form__input, .hb-preview-comment-form__textarea { font: inherit; font-size: var(--fs-md, 14px); padding: 8px 10px; border: 1px solid var(--line-strong, #c8c8c8); border-radius: var(--r-sm, 3px); background: var(--paper, #fff); color: var(--ink, #0a0a0a); }
    .hb-preview-comment-form__textarea { resize: vertical; min-height: 80px; }
    .hb-preview-comment-form__actions { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
    .hb-preview-comment-form__submit { background: var(--ink, #0a0a0a); color: var(--on-ink, #fff); border: none; border-radius: var(--r-sm, 3px); padding: 8px 16px; font-size: var(--fs-sm, 13px); font-weight: 600; cursor: pointer; }
    .hb-preview-comment-form__submit:disabled { opacity: 0.6; cursor: default; }
    .hb-preview-comment-form__cancel { background: none; border: none; padding: 0; margin: 0; color: var(--slate, #5a5a5a); font: inherit; font-size: var(--fs-sm, 13px); text-decoration: underline; cursor: pointer; }
    .hb-preview-comment-form__notice { font-size: var(--fs-sm, 13px); color: var(--slate, #5a5a5a); }
    .hb-preview-comment-form__notice--error { color: #b3261e; }
    .hb-preview-comment-form__notice--success { color: #2e7d32; }
    .hb-preview-bar {
            position: sticky; top: 0; z-index: 10; background: var(--ink, #0a0a0a); color: var(--on-ink, #fff);
            font: 12px/1 var(--font-sans, 'Rubik'), sans-serif; letter-spacing: 0.04em;
            padding: 8px 16px; display: flex; align-items: center; gap: 8px;
        }
        .hb-preview-bar b { font-weight: 600; }
        .hb-preview-bar span { opacity: 0.6; }
    </style>
    <style>{!! $blocksCss !!}</style>
    <style>{!! $stateCss ?? '' !!}</style>
    <link rel="stylesheet" href="{{ route('heisenberg.editor.asset.animations') }}" />
    <link rel="stylesheet" href="{{ route('heisenberg.editor.asset.supports') }}" />
</head>
<body>
    <div class="hb-preview-bar"><b>Preview</b><span>— sanitized public rendering; close this tab to return to the editor.</span></div>
    <main class="hb-preview-page">
        @if (($hasDoc ?? true) === false)
            <div style="padding:56px 16px;text-align:center;color:var(--muted,#9a9a9a);font-size:var(--fs-md,14px);line-height:1.6">
                <p style="font-size:17px;color:var(--ink,#0a0a0a);font-weight:600;margin:0 0 6px">Nothing to preview yet</p>
                <p style="margin:0">Save your post in the editor (or use the ↗ preview button) and this page will show the rendered result.</p>
            </div>
        @else
            <h1 class="hb-preview-title">{{ $title }}</h1>
            @if (! empty($featured['url']))
                <figure class="hb-preview-featured">
                    <img
                        src="{{ $featured['url'] }}"
                        @if (! empty($featured['srcset'])) srcset="{{ $featured['srcset'] }}" @endif
                        @if (! empty($featured['sizes'])) sizes="{{ $featured['sizes'] }}" @endif
                        alt="{{ $featured['alt'] ?? '' }}"
                    >
                </figure>
            @endif
            @if (count($toc) > 0)
                <nav class="hb-preview-toc" aria-label="Table of contents">
                    <p class="hb-preview-toc__title">Contents</p>
                    <ol class="hb-preview-toc__list">
                        @foreach ($toc as $entry)
                            <li><a href="#{{ $entry['anchor'] }}">{{ $entry['label'] }}</a></li>
                        @endforeach
                    </ol>
                </nav>
            @endif
            {!! $html !!}

            @if ($comments !== null)
                @php
                    $maxDepth = (int) ($comments['max_depth'] ?? 3);
                    $renderComment = function (array $item, int $depth) use (&$renderComment, $maxDepth) {
                        $canReply = $depth < ($maxDepth - 1);
                        $replies = $item['replies'] ?? [];
                        ob_start();
                        ?>
                        <div class="hb-preview-comment" data-hb-comment data-comment-id="<?= e((string) $item['id']) ?>" data-depth="<?= $depth ?>">
                            <div class="hb-preview-comment__row">
                                <div class="hb-preview-comment__meta">
                                    <span class="hb-preview-comment__author"><?= e($item['author_name']) ?></span>
                                    <span class="hb-preview-comment__date"><?= e((string) $item['created_at']) ?></span>
                                </div>
                                <p class="hb-preview-comment__body"><?= e($item['body']) ?></p>
                                <?php if ($canReply): ?>
                                    <button type="button" class="hb-preview-comment__reply-btn" data-hb-reply-btn>Reply</button>
                                <?php endif; ?>
                                <div class="hb-preview-comment__reply-slot" data-hb-reply-slot></div>
                            </div>
                            <div class="hb-preview-comment__replies" data-hb-replies><?php
                                foreach ($replies as $child) {
                                    echo $renderComment($child, $depth + 1);
                                }
                            ?></div>
                        </div>
                        <?php
                        return ob_get_clean();
                    };
                @endphp
                <section
                    class="hb-preview-comments"
                    data-hb-comments
                    data-submit-url="{{ $comments['submit_url'] }}"
                    data-max-depth="{{ $maxDepth }}"
                    data-can-submit="{{ $comments['can_submit'] ? '1' : '0' }}"
                    data-is-guest="{{ $comments['is_guest'] ? '1' : '0' }}"
                >
                    <div class="hb-preview-comments__header">
                        <h2 class="hb-preview-comments__title">Comments</h2>
                        <span class="hb-preview-comments__count" data-hb-comments-count>{{ $comments['count'] }}</span>
                    </div>

                    <div class="hb-preview-comments__list" data-hb-comments-list>
                        @if (count($comments['items']) === 0)
                            <p class="hb-preview-comments__empty" data-hb-comments-empty>No comments yet — start the discussion.</p>
                        @else
                            @foreach ($comments['items'] as $item)
                                {!! $renderComment($item, 0) !!}
                            @endforeach
                        @endif
                    </div>

                    <div class="hb-preview-comments__new" data-hb-comments-new>
                        @if ($comments['can_submit'])
                            <form class="hb-preview-comment-form" data-hb-comment-form data-parent-id="" novalidate>
                                @if ($comments['is_guest'])
                                    <div class="hb-preview-comment-form__row">
                                        <label class="hb-preview-comment-form__label">Name
                                            <input type="text" class="hb-preview-comment-form__input" data-hb-field="author_name" required>
                                        </label>
                                        <label class="hb-preview-comment-form__label">Email (optional)
                                            <input type="email" class="hb-preview-comment-form__input" data-hb-field="author_email">
                                        </label>
                                    </div>
                                @endif
                                <label class="hb-preview-comment-form__label hb-preview-comment-form__label--full">Comment
                                    <textarea class="hb-preview-comment-form__textarea" data-hb-field="body" rows="4" required></textarea>
                                </label>
                                <div class="hb-preview-comment-form__actions">
                                    <button type="submit" class="hb-preview-comment-form__submit" data-hb-submit>Post comment</button>
                                    <span class="hb-preview-comment-form__notice" data-hb-notice></span>
                                </div>
                            </form>
                        @else
                            <p class="hb-preview-comments__closed">Comments are closed for guests.</p>
                        @endif
                    </div>
                </section>
            @endif
        @endif
    </main>
    <script>
    (function () {
        'use strict';
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        var animated = document.querySelectorAll('[data-hb-anim]');
        if (!animated.length) return;
        if (!('IntersectionObserver' in window)) {
            animated.forEach(function (el) { el.classList.add('hb-anim-play'); });
            return;
        }
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                var el = entry.target;
                var once = el.hasAttribute('data-hb-anim-once');
                if (entry.isIntersecting) {
                    el.classList.remove('hb-anim-wait');
                    el.classList.add('hb-anim-play');
                    if (once) io.unobserve(el);
                } else if (!once) {
                    el.classList.remove('hb-anim-play');
                    el.classList.add('hb-anim-wait');
                }
            });
        }, { threshold: 0.18 });
        animated.forEach(function (el) {
            el.classList.add('hb-anim-wait');
            io.observe(el);
        });
    })();
    </script>
    <script>
    (function () {
        'use strict';
        var root = document.querySelector('[data-hb-comments]');
        if (!root) return;

        var submitUrl = root.getAttribute('data-submit-url');
        var maxDepth = parseInt(root.getAttribute('data-max-depth'), 10) || 3;
        var isGuest = root.getAttribute('data-is-guest') === '1';
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

        var list = root.querySelector('[data-hb-comments-list]');
        var countEl = root.querySelector('[data-hb-comments-count]');
        var openReply = null;

        var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

        function formatDate(value) {
            if (!value) return '';
            var d = new Date(value);
            if (isNaN(d.getTime())) return String(value);
            var hh = String(d.getHours()).padStart(2, '0');
            var mm = String(d.getMinutes()).padStart(2, '0');
            return MONTHS[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear() + ' ' + hh + ':' + mm;
        }

        function buildCommentElement(item, depth) {
            var el = document.createElement('div');
            el.className = 'hb-preview-comment';
            el.setAttribute('data-hb-comment', '');
            el.dataset.commentId = String(item.id);
            el.dataset.depth = String(depth);

            var row = document.createElement('div');
            row.className = 'hb-preview-comment__row';

            var meta = document.createElement('div');
            meta.className = 'hb-preview-comment__meta';
            var author = document.createElement('span');
            author.className = 'hb-preview-comment__author';
            author.textContent = item.author_name || '';
            var date = document.createElement('span');
            date.className = 'hb-preview-comment__date';
            date.textContent = formatDate(item.created_at);
            meta.appendChild(author);
            meta.appendChild(date);
            row.appendChild(meta);

            var body = document.createElement('p');
            body.className = 'hb-preview-comment__body';
            body.textContent = item.body || '';
            row.appendChild(body);

            if (depth < maxDepth - 1) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'hb-preview-comment__reply-btn';
                btn.setAttribute('data-hb-reply-btn', '');
                btn.textContent = 'Reply';
                row.appendChild(btn);
            }

            var slot = document.createElement('div');
            slot.className = 'hb-preview-comment__reply-slot';
            slot.setAttribute('data-hb-reply-slot', '');
            row.appendChild(slot);

            var replies = document.createElement('div');
            replies.className = 'hb-preview-comment__replies';
            replies.setAttribute('data-hb-replies', '');

            el.appendChild(row);
            el.appendChild(replies);

            return el;
        }

        function buildForm(opts) {
            var form = document.createElement('form');
            form.className = 'hb-preview-comment-form';
            form.setAttribute('data-hb-comment-form', '');
            form.dataset.parentId = opts.parentCommentEl ? opts.parentCommentEl.dataset.commentId : '';
            form.setAttribute('novalidate', '');

            if (isGuest) {
                var fieldRow = document.createElement('div');
                fieldRow.className = 'hb-preview-comment-form__row';

                var nameLabel = document.createElement('label');
                nameLabel.className = 'hb-preview-comment-form__label';
                nameLabel.appendChild(document.createTextNode('Name'));
                var nameInput = document.createElement('input');
                nameInput.type = 'text';
                nameInput.required = true;
                nameInput.className = 'hb-preview-comment-form__input';
                nameInput.setAttribute('data-hb-field', 'author_name');
                nameLabel.appendChild(nameInput);

                var emailLabel = document.createElement('label');
                emailLabel.className = 'hb-preview-comment-form__label';
                emailLabel.appendChild(document.createTextNode('Email (optional)'));
                var emailInput = document.createElement('input');
                emailInput.type = 'email';
                emailInput.className = 'hb-preview-comment-form__input';
                emailInput.setAttribute('data-hb-field', 'author_email');
                emailLabel.appendChild(emailInput);

                fieldRow.appendChild(nameLabel);
                fieldRow.appendChild(emailLabel);
                form.appendChild(fieldRow);
            }

            var bodyLabel = document.createElement('label');
            bodyLabel.className = 'hb-preview-comment-form__label hb-preview-comment-form__label--full';
            bodyLabel.appendChild(document.createTextNode('Comment'));
            var textarea = document.createElement('textarea');
            textarea.className = 'hb-preview-comment-form__textarea';
            textarea.required = true;
            textarea.rows = 4;
            textarea.setAttribute('data-hb-field', 'body');
            bodyLabel.appendChild(textarea);
            form.appendChild(bodyLabel);

            var actions = document.createElement('div');
            actions.className = 'hb-preview-comment-form__actions';

            var submit = document.createElement('button');
            submit.type = 'submit';
            submit.className = 'hb-preview-comment-form__submit';
            submit.setAttribute('data-hb-submit', '');
            submit.textContent = 'Post comment';
            actions.appendChild(submit);

            if (opts.cancellable) {
                var cancel = document.createElement('button');
                cancel.type = 'button';
                cancel.className = 'hb-preview-comment-form__cancel';
                cancel.textContent = 'Cancel';
                cancel.addEventListener('click', function () {
                    form.remove();
                    if (openReply && openReply.form === form) openReply = null;
                });
                actions.appendChild(cancel);
            }

            var notice = document.createElement('span');
            notice.className = 'hb-preview-comment-form__notice';
            notice.setAttribute('data-hb-notice', '');
            actions.appendChild(notice);

            form.appendChild(actions);

            wireSubmit(form, opts.parentCommentEl || null);

            return form;
        }

        function insertApprovedComment(comment, parentCommentEl, form) {
            var depth = parentCommentEl ? (parseInt(parentCommentEl.dataset.depth, 10) + 1) : 0;
            var node = buildCommentElement(comment, depth);

            if (parentCommentEl) {
                var repliesContainer = parentCommentEl.querySelector(':scope > [data-hb-replies]');
                repliesContainer.appendChild(node);
                form.remove();
                if (openReply && openReply.form === form) openReply = null;
            } else {
                var emptyEl = list.querySelector('[data-hb-comments-empty]');
                if (emptyEl) emptyEl.remove();
                list.insertBefore(node, list.firstChild);
                form.reset();
            }

            node.classList.add('hb-preview-comment--highlight');
            setTimeout(function () { node.classList.remove('hb-preview-comment--highlight'); }, 1800);

            if (countEl) countEl.textContent = String((parseInt(countEl.textContent, 10) || 0) + 1);
        }

        function wireSubmit(form, parentCommentEl) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();

                var notice = form.querySelector('[data-hb-notice]');
                var submitBtn = form.querySelector('[data-hb-submit]');
                var bodyField = form.querySelector('[data-hb-field="body"]');
                var nameField = form.querySelector('[data-hb-field="author_name"]');
                var emailField = form.querySelector('[data-hb-field="author_email"]');

                notice.textContent = '';
                notice.className = 'hb-preview-comment-form__notice';

                var payload = { body: bodyField ? bodyField.value : '' };
                var parentId = form.dataset.parentId;
                if (parentId) payload.parent_id = Number(parentId);
                if (nameField) payload.author_name = nameField.value;
                if (emailField && emailField.value) payload.author_email = emailField.value;

                submitBtn.disabled = true;
                var originalLabel = submitBtn.textContent;
                submitBtn.textContent = 'Posting…';

                fetch(submitUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(payload)
                }).then(function (res) {
                    return res.json().catch(function () { return {}; }).then(function (data) {
                        return { status: res.status, data: data };
                    });
                }).then(function (result) {
                    if (result.status === 201 && result.data && result.data.ok) {
                        if (result.data.status === 'approved' && result.data.comment) {
                            insertApprovedComment(result.data.comment, parentCommentEl, form);
                        } else {
                            notice.textContent = 'Your comment is awaiting moderation.';
                            notice.className = 'hb-preview-comment-form__notice hb-preview-comment-form__notice--success';
                            if (bodyField) bodyField.value = '';
                        }
                    } else {
                        notice.textContent = (result.data && result.data.message) || 'Something went wrong. Please try again.';
                        notice.className = 'hb-preview-comment-form__notice hb-preview-comment-form__notice--error';
                    }
                }).catch(function () {
                    notice.textContent = 'Network error. Please try again.';
                    notice.className = 'hb-preview-comment-form__notice hb-preview-comment-form__notice--error';
                }).finally(function () {
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalLabel;
                });
            });
        }

        root.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-hb-reply-btn]');
            if (!btn) return;

            var commentEl = btn.closest('[data-hb-comment]');
            var row = btn.closest('.hb-preview-comment__row');
            var slot = row ? row.querySelector('[data-hb-reply-slot]') : null;
            if (!commentEl || !slot) return;

            if (openReply) {
                var wasSameSlot = openReply.slot === slot;
                openReply.form.remove();
                openReply = null;
                if (wasSameSlot) return;
            }

            var form = buildForm({ cancellable: true, parentCommentEl: commentEl });
            slot.appendChild(form);
            openReply = { slot: slot, form: form };
            var ta = form.querySelector('textarea');
            if (ta) ta.focus();
        });

        var topForm = root.querySelector('[data-hb-comments-new] > [data-hb-comment-form]');
        if (topForm) wireSubmit(topForm, null);
    })();
    </script>
</body>
</html>
