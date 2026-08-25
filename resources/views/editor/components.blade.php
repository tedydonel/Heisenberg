<?php
$socialOptions = [
    ['value' => 'a', 'label' => 'Option A'],
    ['value' => 'b', 'label' => 'Option B'],
    ['value' => 'c', 'label' => 'Option C'],
    ['value' => 'd', 'label' => 'Option D'],
];
$tabItems = [
    ['value' => 'default', 'label' => 'Default'],
    ['value' => 'hover', 'label' => 'Hover'],
    ['value' => 'active', 'label' => 'Active'],
    ['value' => 'focus', 'label' => 'Focus'],
];
$panelTabItems = [
    ['value' => 'seo', 'label' => 'SEO'],
    ['value' => 'social', 'label' => 'Social'],
];
$subTabItems = [
    ['value' => 'content', 'icon' => 'browsers-fill', 'label' => 'Content'],
    ['value' => 'style', 'icon' => 'paint-brush-fill', 'label' => 'Style'],
    ['value' => 'advanced', 'icon' => 'gear-fill', 'label' => 'Advanced'],
];
$segmentedItems = array_map(fn ($i) => ['value' => $i, 'icon' => 'square', 'label' => "Segment $i"], range(0, 5));
$mediaItems = [
    ['name' => 'Screenshot 2026-06-01.png', 'meta' => '202.7 KB • Jul 15, 2026'],
    ['name' => 'Travelers.png', 'meta' => '207.5 KB • Jul 12, 2026'],
    ['name' => 'Address Details.png', 'meta' => '215.7 KB • Jul 12, 2026'],
    ['name' => 'Contact Details.png', 'meta' => '234.7 KB • Jul 12, 2026'],
    ['name' => 'Visa Photo.jpg', 'state' => 'uploading', 'progress' => 42, 'status' => 'Uploading… 42%'],
    ['name' => 'Passport Scan.pdf', 'state' => 'uploading', 'progress' => 78, 'status' => 'Uploading… 78%'],
];
$headingSupports = [
    'align' => ['left', 'center', 'right'],
    'color' => ['text' => true, 'background' => true],
    'typography' => ['fontFamily' => true, 'fontWeight' => true, 'fontSize' => true, 'lineHeight' => true],
    'size' => ['width' => true, 'height' => true],
    'spacing' => ['padding' => ['top' => true]],
    'border' => ['style' => true, 'width' => true, 'color' => true, 'radius' => ['topLeft' => true]],
];
$containerSupports = [
    'layout' => ['direction' => true, 'gap' => true, 'padding' => true],
    'size' => ['width' => true, 'height' => true],
    'border' => ['radius' => ['topLeft' => true]],
];
$headingSettings = [
    ['label' => 'Size', 'type' => 'select', 'value' => '2', 'options' => [
        ['value' => '1', 'label' => 'Heading 1'], ['value' => '2', 'label' => 'Heading 2'],
        ['value' => '3', 'label' => 'Heading 3'], ['value' => '4', 'label' => 'Heading 4'],
    ]],
    ['label' => 'Text', 'type' => 'textarea', 'value' => 'Next generation website builder'],
];
$headingClasses = ['heading', 'mb-4', 'display-1', 'aos-init', 'aos-animate'];
$inspStyle = 'width:272px; border:1px solid var(--hb-border); border-radius:8px; overflow:hidden; background:var(--hb-bg);';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Heisenberg Editor Components</title>
    <link rel="stylesheet" href="/heisenberg-assets/editor.css">
</head>
<body class="hb-preview">
    <main class="hb-preview__main">
        <header>
            <p class="hb-preview__eyebrow">Editor UI</p>
            <h1 class="hb-preview__title">Component library</h1>
        </header>

        <section class="hb-preview__grid">
            <article class="hb-preview__card">
                <h2 class="hb-preview__card-title">Button</h2>
                <div class="hb-preview__card-body" style="gap:8px;">
                    <x-ui.button variant="primary" leading-icon="plus">Save</x-ui.button>
                    <x-ui.button variant="secondary">Cancel</x-ui.button>
                    <x-ui.button variant="ghost">Ghost</x-ui.button>
                    <x-ui.button variant="primary" :disabled="true">Disabled</x-ui.button>
                </div>
            </article>

            <article class="hb-preview__card">
                <h2 class="hb-preview__card-title">Icon Button</h2>
                <div class="hb-preview__card-body" style="gap:8px;">
                    <x-ui.icon-button icon="align-left" label="Align left" />
                    <x-ui.icon-button icon="align-center" label="Align center" :active="true" />
                </div>
            </article>

            <article class="hb-preview__card">
                <h2 class="hb-preview__card-title">Select</h2>
                <div class="hb-preview__card-body">
                    <x-ui.select :options="$socialOptions" value="b" />
                </div>
            </article>

            <article class="hb-preview__card">
                <h2 class="hb-preview__card-title">Input / Field / Number Field</h2>
                <div class="hb-preview__card-body" style="gap:8px; flex-wrap:wrap;">
                    <x-ui.input value="Value" :show-chevron="true" />
                    <x-ui.field prefix="X" value="0" unit="px" :chevron="true" />
                    <x-ui.number-field value="100" />
                </div>
            </article>

            <article class="hb-preview__card">
                <h2 class="hb-preview__card-title">Field Label / Text Area</h2>
                <div class="hb-preview__card-body" style="flex-direction:column; align-items:flex-start; gap:6px;">
                    <x-ui.field-label>Label</x-ui.field-label>
                    <x-ui.text-area />
                </div>
            </article>

            <article class="hb-preview__card">
                <h2 class="hb-preview__card-title">Checkbox / Radio / Toggle</h2>
                <div class="hb-preview__card-body" style="flex-direction:column; align-items:flex-start; gap:8px;">
                    <x-ui.checkbox label="Checked" :checked="true" />
                    <x-ui.checkbox label="Indeterminate" :indeterminate="true" />
                    <x-ui.checkbox label="Unchecked" />
                    <x-ui.radio name="demo-radio" label="Selected" :selected="true" />
                    <x-ui.radio name="demo-radio" label="Unselected" />
                    <x-ui.toggle :on="true" />
                </div>
            </article>

            <article class="hb-preview__card">
                <h2 class="hb-preview__card-title">Tabs (pill)</h2>
                <div class="hb-preview__card-body">
                    <x-ui.tabs :items="$tabItems" :active-index="0" />
                </div>
            </article>

            <article class="hb-preview__card">
                <h2 class="hb-preview__card-title">Tab Item / Panel Tabs</h2>
                <div class="hb-preview__card-body" style="flex-direction:column; align-items:flex-start; gap:10px;">
                    <div style="display:flex; gap:6px;">
                        <x-ui.tab-item label="Tab" :active="true" />
                        <x-ui.tab-item label="Tab" />
                    </div>
                    <x-ui.panel-tabs :items="$panelTabItems" :active-index="0" />
                </div>
            </article>

            <article class="hb-preview__card">
                <h2 class="hb-preview__card-title">Sub-Tabs / Segmented</h2>
                <div class="hb-preview__card-body" style="flex-direction:column; align-items:stretch; gap:10px;">
                    <x-ui.sub-tabs :items="$subTabItems" :active-index="1" />
                    <x-ui.segmented :items="$segmentedItems" :active-index="0" />
                </div>
            </article>

            <article class="hb-preview__card">
                <h2 class="hb-preview__card-title">Swatch / Slider</h2>
                <div class="hb-preview__card-body" style="gap:16px;">
                    <x-ui.swatch color="#3D68F5" />
                    <x-ui.swatch color="rgba(61,104,245,0.4)" />
                    <x-ui.slider value="60" />
                </div>
            </article>

            <article class="hb-preview__card">
                <h2 class="hb-preview__card-title">Badge / Status Tag / Chip</h2>
                <div class="hb-preview__card-body" style="gap:8px; flex-wrap:wrap;">
                    <x-ui.badge label="Badge" />
                    <x-ui.badge label="Accent" variant="accent" />
                    <x-ui.status-tag label="Active" status="success" />
                    <x-ui.status-tag label="Error" status="danger" />
                    <x-ui.chip label="Chip" />
                </div>
            </article>

            <article class="hb-preview__card">
                <h2 class="hb-preview__card-title">Divider</h2>
                <div class="hb-preview__card-body">
                    <x-ui.divider />
                </div>
            </article>

            <article class="hb-preview__card">
                <h2 class="hb-preview__card-title">Nav Item / Search Field</h2>
                <div class="hb-preview__card-body" style="flex-direction:column; align-items:stretch; gap:8px;">
                    <x-ui.nav-item icon="house-fill" label="Home" :active="true" />
                    <x-ui.nav-item icon="circle" label="Menu Item" />
                    <x-ui.search-field placeholder="Search components…" />
                </div>
            </article>

            <article class="hb-preview__card">
                <h2 class="hb-preview__card-title">Category Head / Suggestion Row</h2>
                <div class="hb-preview__card-body" style="flex-direction:column; align-items:stretch; gap:8px;">
                    <x-ui.category-head label="Base" />
                    <x-ui.suggestion-row label="Suggestion" />
                </div>
            </article>

            <article class="hb-preview__card">
                <h2 class="hb-preview__card-title">Status Check Row</h2>
                <div class="hb-preview__card-body" style="flex-direction:column; align-items:stretch; gap:8px;">
                    <x-ui.status-check-row status="pass" text="Check item passed" />
                    <x-ui.status-check-row status="fail" text="Check item failed" />
                </div>
            </article>

            <article class="hb-preview__card">
                <h2 class="hb-preview__card-title">Social Preview Row</h2>
                <div class="hb-preview__card-body">
                    <x-ui.social-preview-row logo="facebook-logo-bold" label="Facebook" />
                </div>
            </article>

            <article class="hb-preview__card">
                <h2 class="hb-preview__card-title">Tool Card / Theme Preset Card</h2>
                <div class="hb-preview__card-body" style="gap:8px;">
                    <div style="width:96px;"><x-ui.tool-card icon="sparkle" label="Tool" /></div>
                    <div style="width:96px;"><x-ui.theme-preset-card :colors="['#FFFFFF', '#000000', '#0A0A0A']" label="Theme" /></div>
                </div>
            </article>

        </section>

        <section class="hb-preview__section" style="min-height:auto;">
            <h2 class="hb-preview__section-title">Block inspector — Style tab gates on the block's capabilities</h2>
            <p class="hb-preview__section-note">Same panel, two block types. A <b>text block</b> (heading — supports typography/color/align/size/border) shows Typography, Fill, Alignment; a <b>container</b> (supports layout/size/border) shows Flex Layout instead. Each section renders only when the block contract declares the matching <code>supports.*</code>.</p>
            <div style="display:flex; gap:24px; flex-wrap:wrap; margin-top:16px; align-items:flex-start;">
                <div>
                    <p class="hb-preview__card-title" style="margin-bottom:8px;">Text block (heading)</p>
                    <div style="{{ $inspStyle }}">
                        <x-ui.sub-tabs :items="$subTabItems" :active-index="1" />
                        <x-live.block.style-panel :supports="$headingSupports" />
                    </div>
                </div>
                <div>
                    <p class="hb-preview__card-title" style="margin-bottom:8px;">Container</p>
                    <div style="{{ $inspStyle }}">
                        <x-ui.sub-tabs :items="$subTabItems" :active-index="1" />
                        <x-live.block.style-panel :supports="$containerSupports" />
                    </div>
                </div>
            </div>
        </section>

        <section class="hb-preview__section" style="min-height:auto;">
            <h2 class="hb-preview__section-title">Block.content · Block.advanced</h2>
            <div style="display:flex; gap:24px; flex-wrap:wrap; margin-top:16px; align-items:flex-start;">
                <div>
                    <p class="hb-preview__card-title" style="margin-bottom:8px;">Content</p>
                    <div style="{{ $inspStyle }}">
                        <x-ui.sub-tabs :items="$subTabItems" :active-index="0" />
                        <x-live.block.content block-title="Heading" :settings="$headingSettings" :classes="$headingClasses" />
                    </div>
                </div>
                <div>
                    <p class="hb-preview__card-title" style="margin-bottom:8px;">Advanced</p>
                    <div style="{{ $inspStyle }}">
                        <x-ui.sub-tabs :items="$subTabItems" :active-index="2" />
                        <x-live.block.advanced />
                    </div>
                </div>
            </div>
        </section>

        <section class="hb-preview__section" style="min-height:auto;">
            <h2 class="hb-preview__section-title">Block toolbar — gates on the block's capabilities</h2>
            <p class="hb-preview__section-note">Like the inspector, the toolbar loads only the groups a block can use. A <b>text block</b> gets Format + text-colour + Align + AI; an <b>image</b> (no rich-text / colour / align) gets just handle · save · more. Its type-pill opens the block-type dropdown.</p>
            <div style="display:flex; flex-direction:column; gap:16px; margin-top:16px; align-items:flex-start;">
                <div><p class="hb-preview__section-note" style="margin-bottom:6px;">Text block</p><x-live.toolbar.block-toolbar :rich-text="true" :supports="$headingSupports" block-type="Heading" /></div>
                <div><p class="hb-preview__section-note" style="margin-bottom:6px;">Image block</p><x-live.toolbar.block-toolbar :rich-text="false" :supports="['size' => ['width' => true], 'border' => ['radius' => true]]" block-type="Image" /></div>
                <div style="margin-top:8px;"><p class="hb-preview__section-note" style="margin-bottom:6px;">Block-type dropdown</p><x-live.toolbar.type-menu selected="Text" /></div>
            </div>
        </section>

        <section class="hb-preview__section" style="min-height:auto;">
            <h2 class="hb-preview__section-title">Custom color picker</h2>
            <p class="hb-preview__section-note">The custom picker (<code>UluZm</code>/<code>Dcq6M</code>): Fill (SV square, hue + alpha sliders, eyedropper, RGBA model + inputs) and Gradient (type/angle/reverse, gradient bar, stops).</p>
            <div style="display:flex; gap:24px; flex-wrap:wrap; margin-top:16px; align-items:flex-start;">
                <x-live.pickers.color-picker mode="fill" />
                <x-live.pickers.color-picker mode="gradient" />
            </div>
        </section>

        <section class="hb-preview__section" style="min-height:auto;">
            <h2 class="hb-preview__section-title">Popovers — drop shadow · variable pickers</h2>
            <div style="display:flex; gap:24px; flex-wrap:wrap; margin-top:16px; align-items:flex-start;">
                <x-live.pickers.effect-editor />
                <x-live.pickers.variable-menu mode="color" selected="border" />
                <x-live.pickers.variable-menu mode="number" selected="radius-md" />
            </div>
        </section>

        <section class="hb-preview__section" style="min-height:auto;">
            <h2 class="hb-preview__section-title">Media — Card states</h2>
            <p class="hb-preview__section-note">Media Card (NVDCV) and its variants: default, hover, selected, uploading, error.</p>
            <div style="display:flex; gap:20px; flex-wrap:wrap; margin-top:16px;">
                <div style="width:200px;"><x-live.media.media-card name="Filename.png" meta="0 KB • Jan 1, 2026" /></div>
                <div style="width:200px;"><x-live.media.media-card state="hover" name="Hovered.png" meta="128 KB • Jul 12, 2026" /></div>
                <div style="width:200px;"><x-live.media.media-card state="selected" name="Selected.png" meta="128 KB • Jul 12, 2026" /></div>
                <div style="width:200px;"><x-live.media.media-card state="uploading" name="Visa Photo.jpg" :progress="42" /></div>
                <div style="width:200px;"><x-live.media.media-card state="error" name="Passport.pdf" /></div>
            </div>
        </section>

        <section class="hb-preview__section" style="min-height:auto;">
            <h2 class="hb-preview__section-title">Media — Upload dropzone</h2>
            <div style="margin-top:16px;"><x-live.media.upload-dropzone /></div>
        </section>

        <section class="hb-preview__section" style="min-height:auto;">
            <h2 class="hb-preview__section-title">Media Dialog — Upload Files</h2>
            <div style="margin-top:16px; overflow-x:auto;"><x-live.media.media-dialog tab="upload" /></div>
        </section>

        <section class="hb-preview__section" style="min-height:auto;">
            <h2 class="hb-preview__section-title">Media Dialog — Media Library</h2>
            <div style="margin-top:16px; overflow-x:auto;"><x-live.media.media-dialog tab="library" :items="$mediaItems" /></div>
        </section>

        <section class="hb-preview__section">
            <h2 class="hb-preview__section-title">Scrollbar</h2>
            <p class="hb-preview__section-note">Scroll this page to verify the custom scrollbar component.</p>
            @include('heisenberg::components.ui.custom-scrollbar')
        </section>
    </main>
</body>
</html>
