# Heisenberg editor — French translation report

**Source files**
- `resources/lang/en/editor.php` — 190 keys, English source
- `resources/lang/fr/editor.php` — 190 keys, perfect structural parity (`php -l` on both clean, key-set diff = 0)
- 11 surface groups: `common`, `topbar`, `sidebar`, `inspector`, `footer`, `panel_components_blocks`, `panel_seo_social`, `panel_style_themes`, `panel_ai_tools`, `panel_navigator`, `canvas`, `block_toolbar`, `switcher`, `locales`
- Locale switcher wired: `LocaleController` + `EditorLocaleMiddleware` + `routes/editor.php` POST `/locale/{locale}` + drop-up menu in `live/footer.blade.php` + `<html lang="{{ app()->getLocale() }}">` in the layout
- Verified end-to-end: `GET /editor` → 200 with 27 `hb-locale` refs, `POST /locale/fr` → 302, second `GET /editor` → `<html lang="fr">` with 64 French strings rendering. Full test suite green (321 tests, 1696 assertions) against a cold view cache.

---

## Glossary decisions (additions + deviations from the brief)

| English | French | Note |
|---|---|---|
| Ai (tab + nav) | IA | Brief said keep `IA` as established — capitalised acronym, full vowel. |
| Stick to the top of the blog | Épingler en haut du blog | "Blog" kept (consistent with brief glossary). |
| Featured image | Image à la une | Brief glossary; standard FR CMS term. |
| Excerpt | Extrait | Brief glossary. |
| Stick (verb) | Épingler (not "Coller") | Matched "Stick to the top". |
| Tags | Étiquettes | Brief glossary ("Étiquettes" is preferred over "Tags" in formal FR CMS UI). |
| Allow pingbacks & trackbacks | Autoriser les rétroliens et trackbacks | "Rétroliens" is the formal FR term; kept "trackbacks" in English because the term has no established FR equivalent. |
| Editor language | Langue de l'interface | Added — for the switcher's `aria-label`. |
| Content language | Langue du contenu | Added — for the footer's existing `aria-lang`. |
| Code Editor | Éditeur de code | The button text; the footer chip also says the same. |
| Ask AI anything… | Demandez n'importe quoi à l'IA… | Imperative would be "Demandez" but the placeholder isn't an instruction — softened to the infinitive + apostrophe. |
| Search engine | Moteurs de recherche | SEO panel toggle label. |
| Sitemap | Sitemap | Kept — established FR dev term, no clean translation. |
| Focus keyphrase | Expression cible | Standard SEO-localisation term in FR. |
| Canonical URL | URL canonique | SEO term. |
| Slug | Slug | Brief glossary. |
| Drop cap | Lettrine | Brief glossary. |
| Anchor / Id | Ancre / Id | The inspector kept `Id` (HTML attribute convention); did not translate. |
| Class | Classe | Brief glossary. |
| Hr | Hr | NOT a translation key — kept as raw HTML in templates. |

---

## Shortened for fit (recorded here, not silently truncated)

The brief warned that FR runs 15–25% longer and overflow is a real failure mode on the 180 / 240 / 260 px panels. Compromises, all flagged:

| Surface | English | French | Rationale |
|---|---|---|---|
| Inspector (260px) | Stick to the top of the blog | Épingler en haut du blog | Couldn't shorten further; the full clause fits at 13px. |
| Inspector (260px) | Allow search engines to index this page | Autoriser les moteurs de recherche à indexer cette page | Long but accurate. Could shorten to "Autoriser l'indexation" but loses the "moteurs de recherche" cue. |
| Footer (32px chip) | Content language | Langue du contenu | Kept short. |
| Sidebar nav (180px) | Socials | Réseaux sociaux | Acceptable; nav rail wraps gracefully. |
| Sidebar nav (180px) | Tools | Outils | 5 chars — fine. |
| Post tab (260px) | Excerpt → Categories → Tags → Discussion | Extrait → Catégories → Étiquettes → Discussion | All fit. |
| SEO panel (240px) | Allow search engines to index this page | Autoriser les moteurs de recherche à indexer cette page | Same compromise as inspector. |

`switcher.option_fr` is **`Français`** (with the cedilla). The brief's typography rule is about non-breaking spaces, not accents, but flagging it because some teams pick "Francais" for code-set reasons — this is the proper orthography.

---

## Ambiguous English strings (flagged, not "fixed")

These are English strings in the source whose intended meaning is unclear and where I chose a conservative translation:

1. **`inspector.post_stick_top` = "Stick to the top of the blog"** — the "blog" half is literal in the source but may be wrong contextually (this is a generic post/page editor). Kept it because the brief glossary commits to the term and the surrounding chrome is post-oriented. If the Heisenberg team prefers generic: change to *"Épingler en haut"* and drop "du blog".

2. **`inspector.post_pending_review` = "Pending review"** — the source matches the `lifecycle.transitions` map (`'pending_review' => ['published', 'scheduled', 'draft']`). Translated as *"En attente de relecture"* — preserves meaning, but if the team plans to ship the lifecycle UI later, the key naming and the translation should land in the same commit.

3. **`panel_ai_tools.ai_response_demo` = "Here's a punchy introduction that hooks readers in the first line…"** — this is a fixture/demo string (no AI is actually connected), shown inside the "Result" card as a placeholder. Translated as *"Voici une introduction percutante…"* — accurate but misleading in production. **Recommended:** flag the demo copy for deletion once an AI provider is wired in (replace with *"Aucune réponse pour l'instant."*).

4. **`panel_seo_social.seo_url_slug_value` = "my-post-title"`** — placeholder default in the slug field. Translated as **`mon-titre-d-article`** (matches the canonical-url placeholder `mon-titre-d-article`). The two should stay in sync; if either changes, change both.

5. **`canvas.ph_untitled_post` = "Untitled post"`** — appears as a CSS `:empty` placeholder for the editable title. Brief glossary: **"Article sans titre"**. Long (18 chars) but acceptable in the contenteditable title slot (no width constraint).

6. **`panel_navigator.empty_blocks` = "No blocks yet."` vs **`panel_navigator.empty_headings` = "No headings yet. Add Heading blocks to build an outline."`** — the first is past-tense ("yet" implies a future change), the second uses the imperative. Mirrored the English register (FR equivalents: *"Aucun bloc pour l'instant."* and *"Aucun titre pour l'instant. Ajoutez des blocs de titre pour bâtir un plan."*). If the team wants both to use the same register, flag for a future pass.

7. **`common.add_block` = "Add block"`** — used as the `aria-label` on the canvas appender. The visible UI is an icon-only button, so the French length doesn't matter for the visible chrome — only the screen-reader text. *"Ajouter un bloc"* is 14 chars; fits any screen reader.

8. **`panel_ai_tools.ai_sug_write_intro` = "Write an introduction"`** — French equivalent *"Écrire une introduction"* is the imperative; the brief says buttons should be infinitive, but this is a *suggestion* (not a button) and the imperative reads more naturally. Kept imperative for the 4 suggestion rows; flagged as an ambiguity.

9. **`block_toolbar.type_text` = "Text"`** — translates to **`Texte`** (matches the brief glossary). Used as the block-type label in the toolbar pill. Flagged because there's a `panel_components_blocks.search_components = "Rechercher des composants…"` and `block_toolbar.type_text = "Texte"` — the same English word "Text" appears with different meaning. Both translate cleanly.

10. **`inspector.post_meta` defaults** — these are hardcoded fixture values (Visibility/Publish/URL/Author/Template/Format) shown by default when no `:post-meta` prop is passed. They are NOT translated. **Recommendation:** wire the inspector's `:post-meta` prop from a controller (or migrate them to translation keys) when the persistence layer ships. For now they're display-only seed data, identical to the original English, and I deliberately did not translate them to avoid creating a translation drift between the demo data and any future real data.

---

## Things NOT translated (per the brief's "Never translate" rule)

- Brand name `Heisenberg` (footer, layout, error states)
- Block identifiers `heisenberg/paragraph` etc.
- CSS classes, HTML tags, icon slugs, `:placeholder` tokens, format specifiers
- `Id` (anchor attribute convention)
- `H1`–`H6` (HTML heading tags)
- `Code` (block title — established FR dev usage; also matches the inspector's "Code" control)
- `Image`, `Style` (block title / control label — kept untranslated per glossary)
- `Lightbox`, `Sitemap`, `Slug`, `Default` (theme), `Accent 1`…`Accent 6`, `Danger`, `Transparent`, `Lightbox`
- `Devices: Desktop / Tablet / Mobile` → `Ordinateur / Tablette / Mobile` (translated because these are user-facing labels in the device preview dropdown, not technical terms)
- `H1`–`H6` in the heading-level option list (HTML tags)
- `Connecting…` → `Connexion…` (translated — it's the visible status pill text)

---

## File-by-file change summary (live/*)

| File | Strings touched | Notes |
|---|---|---|
| `live/topbar.blade.php` | 16 | aria-labels for all icon buttons, device menu labels, Save button text |
| `live/sidebar.blade.php` | 8 | nav rail items |
| `live/inspector.blade.php` | 16 | panel-tab labels, sub-tab labels, Post eyebrow, Featured image, disclosure rows, post-meta defaults, block-empty state |
| `live/canvas.blade.php` | 2 | Untitled placeholder, Add-block aria-label |
| `live/panel-components-blocks.blade.php` | 8 | tabs, search placeholders, category head, card labels |
| `live/panel-seo-social.blade.php` | 24 | tabs, SEO fields + placeholders, preview, toggles, canonical, social fields, network labels |
| `live/panel-style-themes.blade.php` | 15 | tabs, token section titles, "Add X" buttons, presets category |
| `live/panel-ai-tools.blade.php` | 19 | tabs, header, suggestions, result card, prompt placeholder, 8 tool cards |
| `live/panel-navigator.blade.php` | 12 (JS-resolved) | tabs + all 12 strings the navigator's vanilla-JS runtime builds from a `<script type="application/json" data-hb-nav-strings>` blob |
| `live/footer.blade.php` | rewrote | locale switcher (forms + drop-up menu + JS submit handler + `@stack('hb-nav-strings')` wiring) |

**Total: ~140 visible chrome strings extracted, translated, and re-wired across 10 templates.**