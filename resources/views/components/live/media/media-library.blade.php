{{-- live/media/media-library — a search field over a responsive auto-fill card grid. Reuses
     ui/search-field and live/media/media-card.

     When `select-url` is given (media-dialog.blade.php threads through route('media.select'), see
     routes/media.php + MediaLibraryController::select), the search field and grid are live: search
     debounces into a fetch, results replace the grid, and picking a card dispatches a bubbling
     `hb:media-pick` event with the file payload. Without `select-url` (the showcase page never sets
     it) this stays exactly the static, presentational component it was — `hbRefresh()` is a deliberate
     no-op in that case, so nothing here fetches or changes for that page. --}}
@once
<style>
    .hb-medialib { display: flex; flex-direction: column; gap: var(--hb-space-5, 20px); width: 100%; }
    .hb-medialib__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: var(--hb-space-5, 20px); align-content: start; }
    .hb-medialib__grid[hidden] { display: none; }
    .hb-medialib__empty { padding: 48px 0; text-align: center; color: var(--hb-text-muted, #9A9A9A); font-size: var(--hb-fs-base, 13px); }
    .hb-medialib__empty[hidden] { display: none; }
    .hb-medialib .hb-mediacard:focus-visible { outline: 2px solid var(--hb-border-focus, #000); outline-offset: 2px; }
    .hb-medialib template { display: none; }
</style>
@endonce
@once('hb-medialib-core')
<script>
    (() => {
        const boot = () => {
            document.querySelectorAll('[data-hb-medialib]').forEach((root) => {
                if (root.__hbMedialib) return;
                root.__hbMedialib = true;

                const selectUrl = root.dataset.selectUrl || '';
                const grid = root.querySelector('[data-hb-medialib-grid]');
                const empty = root.querySelector('[data-hb-medialib-empty]');
                const template = root.querySelector('[data-hb-mediacard-template]');
                const searchInput = root.querySelector('.hb-searchfield__input');
                let selectedId = null;
                let currentFiles = [];
                let searchTimer = null;

                const setMessage = (msg) => {
                    if (grid) grid.hidden = true;
                    if (empty) { empty.textContent = msg; empty.hidden = false; }
                };

                const pick = (file) => {
                    selectedId = file.id;
                    paint(currentFiles);
                    root.dispatchEvent(new CustomEvent('hb:media-pick', { bubbles: true, detail: file }));
                };

                function paint(files) {
                    currentFiles = files || [];
                    if (!grid || !template) return;
                    grid.innerHTML = '';
                    if (!currentFiles.length) {
                        setMessage('No media yet — upload a file to get started.');
                        return;
                    }
                    if (empty) empty.hidden = true;
                    grid.hidden = false;
                    currentFiles.forEach((file) => {
                        const node = template.content.firstElementChild.cloneNode(true);
                        const thumbEl = node.querySelector('.hb-mediacard__thumb');
                        let img = node.querySelector('.hb-mediacard__img');
                        const src = file.thumbnail_url || file.url || '';
                        if (src) {
                            if (!img) {
                                img = document.createElement('img');
                                img.className = 'hb-mediacard__img';
                                thumbEl?.insertBefore(img, thumbEl.firstChild);
                            }
                            img.src = src;
                            img.alt = file.original_name || '';
                        } else {
                            img?.remove();
                        }
                        const nameEl = node.querySelector('.hb-mediacard__name');
                        if (nameEl) nameEl.textContent = file.original_name || 'Untitled';
                        const metaEl = node.querySelector('.hb-mediacard__meta');
                        if (metaEl) metaEl.textContent = file.human_size || '';
                        const selected = selectedId != null && file.id === selectedId;
                        node.classList.toggle('hb-mediacard--selected', selected);
                        node.setAttribute('role', 'button');
                        node.setAttribute('tabindex', '0');
                        node.setAttribute('aria-selected', selected ? 'true' : 'false');
                        node.addEventListener('click', () => pick(file));
                        node.addEventListener('keydown', (e) => {
                            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(file); }
                        });
                        grid.appendChild(node);
                    });
                }

                // Deliberately a no-op with no select-url — see the file header. This is what
                // keeps the showcase page's static demo untouched.
                root.hbRefresh = (search) => {
                    if (!selectUrl) return;
                    const sep = selectUrl.indexOf('?') === -1 ? '?' : '&';
                    const url = search ? `${selectUrl}${sep}search=${encodeURIComponent(search)}` : selectUrl;
                    window.fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                        .then((r) => { if (!r.ok) throw new Error('http-' + r.status); return r.json(); })
                        .then((res) => paint(Array.isArray(res.files) ? res.files : []))
                        .catch(() => setMessage('Couldn’t load the media library. Try again.'));
                };

                searchInput?.addEventListener('input', () => {
                    clearTimeout(searchTimer);
                    const value = searchInput.value.trim();
                    searchTimer = setTimeout(() => root.hbRefresh(value), 250);
                });
            });
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
        else boot();
        document.addEventListener('hb:refresh', boot);
    })();
</script>
@endonce

@props([
    'items' => [],
    'placeholder' => 'Search media items…',
    'selectUrl' => null,
])
<div {{ $attributes->merge(['class' => 'hb-medialib']) }} data-hb-medialib data-select-url="{{ $selectUrl }}">
    <x-ui.search-field :placeholder="$placeholder" />
    <div class="hb-medialib__grid" data-hb-medialib-grid @if (! count($items)) hidden @endif>
        @foreach ($items as $item)
            <x-live.media.media-card
                :state="$item['state'] ?? 'default'"
                :name="$item['name'] ?? 'Filename.png'"
                :meta="$item['meta'] ?? ''"
                :thumb="$item['thumb'] ?? null"
                :progress="$item['progress'] ?? 0"
                :status="$item['status'] ?? null"
            />
        @endforeach
    </div>
    <div class="hb-medialib__empty" data-hb-medialib-empty @if (count($items)) hidden @endif>No media yet — upload a file to get started.</div>
    {{-- Clone target for JS-rendered results — guarantees fetched cards are pixel-identical to
         the server-rendered ones above without re-implementing media-card's markup/icons in JS. --}}
    <template data-hb-mediacard-template><x-live.media.media-card /></template>
</div>
