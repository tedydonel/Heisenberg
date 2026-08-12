    <div class="hb-inspector__block-content" data-hb-inspector-block-content @if ($panelActiveIndex !== 1) hidden @endif>
        <div class="hb-inspector__header">
            <div class="hb-inspector__title-row">
                {{-- The frozen block-runtime.blade.php's own updateInspector() already syncs
                     .hb-inspector__name/.hb-inspector__desc by class selector on every selection
                     (see select() -> updateInspector()) — this file doesn't duplicate that. It
                     does NOT sync the icon, though (it only ever touches the two text nodes), and
                     the icon SVG can only be resolved server-side (@include(...icon...)), so —
                     same pattern as the Content/Style/Advanced panels below — every registered
                     block's icon is pre-rendered once here, hidden, and the script at the bottom
                     of this file shows the one matching the selection. --}}
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

        {{-- Nothing selected yet (initial load, or the Block tab opened by hand with no
             selection) — shown by default; hb:block-selected/hb:block-deselected below toggle
             this against .hb-inspector__populated. --}}
        <div class="hb-inspector__empty" data-hb-block-empty>
            <p>{{ __('heisenberg::editor.common.no_block_empty_panel') }}</p>
        </div>

        <div class="hb-inspector__populated" data-hb-block-populated hidden>
            <x-ui.sub-tabs :items="$subTabs" :active-index="$subActiveIndex" />
            <div class="hb-inspector__body" data-hb-inspector-body>
                {{-- One instance of each panel per registered block type, pre-rendered from its
                     real contract (controls/panels/attributeDefinitions — see
                     BlockViewData::clientBlocks()). All but the selected block's are hidden;
                     see this file's docblock for why the body is built this way instead of from
                     a JS-templated string. --}}
                <div data-hb-subpanel="content" data-hb-subpanel-content>
                    @foreach ($registry as $hbRegBlockName => $hbRegBlockContract)
                        @php
                            // live/block/content already takes the shape it needs — a list of
                            // {key,label,type,value,options} fields. Build it from the contract's
                            // own `settings`-section controls (BlockRegistryService::deriveControls)
                            // rather than re-implementing the component around a different shape.
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
                        {{-- live/block/style-panel already gates each section on the contract's
                             `supports` map, which is exactly what it was designed to do — it needs
                             the real supports passed in, nothing more. --}}
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

                {{-- One track per sub-tab, mounted as SIBLINGS of the scroll regions rather than
                     inside them. An absolutely-positioned track inside its own scroll container is
                     anchored to the scrolling content, so `top: 0` scrolls up out of view the moment
                     you scroll — the track has to live in a positioned, non-scrolling parent
                     (.hb-inspector__body). Same arrangement panel-navigator uses. Only the active
                     sub-tab's container has a non-zero height, so only its track ever shows. --}}
                <x-ui.custom-scrollbar container="[data-hb-subpanel-content]" />
                <x-ui.custom-scrollbar container="[data-hb-subpanel-style]" />
                <x-ui.custom-scrollbar container="[data-hb-subpanel-advanced]" />
            </div>
        </div>
    </div>
