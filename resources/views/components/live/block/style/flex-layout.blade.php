{{-- live/block/style/flex-layout -- the EXTRACTED Pencil composition, wired (2026-08-06).
     Restored from its pre-wiring markup after a rework wrongly replaced the extracted UI
     with generic selects (house rule: wire the extracted composition, never substitute it):
       - the segmented (wrap / column / row) is the flex MODE -- wrap means direction=row +
         flex-wrap=wrap; column/row set that direction with wrap off. Two model paths from
         one control, so it commits through inspector.blade.php's [data-hb-style-flexmode]
         handler rather than data-hb-control.
       - the 3x3 dot grid picks justify (columns) x align (rows) in ONE gesture and writes
         both paths; clicking the active dot clears them. Rows/columns are start/center/end
         (align "stretch" is the unset default, exactly as CSS has it).
       - the radio column is justify's spacing mode: the Gap row (packed -- justify comes
         from the grid) and Space Between / Space Around.
     Per-feature gating on the contract's supports.layout map (absent/empty = render all). --}}
@props(['layout' => null])
@php
    $hbFlexOn = fn (string $key): bool => $layout === null || $layout === [] || (($layout[$key] ?? false) === true);
    $hbFlexAxis = ['start', 'center', 'end'];
@endphp
<x-ui.panel-section title="Flex Layout">
    @if ($hbFlexOn('direction'))
        <x-ui.segmented data-hb-style-flexmode :active-index="null" :items="[
            ['value' => 'wrap', 'icon' => 'squares-four'],
            ['value' => 'column', 'icon' => 'arrow-down'],
            ['value' => 'row', 'icon' => 'arrow-right'],
        ]" />
    @endif
    <div class="hb-irow hb-irow--top" style="gap:14px;">
        @if ($hbFlexOn('justify') || $hbFlexOn('align'))
            <div class="hb-icol" style="flex:none;">
                <span class="hb-ilbl">Alignment</span>
                <div class="hb-agrid" role="group" aria-label="Align content" data-hb-style-alignment-grid>
                    @foreach ($hbFlexAxis as $alignValue)
                        <div class="hb-agrid__row">
                            @foreach ($hbFlexAxis as $justifyValue)
                                <button type="button" class="hb-agrid__dot"
                                    aria-label="justify {{ $justifyValue }}, align {{ $alignValue }}" aria-pressed="false"
                                    data-hb-style-alignment
                                    data-hb-flex-justify="{{ $justifyValue }}"
                                    data-hb-flex-align="{{ $alignValue }}"></button>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
        <div class="hb-icol">
            <span class="hb-ilbl">Gap</span>
            @if ($hbFlexOn('gap'))
                <div class="hb-iradio hb-iradio--on is-active" role="radio" aria-checked="true" tabindex="0" data-hb-style-radio data-hb-flex-spacing="packed">
                    <span class="hb-iradio__dot hb-iradio__dot--on"></span>
                    <x-ui.field icon="arrows-horizontal" value="0" data-hb-control="layout.gap" data-hb-control-kind="supports" data-hb-control-type="text" />
                </div>
            @endif
            @if ($hbFlexOn('justify'))
                <div class="hb-iradio" role="radio" aria-checked="false" tabindex="0" data-hb-style-radio data-hb-flex-spacing="space-between"><span class="hb-iradio__dot"></span><span class="hb-iradio__label">Space Between</span></div>
                <div class="hb-iradio" role="radio" aria-checked="false" tabindex="0" data-hb-style-radio data-hb-flex-spacing="space-around"><span class="hb-iradio__dot"></span><span class="hb-iradio__label">Space Around</span></div>
            @endif
        </div>
    </div>
</x-ui.panel-section>
