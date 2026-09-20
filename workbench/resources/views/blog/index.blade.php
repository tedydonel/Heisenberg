@extends('blog.layout', ['title' => 'Widgets Weekly — the demo blog'])

@section('content')
    <h1 style="font-size:32px;margin:0 0 8px;">Widgets Weekly</h1>
    <p style="color:var(--muted);margin:0 0 28px;">
        The workbench demo blog — {{ $posts->count() }} published post(s) in
        <strong>{{ strtoupper($locale) }}</strong>.
        <span class="wb-locale-switch" style="display:inline-flex;margin-left:8px;vertical-align:middle;">
            <a class="{{ $locale === 'en' ? 'wb-locale--active' : '' }}" href="{{ url('/blog?locale=en') }}">EN</a>
            <a class="{{ $locale === 'fr' ? 'wb-locale--active' : '' }}" href="{{ url('/blog?locale=fr') }}">FR</a>
        </span>
    </p>

    @forelse ($posts as $post)
        <article style="border-bottom:1px solid var(--line);padding:20px 0;display:flex;gap:20px;">
            @if ($post->featuredImage)
                <img src="{{ $post->featuredImage->thumbnail_url }}" alt="" width="120" height="80"
                     style="object-fit:cover;border-radius:var(--r-md);flex:0 0 120px;">
            @endif
            <div>
                <h2 style="margin:0 0 6px;font-size:20px;">
                    <a href="{{ url('/blog/' . $locale . '/' . $post->slug) }}" style="text-decoration:none;color:inherit;">
                        {{ $post->title($locale) }}
                    </a>
                </h2>
                <p style="margin:0 0 8px;color:var(--muted);">{{ $post->excerptText($locale) }}</p>
                <div style="font-size:12px;color:var(--muted);">
                    @foreach ($post->categories as $category)
                        <span class="wb-badge">{{ $locale === 'fr' ? $category->name_fr : $category->name_en }}</span>
                    @endforeach
                </div>
            </div>
        </article>
    @empty
        <p>No published posts in this locale yet — run <code>php vendor/bin/testbench demo:seed</code>.</p>
    @endforelse
@endsection
