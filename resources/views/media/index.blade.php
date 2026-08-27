<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('heisenberg::editor.media.tab_library') }}</title>
    <style nonce="{{ heisenberg_csp_nonce() }}">
        * { box-sizing: border-box; }
        body { margin: 0; background: #fafafa; color: #0a0a0a; font: 14px/1.5 Rubik, -apple-system, sans-serif; }
        .hb-media-page { max-width: 1040px; margin: 0 auto; padding: 32px 24px 64px; }
        .hb-media-page__head { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 20px; }
        .hb-media-page__head h1 { font-size: 20px; margin: 0; }
        .hb-media-page__filters { display: flex; gap: 8px; }
        .hb-media-page__filters input, .hb-media-page__filters select {
            height: 32px; padding: 0 10px; border: 1px solid #e4e4e4; border-radius: 5px; background: #fff; font: inherit;
        }
        .hb-media-page__filters button { height: 32px; padding: 0 14px; border: 0; border-radius: 5px; background: #0a0a0a; color: #fff; font: inherit; cursor: pointer; }
        .hb-media-page__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 16px; }
        .hb-media-page__card { background: #fff; border: 1px solid #e4e4e4; border-radius: 5px; overflow: hidden; }
        .hb-media-page__thumb { display: flex; align-items: center; justify-content: center; aspect-ratio: 4 / 3; background: #f4f4f4; color: #9a9a9a; font-weight: 600; letter-spacing: .04em; }
        .hb-media-page__thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .hb-media-page__meta { padding: 8px 10px; }
        .hb-media-page__name { font-size: 12px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .hb-media-page__sub { font-size: 11px; color: #9a9a9a; }
        .hb-media-page__empty { padding: 64px 0; text-align: center; color: #9a9a9a; }
        .hb-media-page__pager { display: flex; justify-content: center; gap: 12px; margin-top: 24px; }
        .hb-media-page__pager a { color: #0a0a0a; }
    </style>
</head>
<body>
    <div class="hb-media-page">
        <div class="hb-media-page__head">
            <h1>{{ __('heisenberg::editor.media.tab_library') }}</h1>
            <form class="hb-media-page__filters" method="get">
                <input type="search" name="search" value="{{ request('search') }}"
                    placeholder="{{ __('heisenberg::editor.media.search_ph') }}">
                <select name="extension">
                    <option value="">—</option>
                    @foreach ($types as $type)
                        <option value="{{ $type }}" @selected(request('extension') === $type)>{{ strtoupper($type) }}</option>
                    @endforeach
                </select>
                <button type="submit">{{ __('heisenberg::editor.media.search_ph') }}</button>
            </form>
        </div>

        @if ($files->isEmpty())
            <div class="hb-media-page__empty">{{ __('heisenberg::editor.media.empty') }}</div>
        @else
            <div class="hb-media-page__grid">
                @foreach ($files as $file)
                    <div class="hb-media-page__card">
                        <div class="hb-media-page__thumb">
                            @if ($file->isImageType())
                                <img src="{{ $file->thumbnail_url }}" alt="{{ $file->getAlt(app()->getLocale()) }}">
                            @else
                                {{ strtoupper((string) $file->type) }}
                            @endif
                        </div>
                        <div class="hb-media-page__meta">
                            <div class="hb-media-page__name">{{ $file->original_name }}</div>
                            <div class="hb-media-page__sub">{{ $file->human_size }} · {{ $file->created_at?->format('M j, Y') }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="hb-media-page__pager">
                @if ($files->previousPageUrl())<a href="{{ $files->withQueryString()->previousPageUrl() }}">&larr;</a>@endif
                @if ($files->nextPageUrl())<a href="{{ $files->withQueryString()->nextPageUrl() }}">&rarr;</a>@endif
            </div>
        @endif
    </div>
</body>
</html>
