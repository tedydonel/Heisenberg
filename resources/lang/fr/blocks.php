<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Libellés des blocs (français) — heisenberg::blocks.*
|--------------------------------------------------------------------------
| Résout les clés de traduction portées par les contrats livrés (titres,
| descriptions, libellés de contrôles, libellés d’options). Le registre
| localise un contrat via ces clés ; l’inséreur et l’inspecteur de
| l’éditeur lisent les chaînes résolues. (Blueprint §11.2)
*/

return [

    'paragraph' => [
        'title' => 'Paragraphe',
        'description' => 'Du texte simple dans votre article.',
        'controls' => [
            'content' => 'Texte',
            'dropCap' => 'Lettrine',
            'anchor' => 'Id',
            'titleAttr' => 'Titre',
            'extraClasses' => 'Classe',
            'hideXs' => 'Très petits écrans',
            'hideSm' => 'Petits écrans',
            'hideMd' => 'Écrans moyens',
            'hideLg' => 'Grands écrans',
            'hideXl' => 'Écrans Xl',
            'hideXxl' => 'Écrans Xxl',
            'animate' => 'Type d’animation',
            'animateDuration' => 'Durée',
            'animateDelay' => 'Délai',
        ],
    ],

    'spacer' => [
        'title' => 'Espacement',
        'description' => 'Espace vertical entre les blocs.',
        'controls' => [
            'height' => 'Hauteur (px)',
            'anchor' => 'Id',
            'extraClasses' => 'Classe',
        ],
    ],

    'code' => [
        'title' => 'Code',
        'description' => 'Un extrait de code brut.',
        'controls' => [
            'content' => 'Code',
            'language' => 'Langage',
            'anchor' => 'Id',
            'extraClasses' => 'Classe',
        ],
    ],

    'pullquote' => [
        'title' => 'Citation mise en avant',
        'description' => 'Une citation détachée avec attribution.',
        'controls' => [
            'content' => 'Citation',
            'cite' => 'Attribution',
            'anchor' => 'Id',
            'extraClasses' => 'Classe',
        ],
    ],

    'embed' => [
        'title' => 'Contenu intégré',
        'description' => 'Vidéo YouTube ou Vimeo.',
        'controls' => [
            'url' => 'URL de la vidéo',
            'caption' => 'Légende',
            'anchor' => 'Id',
            'extraClasses' => 'Classe',
            'titleAttr' => 'Titre',
            'hideXs' => 'Très petits écrans',
            'hideSm' => 'Petits écrans',
            'hideMd' => 'Écrans moyens',
            'hideLg' => 'Grands écrans',
            'hideXl' => 'Écrans Xl',
            'hideXxl' => 'Écrans Xxl',
            'animate' => 'Type d’animation',
            'animateDuration' => 'Durée',
            'animateDelay' => 'Délai',
        ],
    ],

    'columns' => [
        'title' => 'Colonnes',
        'description' => 'Colonne(s) en disposition côte à côte.',
        'controls' => [
            'columns' => 'Colonnes',
            'gap' => 'Espacement',
            'anchor' => 'Id',
            'titleAttr' => 'Titre',
            'extraClasses' => 'Classe',
            'hideXs' => 'Très petits écrans',
            'hideSm' => 'Petits écrans',
            'hideMd' => 'Écrans moyens',
            'hideLg' => 'Grands écrans',
            'hideXl' => 'Écrans Xl',
            'hideXxl' => 'Écrans Xxl',
            'animate' => 'Type d’animation',
            'animateDuration' => 'Durée',
            'animateDelay' => 'Délai',
        ],
    ],

    'column' => [
        'title' => 'Colonne',
        'description' => 'Une colonne dans une rangée de Colonnes.',
        'controls' => [
            'anchor' => 'Id',
            'titleAttr' => 'Titre',
            'extraClasses' => 'Classe',
            'hideXs' => 'Très petits écrans',
            'hideSm' => 'Petits écrans',
            'hideMd' => 'Écrans moyens',
            'hideLg' => 'Grands écrans',
            'hideXl' => 'Écrans Xl',
            'hideXxl' => 'Écrans Xxl',
            'animate' => 'Type d’animation',
            'animateDuration' => 'Durée',
            'animateDelay' => 'Délai',
        ],
    ],

    'group' => [
        'title' => 'Groupe',
        'description' => 'Un conteneur flex qui regroupe d’autres blocs.',
        'controls' => [
            'anchor' => 'Id',
            'titleAttr' => 'Titre',
            'extraClasses' => 'Classe',
            'hideXs' => 'Très petits écrans',
            'hideSm' => 'Petits écrans',
            'hideMd' => 'Écrans moyens',
            'hideLg' => 'Grands écrans',
            'hideXl' => 'Écrans Xl',
            'hideXxl' => 'Écrans Xxl',
            'animate' => 'Type d’animation',
            'animateDuration' => 'Durée',
            'animateDelay' => 'Délai',
        ],
    ],

    'heading' => [
        'title' => 'Titre',
        'description' => 'Titre de section ou sous-titre.',
        'controls' => [
            'content' => 'Texte',
            'level' => 'Niveau',
            'anchor' => 'Id',
            'titleAttr' => 'Titre',
            'extraClasses' => 'Classe',
            'hideXs' => 'Très petits écrans',
            'hideSm' => 'Petits écrans',
            'hideMd' => 'Écrans moyens',
            'hideLg' => 'Grands écrans',
            'hideXl' => 'Écrans Xl',
            'hideXxl' => 'Écrans Xxl',
            'animate' => 'Type d’animation',
            'animateDuration' => 'Durée',
            'animateDelay' => 'Délai',
        ],
        'options' => [
            'level' => [1 => 'H1', 2 => 'H2', 3 => 'H3', 4 => 'H4', 5 => 'H5', 6 => 'H6'],
        ],
    ],

    'image' => [
        'title' => 'Image',
        'description' => 'Téléverser ou intégrer une image.',
        'controls' => [
            'url' => 'Image',
            'alt' => 'Texte alternatif',
            'caption' => 'Légende',
            'href' => 'Lien',
            'target' => 'Ouvrir dans',
            'alignment' => 'Alignement',
            'width' => 'Largeur',
            'height' => 'Hauteur',
            'aspect_ratio' => 'Format',
            'scale' => 'Échelle',
            'lightbox_enabled' => 'Lightbox',
        ],
        'options' => [
            'target' => ['self' => 'Même onglet', 'blank' => 'Nouvel onglet'],
            'alignment' => ['left' => 'Gauche', 'center' => 'Centre', 'right' => 'Droite', 'wide' => 'Large', 'full' => 'Plein'],
            'scale' => ['cover' => 'Recouvrir', 'contain' => 'Contenir', 'fill' => 'Remplir'],
        ],
    ],

    'button' => [
        'title' => 'Bouton',
        'description' => 'Déclencher une action au clic.',
        'controls' => [
            'text' => 'Libellé',
            'url' => 'Lien',
            'variant' => 'Style',
            'icon' => 'Icône',
            'icon_position' => 'Position de l’icône',
            'target' => 'Ouvrir dans',
            'full_width' => 'Pleine largeur',
            'hover_text_color' => 'Texte au survol',
            'hover_background_color' => 'Fond au survol',
            'hover_border_color' => 'Bordure au survol',
        ],
        'options' => [
            'variant' => ['primary' => 'Principal', 'secondary' => 'Secondaire', 'danger' => 'Danger', 'link' => 'Lien', 'outline' => 'Contour'],
            'icon_position' => ['left' => 'Gauche', 'right' => 'Droite'],
            'target' => ['self' => 'Même onglet', 'blank' => 'Nouvel onglet'],
            'color' => [
                'default' => 'Par défaut', 'accent_1' => 'Accent 1', 'accent_2' => 'Accent 2',
                'accent_3' => 'Accent 3', 'accent_4' => 'Accent 4', 'accent_6' => 'Accent 6',
                'muted' => 'Atténué', 'danger' => 'Danger', 'transparent' => 'Transparent',
            ],
        ],
    ],

    'cta' => [
        'title' => 'Appel à l’action',
        'description' => 'Une bannière qui incite à l’action.',
        'controls' => [
            'heading' => 'Titre',
            'subheading' => 'Sous-titre',
            'button_text' => 'Libellé du bouton',
            'button_url' => 'Lien du bouton',
            'button_icon' => 'Icône du bouton',
            'variant' => 'Style',
            'alignment' => 'Alignement',
        ],
        'options' => [
            'variant' => ['default' => 'Par défaut', 'accent' => 'Accent', 'muted' => 'Atténué'],
            'alignment' => ['left' => 'Gauche', 'center' => 'Centre', 'right' => 'Droite'],
        ],
    ],

    'list' => [
        'title' => 'Liste',
        'description' => 'Une liste à puces ou numérotée.',
        'controls' => [
            'content' => 'Éléments',
            'ordered' => 'Numérotée',
            'start' => 'Démarrer à',
            'reversed' => 'Inversée',
            'style' => 'Style',
        ],
        'options' => [
            'style' => ['default' => 'Par défaut', 'checkmark' => 'Coche'],
        ],
    ],

    'quote' => [
        'title' => 'Citation',
        'description' => 'Mettre une citation en valeur.',
        'controls' => [
            'content' => 'Citation',
            'citation' => 'Référence',
            'style' => 'Style',
        ],
        'options' => [
            'style' => ['default' => 'Par défaut', 'plain' => 'Simple'],
        ],
    ],

    'separator' => [
        'title' => 'Séparateur',
        'description' => 'Une division visuelle.',
        'controls' => [
            'style' => 'Style',
            'opacity' => 'Opacité',
            'color' => 'Couleur',
            'thickness' => 'Épaisseur',
            'width' => 'Largeur',
        ],
        'options' => [
            'style' => ['default' => 'Par défaut', 'wide_line' => 'Trait large', 'dots' => 'Points'],
            'opacity' => ['default' => 'Par défaut', 'alpha_channel' => 'Doux'],
        ],
    ],

    'section_head' => [
        'title' => 'Tête de section',
        'description' => 'Un titre de section étiqueté.',
        'controls' => [
            'label' => 'Étiquette',
            'heading' => 'Titre',
            'style' => 'Style',
        ],
        'options' => [
            'style' => ['default' => 'Par défaut', 'accent' => 'Accent', 'muted' => 'Atténué'],
        ],
    ],

    'common' => [
        'color' => [
            'default' => 'Par défaut', 'accent_1' => 'Accent 1', 'accent_2' => 'Accent 2',
            'accent_3' => 'Accent 3', 'accent_4' => 'Accent 4', 'muted' => 'Atténué',
        ],
    ],

];
