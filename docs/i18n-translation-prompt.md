# Heisenberg — French localisation brief (for the translation agent)

Hand this file to the translating agent as its prompt. It is self-contained.

**Repo:** `Heisenberg` — a Laravel *package* providing a block-based post/page editor.
**Goal:** the editor UI is usable end-to-end in French. A French user opens `/editor` and every
label, button, tooltip, placeholder, panel title and screen-reader label is French.

---

## Work is in two parts

**Part 1 — `blocks.php` · AVAILABLE NOW.**
`resources/lang/en/blocks.php` exists (257 lines, fully keyed). Produce
`resources/lang/fr/blocks.php`. No dependency — start here.

**Part 2 — `editor.php` · NOT YET AVAILABLE.**
The editor chrome (~190 strings) is still hardcoded in Blade templates. The Heisenberg team is
extracting it into `resources/lang/en/editor.php` now. When that file lands, translate it to
`resources/lang/fr/editor.php` under the same rules. **Do not attempt Part 2 by editing Blade
templates** — you would be editing markup the team is concurrently rewriting, and the conflict
would be unrecoverable.

---

## Absolute rules

1. **Translate values only. Never touch keys.** `'title' => 'Paragraph'` → `'title' => 'Paragraphe'`.
   The key `title` is a code identifier read by PHP; changing it breaks the app.
2. **Preserve file structure exactly** — same nesting, same key order, same array shape. The French
   file must be a structural mirror of the English one. A missing key silently falls back to
   English; a renamed key throws.
3. **Keep it valid PHP.** `<?php`, `declare(strict_types=1);`, `return [ ... ];`. Escape apostrophes
   in single-quoted strings: `'Aujourd\'hui'` — French is apostrophe-heavy, so this matters
   constantly. Prefer `"…"` double quotes where a string contains `'`, but then escape any `$`.
4. **Never translate:** the brand name *Heisenberg*; block identifiers like `heisenberg/paragraph`;
   CSS classes; HTML tags; icon slugs; `{{ }}` or `:placeholder` tokens; format specifiers like
   `%s`, `%d`, `:count`, `:name`.
5. **Do not add, remove, reorder, or "improve" entries.** If an English string looks wrong, note it
   in your report — do not fix it unilaterally.
6. **No machine-translation artefacts.** Terms of art (below) are fixed; do not paraphrase them
   differently in different places.

---

## Register and style

- **Vouvoiement.** Address the user formally where a pronoun is unavoidable. Most UI strings should
  avoid pronouns entirely.
- **Buttons and actions take the infinitive**, French UI convention: *Enregistrer*, *Publier*,
  *Supprimer*, *Annuler* — **not** the imperative *Enregistrez*.
- **Sentence case for labels and descriptions.** French does not use English title case. "Featured
  image" → "Image à la une", not "Image À La Une".
- **Descriptions are short sentences with a final period**, matching the English (e.g. "Plain text
  in your story." → "Du texte simple dans votre article.").
- **Labels and button text take no final period.**
- **Non-breaking spaces** before `:` `;` `!` `?` and inside `«  »`, per French typography. Use the
  literal U+00A0 character.
- **Apostrophes:** use the typographic `’` in prose values where the English uses `'`. In PHP
  single-quoted strings this needs no escaping, which also sidesteps rule 3.

---

## Fixed glossary — use these consistently

| English | French | Note |
|---|---|---|
| Block | Bloc | |
| Post | Article | not "Poste" |
| Page | Page | |
| Draft | Brouillon | |
| Publish / Published | Publier / Publié | |
| Pending review | En attente de relecture | |
| Save / Saved | Enregistrer / Enregistré | not "Sauvegarder" |
| Settings | Paramètres | |
| Canvas | Zone d’édition | |
| Inspector | Inspecteur | |
| Sidebar | Barre latérale | |
| Toolbar | Barre d’outils | |
| Preview | Aperçu | |
| Featured image | Image à la une | standard FR CMS term |
| Excerpt | Extrait | |
| Tags | Étiquettes | |
| Categories | Catégories | |
| Slug | Slug | keep — established in FR dev usage |
| Heading | Titre | |
| Paragraph | Paragraphe | |
| Spacer | Espacement | |
| Separator / Divider | Séparateur | |
| Quote / Pullquote | Citation / Citation mise en avant | |
| Button | Bouton | |
| Image | Image | |
| Embed | Contenu intégré | |
| Code | Code | |
| List | Liste | |
| Columns / Column | Colonnes / Colonne | |
| Drop cap | Lettrine | |
| Anchor / Id | Ancre / Id | |
| Class | Classe | |
| Alignment | Alignement | |
| Typography | Typographie | |
| Fill | Remplissage | |
| Stroke | Contour | |
| Border | Bordure | |
| Radius | Rayon | |
| Shadow | Ombre | |
| Opacity | Opacité | |
| Width / Height | Largeur / Hauteur | |
| Padding / Margin | Marge intérieure / Marge extérieure | |
| Animation type | Type d’animation | |
| Duration / Delay | Durée / Délai | |
| Extra small / Small / Medium / Large devices | Très petits / Petits / Moyens / Grands écrans | |

---

## Length constraints (important — this UI is narrow)

The chrome is tight and **French runs ~15–25% longer than English**. Overflow is a real failure
mode here, not a hypothetical.

| Surface | Width | Guidance |
|---|---|---|
| Left sidebar | 180px | very short labels — 1 word where possible |
| Side panel | 240px | short |
| Inspector (right) | 260px | short; control labels sit beside their input |
| Block toolbar | icon-only | only `aria-label`s — length is unconstrained |
| Footer pills | small | 1–2 words |

Where a faithful translation would clearly overflow, prefer a shorter accurate synonym and **list
the compromise in your report**. Do not silently truncate.

Screen-reader strings (`aria-label`) are never visible — prioritise clarity over brevity there.

---

## Deliverables

1. `resources/lang/fr/blocks.php` — Part 1.
2. `resources/lang/fr/editor.php` — Part 2, once `en/editor.php` exists.
3. A short report listing: any English strings that looked wrong or ambiguous; every place you
   shortened a translation to fit; any term you had to add to the glossary.

## Self-check before delivering

- [ ] `php -l resources/lang/fr/blocks.php` passes (syntax valid)
- [ ] Key set is **identical** to the English file — same keys, same nesting, same order
- [ ] No key was translated; no value was left in English
- [ ] Every `'` inside a single-quoted value is escaped, or the value uses `’`
- [ ] No placeholder token (`%s`, `:count`, `{{ … }}`) was altered
- [ ] Glossary terms are used consistently throughout
