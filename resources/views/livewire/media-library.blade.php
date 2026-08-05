{{-- livewire/media-library — the live Media Dialog (WO65C/Krcma). Reuses the
     presentational live/media components (media-card, upload-dropzone). Tab state
     is pure UI, so Alpine owns it (instant, no round-trip); data (search, upload,
     select) is Livewire. On upload we jump to the Library tab and show each file
     as an in-grid card in the media-card *uploading* state — the design already
     carries that state (QPTbJ), so no progress bar clutters the upload tab. --}}
<div class="hb-mlw" x-data="{ tab: 'library', pending: [] }"
    x-on:livewire-upload-start="tab = 'library'"
    x-on:livewire-upload-progress="pending.forEach(p => p.progress = $event.detail.progress)"
    x-on:uploads-done.window="pending = []">
    @once
    <style>
        .hb-mlw { display: flex; flex-direction: column; overflow: hidden; width: 900px; max-width: 100%;
            background: var(--hb-bg, #fff); border: 1px solid var(--hb-border, #E4E4E4);
            border-radius: var(--hb-radius-lg, 8px); box-shadow: 0 24px 64px rgba(0, 0, 0, .16);
            font-family: var(--hb-font-sans, Rubik, sans-serif); }
        .hb-mlw [x-cloak] { display: none !important; }
        .hb-mlw__top { display: flex; align-items: center; justify-content: space-between; height: 32px;
            padding-left: var(--hb-space-4, 16px); background: var(--hb-bg-muted, #F4F4F4); flex: none; }
        .hb-mlw__title { font-size: var(--hb-fs-sm, 12px); color: var(--hb-accent, #000); white-space: nowrap; flex: none; }
        .hb-mlw__tabs { display: flex; gap: 2px; width: 200px; height: 26px; padding: 2px;
            background: var(--hb-bg-inset, #EEEEEE); border-radius: 5px; flex: none; }
        .hb-mlw__tab { flex: 1 1 0; border: 0; border-radius: 4px; background: transparent; cursor: pointer;
            font-family: inherit; font-size: var(--hb-fs-xs, 11px); font-weight: 300; color: var(--hb-text-secondary, #5A5A5A); }
        .hb-mlw__tab.is-on { background: var(--hb-bg, #fff); color: var(--hb-accent, #000); font-weight: 400; box-shadow: 0 1px 2px rgba(0,0,0,.08); }
        .hb-mlw__close { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 32px;
            border: 0; background: none; cursor: pointer; color: var(--hb-text-secondary, #5A5A5A); border-radius: var(--hb-radius-lg, 8px); flex: none; }
        .hb-mlw__close:hover { background: var(--hb-surface-hover, #F7F7F7); color: var(--hb-text-primary, #0A0A0A); }
        .hb-mlw__body { flex: 1 1 auto; min-height: 0; overflow: auto; }
        .hb-mlw__body--upload { display: flex; align-items: center; justify-content: center; padding: var(--hb-space-4, 16px); min-height: 420px; }
        .hb-mlw__body--library { padding: var(--hb-space-6, 24px); display: flex; flex-direction: column; gap: var(--hb-space-5, 20px); }
        .hb-mlw__drop { position: relative; display: block; width: 100%; max-width: 700px; cursor: pointer; }
        .hb-mlw__file { position: absolute; width: 1px; height: 1px; opacity: 0; overflow: hidden; }
        .hb-mlw__err { font-size: var(--hb-fs-sm, 12px); color: var(--hb-danger, #D4191A); }
        .hb-mlw__search { display: flex; align-items: center; gap: var(--hb-space-2, 8px); height: 36px; padding: 0 10px;
            border-bottom: 1px solid var(--hb-border, #E4E4E4); }
        .hb-mlw__search:focus-within { border-bottom-color: var(--hb-border-focus, #000); }
        .hb-mlw__search-ic { display: inline-flex; width: 13px; height: 13px; color: var(--hb-text-muted, #9A9A9A); flex: none; }
        .hb-mlw__search input { flex: 1 1 auto; min-width: 0; border: 0; outline: 0; background: transparent;
            font-family: inherit; font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-primary, #0A0A0A); }
        .hb-mlw__search input::placeholder { color: var(--hb-text-muted, #9A9A9A); }
        .hb-mlw__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: var(--hb-space-5, 20px); align-content: start; }
        .hb-mlw__empty { padding: 48px 0; text-align: center; color: var(--hb-text-muted, #9A9A9A); font-size: var(--hb-fs-base, 13px); }
        /* CSS spinner for the optimistic uploading cards (mirrors the card's spinner) */
        .hb-mlw__spin { width: 32px; height: 32px; border-radius: 50%; border: 3px solid var(--hb-bg-inset, #EEEEEE);
            border-top-color: var(--hb-text-muted, #9A9A9A); animation: hb-mediacard-spin 1s linear infinite; }
    </style>
    @endonce

    <div class="hb-mlw__top">
        <span class="hb-mlw__title">Select Featured Image</span>
        <div class="hb-mlw__tabs" role="tablist">
            <button type="button" role="tab" class="hb-mlw__tab" :class="{ 'is-on': tab === 'upload' }"
                x-on:click="tab = 'upload'">Upload Files</button>
            <button type="button" role="tab" class="hb-mlw__tab" :class="{ 'is-on': tab === 'library' }"
                x-on:click="tab = 'library'">Media Library</button>
        </div>
        <button type="button" class="hb-mlw__close" aria-label="Close">
            @include('heisenberg::components.ui.icon', ['name' => 'x', 'size' => 16])
        </button>
    </div>

    {{-- Upload tab: just the dropzone. Progress now shows per-file in the library. --}}
    <div class="hb-mlw__body hb-mlw__body--upload" x-show="tab === 'upload'" x-cloak>
        <label class="hb-mlw__drop">
            <input type="file" class="hb-mlw__file" wire:model="uploads" multiple
                x-on:change="pending = [...$event.target.files].map(f => ({ name: f.name, progress: 0 })); tab = 'library'">
            <x-live.media.upload-dropzone />
        </label>
    </div>

    {{-- Library tab: search + grid. Optimistic uploading cards (Alpine, wire:ignore
         so morphs don't touch them) sit above the real grid and clear on finish. --}}
    <div class="hb-mlw__body hb-mlw__body--library" x-show="tab === 'library'">
        <div class="hb-mlw__search">
            <span class="hb-mlw__search-ic" aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'magnifying-glass', 'size' => 13])</span>
            <input type="search" placeholder="Search media items…" wire:model.live.debounce.300ms="search">
        </div>

        @if ($error)<div class="hb-mlw__err">{{ $error }}</div>@endif

        <div class="hb-mlw__grid" wire:ignore x-show="pending.length" x-cloak>
            <template x-for="(f, i) in pending" :key="'pending-' + i">
                <div class="hb-mediacard hb-mediacard--uploading">
                    <div class="hb-mediacard__thumb"><span class="hb-mlw__spin" aria-hidden="true"></span></div>
                    <div class="hb-mediacard__name" x-text="f.name"></div>
                    <div class="hb-mediacard__progress">
                        <div class="hb-mediacard__track"><div class="hb-mediacard__fill" :style="`width: ${f.progress}%`"></div></div>
                        <div class="hb-mediacard__status" x-text="`Uploading… ${f.progress}%`"></div>
                    </div>
                </div>
            </template>
        </div>

        @if ($files->count())
            <div class="hb-mlw__grid">
                @foreach ($files as $file)
                    <x-live.media.media-card
                        wire:key="media-{{ $file->id }}"
                        wire:click="select({{ $file->id }})"
                        :state="$selectedId === $file->id ? 'selected' : 'default'"
                        :name="$file->original_name"
                        :meta="$file->human_size . ' • ' . $file->created_at->format('M j, Y')"
                        :thumb="$file->thumbnail_url ?: $file->url" />
                @endforeach
            </div>
        @else
            <div class="hb-mlw__empty" x-show="pending.length === 0">No media yet — upload a file to get started.</div>
        @endif
    </div>
</div>
