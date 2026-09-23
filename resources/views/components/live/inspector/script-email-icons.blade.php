{{--
    EMAIL SURFACE — an icon block's glyph, rasterized.

    Mail clients render no SVG, which is why the icon block used to be missing from the email
    palette entirely (docs/email-system.md §4). It ships as a PNG instead: this rasterizes the
    glyph the canvas is already showing — with the colour and pixel size the author picked — and
    posts the bytes to EmailIconImageController, which validates and stores them under a derived
    name. The block keeps the resulting URL in a hidden `emailImage` attribute; its email template
    renders a plain <img>, and EmailRenderer turns that into the same inline `cid:` part every
    email image already uses, so it arrives in the body rather than as a visible attachment.

    Rasterizing HERE rather than in PHP is deliberate: converting SVG to PNG server-side needs
    Imagick with an SVG delegate (GD cannot do it), which is not present on every host and is far
    too heavy a dependency to force on one. The browser already has the glyph on screen.

    Only ever runs for an email document; a post's icons are untouched.
--}}
    const HB_EMAIL_ICON_URL = @json(route('heisenberg.editor.email.icon'));
    // block-runtime keeps its own DOCUMENT_TYPE inside its IIFE; read the same source it does.
    const hbIsEmailDocument = () =>
        (document.querySelector('[data-hb-canvas]')?.dataset.hbDocumentType || 'post') === 'email';
    const hbEmailIconPending = new Set();
    /** block id -> polls spent waiting for its glyph to be injected (see hbSyncEmailIcon). */
    const hbEmailIconWaits = new Map();

    function hbIconSizeOf(host) {
        const box = host.getBoundingClientRect();
        const size = Math.round(Math.max(box.width, box.height));
        // Matches the endpoint's own bounds; a 0-size box means the block is not laid out yet.
        return size >= 8 && size <= 512 ? size : null;
    }

    function hbIconColorOf(host) {
        const color = getComputedStyle(host).color;
        const m = /^rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(color);
        if (!m) return null;
        const hex = (n) => Number(n).toString(16).padStart(2, '0');
        return '#' + hex(m[1]) + hex(m[2]) + hex(m[3]);
    }

    /**
     * The glyph as standalone SVG markup: `currentColor` only means something while the node sits
     * in the document, and a canvas rasterizes an <img> that has no such context — so the colour
     * is baked in, along with an explicit pixel size for the raster.
     */
    function hbIconSvgMarkup(svg, color, size) {
        const clone = svg.cloneNode(true);
        clone.setAttribute('width', String(size));
        clone.setAttribute('height', String(size));
        clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
        return new XMLSerializer().serializeToString(clone).replace(/currentColor/g, color);
    }

    function hbRasterizeIcon(markup, size) {
        return new Promise((resolve) => {
            const image = new Image();
            // 2x so the icon stays sharp on a retina mail client, as the endpoint requires.
            const scale = 2;
            image.onload = () => {
                try {
                    const canvas = document.createElement('canvas');
                    canvas.width = size * scale;
                    canvas.height = size * scale;
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(image, 0, 0, canvas.width, canvas.height);
                    const url = canvas.toDataURL('image/png');
                    resolve(url.indexOf('data:image/png;base64,') === 0 ? url.slice(22) : null);
                } catch (e) { resolve(null); }
            };
            image.onerror = () => resolve(null);
            image.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(markup);
        });
    }

    function hbSyncEmailIcon(id) {
        if (!hbIsEmailDocument() || !window.hbEditor) return;
        const model = window.hbEditor.getModel(id);
        if (!model || model.name !== 'heisenberg/icon') return;
        const reference = String((model.attributes || {}).icon || '').trim();
        if (!/^[a-z0-9-]+\/[a-z0-9-]+$/.test(reference)) return;

        const host = document.querySelector('.hb-blk[data-block="' + id + '"] [data-block-id]');
        const svg = host && host.querySelector('svg');
        if (!svg) {
            // The glyph is fetched and injected asynchronously and nothing announces its
            // arrival, so waiting for the next edit would leave a just-placed icon unrasterized
            // (and flagged as missing) until the author happened to touch it again. Poll a few
            // times, then give up: an icon that never resolves is a 404, not a slow network.
            const waited = (hbEmailIconWaits.get(id) || 0) + 1;
            hbEmailIconWaits.set(id, waited);
            if (waited <= 12) setTimeout(() => hbSyncEmailIcon(id), 300);
            return;
        }
        hbEmailIconWaits.delete(id);

        const size = hbIconSizeOf(host);
        const color = hbIconColorOf(host);
        if (!size || !color) return;

        const key = reference + '|' + color + '|' + size;
        if (String((model.attributes || {}).emailImageKey || '') === key
            && String((model.attributes || {}).emailImage || '') !== '') return;
        if (hbEmailIconPending.has(key + '|' + id)) return;
        hbEmailIconPending.add(key + '|' + id);

        hbRasterizeIcon(hbIconSvgMarkup(svg, color, size), size)
            .then((png) => {
                if (!png) return null;
                return window.fetch(HB_EMAIL_ICON_URL, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ icon: reference, color: color, size: size, png: png }),
                }).then((res) => (res.ok ? res.json() : null));
            })
            .then((data) => {
                // Failure is silent and non-destructive: the block keeps whatever it had, and
                // EmailBlockCoverageService flags an icon with no image so the author is told
                // before the send rather than after it.
                if (!data || !data.url) return;
                if (!window.hbEditor.getModel(id)) return;
                window.hbEditor.setAttribute(id, 'emailImage', data.url);
                window.hbEditor.setAttribute(id, 'emailImageW', String(data.width));
                window.hbEditor.setAttribute(id, 'emailImageH', String(data.height));
                window.hbEditor.setAttribute(id, 'emailImageKey', key);
            })
            .finally(() => hbEmailIconPending.delete(key + '|' + id));
    }

    function hbSyncEmailIcons() {
        if (!hbIsEmailDocument() || !window.hbEditor) return;
        (function walk(blocks) {
            (blocks || []).forEach((block) => {
                if (block.name === 'heisenberg/icon') hbSyncEmailIcon(block.id);
                walk(block.innerBlocks);
            });
        })(window.hbEditor.getDoc().blocks);
    }

    // Debounced: colour and size arrive as a stream of edits while a picker is dragged, and only
    // the value the author settles on is worth rasterizing.
    let hbEmailIconTimer = null;
    const hbScheduleEmailIcons = () => {
        clearTimeout(hbEmailIconTimer);
        hbEmailIconTimer = setTimeout(hbSyncEmailIcons, 400);
    };
    ['hb:blocks-changed', 'hb:block-updated', 'hb:block-selected'].forEach((name) => {
        document.addEventListener(name, hbScheduleEmailIcons);
    });
