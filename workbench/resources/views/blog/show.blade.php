@extends('blog.layout', [
    'title' => $title,
    'themeCss' => $themeCss,
    'blocksCss' => $blocksCss,
    'stateCss' => $stateCss,
    'fontsHref' => $fontsHref,
])

@section('content')
    @if (! empty($breadcrumbs))
        <nav aria-label="Breadcrumb" data-wb-breadcrumbs style="font-size:13px;color:var(--muted);margin-bottom:16px;">
            @foreach ($breadcrumbs as $i => $crumb)
                @if ($i > 0)<span> / </span>@endif
                @if ($crumb['url'])<a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a>@else<span>{{ $crumb['label'] }}</span>@endif
            @endforeach
        </nav>
    @endif

    <h1 style="font-size:34px;margin:0 0 10px;line-height:1.15;">{{ $title }}</h1>

    @if ($readingTime)
        <p data-wb-reading-time style="color:var(--muted);font-size:13px;margin:0 0 20px;">{{ $readingTime }}</p>
    @endif

    @if ($featured)
        <figure data-wb-featured style="margin:0 0 28px;">
            <img src="{{ $featured['url'] }}" alt="{{ $featured['alt'] ?? '' }}"
                 style="display:block;width:100%;max-height:420px;object-fit:cover;border-radius:var(--r-md);">
        </figure>
    @endif

    @if (! empty($toc))
        <nav aria-label="Table of contents" data-wb-toc
             style="background:var(--subtle);border:1px solid var(--line);border-radius:var(--r-md);padding:16px 20px;margin:0 0 28px;">
            <p style="margin:0 0 8px;font-weight:600;font-size:13px;text-transform:uppercase;letter-spacing:.04em;">{{ $tocTitle }}</p>
            <ol style="margin:0;padding-left:18px;">
                @foreach ($toc as $entry)
                    <li><a href="#{{ $entry['anchor'] }}">{{ $entry['label'] }}</a></li>
                @endforeach
            </ol>
        </nav>
    @endif

    <div data-wb-post-body>
        {!! $html !!}
    </div>

    @if (! empty($shareNetworks))
        <div data-wb-share style="margin:36px 0;padding-top:20px;border-top:1px solid var(--line);display:flex;gap:10px;flex-wrap:wrap;">
            <span style="font-size:13px;color:var(--muted);">Share:</span>
            @foreach ($shareNetworks as $network)
                <a href="#" data-network="{{ $network }}" class="wb-badge" style="text-decoration:none;">{{ $network }}</a>
            @endforeach
        </div>
    @endif

    @if ($authorBox)
        <div data-wb-author-box style="display:flex;gap:14px;align-items:center;background:var(--subtle);border:1px solid var(--line);border-radius:var(--r-md);padding:16px 20px;margin:20px 0;">
            <div style="width:44px;height:44px;border-radius:50%;background:var(--brand);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;">W</div>
            <div>
                <p style="margin:0;font-weight:600;">{{ $authorBox['name'] }}</p>
                <p style="margin:0;color:var(--muted);font-size:13px;">{{ $authorBox['bio'] }}</p>
            </div>
        </div>
    @endif

    @if ($related->isNotEmpty())
        <section data-wb-related style="margin-top:40px;">
            <h2 style="font-size:18px;">Related posts</h2>
            <ul style="padding-left:18px;">
                @foreach ($related as $relatedPost)
                    <li><a href="{{ url('/blog/' . $locale . '/' . $relatedPost->slug) }}">{{ $relatedPost->title($locale) }}</a></li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($comments)
        <section data-wb-comments style="margin-top:40px;padding-top:24px;border-top:1px solid var(--line);">
            <h2 style="font-size:20px;">Comments <span style="color:var(--muted);font-weight:400;">({{ $comments['count'] }})</span></h2>
            @forelse ($comments['items'] as $item)
                <div data-wb-comment style="padding:12px 0;border-bottom:1px solid var(--line);">
                    <strong>{{ $item['author_name'] }}</strong>
                    <p style="margin:4px 0 0;">{{ $item['body'] }}</p>
                </div>
            @empty
                <p style="color:var(--muted);">No comments yet.</p>
            @endforelse
        </section>
    @endif
@endsection
