# Heisenberg Editor — File Structure

> Rewritten 2026-08-05, updated 2026-09-19 for the subsystems that shipped since (email, AI,
> MCP, comments, SEO, patterns, public rendering). The original version of this document
> described the Builder/Editor coexistence plan; the Builder was deleted in the 2026-08-02
> reset and `/editor` is now the only surface. See also [`docs/ARCHITECTURE.md`](ARCHITECTURE.md)
> for a subsystem-level map rather than a directory listing.

## Surfaces

- `/editor` — the editor application (blank document; `/editor/{post}` opens a saved post),
  including email documents (`/editor/email`, `type = 'email'` posts).
- `/editor/components` — gallery page rendering every `ui/` primitive with fixture props,
  used for visual verification against the Pencil design source.
- `/editor/media` — the media library (Livewire).
- `/editor/{post}/preview` and `POST/GET /editor/preview` — sanitized rendering through
  `BlockRenderer` (`resources/views/preview.blade.php`), used by the editor's own Preview
  action.
- `GET /posts/{locale}/{slug}` — opt-in bundled public post route (`heisenberg.public.routes`,
  default `false`); see `routes/public.php` and `PostPublicController`.
- `routes/comments.php`, `routes/translations.php`, `routes/seo.php` (`GET /sitemap.xml`),
  `routes/email.php` (served email documents), `routes/ai.php`, `routes/mcp.php` — each behind
  its own `heisenberg.<name>.routes` opt-out flag and `heisenberg.middleware.<name>` stack.

Livewire (`livewire/livewire`) is a hard composer dependency; today it powers only the media
library (`src/Livewire/MediaLibrary.php`). Everything else on `/editor` is vanilla JavaScript
delivered inline from Blade — there is no Alpine, no bundler, and no `resources/js/` directory.

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
├── blocks/                         # block contracts — one dir per block, <slug>.json + <slug>.css
│   ├── button/ column/ columns/ embed/ group/ heading/ icon/
│   └── image/ list/ paragraph/ quote/ separator/         (12 today)
│
├── fonts/vendor/                   # self-hosted editor chrome fonts (Rubik woff2)
├── icons/phosphor/                 # bundled icon set consumed by ui/icon and the icon block
├── lang/{en,fr}/                   # editor.php + blocks.php translations
├── templates/                      # post-template contracts (article/) — validated and
│                                   #   discoverable, not yet consulted at render time (§ below)
│
└── views/
    ├── components/
    │   ├── ui/                     # stateless design-system primitives (button, field,
    │   │                           #   select, combobox, tabs, slider, toggle, …)
    │   └── live/                   # composed editor pieces (~55 files, ~16k lines total)
    │       ├── topbar/ sidebar / canvas / inspector / footer / side panels
    │       ├── block-runtime.blade.php   # THE client runtime: doc model, undo/redo history,
    │       │                              #   window.hbEditor — 2,014 lines, a "god file"
    │       ├── panel-seo-social.blade.php     # SEO/social score + live FB/X preview cards
    │       ├── panel-email-variables.blade.php  # host-defined token labels (display only —
    │       │                              #   Heisenberg never substitutes the values, §6.1 email-system.md)
    │       ├── panel-ai.blade.php / ai/    # AI assistant chat dialog
    │       ├── panel-components-blocks.blade.php  # Components panel — saved patterns browse/insert
    │       ├── panel-navigator.blade.php   # block tree navigator
    │       ├── block/               # inspector Content/Style/Advanced panels (+ style/*)
    │       ├── toolbar/              # floating block toolbar (+ save-as-pattern dialog, groups/, popovers)
    │       ├── pickers/              # color-picker, variable-menu (theme tokens), effect-editor
    │       ├── code-editor.blade.php   # shortcode dialect editor surface (window.hbCodeView)
    │       ├── revisions-dialog.blade.php / toc-dialog.blade.php
    │       └── media/                # media dialog/cards/dropzone (used by inspector + gallery)
    ├── editor/
    │   ├── layouts/app.blade.php   # editor page shell
    │   ├── index.blade.php         # /editor — composes the live/* components
    │   ├── components.blade.php    # /editor/components gallery
    │   └── media.blade.php         # /editor/media page hosting the Livewire component
    ├── livewire/media-library.blade.php
    └── preview.blade.php           # sanitized-render preview page (editor Preview action)

src/
├── HeisenbergServiceProvider.php   # bindings, policies, routes, commands, middleware
├── Adapters/  Contracts/ (14 interfaces)  Enums/  Policies/
├── Ai/                              # AiMessage/AiModel/AiProviderProfile/AiRequest/AiResponse/
│                                   #   AiStreamEvent/EditorPrompt/McpServer/ReasoningFilter/SseReader
├── Models/                         # Post, Block, Category, Tag, Comment, SeoMeta, Pattern,
│                                   #   TocEntry, Revision, PublicFile, AiConversation, AiChatMessage
├── Console/Commands/               # blocks:verify, templates:verify, config-diff, heisenberg:warm,
│                                   #   merge-translations, scheduled publish
├── Editor/EditorIcon.php
├── Http/{Controllers,Middleware,Requests}/   # ~28 controllers — editor, media, AI, MCP,
│                                   #   comments, SEO, translations, public, patterns, themes
├── Livewire/MediaLibrary.php       # the ONLY Livewire component
├── Mail/HeisenbergMailable.php     # convenience Mailable seam over EmailRenderResult
├── Services/                       # BlockRegistryService, BlockContractValidator, BlockRenderer,
│                                   #   HtmlSanitizationService, BlocksPayloadService, ThemeRepository,
│                                   #   MediaLibraryService, EmailRenderer, EmailVariableCatalog,
│                                   #   PostTemplateRegistryService/ContractValidator, SeoAnalyzer,
│                                   #   SeoUrlResolver, ShortcodeDialect/Parser/Serializer,
│                                   #   TranslationStatusService, AiProviderRegistry, AiToolRunner,
│                                   #   McpToolRegistry (2,046 lines, a "god file"), WebSearchService
└── Support/                        # BlockViewData, SupportsStyle, AnimationCatalog, ConfigMerge,
                                    #   LocaleConfig, LocalizedAttributes, EmailRenderResult

routes/
├── editor.php     # /editor pages, JSON post API, assets, locale switch, patterns
├── media.php      # media library API
├── ai.php         # AI assistant chat/settings endpoints
├── mcp.php        # MCP server endpoint (Heisenberg AS a server)
├── public.php     # opt-in GET /posts/{locale}/{slug}
├── comments.php   # public thread + submit + moderation
├── translations.php  # GET /heisenberg/posts/{post}/translations
├── seo.php        # GET /sitemap.xml
└── email.php      # served email documents + single-post .eml export

database/                           # package migrations (auto-loaded, see UPGRADING.md)
config/heisenberg.php
docs/                               # this file, BLUEPRINT (block-engine reconstruction spec
                                    #   only), ARCHITECTURE.md (current full system map),
                                    #   STATUS.md (living state doc), schemas, composition docs
examples/                           # compile-checked host integration snippets
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
mutation events schedule a debounced (400 ms) commit of the serialized document (capped at
100 snapshots), so rapid typing coalesces into one undo step, and undo/redo replay a snapshot
through the same render path with block ids preserved. Ctrl/Cmd+Z and Ctrl/Cmd+Shift+Z (or
Ctrl+Y) drive the same stack from the keyboard. Every stack change fires `hb:history` with
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
