# Heisenberg Editor — File Structure

> Rewritten 2026-08-05. The original version of this document described the Builder/Editor
> coexistence plan; the Builder was deleted in the 2026-08-02 reset and `/editor` is now the
> only surface. This version describes what actually ships.

## Surfaces

- `/editor` — the editor application (blank document; `/editor/{post}` opens a saved post).
- `/editor/components` — gallery page rendering every `ui/` primitive with fixture props,
  used for visual verification against the Pencil design source.
- `/editor/media` — the media library (Livewire).
- `/editor/{post}/preview` and `POST/GET /editor/preview` — sanitized public rendering
  through `BlockRenderer` (`resources/views/preview.blade.php`).

Livewire (`livewire/livewire`) is a hard composer dependency; today it powers only the media
library. Everything else on `/editor` is vanilla JavaScript delivered inline from Blade —
there is no Alpine, no bundler, and no `resources/js/` directory.

## Directory structure

```text
resources/
├── css/
│   ├── tokens.css                  # design tokens (--hb-*) — colors, radius, spacing, type, elevation
│   └── editor/                     # editor chrome CSS, flat and numerically ordered
│       ├── 00-fonts.css            ├── 01-reset.css            ├── 02-dark-theme.css
│       ├── 20-shell.css            ├── 30-media.css            ├── 31-block-inspector.css
│       ├── 32-pickers.css          ├── 33-toolbar.css          ├── 34-canvas.css
│       └── 35-blocks.css
│
├── blocks/                         # block contracts — one dir per block
│   ├── heading/                    #   <slug>.json (contract) + <slug>.css (block styles)
│   └── paragraph/
│
├── fonts/vendor/                   # self-hosted editor chrome fonts (Rubik woff2)
├── icons/phosphor/                 # icon set consumed by ui/icon
├── lang/{en,fr}/                   # editor.php + blocks.php translations
├── templates/                      # post-template contracts (article/)
│
└── views/
    ├── components/
    │   ├── ui/                     # stateless design-system primitives (button, field,
    │   │                           #   select, combobox, tabs, slider, toggle, …)
    │   └── live/                   # composed editor pieces
    │       ├── topbar / sidebar / canvas / inspector / footer / side panels
    │       ├── block-runtime.blade.php   # THE client runtime: doc model + window.hbEditor
    │       ├── block/              # inspector Content/Style/Advanced panels (+ style/*)
    │       ├── toolbar/            # floating block toolbar (+ groups/, popover menus)
    │       ├── pickers/            # color picker, variable menu, effect editor
    │       └── media/              # media dialog/cards/dropzone (used by inspector + gallery)
    ├── editor/
    │   ├── layouts/app.blade.php   # editor page shell
    │   ├── index.blade.php         # /editor — composes the live/* components
    │   ├── components.blade.php    # /editor/components gallery
    │   └── media.blade.php         # /editor/media page hosting the Livewire component
    ├── livewire/media-library.blade.php
    └── preview.blade.php           # public-rendering preview page

src/
├── HeisenbergServiceProvider.php   # bindings, policies, routes, commands, middleware
├── Adapters/  Contracts/  Enums/  Models/  Policies/
├── Console/Commands/               # blocks:verify, templates:verify, scheduled publish
├── Editor/EditorIcon.php
├── Http/{Controllers,Middleware,Requests}/
├── Livewire/MediaLibrary.php
├── Services/                       # BlockRegistryService, BlockRenderer, validators,
│                                   #   BlocksPayloadService, ThemeRepository, MediaLibraryService, …
└── Support/                        # BlockViewData, SupportsStyle, AnimationCatalog, …

routes/
├── editor.php                      # /editor pages, JSON post API, assets, locale switch
└── media.php                       # media library API

database/                           # package migrations
config/heisenberg.php
docs/                               # this file, BLUEPRINT, schemas, composition docs
```

## Placement rules (unchanged in spirit)

- `components/ui/` — small, stateless, presentational primitives driven by props/slots.
  No backend access. Verified via `/editor/components`.
- `components/live/` — composed editor pieces. Each owns its inline `<script>` (an `@once`
  IIFE booting on `DOMContentLoaded` and re-booting idempotently on `hb:refresh`) and talks
  to the document only through `window.hbEditor`.
- `views/editor/` — the application pages and layout, not reusable components.

## Client state model

`components/live/block-runtime.blade.php` owns the single document model (`doc.blocks`) and
exposes the public API `window.hbEditor` (getDoc/getModel/insertBlock/setAttribute/
setSupport/moveBlock/removeBlock/selectById/reRenderBlock/replaceDoc/previewState/
buildSavePayload/undo/redo/canUndo/canRedo). All mutations go through it; it re-renders the
touched block and fires the `hb:*` integration events (`hb:block-selected`,
`hb:block-updated`, `hb:blocks-changed`, …) that the inspector, toolbar, and navigator
listen for.

History lives in the runtime too, because `doc.blocks` does. It is **snapshot-based**: the
mutation events schedule a debounced (400 ms) commit of the serialized document, so rapid
typing coalesces into one undo step, and undo/redo replay a snapshot through the same
render path with block ids preserved. Every stack change fires `hb:history` with
`{ canUndo, canRedo }` — that event, not polling, is what drives the topbar's
`data-hb-undo`/`data-hb-redo` buttons (which ship `disabled`). `replaceDoc(blocks,
{ baseline: true })` resets the stack, so saved-post hydration cannot be undone back to an
empty canvas.

`components/live/code-editor.blade.php` is the second editing surface over the same model:
a shortcode dialect of the block contracts (see `docs/code-view.md`), toggled by the
footer's Code Editor chip, applying only clean parses via `hbEditor.replaceDoc()`.
`components/live/revisions-dialog.blade.php` is a third read-only-until-restore path over
it: opened from the inspector's Post tab, it lists a post's saved revisions
(`PostRevisionsController`, snapshotted on every update by
`PostController::captureRevision()`) and restores one through `hbEditor.replaceDoc()` — so
a restore is an ordinary, undoable document swap.

## Buildless asset serving

`EditorController` assembles CSS at request time: `tokens.css` explicitly first, then
`glob(resources/css/editor/*.css)` sorted `SORT_STRING`, concatenated (`css()`). Generated
stylesheets are served by their own routes: the supports-capabilities sheet
(`SupportsStyle::css()`), the animation catalog (`AnimationCatalog::css()`), block CSS
(concatenated per-contract files via `BlockViewData::blocksCss()`, embedded inline), editor
fonts (`font()`, strict filename allow-list), and a dev-only `/uploads/{path}` passthrough
for the uploads disk.

## Verification requirements

- Start the Testbench server; open `/editor` (and `/editor/components`) in a real browser.
- Verify visually against the Pencil design source — a 200 response is not verification.
- Run the package PHPUnit suite (`vendor/bin/phpunit`).
