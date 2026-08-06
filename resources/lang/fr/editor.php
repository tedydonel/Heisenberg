<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Libellés du chrome de l'éditeur (français) — heisenberg::editor.*
|--------------------------------------------------------------------------
| Résout les libellés, infobulles, espaces réservés, titres de panneaux et
| textes pour lecteurs d’écran utilisés par les surfaces de l’éditeur
| (barre supérieure, barre latérale, panneaux latéraux, inspecteur, pied
| de page, barre d’outils, zone de dessin). Le lot est piloté par les
| gabarits : chaque fichier Blade sous resources/views/components/live
| appelle __() / trans() sur ces clés, et un rechargement HTML après un
| changement de langue re-rend toute la coquille. (Blueprint §11.3)
*/

return [

    // ── Chrome commun (partagé par plusieurs surfaces) ───────────
    'common' => [
        'save' => 'Enregistrer',
        'cancel' => 'Annuler',
        'saved' => 'Enregistré',
        'connecting' => 'Connexion…',
        'published' => 'Publié',
        'untitled' => 'Sans titre',
        'no_title' => 'Sans titre',
        'no_block_selected_title' => 'Aucun bloc sélectionné',
        'no_block_selected_desc' => 'Sélectionnez un bloc dans la zone de dessin pour modifier ses paramètres.',
        'no_block_empty_panel' => 'Sélectionnez un bloc dans la zone de dessin pour voir ses paramètres ici.',
        'untitled_post' => 'Article sans titre',
        'add_block' => 'Ajouter un bloc',
        'send' => 'Envoyer',
        'close' => 'Fermer',
        'retry' => 'Réessayer',
    ],

    // ── Barre supérieure (live/topbar.blade.php) ──────────────────
    'topbar' => [
        'aria_home' => 'Accueil',
        'aria_menu' => 'Menu',
        'aria_panel_left' => 'Afficher / masquer le panneau de gauche',
        'aria_undo' => 'Annuler',
        'aria_redo' => 'Rétablir',
        'aria_preview' => 'Aperçu',
        'aria_fullscreen' => 'Plein écran',
        'aria_layers' => 'Calques',
        'aria_theme' => 'Activer / désactiver le mode sombre',
        'aria_open_new_tab' => 'Ouvrir dans un nouvel onglet',
        'aria_device' => 'Aperçu par appareil',
        'aria_panel_right' => 'Afficher / masquer le panneau de droite',
        'device_desktop' => 'Ordinateur',
        'device_tablet' => 'Tablette',
        'device_mobile' => 'Mobile',
        // Messages d'échec d'enregistrement (via hb:save-state → la pastille du pied de page).
        'save_conflict' => 'Cet article a été modifié ailleurs — rechargez et réessayez.',
        'save_invalid' => 'Enregistrement impossible — vérifiez le formulaire.',
        'save_network' => 'Erreur réseau — vérifiez votre connexion.',
    ],

    // ── Médias : dialogue / bibliothèque / zone de dépôt (live/media/*) ──
    'media' => [
        'select_featured_image' => 'Choisir l\'image mise en avant',
        'tab_upload' => 'Téléverser des fichiers',
        'tab_library' => 'Bibliothèque de médias',
        'search_ph' => 'Rechercher des médias…',
        'empty' => 'Aucun média — téléversez un fichier pour commencer.',
        'load_error' => 'Impossible de charger la bibliothèque. Réessayez.',
        'upload_failed' => 'Le téléversement a échoué.',
        'upload_network' => 'Le téléversement a échoué. Vérifiez votre connexion et réessayez.',
        'uploading' => 'Téléversement…',
        'dropzone_title' => 'Déposez des fichiers pour les téléverser',
        'dropzone_desc' => 'Les images sont optimisées automatiquement. Les documents sont conservés tels quels. Taille maximale : 10 Mo chacun.',
    ],

    // ── Éditeur d'effets (live/pickers/effect-editor.blade.php) ───
    'effects' => [
        'drop_shadow' => 'Ombre portée',
        'color' => 'Couleur',
        'blur' => 'Flou',
        'offset' => 'Décalage',
    ],

    // ── Barre latérale (live/sidebar.blade.php) ───────────────────
    'sidebar' => [
        'nav_components' => 'Composants',
        'nav_blocks' => 'Blocs',
        'nav_seo' => 'SEO',
        'nav_socials' => 'Réseaux sociaux',
        'nav_style' => 'Style',
        'nav_themes' => 'Thèmes',
        'nav_ai' => 'IA',
        'nav_tools' => 'Outils',
    ],

    // ── Inspecteur (live/inspector.blade.php) ─────────────────────
    'inspector' => [
        'tab_post' => 'Article',
        'tab_block' => 'Bloc',
        'subtab_content' => 'Contenu',
        'subtab_style' => 'Style',
        'subtab_advanced' => 'Avancé',
        'post_title_eyebrow' => 'TITRE DE L’ARTICLE',
        'post_title_placeholder' => 'Article sans titre',
        'post_title_label' => 'Titre de l’article',
        'post_featured_image' => 'Image à la une',
        'post_featured_set' => 'Définir l’image à la une',
        'post_featured_replace' => 'Remplacer l’image à la une',
        'post_featured_remove' => 'Retirer l’image à la une',
        'post_summary' => 'Résumé',
        'post_pending_review' => 'En attente de relecture',
        'post_stick_top' => 'Épingler en haut du blog',
        'post_move_trash' => 'Mettre à la corbeille',
        'post_categories' => 'Catégories',
        'post_category_add_ph' => 'Ajouter ou rechercher une catégorie…',
        'post_category_empty' => 'Aucune catégorie pour l’instant.',
        'post_tags' => 'Étiquettes',
        'post_tag_add_ph' => 'Ajouter ou rechercher une étiquette…',
        'post_tag_empty' => 'Aucune étiquette pour l’instant.',
        'post_taxonomy_needs_save' => 'Enregistrez d\'abord l\'article pour ajouter des catégories ou des étiquettes.',
        'post_discussion' => 'Discussion',
        'post_allow_comments' => 'Autoriser les commentaires',
        'post_page_layout' => 'Mise en page',
        'layer_opacity' => 'Opacité du calque',
        'pick_colour' => 'Choisir une couleur',
        'bind_theme_variable' => 'Lier à une variable de thème',
        'post_layout_padding_x' => 'X Padding',
        'post_layout_padding_y' => 'Y Padding',
        'block_empty' => 'Sélectionnez un bloc dans la zone de dessin pour modifier ses paramètres.',
    ],

    // ── Onglet Avancé du bloc (live/block/advanced.blade.php) ─────
    'advanced' => [
        'section_visibility' => 'Masquer selon la largeur d\'écran',
        'hide_xs' => 'Très petits écrans',
        'hide_sm' => 'Petits écrans',
        'hide_md' => 'Écrans moyens',
        'hide_lg' => 'Grands écrans',
        'hide_xl' => 'Écrans XL',
        'hide_xxl' => 'Écrans XXL',
        'section_animate' => 'Animer au défilement',
        'animation_type' => 'Type d\'animation',
        'duration' => 'Durée',
        'delay' => 'Délai',
        'easing' => 'Interpolation',
        'play_once' => 'Jouer une seule fois',
        'play_animation' => 'Jouer l\'animation',
    ],

    // ── Pied de page (live/footer.blade.php) ───────────────────────
    'footer' => [
        'aria_status' => 'État du document',
        'aria_lang' => 'Langue du contenu',
        'aria_code_editor' => 'Éditeur de code',
        'lang_label' => 'Français',
        'code_editor_label' => 'Éditeur de code',
        // États de la puce de statut d’enregistrement (live/footer.blade.php +
        // le câblage d’enregistrement de live/topbar.blade.php). « saved »
        // réutilise common.saved ; il n’y a pas de clé séparée pour cet état.
        'status_saving' => 'Enregistrement…',
        'status_unsaved' => 'Modifications non enregistrées',
        'status_offline' => 'Hors ligne',
        'status_conflict' => 'Conflit',
        'status_error' => 'Erreur',
    ],

    // ── Panneau Composants / Blocs (live/panel-components-blocks.blade.php)
    'panel_components_blocks' => [
        'tab_components' => 'Composants',
        'tab_blocks' => 'Blocs',
        'search_components' => 'Rechercher des composants…',
        'search_blocks' => 'Rechercher des blocs…',
        'category_base' => 'Base',
        'card_heading' => 'Titre',
        'card_image' => 'Image',
        'card_divider' => 'Séparateur',
        'card_button' => 'Bouton',
    ],

    // ── Panneau SEO / Social (live/panel-seo-social.blade.php) ────
    'panel_seo_social' => [
        'tab_seo' => 'SEO',
        'tab_social' => 'Réseaux sociaux',
        'search_themes' => 'Rechercher des thèmes…',
        'section_presets' => 'PRÉRÉGLAGES',
        'theme_default' => 'Par défaut',
        'theme_midnight' => 'Minuit',
        'theme_sunset' => 'Coucher de soleil',
        'theme_ocean' => 'Océan',
        'theme_forest' => 'Forêt',
        'theme_blush' => 'Rose poudré',
        'seo_title_label' => 'Titre SEO',
        'seo_title_ph' => 'Saisissez un titre SEO…',
        'seo_meta_label' => 'Méta-description',
        'seo_meta_ph' => 'Rédigez une courte description qui apparaîtra dans les résultats de recherche…',
        'seo_url_slug' => 'Slug de l’URL',
        'seo_url_slug_value' => 'mon-titre-d-article',
        'seo_url_slug_prefix' => 'votresite.com › blog › :slug',
        'seo_preview_title' => 'Votre titre SEO apparaît ici',
        'seo_preview_desc' => 'Rédigez une courte description qui apparaîtra dans les résultats de recherche…',
        'seo_focus_keyphrase' => 'Expression cible',
        'seo_focus_keyphrase_ph' => 'ex. recette de levain naturel',
        'seo_index_label' => 'Autoriser les moteurs de recherche à indexer cette page',
        'seo_sitemap_label' => 'Inclure dans le sitemap',
        'seo_follow_label' => 'Suivre les liens sur cette page',
        'seo_canonical' => 'URL canonique',
        'seo_canonical_ph' => 'https://votresite.com/blog/mon-titre-d-article',
        'seo_canonical_prefix' => '/blog/',
        'checklist_keyphrase_title' => 'Expression cible trouvée dans le titre SEO',
        'checklist_title_length' => 'La longueur du titre SEO est correcte',
        'checklist_slug_keyphrase' => 'Le slug de l’URL contient l’expression cible',
        'checklist_meta_missing' => 'La méta-description ne contient pas l’expression cible',
        'checklist_density_low' => 'La densité de l’expression cible est trop faible',
        'social_preview_heading' => 'Aperçu social',
        'social_set_image' => 'Définir l’image de partage',
        'social_title' => 'Titre social',
        'social_title_ph' => 'Identique au titre SEO',
        'social_description' => 'Description sociale',
        'social_description_ph' => 'Identique à la méta-description',
        'social_preview_label' => 'Aperçu',
        'social_facebook' => 'Facebook',
        'social_x' => 'X',
        'social_linkedin' => 'LinkedIn',
    ],

    // ── Panneau Style / Thèmes (live/panel-style-themes.blade.php) ─
    'panel_style_themes' => [
        'tab_style' => 'Style',
        'tab_themes' => 'Thèmes',
        'category_writing' => 'Rédaction',
        'token_colors' => 'Couleurs',
        'token_radius' => 'Rayons',
        'token_spacing' => 'Espacement',
        'token_fonts' => 'Polices',
        'token_font_sizes' => 'Tailles de police',
        'add_color' => 'Ajouter une couleur',
        'add_radius' => 'Ajouter un rayon',
        'add_spacing' => 'Ajouter un espacement',
        'add_font' => 'Ajouter une police',
        'add_size' => 'Ajouter une taille',
        'select_font_ph' => 'Choisir une police…',
        'save_to_themes' => 'Enregistrer dans Thèmes',
        'save_theme_name_ph' => 'Nom du thème…',
        'save_theme_confirm_aria' => 'Confirmer',
        'search_themes' => 'Rechercher des thèmes…',
        'category_your_themes' => 'Vos thèmes',
        'no_saved_themes' => 'Aucun thème enregistré pour l’instant',
        'delete_theme_aria' => 'Supprimer le thème',
        'category_presets' => 'Préréglages',
    ],

    // ── Panneau IA / Outils (live/panel-ai-tools.blade.php) ───────
    'panel_ai_tools' => [
        'tab_ai' => 'IA',
        'tab_tools' => 'Outils',
        'ai_assistant' => 'Assistant IA',
        'ai_subtitle' => 'Obtenez de l’aide pour rédiger, relire et optimiser votre article.',
        'ai_suggestions' => 'Suggestions',
        'ai_result' => 'Résultat',
        'ai_response_demo' => 'Voici une introduction percutante qui accroche le lecteur dès la première ligne et prépare la suite de votre article…',
        'ai_insert' => 'Insérer',
        'ai_regenerate' => 'Régénérer',
        'ai_prompt_ph' => 'Demandez n’importe quoi à l’IA…',
        'tool_generate_title' => 'Générer un titre',
        'tool_write_summary' => 'Rédiger un résumé',
        'tool_improve_writing' => 'Améliorer le texte',
        'tool_fix_grammar' => 'Corriger la grammaire',
        'tool_change_tone' => 'Changer le ton',
        'tool_generate_image' => 'Générer une image',
        'tool_translate' => 'Traduire',
        'tool_seo_optimize' => 'Optimiser le SEO',
        'sug_write_intro' => 'Écrire une introduction',
        'sug_improve_paragraph' => 'Améliorer ce paragraphe',
        'sug_fix_grammar' => 'Corriger la grammaire et l’orthographe',
        'sug_generate_title' => 'Générer un titre',
        'search_tools' => 'Rechercher des outils…',
        'category_writing' => 'Rédaction',
    ],

    // ── Panneau Navigateur (live/panel-navigator.blade.php) ───────
    'panel_navigator' => [
        'tab_list' => 'Vue en liste',
        'tab_outline' => 'Plan',
        'empty_blocks' => 'Aucun bloc pour l’instant.',
        'empty_headings' => 'Aucun titre pour l’instant. Ajoutez des blocs de titre pour bâtir un plan.',
        'stat_characters' => 'Caractères :',
        'stat_words' => 'Mots :',
        'stat_time' => 'Temps de lecture :',
        'minutes_one' => 'minute',
        'minutes_other' => 'minutes',
        'minutes_zero' => '0 minute',
        'empty_heading' => 'Titre vide',
        'block_fallback' => 'Bloc',
        'moved_announcement' => ':label déplacé à la position :pos sur :total.',
    ],

    // ── Barre d’outils des blocs (live/toolbar/*) ─────────────────
    'block_toolbar' => [
        'type_text' => 'Texte',
        'save_block' => 'Enregistrer',
        'more' => 'Plus',
        'undo' => 'Annuler',
        'redo' => 'Rétablir',
        'duplicate' => 'Dupliquer',
        'delete' => 'Supprimer',
        'bold' => 'Gras',
        'italic' => 'Italique',
        'underline' => 'Souligné',
        'strikethrough' => 'Barré',
        'link' => 'Lien',
        'text_color' => 'Couleur du texte',
        'background_color' => 'Couleur de fond',
        'align_left' => 'Aligner à gauche',
        'align_center' => 'Centrer',
        'align_right' => 'Aligner à droite',
        'transform_text' => 'Transformer le texte',
    ],

    // ── Zone de dessin (live/canvas.blade.php) ────────────────────
    'canvas' => [
        'ph_untitled_post' => 'Article sans titre',
    ],

    // ── Sélecteur de couleur (live/pickers/color-picker.blade.php) ─
    // Les noms de modèles colorimétriques (HEX/RGB/RGBA/HSL/HSLA/HSB) et les lettres
    // des champs (R/G/B/A/H/S/L/B) relèvent de la notation, pas de la prose : ils
    // restent identiques dans toutes les langues et ne sont donc pas traduits ici.
    'color_picker' => [
        'tab_fill' => 'Remplissage',
        'tab_gradient' => 'Dégradé',
        'stops' => 'Étapes',
        'type_linear' => 'Linéaire',
        'type_radial' => 'Radial',
        'type_conic' => 'Conique',
        'shape_circle' => 'Cercle',
        'shape_ellipse' => 'Ellipse',
        'aria_eyedropper' => 'Prélever une couleur à l’écran',
        'aria_copy' => 'Copier la valeur de la couleur',
        'aria_model' => 'Modèle colorimétrique',
        'aria_gradient_type' => 'Type de dégradé',
        'aria_gradient_angle' => 'Angle du dégradé',
        'aria_gradient_shape' => 'Forme du dégradé',
        'aria_reverse' => 'Inverser le dégradé',
        'aria_distribute' => 'Répartir les étapes uniformément',
        'aria_duplicate' => 'Dupliquer l’étape sélectionnée',
        'aria_add_stop' => 'Ajouter une étape',
        'aria_remove_stop' => 'Supprimer l’étape',
        'aria_select_stop' => 'Sélectionner l’étape',
        'aria_stop_hex' => 'Couleur de l’étape',
        'aria_stop_opacity' => 'Opacité de l’étape',
        'aria_stop_position' => 'Position de l’étape',
        'aria_stops' => 'Étapes du dégradé',
    ],

    // ── Sélecteur de langue (puce du pied de page) ────────────────
    'switcher' => [
        'aria_locale' => 'Langue de l’interface',
        'option_en' => 'English',
        'option_fr' => 'Français',
        'switched_to' => 'Langue changée : :locale.',
    ],

    // ── Langues livrées avec le paquet (utilisées par le sélecteur) ─
    'locales' => [
        'en' => 'English',
        'fr' => 'Français',
    ],

];