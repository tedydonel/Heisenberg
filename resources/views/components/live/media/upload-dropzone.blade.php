@once
<style nonce="{{ heisenberg_csp_nonce() }}">
    .hb-dropzone {
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        gap: var(--hb-space-3, 12px); width: 100%; max-width: 700px; margin: 0 auto;
        padding: var(--hb-space-8, 32px);
        background: var(--hb-bg-muted);
        border: 1px solid var(--hb-border);
        border-radius: var(--hb-radius-lg, 8px);
        text-align: center; cursor: pointer;
        transition: border-color .12s ease, background-color .12s ease;
        font-family: var(--hb-font-sans, Rubik, sans-serif);
    }
    .hb-dropzone:hover, .hb-dropzone--drag { border-color: var(--hb-accent); background: var(--hb-surface-hover); }
    .hb-dropzone__icon { display: inline-flex; color: var(--hb-accent); }
    .hb-dropzone__title { font-size: var(--hb-fs-lg, 16px); font-weight: 600; color: var(--hb-text-primary); }
    .hb-dropzone__desc { font-size: var(--hb-fs-sm, 12px); color: var(--hb-text-secondary); max-width: 480px; }
</style>
@endonce

@props([
    'title' => null,
    'description' => null,
])
<div {{ $attributes->merge(['class' => 'hb-dropzone']) }} role="button" tabindex="0">
    <span class="hb-dropzone__icon" aria-hidden="true">@include('heisenberg::components.ui.icon', ['name' => 'cloud-arrow-up', 'size' => 56])</span>
    <div class="hb-dropzone__title">{{ $title ?? __('heisenberg::editor.media.dropzone_title') }}</div>
    <div class="hb-dropzone__desc">{{ $description ?? __('heisenberg::editor.media.dropzone_desc') }}</div>
</div>
