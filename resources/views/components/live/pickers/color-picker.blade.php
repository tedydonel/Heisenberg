{{-- live/pickers/color-picker — Fill and Gradient picker.

     Structure note: the colour editor (SV square, hue/alpha sliders, model inputs) is NOT
     inside the Fill tab — it sits below both tabs, and edits the flat colour in Fill mode. It
     is HIDDEN in Gradient mode (32-pickers.css, `.hb-cp[data-cp-mode="gradient"] .hb-cp__editor`)
     rather than repurposed to edit the selected stop: a picker with its own gradient section
     showing a full second colour editor underneath it reads as one picker nested inside
     another. Clicking a stop's swatch instead opens a SEPARATE `standalone` instance of this
     same component (Fill only, no tabs, no gradient section) anchored to that stop — see the
     `standalone` prop below and script-fonts-and-style-events.blade.php's `gradientstopedit`
     listener, which owns the open/seed/write-back around it.

     Dropdowns are ui/select throughout (gradient type, radial shape, colour model) rather than
     the click-to-cycle buttons the first pass used — a 2-state toggle can't express 3 gradient
     types, and cycling hides the options from the user entirely.

     Public API on the root element (window-facing, used by the inspector):
       __hbCp.setHex(hex)          set the flat colour
       __hbCp.setGradient(spec)    { type, angle, shape, stops:[{position,color,opacity}] }
       __hbCp.setGradientCss(css)  seed from a stored `linear-gradient(...)` string; switches
                                   to Gradient mode. Returns false if the string is unparseable.
       __hbCp.getValue()           { mode, hex, rgba, gradient }
       __hbCp.setMode('fill'|'gradient')
     Events (bubbling): `colorchange` { r,g,b,a,hex,gradientStop }, `gradientchange`
     { css,type,angle,shape,stops }. Both signatures are unchanged from the first pass — the
     inspector's two listeners (inspector.blade.php:1082,1093) keep working as-is.

     `standalone`: a Fill-only instance with no Fill/Gradient tabs and no gradient section at
     all — the popup a gradient STOP opens (script-fonts-and-style-events.blade.php's
     `gradientstopedit` listener). Editing a stop's colour must never re-offer the gradient UI
     underneath it (that is the self-nesting this prop exists to avoid); the JS below already
     null-guards every gradient element lookup, so omitting that markup needs no script changes,
     only this one to skip rendering it. --}}
@props(['mode' => 'fill', 'value' => '#E3E3E3', 'standalone' => false])
@php
    $gradientTypes = [
        ['value' => 'linear', 'label' => __('heisenberg::editor.color_picker.type_linear')],
        ['value' => 'radial', 'label' => __('heisenberg::editor.color_picker.type_radial')],
        ['value' => 'conic', 'label' => __('heisenberg::editor.color_picker.type_conic')],
    ];
    $gradientShapes = [
        ['value' => 'circle', 'label' => __('heisenberg::editor.color_picker.shape_circle')],
        ['value' => 'ellipse', 'label' => __('heisenberg::editor.color_picker.shape_ellipse')],
    ];
    // Model names are notation, not prose — deliberately untranslated, like the R/G/B field
    // letters below them.
    $colorModels = [
        ['value' => 'hex', 'label' => 'HEX'],
        ['value' => 'rgb', 'label' => 'RGB'],
        ['value' => 'rgba', 'label' => 'RGBA'],
        ['value' => 'hsl', 'label' => 'HSL'],
        ['value' => 'hsla', 'label' => 'HSLA'],
        ['value' => 'hsb', 'label' => 'HSB'],
    ];
@endphp
<div class="hb-pop hb-cp @if ($standalone) hb-cp--standalone @endif" data-hb-colorpicker data-cp-value="{{ $value }}" data-cp-mode="{{ $standalone ? 'fill' : $mode }}" style="--hb-cp-hue: #f00;">
    @unless ($standalone)
    <div class="hb-cp__tabs">
        <x-ui.tabs :active-index="$mode === 'gradient' ? 1 : 0" :items="[
            ['value' => 'fill', 'label' => __('heisenberg::editor.color_picker.tab_fill')],
            ['value' => 'gradient', 'label' => __('heisenberg::editor.color_picker.tab_gradient')],
        ]" />
    </div>

    <div class="hb-cp__body" data-cp-body="gradient" @if ($mode !== 'gradient') hidden @endif>
        <div class="hb-cp__type-row">
            <x-ui.select
                class="hb-cp__select hb-cp__select--type"
                data-cp-gradient-type
                :options="$gradientTypes"
                value="linear"
                :aria-label="__('heisenberg::editor.color_picker.aria_gradient_type')"
            />
            <label class="hb-cp__angle" data-cp-angle-wrap>
                @include('heisenberg::components.ui.icon', ['name' => 'arrow-up-right', 'size' => 13])
                <input type="text" inputmode="numeric" value="90" aria-label="{{ __('heisenberg::editor.color_picker.aria_gradient_angle') }}" data-cp-gradient-angle>
                <span>&deg;</span>
            </label>
            <x-ui.select
                class="hb-cp__select hb-cp__select--shape"
                data-cp-gradient-shape
                :options="$gradientShapes"
                value="circle"
                :aria-label="__('heisenberg::editor.color_picker.aria_gradient_shape')"
                hidden
            />
            <button type="button" class="hb-cp__reverse" aria-label="{{ __('heisenberg::editor.color_picker.aria_reverse') }}" title="{{ __('heisenberg::editor.color_picker.aria_reverse') }}" data-cp-gradient-reverse>
                @include('heisenberg::components.ui.icon', ['name' => 'swap', 'size' => 14])
            </button>
        </div>

        <div class="hb-cp__gbar" data-cp-gradient-bar role="group" aria-label="{{ __('heisenberg::editor.color_picker.aria_stops') }}">
            <span class="hb-cp__gbar-ramp" data-cp-gradient-ramp></span>
        </div>

        <div class="hb-cp__stops-head">
            <span class="hb-cp__stops-title">{{ __('heisenberg::editor.color_picker.stops') }}</span>
            <span class="hb-cp__stops-actions">
                <button type="button" class="hb-cp__add" aria-label="{{ __('heisenberg::editor.color_picker.aria_distribute') }}" title="{{ __('heisenberg::editor.color_picker.aria_distribute') }}" data-cp-gradient-distribute>
                    @include('heisenberg::components.ui.icon', ['name' => 'arrows-out-line-horizontal', 'size' => 15])
                </button>
                <button type="button" class="hb-cp__add" aria-label="{{ __('heisenberg::editor.color_picker.aria_duplicate') }}" title="{{ __('heisenberg::editor.color_picker.aria_duplicate') }}" data-cp-gradient-duplicate>
                    @include('heisenberg::components.ui.icon', ['name' => 'copy', 'size' => 15])
                </button>
                <button type="button" class="hb-cp__add" aria-label="{{ __('heisenberg::editor.color_picker.aria_add_stop') }}" title="{{ __('heisenberg::editor.color_picker.aria_add_stop') }}" data-cp-gradient-add>
                    @include('heisenberg::components.ui.icon', ['name' => 'plus', 'size' => 16])
                </button>
            </span>
        </div>

        <div class="hb-cp__stops" data-cp-gradient-stops></div>

        <template data-cp-gradient-stop-template>
            <div class="hb-gsr" data-cp-gradient-stop-row>
                <button type="button" class="hb-gsr__sel" aria-label="{{ __('heisenberg::editor.color_picker.aria_select_stop') }}" data-cp-gradient-stop-select>
                    <span class="hb-gsr__sw" data-cp-gradient-stop-swatch></span>
                </button>
                <span class="hb-gsr__hexbox">
                    <input type="text" class="hb-gsr__hex" spellcheck="false" aria-label="{{ __('heisenberg::editor.color_picker.aria_stop_hex') }}" data-cp-gradient-stop-hex>
                </span>
                <span class="hb-gsr__op"><input type="text" inputmode="decimal" aria-label="{{ __('heisenberg::editor.color_picker.aria_stop_opacity') }}" data-cp-gradient-stop-opacity><span>%</span></span>
                <span class="hb-gsr__op hb-gsr__op--pos"><input type="text" inputmode="decimal" aria-label="{{ __('heisenberg::editor.color_picker.aria_stop_position') }}" data-cp-gradient-stop-position><span>%</span></span>
                <button type="button" class="hb-gsr__rm" aria-label="{{ __('heisenberg::editor.color_picker.aria_remove_stop') }}" data-cp-gradient-stop-remove>
                    @include('heisenberg::components.ui.icon', ['name' => 'minus', 'size' => 16])
                </button>
            </div>
        </template>
    </div>
    @endunless

    <div class="hb-cp__editor" data-cp-editor>
        <div class="hb-cp__editing" data-cp-editing hidden>
            <span class="hb-cp__editing-sw" data-cp-editing-swatch></span>
            <span class="hb-cp__editing-l" data-cp-editing-label></span>
        </div>
        <div class="hb-cp__sv" data-cp-sv><span class="hb-cp__sv-handle" data-cp-sv-handle></span></div>
        <div class="hb-cp__sliders">
            <div class="hb-cp__slider hb-cp__slider--hue" data-cp-hue><span class="hb-cp__slider-handle" data-cp-hue-handle></span></div>
            <div class="hb-cp__slider hb-cp__slider--alpha" data-cp-alpha><span class="hb-cp__slider-handle" data-cp-alpha-handle></span></div>
        </div>
        <div class="hb-cp__controls">
            <button type="button" class="hb-cp__eyedropper" aria-label="{{ __('heisenberg::editor.color_picker.aria_eyedropper') }}" title="{{ __('heisenberg::editor.color_picker.aria_eyedropper') }}" data-cp-eyedropper hidden>
                @include('heisenberg::components.ui.icon', ['name' => 'eyedropper', 'size' => 14])
            </button>
            <x-ui.select
                class="hb-cp__select hb-cp__select--model"
                data-cp-model
                :options="$colorModels"
                value="rgba"
                :aria-label="__('heisenberg::editor.color_picker.aria_model')"
            />
            <button type="button" class="hb-cp__eyedropper" aria-label="{{ __('heisenberg::editor.color_picker.aria_copy') }}" title="{{ __('heisenberg::editor.color_picker.aria_copy') }}" data-cp-copy>
                @include('heisenberg::components.ui.icon', ['name' => 'copy', 'size' => 14])
            </button>
        </div>
        <div class="hb-cp__inputs" data-cp-inputs></div>
    </div>

    @once
    <script>
        (() => {
            const clamp = (x, a, b) => (x < a ? a : x > b ? b : x);
            const round = (x) => Math.round(x);
            const toHex = (value) => clamp(round(Number(value) || 0), 0, 255).toString(16).padStart(2, '0');

            // Accepts 3, 4, 6 and 8 digit hex. Returns [r, g, b, a] with a in 0..1, or null when
            // unparseable — callers decide the fallback rather than getting a silent black.
            const parseHex = (value) => {
                const raw = String(value == null ? '' : value).trim().replace(/^#/, '');
                const expand = (s) => s.split('').map((c) => c + c).join('');
                let hex = null;
                if (/^[0-9a-f]{3}$/i.test(raw)) hex = expand(raw) + 'ff';
                else if (/^[0-9a-f]{4}$/i.test(raw)) hex = expand(raw);
                else if (/^[0-9a-f]{6}$/i.test(raw)) hex = raw + 'ff';
                else if (/^[0-9a-f]{8}$/i.test(raw)) hex = raw;
                if (!hex) return null;
                const n = parseInt(hex, 16);
                return [(n >>> 24) & 255, (n >>> 16) & 255, (n >>> 8) & 255, (n & 255) / 255];
            };

            function hsvToRgb(h, s, v) {
                h = (((h % 360) + 360) % 360) / 360;
                const i = Math.floor(h * 6), f = h * 6 - i, p = v * (1 - s), q = v * (1 - f * s), t = v * (1 - (1 - f) * s);
                let r, g, b;
                switch (i % 6) {
                    case 0: r = v; g = t; b = p; break; case 1: r = q; g = v; b = p; break;
                    case 2: r = p; g = v; b = t; break; case 3: r = p; g = q; b = v; break;
                    case 4: r = t; g = p; b = v; break; default: r = v; g = p; b = q;
                }
                return [round(r * 255), round(g * 255), round(b * 255)];
            }
            function rgbToHsv(r, g, b) {
                r /= 255; g /= 255; b /= 255;
                const mx = Math.max(r, g, b), mn = Math.min(r, g, b), d = mx - mn; let h = 0;
                if (d) { if (mx === r) h = ((g - b) / d) % 6; else if (mx === g) h = (b - r) / d + 2; else h = (r - g) / d + 4; h *= 60; if (h < 0) h += 360; }
                return [h, mx ? d / mx : 0, mx];
            }
            function rgbToHsl(r, g, b) {
                r /= 255; g /= 255; b /= 255;
                const mx = Math.max(r, g, b), mn = Math.min(r, g, b), d = mx - mn;
                const l = (mx + mn) / 2;
                let h = 0, s = 0;
                if (d) {
                    s = l > 0.5 ? d / (2 - mx - mn) : d / (mx + mn);
                    if (mx === r) h = ((g - b) / d) % 6; else if (mx === g) h = (b - r) / d + 2; else h = (r - g) / d + 4;
                    h *= 60; if (h < 0) h += 360;
                }
                return [h, s, l];
            }
            function hslToRgb(h, s, l) {
                h = ((h % 360) + 360) % 360;
                const c = (1 - Math.abs(2 * l - 1)) * s, x = c * (1 - Math.abs((h / 60) % 2 - 1)), m = l - c / 2;
                let r, g, b;
                if (h < 60) { r = c; g = x; b = 0; } else if (h < 120) { r = x; g = c; b = 0; }
                else if (h < 180) { r = 0; g = c; b = x; } else if (h < 240) { r = 0; g = x; b = c; }
                else if (h < 300) { r = x; g = 0; b = c; } else { r = c; g = 0; b = x; }
                return [round((r + m) * 255), round((g + m) * 255), round((b + m) * 255)];
            }

            // Field sets per colour model. `k` is the parse key, `l` the printed letter.
            const MODELS = {
                hex: [{ k: 'hex', l: 'HEX', wide: true }],
                rgb: [{ k: 'r', l: 'R' }, { k: 'g', l: 'G' }, { k: 'b', l: 'B' }],
                rgba: [{ k: 'r', l: 'R' }, { k: 'g', l: 'G' }, { k: 'b', l: 'B' }, { k: 'a', l: 'A' }],
                hsl: [{ k: 'hh', l: 'H' }, { k: 'sl', l: 'S' }, { k: 'll', l: 'L' }],
                hsla: [{ k: 'hh', l: 'H' }, { k: 'sl', l: 'S' }, { k: 'll', l: 'L' }, { k: 'a', l: 'A' }],
                hsb: [{ k: 'hh', l: 'H' }, { k: 'sb', l: 'S' }, { k: 'vb', l: 'B' }],
            };

            function init(root) {
                if (root.__hbCp) return;
                const q = (selector) => root.querySelector(selector);
                const sv = q('[data-cp-sv]'), svH = q('[data-cp-sv-handle]');
                const hue = q('[data-cp-hue]'), hueH = q('[data-cp-hue-handle]');
                const alpha = q('[data-cp-alpha]'), alphaH = q('[data-cp-alpha-handle]');
                const inputsWrap = q('[data-cp-inputs]');
                const modelSelect = q('[data-cp-model]');
                const editingRow = q('[data-cp-editing]');
                const editingSwatch = q('[data-cp-editing-swatch]');
                const editingLabel = q('[data-cp-editing-label]');
                const eyedropper = q('[data-cp-eyedropper]');

                const gradientBar = q('[data-cp-gradient-bar]');
                const gradientRamp = q('[data-cp-gradient-ramp]');
                const gradientStops = q('[data-cp-gradient-stops]');
                const gradientTemplate = q('[data-cp-gradient-stop-template]');
                const typeSelect = q('[data-cp-gradient-type]');
                const shapeSelect = q('[data-cp-gradient-shape]');
                const angleWrap = q('[data-cp-angle-wrap]');
                const gradientAngle = q('[data-cp-gradient-angle]');

                const seed = parseHex(root.dataset.cpValue) || [227, 227, 227, 1];
                const [ih, is, iv] = rgbToHsv(seed[0], seed[1], seed[2]);
                const st = { h: ih, s: is, v: iv, a: seed[3] };

                let stopId = 0;
                const gradient = {
                    type: 'linear', angle: 90, shape: 'circle', selected: 0,
                    stops: [
                        { id: stopId++, position: 0, color: '#000000', opacity: 100 },
                        { id: stopId++, position: 100, color: '#FFFFFF', opacity: 100 },
                    ],
                };
                let mode = root.dataset.cpMode === 'gradient' ? 'gradient' : 'fill';
                let model = 'rgba';
                let fields = [];              // live input elements, keyed by MODELS entry
                let suppressStopSync = false; // set while typing into a stop row's own inputs
                let draggedStop = false;      // set by a handle drag, consumed by the click that follows
                // Seeded, not null: a picker mounted straight into gradient mode never runs the
                // fill→gradient park, so without this its first switch to Fill would inherit a
                // stop colour instead of the value the component was given.
                let fillState = { h: st.h, s: st.s, v: st.v, a: st.a };

                // The EyeDropper API is Chromium-only. Rather than ship a button that silently
                // does nothing on Firefox/Safari, it stays hidden unless the API is present.
                if (eyedropper && window.EyeDropper) eyedropper.hidden = false;

                const rgb = () => hsvToRgb(st.h, st.s, st.v);
                const hexOf = ([r, g, b]) => '#' + toHex(r) + toHex(g) + toHex(b);
                const selectedStop = () => gradient.stops[gradient.selected] || null;

                /* ── gradient model ─────────────────────────────────────── */

                const stopCss = (stop) => {
                    const parsed = parseHex(stop.color) || [0, 0, 0, 1];
                    return 'rgba(' + parsed[0] + ',' + parsed[1] + ',' + parsed[2] + ',' + (stop.opacity / 100).toFixed(3) + ') ' + stop.position + '%';
                };
                const orderedStops = () => [...gradient.stops].sort((a, b) => a.position - b.position);
                const stopList = () => orderedStops().map(stopCss).join(', ');

                function gradientCss() {
                    const stops = stopList();
                    if (gradient.type === 'radial') return 'radial-gradient(' + gradient.shape + ' at center, ' + stops + ')';
                    if (gradient.type === 'conic') return 'conic-gradient(from ' + gradient.angle + 'deg, ' + stops + ')';
                    return 'linear-gradient(' + gradient.angle + 'deg, ' + stops + ')';
                }
                // The preview bar is always a left-to-right ramp regardless of gradient type —
                // it represents the stop sequence, not the final projection.
                const rampCss = () => 'linear-gradient(90deg, ' + stopList() + ')';

                // Colour of the ramp at `position`, used when clicking the bar to insert a stop
                // so the new stop starts invisible rather than jumping to a hardcoded grey.
                function sampleAt(position) {
                    const list = orderedStops();
                    if (!list.length) return { color: '#808080', opacity: 100 };
                    let before = list[0], after = list[list.length - 1];
                    for (const stop of list) if (stop.position <= position) before = stop;
                    for (let i = list.length - 1; i >= 0; i--) if (list[i].position >= position) after = list[i];
                    const span = after.position - before.position;
                    const t = span <= 0 ? 0 : (position - before.position) / span;
                    const a = parseHex(before.color) || [0, 0, 0, 1];
                    const b = parseHex(after.color) || [0, 0, 0, 1];
                    return {
                        color: hexOf([a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t, a[2] + (b[2] - a[2]) * t]),
                        opacity: round(before.opacity + (after.opacity - before.opacity) * t),
                    };
                }

                function emitGradient() {
                    root.dispatchEvent(new CustomEvent('gradientchange', {
                        bubbles: true,
                        detail: {
                            css: gradientCss(), type: gradient.type, angle: gradient.angle, shape: gradient.shape,
                            stops: orderedStops().map(({ position, color, opacity }) => ({ position, color, opacity })),
                        },
                    }));
                }

                // Keep `selected` pointing at the same stop object across a re-sort. Positions are
                // the sort key and they change on drag, so index-based tracking loses the stop
                // mid-drag; the per-stop `id` is what actually survives.
                function sortStops(keepId) {
                    const id = keepId === undefined ? (selectedStop() || {}).id : keepId;
                    gradient.stops.sort((a, b) => a.position - b.position);
                    const index = gradient.stops.findIndex((stop) => stop.id === id);
                    gradient.selected = index === -1 ? 0 : index;
                }

                /* ── gradient rendering ─────────────────────────────────── */

                function renderBar() {
                    if (!gradientBar || !gradientRamp) return;
                    gradientRamp.style.background = rampCss();
                    const handles = new Map(
                        Array.from(gradientBar.querySelectorAll('[data-cp-gradient-stop-handle]')).map((el) => [el.dataset.cpGradientStopHandle, el])
                    );
                    gradient.stops.forEach((stop, index) => {
                        let handle = handles.get(String(stop.id));
                        if (handle) handles.delete(String(stop.id));
                        else {
                            handle = document.createElement('button');
                            handle.type = 'button';
                            handle.className = 'hb-cp__gstop';
                            handle.dataset.cpGradientStopHandle = String(stop.id);
                            gradientBar.append(handle);
                        }
                        const parsed = parseHex(stop.color) || [0, 0, 0, 1];
                        handle.style.left = stop.position + '%';
                        handle.style.background = 'rgba(' + parsed[0] + ',' + parsed[1] + ',' + parsed[2] + ',' + (stop.opacity / 100).toFixed(3) + ')';
                        handle.classList.toggle('is-active', gradient.selected === index);
                        handle.setAttribute('aria-label', stop.position + '%');
                    });
                    handles.forEach((orphan) => orphan.remove());
                }

                function renderStopRows() {
                    if (!gradientStops || !gradientTemplate) return;
                    const rows = new Map(
                        Array.from(gradientStops.querySelectorAll('[data-cp-gradient-stop-row]')).map((el) => [el.dataset.cpGradientStopRow, el])
                    );
                    const ordered = [];
                    gradient.stops.forEach((stop, index) => {
                        let row = rows.get(String(stop.id));
                        if (row) rows.delete(String(stop.id));
                        else {
                            row = gradientTemplate.content.cloneNode(true).querySelector('[data-cp-gradient-stop-row]');
                            row.dataset.cpGradientStopRow = String(stop.id);
                        }
                        row.classList.toggle('is-active', gradient.selected === index);
                        row.querySelector('[data-cp-gradient-stop-swatch]').style.background = stop.color;
                        // Skip the field the user is currently typing in — writing back to it
                        // would fight the caret.
                        const hexInput = row.querySelector('[data-cp-gradient-stop-hex]');
                        const opacityInput = row.querySelector('[data-cp-gradient-stop-opacity]');
                        const positionInput = row.querySelector('[data-cp-gradient-stop-position]');
                        if (document.activeElement !== hexInput) hexInput.value = stop.color;
                        if (document.activeElement !== opacityInput) opacityInput.value = stop.opacity;
                        if (document.activeElement !== positionInput) positionInput.value = stop.position;
                        row.querySelector('[data-cp-gradient-stop-remove]').disabled = gradient.stops.length <= 2;
                        ordered.push(row);
                    });
                    rows.forEach((orphan) => orphan.remove());
                    ordered.forEach((row, index) => {
                        if (gradientStops.children[index] !== row) gradientStops.insertBefore(row, gradientStops.children[index] || null);
                    });
                }

                function renderGradientChrome() {
                    const isRadial = gradient.type === 'radial';
                    if (angleWrap) angleWrap.hidden = isRadial;
                    if (shapeSelect) shapeSelect.hidden = !isRadial;
                    if (gradientAngle && document.activeElement !== gradientAngle) gradientAngle.value = gradient.angle;
                }

                function renderGradient({ emit = false } = {}) {
                    renderGradientChrome();
                    renderBar();
                    renderStopRows();
                    if (emit) emitGradient();
                }

                /* ── colour editor rendering ────────────────────────────── */

                function fieldValues() {
                    const [r, g, b] = rgb();
                    const [, sl, ll] = rgbToHsl(r, g, b);
                    const a = Number(st.a.toFixed(2));
                    return {
                        hex: a < 1 ? hexOf([r, g, b]) + toHex(a * 255) : hexOf([r, g, b]),
                        r, g, b, a,
                        // HSL and HSB share a hue, and st.h is the continuous one the hue slider
                        // actually holds — re-deriving it from the quantised 8-bit RGB makes the
                        // printed H drift a degree off the slider it is supposed to mirror.
                        hh: round(((st.h % 360) + 360) % 360),
                        sl: round(sl * 100), ll: round(ll * 100),
                        sb: round(st.s * 100), vb: round(st.v * 100),
                    };
                }

                function buildInputs() {
                    if (!inputsWrap) return;
                    fields = [];
                    inputsWrap.replaceChildren(...MODELS[model].map((spec) => {
                        const field = document.createElement('div');
                        field.className = 'hb-cp__field' + (spec.wide ? ' hb-cp__field--wide' : '');
                        const box = document.createElement('span');
                        box.className = 'hb-cp__box';
                        const input = document.createElement('input');
                        input.type = 'text';
                        input.spellcheck = false;
                        input.inputMode = spec.k === 'hex' ? 'text' : 'decimal';
                        input.dataset.cpInput = spec.k;
                        input.setAttribute('aria-label', spec.l);
                        const label = document.createElement('span');
                        label.className = 'hb-cp__field-l';
                        label.textContent = spec.l;
                        box.append(input);
                        field.append(box, label);
                        fields.push({ spec, input });
                        return field;
                    }));
                    syncInputs();
                }

                function syncInputs() {
                    const values = fieldValues();
                    fields.forEach(({ spec, input }) => {
                        if (document.activeElement === input) return;
                        input.value = values[spec.k];
                    });
                }

                // Read every field of the active model at once — RGB and HSL are only meaningful
                // as a triple, so committing one field has to re-read its siblings.
                function commitInputs(changedKey) {
                    const read = (key) => {
                        const found = fields.find((field) => field.spec.k === key);
                        return found ? found.input.value : null;
                    };
                    if (changedKey === 'hex') {
                        const parsed = parseHex(read('hex'));
                        if (!parsed) return syncInputs();
                        const [h, s, v] = rgbToHsv(parsed[0], parsed[1], parsed[2]);
                        st.h = h; st.s = s; st.v = v; st.a = parsed[3];
                    } else if (changedKey === 'a') {
                        st.a = clamp(parseFloat(read('a')) || 0, 0, 1);
                    } else if (model === 'rgb' || model === 'rgba') {
                        const [h, s, v] = rgbToHsv(
                            clamp(parseFloat(read('r')) || 0, 0, 255),
                            clamp(parseFloat(read('g')) || 0, 0, 255),
                            clamp(parseFloat(read('b')) || 0, 0, 255)
                        );
                        st.h = h; st.s = s; st.v = v;
                    } else if (model === 'hsl' || model === 'hsla') {
                        const [r, g, b] = hslToRgb(
                            parseFloat(read('hh')) || 0,
                            clamp(parseFloat(read('sl')) || 0, 0, 100) / 100,
                            clamp(parseFloat(read('ll')) || 0, 0, 100) / 100
                        );
                        const [h, s, v] = rgbToHsv(r, g, b);
                        st.h = h; st.s = s; st.v = v;
                    } else if (model === 'hsb') {
                        st.h = ((parseFloat(read('hh')) || 0) % 360 + 360) % 360;
                        st.s = clamp(parseFloat(read('sb')) || 0, 0, 100) / 100;
                        st.v = clamp(parseFloat(read('vb')) || 0, 0, 100) / 100;
                    }
                    render();
                }

                function render() {
                    const hueCol = 'hsl(' + round(st.h) + ',100%,50%)';
                    root.style.setProperty('--hb-cp-hue', hueCol);
                    if (svH) { svH.style.left = (st.s * 100) + '%'; svH.style.top = ((1 - st.v) * 100) + '%'; }
                    if (hueH) { hueH.style.left = (st.h / 360 * 100) + '%'; hueH.style.background = hueCol; }
                    if (alphaH) alphaH.style.left = (st.a * 100) + '%';
                    syncInputs();

                    const parts = rgb();
                    const hex = hexOf(parts);
                    root.dataset.cpValue = hex;

                    if (mode === 'gradient') {
                        const stop = selectedStop();
                        if (stop && !suppressStopSync) {
                            stop.color = hex;
                            stop.opacity = round(st.a * 100);
                        }
                        if (editingSwatch) editingSwatch.style.background = hex;
                        if (editingLabel && stop) editingLabel.textContent = stop.position + '%';
                        renderGradient({ emit: true });
                    }

                    root.dispatchEvent(new CustomEvent('colorchange', {
                        bubbles: true,
                        detail: {
                            r: parts[0], g: parts[1], b: parts[2], a: st.a, hex,
                            gradientStop: mode === 'gradient' ? gradient.selected : null,
                        },
                    }));
                }

                /* ── mode + selection ───────────────────────────────────── */

                function loadSelectedStopIntoEditor() {
                    const stop = selectedStop();
                    if (!stop) return;
                    const parsed = parseHex(stop.color) || [0, 0, 0, 1];
                    const [h, s, v] = rgbToHsv(parsed[0], parsed[1], parsed[2]);
                    st.h = h; st.s = s; st.v = v; st.a = stop.opacity / 100;
                }

                function setMode(next) {
                    const target = next === 'gradient' ? 'gradient' : 'fill';
                    // The two tabs edit different things through one editor, so the flat colour
                    // has to be parked on the way into gradient mode and restored on the way
                    // back — otherwise a trip through the Gradient tab silently replaces the
                    // user's fill with whichever stop they last touched.
                    if (mode === 'fill' && target === 'gradient') fillState = { h: st.h, s: st.s, v: st.v, a: st.a };
                    mode = target;
                    root.dataset.cpMode = mode;
                    root.querySelectorAll('[data-cp-body]').forEach((body) => { body.hidden = body.dataset.cpBody !== mode; });
                    root.querySelectorAll('[data-hb-tablist] [data-hb-tab]').forEach((tab) => {
                        tab.setAttribute('aria-selected', tab.dataset.hbTab === mode ? 'true' : 'false');
                    });
                    if (editingRow) editingRow.hidden = mode !== 'gradient';
                    if (mode === 'gradient') { loadSelectedStopIntoEditor(); renderGradient(); }
                    else if (fillState) { st.h = fillState.h; st.s = fillState.s; st.v = fillState.v; st.a = fillState.a; }
                    render();
                }

                function selectStop(index) {
                    gradient.selected = clamp(index, 0, gradient.stops.length - 1);
                    loadSelectedStopIntoEditor();
                    render();
                }

                /* ── drag helpers ───────────────────────────────────────── */

                function track(el, fn) {
                    if (!el) return;
                    const move = (event) => fn(event.touches ? event.touches[0] : event, el.getBoundingClientRect());
                    el.addEventListener('mousedown', (event) => {
                        move(event);
                        const up = () => { document.removeEventListener('mousemove', move); document.removeEventListener('mouseup', up); };
                        document.addEventListener('mousemove', move); document.addEventListener('mouseup', up);
                        event.preventDefault();
                    });
                    el.addEventListener('touchstart', (event) => { move(event); event.preventDefault(); }, { passive: false });
                    el.addEventListener('touchmove', (event) => { move(event); event.preventDefault(); }, { passive: false });
                }

                track(sv, (event, rect) => { st.s = clamp((event.clientX - rect.left) / rect.width, 0, 1); st.v = clamp(1 - (event.clientY - rect.top) / rect.height, 0, 1); render(); });
                track(hue, (event, rect) => { st.h = clamp((event.clientX - rect.left) / rect.width, 0, 1) * 360; render(); });
                track(alpha, (event, rect) => { st.a = clamp((event.clientX - rect.left) / rect.width, 0, 1); render(); });

                // Stop handles drag along the bar. Identity is the stop id, so the re-sort each
                // frame can't swap which stop the pointer owns.
                if (gradientBar) {
                    gradientBar.addEventListener('mousedown', (event) => {
                        const handle = event.target.closest('[data-cp-gradient-stop-handle]');
                        if (!handle) return;
                        event.preventDefault();
                        const id = Number(handle.dataset.cpGradientStopHandle);
                        selectStop(gradient.stops.findIndex((stop) => stop.id === id));
                        const rect = gradientBar.getBoundingClientRect();
                        const move = (moveEvent) => {
                            const stop = gradient.stops.find((item) => item.id === id);
                            if (!stop) return;
                            // Releasing a drag anywhere over the bar still fires a click, and the
                            // click handler's job is "insert a stop here" — so a drag that ended
                            // off the handle would add a phantom stop. Mark it and swallow the
                            // click that follows.
                            draggedStop = true;
                            stop.position = round(clamp((moveEvent.clientX - rect.left) / rect.width, 0, 1) * 100);
                            sortStops(id);
                            renderGradient({ emit: true });
                        };
                        const up = () => { document.removeEventListener('mousemove', move); document.removeEventListener('mouseup', up); };
                        document.addEventListener('mousemove', move);
                        document.addEventListener('mouseup', up);
                    });
                    // Keyboard nudge, so stops are reachable without a pointer.
                    gradientBar.addEventListener('keydown', (event) => {
                        const handle = event.target.closest('[data-cp-gradient-stop-handle]');
                        if (!handle) return;
                        const delta = event.key === 'ArrowLeft' ? -1 : event.key === 'ArrowRight' ? 1 : 0;
                        if (!delta) return;
                        event.preventDefault();
                        const id = Number(handle.dataset.cpGradientStopHandle);
                        const stop = gradient.stops.find((item) => item.id === id);
                        if (!stop) return;
                        stop.position = clamp(stop.position + delta * (event.shiftKey ? 10 : 1), 0, 100);
                        sortStops(id);
                        renderGradient({ emit: true });
                        root.querySelector('[data-cp-gradient-stop-handle="' + id + '"]')?.focus();
                    });
                }

                /* ── public API ─────────────────────────────────────────── */

                function setHex(hex) {
                    const parsed = parseHex(hex);
                    if (!parsed) return;
                    const [h, s, v] = rgbToHsv(parsed[0], parsed[1], parsed[2]);
                    st.h = h; st.s = s; st.v = v; st.a = parsed[3];
                    render();
                }
                // Inverse of gradientCss(). Stops are emitted as rgba(), which parseHex() does not
                // read — seeding through it turned every restored stop black, which is what
                // reopening a saved gradient looked like.
                function splitTopLevel(text) {
                    const parts = [];
                    let depth = 0;
                    let current = '';
                    for (const char of text) {
                        if (char === '(') depth++;
                        if (char === ')') depth--;
                        if (char === ',' && depth === 0) { parts.push(current); current = ''; continue; }
                        current += char;
                    }
                    if (current.trim() !== '') parts.push(current);
                    return parts.map((part) => part.trim()).filter((part) => part !== '');
                }
                function parseGradientStop(text) {
                    const position = text.match(/\s(-?\d+(?:\.\d+)?)%$/);
                    const colorText = (position ? text.slice(0, -position[0].length) : text).trim();
                    const at = position ? clamp(parseFloat(position[1]), 0, 100) : 0;
                    const rgba = colorText.match(/^rgba?\(([^)]*)\)$/i);
                    if (rgba) {
                        const nums = rgba[1].split(',').map((part) => parseFloat(part.trim()));
                        if (nums.length < 3 || nums.slice(0, 3).some((n) => !Number.isFinite(n))) return null;
                        const hex = '#' + nums.slice(0, 3).map((n) => clamp(Math.round(n), 0, 255).toString(16).padStart(2, '0')).join('');
                        const alpha = Number.isFinite(nums[3]) ? nums[3] : 1;
                        return { position: at, color: hex, opacity: clamp(Math.round(alpha * 100), 0, 100) };
                    }
                    const parsed = parseHex(colorText);
                    if (!parsed) return null;
                    const hex = '#' + parsed.slice(0, 3).map((n) => clamp(Math.round(n), 0, 255).toString(16).padStart(2, '0')).join('');
                    return { position: at, color: hex, opacity: clamp(Math.round((parsed[3] == null ? 1 : parsed[3]) * 100), 0, 100) };
                }
                function parseGradientCss(css) {
                    const match = String(css || '').trim().match(/^(linear|radial|conic)-gradient\((.*)\)$/is);
                    if (!match) return null;
                    const spec = { type: match[1].toLowerCase() };
                    const parts = splitTopLevel(match[2]);
                    if (!parts.length) return null;
                    const head = parts[0];
                    if (spec.type === 'linear' && /^-?\d+(?:\.\d+)?deg$/i.test(head)) { spec.angle = parseFloat(head); parts.shift(); }
                    else if (spec.type === 'conic' && /^from\s+-?\d+(?:\.\d+)?deg$/i.test(head)) { spec.angle = parseFloat(head.replace(/^from\s+/i, '')); parts.shift(); }
                    else if (spec.type === 'radial' && /^(circle|ellipse)\b/i.test(head)) { spec.shape = head.split(/\s+/)[0].toLowerCase(); parts.shift(); }
                    spec.stops = parts.map(parseGradientStop).filter(Boolean);
                    return spec.stops.length >= 2 ? spec : null;
                }
                function setGradientCss(css) {
                    const spec = parseGradientCss(css);
                    if (!spec) return false;
                    setMode('gradient');
                    setGradient(spec, false);
                    return true;
                }
                function setGradient(spec, emit = true) {
                    if (!spec) return;
                    if (spec.type) gradient.type = spec.type;
                    if (Number.isFinite(spec.angle)) gradient.angle = clamp(spec.angle, 0, 360);
                    if (spec.shape) gradient.shape = spec.shape;
                    if (Array.isArray(spec.stops) && spec.stops.length >= 2) {
                        gradient.stops = spec.stops.map((stop) => ({
                            id: stopId++,
                            position: clamp(Number(stop.position) || 0, 0, 100),
                            color: (parseHex(stop.color) ? String(stop.color) : '#000000'),
                            opacity: clamp(Number(stop.opacity == null ? 100 : stop.opacity), 0, 100),
                        }));
                        gradient.selected = 0;
                    }
                    sortStops(gradient.stops[gradient.selected]?.id);
                    syncSelectDisplay(typeSelect, gradient.type);
                    syncSelectDisplay(shapeSelect, gradient.shape);
                    if (mode === 'gradient') loadSelectedStopIntoEditor();
                    renderGradient({ emit });
                    render();
                }
                function getValue() {
                    const parts = rgb();
                    return {
                        mode,
                        hex: hexOf(parts),
                        rgba: { r: parts[0], g: parts[1], b: parts[2], a: st.a },
                        gradient: {
                            css: gradientCss(), type: gradient.type, angle: gradient.angle, shape: gradient.shape,
                            stops: orderedStops().map(({ position, color, opacity }) => ({ position, color, opacity })),
                        },
                    };
                }
                // ui/select owns its trigger text (and, since it became searchable, a display
                // path that can show a value no rendered option matches) — so push through its
                // own setValue rather than writing the label from out here. The fallback covers
                // the boot-order case where this picker initialises before the select does.
                function syncSelectDisplay(select, value) {
                    const option = select?.querySelector('[data-hb-select-option="' + value + '"]');
                    if (!option) return;
                    const label = (option.querySelector('span') || option).textContent.trim();
                    if (select.__hbSelect?.setValue) { select.__hbSelect.setValue(value, label); return; }
                    select.querySelectorAll('[data-hb-select-option]').forEach((item) => item.setAttribute('aria-selected', item === option ? 'true' : 'false'));
                    select.dataset.value = value;
                    const valueEl = select.querySelector('[data-hb-select-value]');
                    if (valueEl) valueEl.textContent = label;
                }

                root.__hbCp = { setHex, setGradient, setGradientCss, getValue, setMode };

                /* ── events ─────────────────────────────────────────────── */

                root.addEventListener('click', async (event) => {
                    if (event.target.closest('[data-cp-eyedropper]')) {
                        if (!window.EyeDropper) return;
                        try { const result = await new window.EyeDropper().open(); setHex(result.sRGBHex); } catch (_) { /* cancelled */ }
                        return;
                    }
                    if (event.target.closest('[data-cp-copy]')) {
                        const values = fieldValues();
                        const text = model === 'hex' ? values.hex
                            : model === 'rgb' ? 'rgb(' + values.r + ', ' + values.g + ', ' + values.b + ')'
                            : model === 'rgba' ? 'rgba(' + values.r + ', ' + values.g + ', ' + values.b + ', ' + values.a + ')'
                            : model === 'hsl' ? 'hsl(' + values.hh + ', ' + values.sl + '%, ' + values.ll + '%)'
                            : model === 'hsla' ? 'hsla(' + values.hh + ', ' + values.sl + '%, ' + values.ll + '%, ' + values.a + ')'
                            : 'hsb(' + values.hh + ', ' + values.sb + '%, ' + values.vb + '%)';
                        navigator.clipboard?.writeText(text).catch(() => { /* clipboard blocked */ });
                        return;
                    }
                    if (event.target.closest('[data-cp-gradient-reverse]')) {
                        const id = (selectedStop() || {}).id;
                        gradient.stops.forEach((stop) => { stop.position = 100 - stop.position; });
                        sortStops(id);
                        if (mode === 'gradient') loadSelectedStopIntoEditor();
                        renderGradient({ emit: true });
                        return;
                    }
                    if (event.target.closest('[data-cp-gradient-distribute]')) {
                        const ordered = orderedStops();
                        const step = 100 / (ordered.length - 1 || 1);
                        ordered.forEach((stop, index) => { stop.position = round(step * index); });
                        sortStops();
                        renderGradient({ emit: true });
                        return;
                    }
                    if (event.target.closest('[data-cp-gradient-duplicate]')) {
                        const source = selectedStop();
                        if (!source) return;
                        const copy = { id: stopId++, position: clamp(source.position + 10, 0, 100), color: source.color, opacity: source.opacity };
                        gradient.stops.push(copy);
                        sortStops(copy.id);
                        renderGradient({ emit: true });
                        return;
                    }
                    if (event.target.closest('[data-cp-gradient-add]')) {
                        const source = selectedStop() || gradient.stops[0];
                        const position = clamp((source ? source.position : 0) + 10, 0, 100);
                        const sampled = sampleAt(position);
                        const added = { id: stopId++, position, color: sampled.color, opacity: sampled.opacity };
                        gradient.stops.push(added);
                        sortStops(added.id);
                        loadSelectedStopIntoEditor();
                        renderGradient({ emit: true });
                        render();
                        return;
                    }
                    const handle = event.target.closest('[data-cp-gradient-stop-handle]');
                    if (handle) {
                        draggedStop = false;
                        const id = Number(handle.dataset.cpGradientStopHandle);
                        selectStop(gradient.stops.findIndex((stop) => stop.id === id));
                        return;
                    }
                    // Clicking bare bar inserts a stop sampled from the ramp at that point.
                    if (gradientBar && (event.target === gradientBar || event.target === gradientRamp)) {
                        if (draggedStop) { draggedStop = false; return; }
                        const rect = gradientBar.getBoundingClientRect();
                        const position = round(clamp((event.clientX - rect.left) / rect.width, 0, 1) * 100);
                        const sampled = sampleAt(position);
                        const added = { id: stopId++, position, color: sampled.color, opacity: sampled.opacity };
                        gradient.stops.push(added);
                        sortStops(added.id);
                        loadSelectedStopIntoEditor();
                        renderGradient({ emit: true });
                        render();
                        return;
                    }
                    const row = event.target.closest('[data-cp-gradient-stop-row]');
                    if (!row) return;
                    const id = Number(row.dataset.cpGradientStopRow);
                    const index = gradient.stops.findIndex((stop) => stop.id === id);
                    if (index === -1) return;
                    if (event.target.closest('[data-cp-gradient-stop-remove]')) {
                        if (gradient.stops.length <= 2) return;
                        gradient.stops.splice(index, 1);
                        gradient.selected = clamp(gradient.selected, 0, gradient.stops.length - 1);
                        loadSelectedStopIntoEditor();
                        renderGradient({ emit: true });
                        render();
                        return;
                    }
                    const selectButton = event.target.closest('[data-cp-gradient-stop-select]');
                    if (selectButton) {
                        selectStop(index);
                        // Fine colour adjustment for a stop happens in its OWN standalone picker
                        // popup (no nested gradient section), never in this picker's own editor —
                        // that editor is hidden in gradient mode (32-pickers.css). The listener
                        // lives in script-fonts-and-style-events.blade.php, which has no reach
                        // into this closure, so the write-back travels as a callback on the event.
                        const stop = gradient.stops[index];
                        root.dispatchEvent(new CustomEvent('gradientstopedit', {
                            bubbles: true,
                            detail: {
                                button: selectButton,
                                color: stop.color,
                                opacity: stop.opacity,
                                setColor: (hex, opacityPercent) => {
                                    const target = gradient.stops.find((item) => item.id === stop.id);
                                    if (!target) return;
                                    target.color = hex;
                                    target.opacity = clamp(round(opacityPercent), 0, 100);
                                    sortStops(target.id);
                                    renderGradient({ emit: true });
                                },
                            },
                        }));
                    }
                });

                // ui/select and ui/tabs both dispatch a bubbling `change` carrying detail — they
                // land here alongside the native `change` from the text inputs, so each branch
                // matches on its own hook before touching detail.
                root.addEventListener('change', (event) => {
                    const tablist = event.target.closest('[data-hb-tablist]');
                    if (tablist) { setMode(event.detail?.value === 'gradient' ? 'gradient' : 'fill'); return; }

                    if (event.target === modelSelect) {
                        model = event.detail?.value || 'rgba';
                        buildInputs();
                        return;
                    }
                    if (event.target === typeSelect) {
                        gradient.type = event.detail?.value || 'linear';
                        renderGradient({ emit: true });
                        return;
                    }
                    if (event.target === shapeSelect) {
                        gradient.shape = event.detail?.value || 'circle';
                        renderGradient({ emit: true });
                        return;
                    }
                    if (event.target === gradientAngle) {
                        gradient.angle = clamp(parseFloat(gradientAngle.value) || 0, 0, 360);
                        renderGradient({ emit: true });
                        return;
                    }
                    if (event.target.matches('[data-cp-input]')) { commitInputs(event.target.dataset.cpInput); return; }

                    const row = event.target.closest('[data-cp-gradient-stop-row]');
                    if (!row) return;
                    const stop = gradient.stops.find((item) => item.id === Number(row.dataset.cpGradientStopRow));
                    if (!stop) return;
                    if (event.target.matches('[data-cp-gradient-stop-hex]')) {
                        const parsed = parseHex(event.target.value);
                        if (parsed) { stop.color = hexOf(parsed); stop.opacity = round(parsed[3] * 100); }
                    }
                    if (event.target.matches('[data-cp-gradient-stop-opacity]')) stop.opacity = clamp(parseFloat(event.target.value) || 0, 0, 100);
                    if (event.target.matches('[data-cp-gradient-stop-position]')) stop.position = clamp(parseFloat(event.target.value) || 0, 0, 100);
                    sortStops(stop.id);
                    // The row's own fields are the source of truth for this keystroke; don't let
                    // render() push the editor's colour back over what was just typed.
                    suppressStopSync = true;
                    if (gradient.stops[gradient.selected] === stop) loadSelectedStopIntoEditor();
                    renderGradient({ emit: true });
                    render();
                    suppressStopSync = false;
                });

                buildInputs();
                setMode(mode);
            }

            const boot = () => document.querySelectorAll('[data-hb-colorpicker]').forEach(init);
            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
            else boot();
            document.addEventListener('hb:refresh', boot);
        })();
    </script>
    @endonce
</div>
