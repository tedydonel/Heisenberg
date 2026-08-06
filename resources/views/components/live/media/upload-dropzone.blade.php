{{-- live/media/upload-dropzone — centered cloud-arrow-up icon (56px, accent) + title +
     description, on bg-muted with a 1px border and radius-lg. --}}
@once
<style>
    .hb-dropzone {
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        gap: var(--hb-space-3, 12px); width: 100%; max-width: 700px; margin: 0 auto;
        padding: var(--hb-space-8, 32px);
        background: var(--hb-bg-muted, #F4F4F4);
        border: 1px solid var(--hb-border, #E4E4E4);
        border-radius: var(--hb-radius-lg, 8px);
        text-align: center; cursor: pointer;
        transition: border-color .12s ease, background-color .12s ease;
        font-family: var(--hb-font-sans, Rubik, sans-serif);
    }
    .hb-dropzone:hover, .hb-dropzone--drag { border-color: var(--hb-accent, #000); background: var(--hb-surface-hover, #F7F7F7); }
    .hb-dropzone__icon { display: inline-flex; color: var(--hb-accent, #000); }
    .hb-dropzone__title { font-size: var(--hb-fs-lg, 16px); font-weight: 600; color: var(--hb-text-primary, #0A0A0A); }
    .hb-dropzone__desc { font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-secondary, #5A5A5A); max-width: 480px; }
</style>
@endonce

@props([
    'title' => 'Drop files to upload',
    'description' => 'Images are optimized automatically. Documents are stored as originals. Maximum size: 10 MB each.',
])
<div {{ $attributes->merge(['class' => 'hb-dropzone']) }} role="button" tabindex="0">
    <span class="hb-dropzone__icon" aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'cloud-arrow-up', 'size' => 56])</span>
    <div class="hb-dropzone__title">{{ $title }}</div>
    <div class="hb-dropzone__desc">{{ $description }}</div>
</div>
