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
        // Menu déroulant de langue d'édition (docs/content-translation.md §0/Wave 2) — change la
        // langue dans laquelle tout le document est édité ; aucune navigation, aucune requête.
        'aria_post_language' => "Langue d'édition",
        // Export d'e-mail (docs/email-system.md §6) — le menu de téléchargement à côté d'Aperçu.
        'aria_email_export' => "Télécharger l'e-mail",
        'export_html' => 'Télécharger le HTML (pour les plateformes d’e-mailing)',
        'export_eml' => 'Télécharger le .eml (autonome)',
    ],

    // ── Médias : dialogue / bibliothèque / zone de dépôt (live/media/*) ──
    'icon_picker' => [
        'title' => 'Choisir une icône',
        'search_ph' => 'Rechercher des icônes…',
        'all_sets' => 'Tous les jeux',
        'empty' => 'Aucune icône ne correspond à cette recherche.',
        'load_more' => 'Charger plus',
        'field_empty' => 'Choisir une icône…',
    ],
    'media' => [
        'select_featured_image' => 'Choisir l\'image mise en avant',
        'select_social_image' => 'Choisir l\'image de partage social',
        'select_image' => 'Choisir une image',
        'tab_upload' => 'Téléverser des fichiers',
        'tab_library' => 'Bibliothèque de médias',
        'search_ph' => 'Rechercher des médias…',
        'empty' => 'Aucun média — téléversez un fichier pour commencer.',
        'load_error' => 'Impossible de charger la bibliothèque. Réessayez.',
        'upload_failed' => 'Le téléversement a échoué.',
        'upload_network' => 'Le téléversement a échoué. Vérifiez votre connexion et réessayez.',
        'upload_too_large' => 'Trop volumineux — la limite de téléversement est de :max.',
        'upload_rejected_by_server' => 'Le fichier n’a pas pu être téléversé — les fichiers dépassant :max (la limite PHP du serveur) sont rejetés avant même que l’application ne les voie.',
        'uploading' => 'Téléversement…',
        'dropzone_title' => 'Déposez des fichiers pour les téléverser',
        'dropzone_desc' => 'Les images sont optimisées automatiquement. Les documents sont conservés tels quels. Taille maximale : 10 Mo chacun.',
    ],

    // ── Réglages IA (live/ai/ai-settings-dialog.blade.php) ────────
    // Les libellés du panneau sont sous `panel_ai_tools` plus bas ; cette
    // section couvre la fenêtre de réglages et les messages du backend.
    'ai' => [
        'settings_title' => 'Réglages IA',
        'settings_open' => 'Réglages IA',
        'tab_providers' => 'Fournisseurs',
        'tab_models' => 'Modèles',
        'tab_mcp' => 'Serveurs MCP',
        'tab_expose' => 'Exposer',

        'no_provider' => 'Aucun fournisseur d\'IA n\'est configuré. Choisissez-en un dans les réglages IA.',
        'provider_not_configured' => 'Aucune clé API n\'est définie pour :provider. Ajoutez-en une dans votre environnement, puis rechargez.',
        'refused' => 'Le modèle a refusé cette requête.',
        'api_error' => ':provider a renvoyé une erreur (:detail).',
        'network_error' => 'Impossible de joindre :provider. Vérifiez le point d\'accès et réessayez.',
        'empty_prompt' => 'Écrivez quelque chose à soumettre à l\'assistant.',
        'not_allowed' => 'Vous n\'êtes pas autorisé à utiliser l\'assistant.',
        'tool_loop_exhausted' => 'L\'assistant a utilisé ses :max tours d\'outils et n\'a toujours pas pu terminer, même après une dernière tentative de réponse sans outils. Le texte déjà produit et les modifications déjà apportées au canevas sont conservés.',
        'stream_failed' => 'La réponse a été interrompue. Rien n\'est perdu - renvoyez-la.',
        'completion_failed' => 'Une erreur est survenue lors du traitement de cette demande. Rien n\'a été envoyé - réessayez.',

        'providers_intro' => 'Un fournisseur est un service chez qui vous avez un compte. Chacun a son point d\'acces, sa cle API et ses modeles.',
        'providers_empty' => 'Aucun fournisseur - ajoutez-en un ci-dessous.',
        'presets_title' => 'Ajouter un fournisseur connu',
        'preset_add' => 'Ajouter',
        'provider_add' => '+ Ajouter un fournisseur personnalise',
        'provider_add_button' => 'Ajouter le fournisseur',
        'provider_name' => 'Nom',
        'provider_format' => 'Format d\'API',
        'provider_format_hint' => 'La forme d\'API parlee par ce service. Presque tous utilisent celle d\'OpenAI.',
        'provider_configure' => 'Configurer le fournisseur',
        'provider_remove' => 'Supprimer le fournisseur',
        'provider_bad_input' => 'Un nom et une URL de base http(s) sont requis.',
        'provider_duplicate' => 'Un fournisseur portant ce nom existe deja.',
        'provider_use' => 'Utiliser',
        'connected' => 'Connecte',
        'not_connected' => 'Non connecte',
        'no_models_yet' => 'Aucun modele',
        'api_key_label' => 'Cle API',
        'key_save' => 'Enregistrer la cle',
        'key_saved' => 'Cle enregistree. Saisissez-en une nouvelle pour la remplacer',
        'key_enter' => 'Collez la cle API',
        'key_from_env' => ':env est utilisee depuis votre environnement - elle prime sur toute cle enregistree ici.',
        'key_env_hint' => 'Ou definissez :env dans votre environnement.',
        'key_env_label' => 'Variable d\'environnement (facultatif)',
        'key_env_optional' => 'Si definie, elle prime sur une cle enregistree ici. Laissez vide pour un point d\'acces local sans cle.',
        'discover_models' => 'Decouvrir les modeles',
        'discovering' => 'Interrogation du fournisseur...',
        'discover_failed' => 'Impossible de lister les modeles de ce fournisseur.',
        'discover_empty' => 'Ce fournisseur n\'a renvoye aucune liste - ajoutez les identifiants a la main.',
        'need_provider' => 'Ajoutez d\'abord un fournisseur.',

        'models_intro' => 'Tous les modeles utilisables, tous fournisseurs confondus. Chacun a sa propre intensite.',
        'model_none' => 'Aucun modele - decouvrez-les depuis un fournisseur, ou ajoutez-en un a la main.',
        'model_add' => '+ Ajouter un modele personnalise',
        'model_edit' => 'Modifier le modele',
        'model_id_label' => 'Identifiant du modele',
        'model_label_label' => 'Nom affiche (facultatif)',
        'model_provider_label' => 'Fournisseur',
        'model_in_use' => 'Utilise',
        'model_remove' => 'Supprimer le modele',
        'model_bad_input' => 'Un identifiant de modele peut contenir des lettres, des chiffres et . _ : / -',
        'model_duplicate' => 'Ce fournisseur possede deja un modele avec cet identifiant.',
        'effort_label' => 'Intensite',
        'effort_hint' => 'Une intensite elevee ameliore les reponses, mais consomme plus de jetons et de temps.',
        'base_url_label' => 'URL de base',

        'mcp_intro' => 'Connectez Heisenberg à des serveurs MCP pour que l\'assistant utilise leurs outils.',
        'mcp_empty' => 'Aucun serveur MCP pour le moment.',
        'mcp_disabled' => 'Le client MCP est désactivé. Définissez HEISENBERG_MCP_CLIENT=true pour l\'activer.',
        'mcp_add' => 'Ajouter un serveur',
        'mcp_add_button' => 'Ajouter',
        'mcp_auth_hint' => 'Un identifiant, une URL, et le NOM de la variable d\'environnement contenant le jeton — jamais le jeton lui-même.',
        'mcp_test' => 'Tester',
        'mcp_remove' => 'Supprimer le serveur',
        'mcp_testing' => 'Test en cours…',
        'mcp_test_failed' => 'Impossible de joindre ce serveur.',
        'mcp_no_tools' => 'Ce serveur ne propose aucun outil.',
        'mcp_tools_hint' => 'Cochez les outils que l\'assistant peut appeler. Tout ce qui n\'est pas coché ne pourra jamais s\'exécuter.',
        'mcp_bad_input' => 'Un identifiant (kebab-case) et une URL http(s) sont requis.',

        'expose_intro' => 'Permettez à d\'autres IA de se connecter à Heisenberg et d\'y construire des pages, avec les mêmes validations que l\'éditeur.',
        'expose_disabled' => 'Le serveur MCP est désactivé. Définissez HEISENBERG_MCP_SERVER=true pour l\'activer.',
        'expose_endpoint' => 'Point d\'accès',
        'expose_tokens' => 'Les jetons sont lus dans :env.',
        'expose_tools' => 'Outils appelables par d\'autres IA',

        'save' => 'Enregistrer',
        'saving' => 'Enregistrement…',
        'saved' => 'Enregistré',
        'save_failed' => 'Échec de l\'enregistrement. Réessayez.',
        'forbidden' => 'Vous n\'êtes pas autorisé à modifier la configuration IA.',
        'coming_soon' => 'Disponible dans une phase ultérieure.',
    ],

    // ── Page de modération des commentaires (comments/index.blade.php) — la
    // surface `GET /editor/comments` en JS natif. Le JS ne peut pas appeler
    // __() : les chaînes dont le script a besoin (gabarit de ligne, boîtes de
    // confirmation/réponse, pagination) sont aussi injectées dans un bloc JSON
    // `data-hb-comments-strings` (même posture que `data-hb-nav-strings` du
    // panneau navigateur). ':id'/':count'/':current'/':total' sont remplacés
    // côté client, pas interpolés ici.
    'comments' => [
        'title' => 'Commentaires',
        'search_ph' => 'Rechercher des commentaires…',
        'tab_pending' => 'En attente',
        'tab_approved' => 'Approuvés',
        'tab_spam' => 'Indésirables',
        'tab_trash' => 'Corbeille',
        'tab_all' => 'Tous',
        'loading' => 'Chargement…',
        'empty' => 'Aucun commentaire.',
        'empty_pending' => 'Aucun commentaire en attente.',
        'empty_approved' => 'Aucun commentaire approuvé.',
        'empty_spam' => 'Aucun indésirable.',
        'empty_trash' => 'La corbeille est vide.',
        'load_error' => 'Impossible de charger les commentaires.',
        'author_anonymous' => 'Anonyme',
        'reply_marker' => 'réponse à #:id',
        'reply_count_one' => ':count réponse',
        'reply_count_other' => ':count réponses',
        'action_approve' => 'Approuver',
        'action_spam' => 'Indésirable',
        'action_trash' => 'Corbeille',
        'action_delete' => 'Supprimer définitivement',
        'action_reply' => 'Répondre',
        'confirm_delete_text' => 'Supprimer définitivement ce commentaire ?',
        'confirm_delete_button' => 'Supprimer',
        'cancel' => 'Annuler',
        'reply_placeholder' => 'Rédigez une réponse…',
        'pager_label' => 'Page :current sur :total',
        'pager_prev' => 'Précédent',
        'pager_next' => 'Suivant',
    ],

    // ── Dialogue des révisions (live/revisions-dialog.blade.php) ──
    'revisions' => [
        'title' => 'Révisions',
        'empty' => 'Aucune révision — elles apparaissent au fil des enregistrements.',
        'loading' => 'Chargement…',
        'error' => 'Impossible de charger les révisions. Réessayez.',
        'needs_save' => 'Enregistrez d\'abord l\'article pour démarrer son historique.',
        'restore' => 'Restaurer',
        'blocks_count' => ':count blocs',
        'type_manual' => 'Enregistré',
        'type_auto' => 'Sauvegarde auto',
        'type_restore' => 'Point de restauration',
    ],

    // ── Table des matières (live/toc-dialog.blade.php) — la TOC rédigée de l'onglet Post,
    // réutilise 'title' pour le libellé du disclosure-row ET le titre du dialogue (même
    // convention que 'revisions' ci-dessus). ───────────────────────────────────────────
    'toc' => [
        'title' => 'Table des matières',
        'summary_empty' => 'Pas encore de table des matières.',
        'summary_count' => ':count entrées',
        'edit' => 'Modifier',
        'add_entry' => 'Ajouter une entrée',
        'load_headings' => 'Charger depuis les titres',
        'no_headings' => 'Aucun titre trouvé dans le document.',
        'no_new_headings' => 'Tous les titres figurent déjà dans la table des matières.',
        'label_ph' => 'Libellé de la section',
        'anchor_ph' => 'identifiant-ancre',
        'move_up' => 'Monter',
        'move_down' => 'Descendre',
        'remove' => 'Supprimer l\'entrée',
        'empty_list' => 'Aucune entrée pour le moment — ajoutez-en une ou chargez depuis les titres.',
        'error' => 'Impossible d\'enregistrer la table des matières. Réessayez.',
        'needs_save' => 'Enregistrez d\'abord l\'article pour ajouter une table des matières.',
        'incomplete' => 'Chaque entrée doit avoir un libellé et une ancre.',
        'invalid_anchor' => 'Les ancres doivent commencer par une lettre et ne contenir que des lettres, chiffres, « - » ou « _ ».',
    ],

    // ── Historique des conversations IA (live/ai/ai-history-dialog.blade.php) ──
    'ai_history' => [
        'title' => 'Historique des conversations',
        'empty' => 'Aucune conversation pour l\'instant — elles apparaissent ici au fil des échanges.',
        'loading' => 'Chargement…',
        'error' => 'Impossible de charger vos conversations. Réessayez.',
        'untitled' => 'Conversation sans titre',
        'messages_count' => ':count messages',
        'open' => 'Ouvrir',
        'select' => 'Sélectionner la conversation',
        'selected_count' => ':count sélectionnée(s)',
        'delete' => 'Supprimer',
        'confirm_delete' => 'Supprimer :count ?',
        'cancel' => 'Annuler',
    ],

    // ── Vue code (live/code-editor.blade.php) ─────────────────────
    'code' => [
        'aria_input' => 'Source en shortcodes',
        'placeholder' => '[h2]Votre titre[/h2]',
        'errors_title' => 'Corrigez ces erreurs avant de revenir en mode visuel :',
        'revert' => 'Rétablir depuis la zone de dessin',
        'line_label' => 'Ligne :line',
        'err_unknown_block' => 'Bloc inconnu « :slug »',
        'err_unknown_attr' => 'Attribut ou chemin de support inconnu « :name » sur [:slug]',
        'err_invalid_value' => 'Valeur invalide pour « :name » sur [:slug]',
        'err_no_children' => '[:slug] ne peut pas contenir d\'autres blocs',
        'err_no_body' => '[:slug] n\'accepte pas de contenu texte',
        'err_stray_close' => 'Balise fermante inattendue [/:slug]',
        'err_unclosed' => '[:slug] ouvert à la ligne :line n\'est jamais fermé',
        'err_outside' => 'Contenu en dehors de tout bloc',
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
        'summary_status' => 'Statut',
        'summary_publish' => 'Publication',
        'summary_url' => 'URL',
        'summary_immediately' => 'Immédiatement',
        // Le champ de slug modifiable de la ligne URL (2026-08-11).
        'summary_slug_label' => 'Slug',
        'summary_slug_placeholder' => 'slug-article',
        // Options du contrôle de statut (EditorController::statusLabel()) — le suffixe de la
        // clé est le nom de statut brut de config('heisenberg.lifecycle.transitions').
        'summary_status_draft' => 'Brouillon',
        'summary_status_pending_review' => 'En attente de relecture',
        'summary_status_published' => 'Publié',
        'summary_status_scheduled' => 'Programmé',
        'summary_status_archived' => 'Archivé',
        'summary_status_save_first' => 'Enregistrez l\'article pour définir son statut.',
        // Affiché tant qu'un statut choisi n'est pas encore persisté (mis en attente pour
        // le prochain Enregistrer explicite — voir data-hb-pending dans post-meta-live-script.blade.php).
        'summary_status_pending_hint' => 'Pas encore enregistré — cliquez sur Enregistrer pour appliquer.',
        // Même logique de mise en attente, pour les lignes Slug et Date de publication
        // (2026-08-12) — voir setRowPending() dans post-meta-live-script.blade.php.
        'summary_slug_pending_hint' => 'Pas encore enregistré — cliquez sur Enregistrer pour appliquer.',
        'summary_publish_pending_hint' => 'Pas encore enregistré — cliquez sur Enregistrer pour appliquer.',
        'summary_schedule_label' => 'Publier le',
        'post_pending_review' => 'En attente de relecture',
        'post_stick_top' => 'Épingler en haut du blog',
        'post_move_trash' => 'Mettre à la corbeille',
        // Confirmation en deux temps (post-trash-script.blade.php) — libellé affiché après le
        // premier clic, avant que le second clic déclenche réellement la suppression.
        'post_move_trash_confirm' => 'Cliquez à nouveau pour confirmer',
        'post_move_trash_save_first' => 'Enregistrez l’article pour le mettre à la corbeille.',
        'post_categories' => 'Catégories',
        'post_category_add_ph' => 'Ajouter ou rechercher une catégorie…',
        'post_category_empty' => 'Aucune catégorie pour l’instant.',
        'post_tags' => 'Étiquettes',
        'post_tag_add_ph' => 'Ajouter ou rechercher une étiquette…',
        'post_tag_empty' => 'Aucune étiquette pour l’instant.',
        'post_taxonomy_needs_save' => 'Enregistrez d\'abord l\'article pour ajouter des catégories ou des étiquettes.',
        'post_discussion' => 'Discussion',
        'post_allow_comments' => 'Autoriser les commentaires',
        // Traductions (docs/content-translation.md §0/Wave 2) — une ligne par langue configurée,
        // montrant à quel point le texte de cette langue est COMPLET sur cet unique article ;
        // cliquer sur une ligne change la langue d'édition. Les noms de langue réutilisent le
        // groupe 'locales' ci-dessous.
        'post_translations' => 'Traductions',
        'post_translations_needs_save' => "D'abord enregistrer",
        'post_translations_complete' => 'Complet',
        'post_translations_in_progress' => 'En cours',
        'post_translations_title_missing' => 'Titre manquant',
        'post_translations_blocks_progress' => ':done/:total blocs',
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
        'aria_visual_editor' => 'Éditeur visuel',
        'visual_editor_label' => 'Éditeur visuel',
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
        // Puce de taille de l’e-mail (docs/email-system.md §7-E3, live/footer.blade.php).
        'aria_email_size' => 'Taille de l’e-mail',
        'email_size_unsaved' => '—',
        'email_size_warning' => 'Plus de 100 Ko — Gmail pourrait tronquer cet e-mail.',
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
        'checklist_empty' => 'Rien à vérifier pour l’instant.',
        // ── Note de qualité (docs/seo-system.md §4, Wave S2b) ─────────
        'score_rating_poor' => 'Faible',
        'score_rating_needs_work' => 'À améliorer',
        'score_rating_good' => 'Bon',
        'score_rating_excellent' => 'Excellent',
        'score_save_first' => 'Enregistrez l’article pour voir son score SEO.',
        'score_analyzing' => 'Analyse en cours…',
        'score_unavailable' => 'Analyse indisponible — réessayez sous peu.',
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
        'ai_role_you' => 'Vous',
        'ai_role_assistant' => 'Assistant',
        'ai_empty' => 'Demandez un titre, une reformulation ou une section entière. L\'assistant peut lire la page en cours.',
        'ai_empty_reply' => 'Le modèle n\'a rien renvoyé à afficher.',
        'ai_new_chat' => 'Nouvelle conversation',
        'ai_stop' => 'Arrêter la génération',
        'ai_stopped' => 'Arrêté.',
        'ai_thinking' => 'Réflexion…',
        'ai_thinking_label' => 'Réflexion…',
        'ai_thought_for' => 'Réflexion : :secs' . 's',
        'ai_building' => 'Construction de la page — :count blocs…',
        'ai_built' => ':count blocs construits sur la page.',
        'ai_translated' => ':count blocs traduits dans cette langue.',
        'ai_translate_append_refused' => 'Impossible d\'ajouter de nouveaux blocs pendant une traduction — passez d\'abord à la langue d\'origine de l\'article, ajoutez-les là, puis revenez traduire.',
        'ai_translate_mismatch' => 'Cette réponse ne correspond pas à la structure de blocs de cet article : rien n\'a été appliqué. Demandez une traduction des mêmes blocs, avec uniquement le texte modifié.',
        'ai_set_title' => 'Titre défini — « :title ».',
        'ai_working_tool' => 'En cours — :tool…',
        'ai_edit' => 'Modifier',
        'ai_applied_label' => 'Appliqué à votre article',
        'ai_quick_inserts' => 'Insertions rapides',
        'ai_model_label' => 'Modèle',
        'ai_history_open' => 'Historique des conversations',
        'ai_history_error' => 'Cette conversation n\'a pas pu être ouverte.',
        'ai_insert_failed' => 'Cette réponse n\'était pas un balisage de blocs valide : rien n\'a été inséré.',
        'ai_inserted' => 'Inséré - ouvert dans l\'éditeur de code.',
        'ai_truncated' => 'La connexion s\'est terminée avant la réponse. Régénérez pour la finir.',
        'ai_length_limit' => 'La réponse a atteint la limite de longueur du modèle. Demandez la suite, ou augmentez heisenberg.ai.max_tokens.',
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

    // ── Sélecteur de date (ui/date-picker.blade.php) ──────────────
    'date_picker' => [
        'weekdays' => ['Lu', 'Ma', 'Me', 'Je', 'Ve', 'Sa', 'Di'],
        'prev_year' => 'Année précédente',
        'prev_month' => 'Mois précédent',
        'next_month' => 'Mois suivant',
        'next_year' => 'Année suivante',
        'hour' => 'Heure',
        'minute' => 'Minute',
        'today' => 'Aujourd’hui',
        'clear' => 'Effacer',
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

    // ── Insertion rapide (fenêtre du bouton d’ajout) ──────────────
    'quick_inserter' => [
        'aria_label' => 'Insérer un bloc',
        'search' => 'Rechercher des blocs…',
    ],

];