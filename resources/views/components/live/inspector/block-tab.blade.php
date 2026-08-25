    <div class="hb-inspector__block-content" data-hb-inspector-block-content @if ($panelActiveIndex !== 1) hidden @endif>
        <div class="hb-inspector__header">
            <div class="hb-inspector__title-row">
                <span class="hb-inspector__icon" aria-hidden="true" data-hb-block-icon-default>
                    @include('heisenberg::components.ui.icon', ['name' => $blockIcon, 'size' => 22])
                </span>
                @foreach ($registry as $hbRegBlockName => $hbRegBlockContract)
                    <span class="hb-inspector__icon" aria-hidden="true" data-hb-block-icon="{{ $hbRegBlockName }}" hidden>
                        @include('heisenberg::components.ui.icon', ['name' => $hbRegBlockContract['icon'] ?? '', 'size' => 22])
                    </span>
                @endforeach
                <span class="hb-inspector__name">{{ $blockName }}</span>
            </div>
            <p class="hb-inspector__desc">{{ $blockDescription }}</p>
        </div>

        <div class="hb-inspector__empty" data-hb-block-empty>
            <p>{{ __('heisenberg::editor.common.no_block_empty_panel') }}</p>
        </div>

        <div class="hb-inspector__populated" data-hb-block-populated hidden>
            <x-ui.sub-tabs :items="$subTabs" :active-index="$subActiveIndex" />
            <div class="hb-inspector__body" data-hb-inspector-body>
                <div data-hb-subpanel="content" data-hb-subpanel-content>
                    @foreach ($registry as $hbRegBlockName => $hbRegBlockContract)
                        @php
                            $hbSettings = [];
                            foreach (($hbRegBlockContract['controls'] ?? []) as $hbCtl) {
                                if (($hbCtl['section'] ?? 'settings') !== 'settings') {
                                    continue;
                                }
                                $hbAttr = $hbCtl['attribute'] ?? ($hbCtl['id'] ?? '');
                                $hbSettings[] = [
                                    'key' => $hbAttr,
                                    'label' => $hbCtl['label'] ?? $hbAttr,
                                    'type' => $hbCtl['type'] ?? 'text',
                                    'value' => $hbRegBlockContract['attributes'][$hbAttr] ?? '',
                                    'options' => $hbCtl['options'] ?? [],
                                ];
                            }
                        @endphp
                        <div data-hb-block-panel="{{ $hbRegBlockName }}" hidden>
                            <x-live.block.content
                                :block-title="$hbRegBlockContract['title'] ?? 'Block'"
                                :settings="$hbSettings"
                            />
                        </div>
                    @endforeach
                </div>
                <div data-hb-subpanel="style" data-hb-subpanel-style hidden>
                    @foreach ($registry as $hbRegBlockName => $hbRegBlockContract)
                        <div data-hb-block-panel="{{ $hbRegBlockName }}" hidden>
                            <x-live.block.style-panel :supports="$hbRegBlockContract['supports'] ?? []" :theme="$theme" :inner-blocks="$hbRegBlockContract['innerBlocks'] ?? []" />
                        </div>
                    @endforeach
                </div>
                <div data-hb-subpanel="advanced" data-hb-subpanel-advanced hidden>
                    @foreach ($registry as $hbRegBlockName => $hbRegBlockContract)
                        <div data-hb-block-panel="{{ $hbRegBlockName }}" hidden>
                            <x-live.block.advanced />
                        </div>
                    @endforeach
                </div>

                <x-ui.custom-scrollbar container="[data-hb-subpanel-content]" />
                <x-ui.custom-scrollbar container="[data-hb-subpanel-style]" />
                <x-ui.custom-scrollbar container="[data-hb-subpanel-advanced]" />
            </div>
        </div>
    </div>
