# Heisenberg Editor — File Structure

## Purpose

Editor is a brand-new editor application that will replace the deprecated Builder.

Builder remains available at `/builder` while Editor is being developed, but its frontend is frozen now. Editor must not import, modify, copy from, or depend on Builder's views, CSS, JavaScript, routes, or asset assemblers.

The only shared implementation surface is the existing backend: services, models, contracts, adapters, and block schemas. Those are connected later, after the UI and interaction model are complete.

## Product lifecycle

### Builder — deprecated

- Existing route: `/builder`
- Existing frontend remains live during the migration
- No further Builder frontend work
- Existing Builder files are not edited during Editor development
- Builder frontend is deleted after Editor is complete and verified
- The existing backend services are retained and reused where appropriate

### Editor — replacement

- New application route: `/editor`
- New views, CSS, JavaScript, and controllers
- Developed independently from Builder
- Built first against exported design HTML and fixture/mock data
- Connected to the existing backend in a later phase

`ui/` primitives are verified via the `/editor/components` gallery route (not `/editor/ui-kit`) — see the Routes section below. The `/editor` application page itself is verified directly once it exists.

## Directory structure

```text
resources/
├── css/
│   ├── tokens.css                         # Shared CSS custom properties
│   ├── builder/                           # Deprecated; frozen until deletion
│   └── editor/                            # Editor CSS, flat and numerically ordered
│       ├── 00-base.css
│       ├── 10-components.css
│       ├── 20-shell.css
│       ├── 30-canvas.css
│       ├── 40-inspector.css
│       └── ...
│
├── js/
│   ├── builder/                            # Deprecated; frozen until deletion
│   └── editor/                             # Editor JavaScript, flat and ordered
│       ├── 00-bootstrap.js
│       ├── 10-selection.js
│       ├── 20-canvas.js
│       ├── 30-inspector.js
│       ├── 40-history.js
│       └── ...
│
├── views/
│   ├── components/
│   │   ├── ui/                             # Low-level presentational primitives
│   │   │   ├── button.blade.php
│   │   │   ├── field.blade.php
│   │   │   ├── select.blade.php
│   │   │   ├── segmented-control.blade.php
│   │   │   ├── slider.blade.php
│   │   │   ├── tabs.blade.php
│   │   │   └── ...
│   │   │
│   │   └── live/                           # Composed Editor pieces
│   │       ├── topbar.blade.php
│   │       ├── sidebar.blade.php
│   │       ├── canvas.blade.php
│   │       ├── inspector.blade.php
│   │       └── ...
│   │
│   ├── editor/                             # Editor application pages and composition
│   │   ├── layouts/
│   │   │   └── app.blade.php
│   │   ├── fixtures/
│   │   │   ├── document.php
│   │   │   └── blocks.php
│   │   └── index.blade.php
│   │
│   └── builder/                            # Deprecated; frozen until deletion
│
└── blocks/                                 # Existing block JSON contracts; retained

src/
├── Http/
│   └── Controllers/
│       ├── BuilderController.php           # Existing; unchanged during migration
│       └── EditorController.php            # New Editor page/assets controller
│
├── Livewire/
│   └── Editor/                             # Phase 3 only; requires approval first
│       ├── Topbar.php
│       ├── Sidebar.php
│       ├── Inspector.php
│       └── Canvas.php
│
└── Services/                               # Existing backend; reused in Phase 3

routes/
└── web.php                                 # New Editor routes added separately

docs/
├── file-structure.md                       # This document
└── ...
```

## Component placement rules

### `resources/views/components/ui/`

This directory contains small, reusable design-system primitives.

They are:

- Presentational only
- Stateless
- Independent of backend services
- Independent of Livewire
- Driven by Blade properties and slots
- Safe to use from Editor pages and future package surfaces

Examples include buttons, fields, selects, tabs, sliders, panels, and segmented controls.

### `resources/views/components/live/`

This directory contains larger composed pieces that will eventually need server state or persistence.

During the initial UI phases they remain ordinary Blade components using fixtures and Alpine. In the backend phase, Livewire is layered underneath them or they are converted into Livewire components under `src/Livewire/Editor/`.

The name describes their intended future responsibility. It does not mean Livewire is installed now.

### `resources/views/editor/`

This directory is reserved for the Editor application itself:

- The Editor page and layout
- Editor-specific composition
- Fixture data
- Page-level markup that is not a reusable design-system component

## Theme tokens

The shared token file is:

```text
resources/css/tokens.css
```

It is intentionally at the root of `resources/css/`, next to `builder/` and `editor/`.

It is named `tokens.css`, not `theme.css`, because `Theme` is already a first-class backend concept in Heisenberg. The distinction is:

- `tokens.css` contains CSS custom properties and design-system values.
- `Theme` is the persisted, user-editable backend configuration.
- In the integration phase, the persisted Theme service may populate or override selected CSS custom properties at runtime.

Token values will be derived from the exported Pencil HTML/design references. The old Builder token file is not a source for the new Editor design system.

## CSS scoping

The shared design system uses a dedicated root class, currently proposed as:

```html
<div class="hb-ui">
    <div class="hb-editor">
        ...
    </div>
</div>
```

Responsibilities:

- `.hb-ui`: shared token scope, base rules, and UI primitives
- `.hb-editor`: Editor application shell, canvas, panels, and page layout

The final shared root name remains open for review. `.hb-editor` remains reserved for the Editor application shell and is not used as the global token root.

All new CSS must be scoped so it cannot collide with the deprecated Builder styles or host-application styles. No new Editor CSS may import or include `resources/css/builder/**`.

## Buildless asset serving

Heisenberg has no JavaScript build toolchain. Editor keeps the existing buildless principle but uses a separate implementation.

`EditorController` will assemble assets by:

1. Reading `resources/css/tokens.css` explicitly first.
2. Reading `resources/css/editor/*.css` with `glob()`.
3. Sorting the Editor files with `SORT_STRING`.
4. Concatenating and returning the result as CSS.
5. Reading `resources/js/editor/*.js` with `glob()`.
6. Sorting the JavaScript files with `SORT_STRING`.
7. Concatenating and returning the result as JavaScript.

The token file is outside the Editor glob by design, so the controller must include it explicitly.

The new assembler must not call BuilderController methods and must not include Builder asset directories.

Planned asset endpoints are implementation details to be finalized with the routes, but they will be Editor-specific and will not change the existing Builder endpoints.

## Routes

The new application route is:

```text
/editor
```

The existing Builder route remains untouched during development:

```text
/builder
```

Visual verification of `ui/` primitives happens through `/editor/components` (`EditorController::components`) — a gallery page rendering each primitive with fixture props, screenshotted with Playwright and checked against the live Pencil source. The `/editor` application shell page itself is verified separately once it exists.

## Design input

Pencil MCP is not currently able to access the active Heisenberg design file. Therefore, Editor implementation will use exported `.html` pages supplied from Pencil.

Those exports are treated as design references for:

- Layout hierarchy
- Visible controls
- Typography
- Spacing
- Colors and borders
- Component states
- Responsive behavior where represented

The exported HTML is reference material, not a production dependency. It will not be copied blindly into the package. The Editor Blade components and scoped CSS will be authored from the inspected design structure.

## State and technology boundary

### Phase 1 — visual foundation

- Blade components
- Editor routes and shell
- CSS tokens
- Scoped Editor CSS
- Fixture/mock data
- No Livewire
- No backend persistence
- No Composer dependency change

### Phase 2 — client interaction

- Alpine.js for immediate interaction
- Canvas selection
- Hover and active states
- Drag/reorder interaction
- Inspector preview changes
- Inline editing
- Undo/redo

Alpine owns temporary, high-frequency interaction state. These operations must not perform a Livewire request for every movement or slider change.

### Phase 3 — backend integration

This phase is gated on explicit approval to add Livewire as a package dependency.

Livewire will own:

- Persisted Editor state
- SEO and post settings
- Media dialog state
- Theme editing and commits
- Block insertion, deletion, reordering, and save operations

Existing backend services remain the implementation authority, including block rendering, block contracts, supports, registries, payload validation, theme/font/media/preview services, models, contracts, and adapters.

## Isolation rules

The following are prohibited during Editor development:

- Editing `resources/js/builder/**`
- Editing `resources/css/builder/**`
- Editing `resources/views/builder/**`
- Importing Builder JavaScript or CSS
- Calling BuilderController asset assemblers from Editor
- Copying Builder implementation as a dependency
- Reusing Builder global class names without an explicit isolated replacement
- Adding Livewire to `composer.json` before approval
- Connecting to backend persistence during the fixture-driven UI phase

The old Builder may be inspected for behavior when necessary, but inspection does not create a dependency.

## Verification requirements

Every UI milestone must be verified in a real browser before it is described as complete.

Required checks:

- Start the Testbench server.
- Open `/editor` in Chrome through Playwright.
- Read the rendered screenshot directly.
- Verify that the Editor page is visually consistent with the exported design reference.
- Confirm the new asset responses contain only Editor/token assets.
- Confirm the markup is rooted under the chosen shared scope.
- Confirm `/builder` still renders.
- Run the existing JavaScript history test.
- Run the package PHPUnit suite.

A template compiling or a route returning HTTP 200 is not sufficient visual verification.

## Completion and migration

Editor is not considered a Builder replacement until:

- All required design pages are implemented.
- Core interactions are working.
- Backend integration is complete and tested.
- Browser screenshots have been reviewed.
- Existing Builder behavior needed for migration has been covered.
- PHPUnit and JavaScript regression checks are green.

Only then will the deprecated Builder frontend be removed. The final migration may either retire `/builder` or provide a compatibility redirect to `/editor`; that decision is made at deletion time.

## Open decisions for review

1. Final name of the shared design-system root (`.hb-ui` is the current proposal).
2. Exact asset endpoint names for Editor.
3. Whether `components/live/` remains the permanent name or is renamed before Phase 3.
4. Livewire as a hard Composer dependency.
5. Final `/builder` behavior after deletion: removal or compatibility redirect.
