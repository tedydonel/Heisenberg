<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Block labels (English) — heisenberg::blocks.*
|--------------------------------------------------------------------------
| Resolves the translation keys carried by the shipped contracts (titles,
| descriptions, control labels, option labels). The registry localizes a
| contract through these keys; the editor's inserter and inspector read the
| resolved strings. (Blueprint §11.2)
*/

return [

    'paragraph' => [
        'title' => 'Paragraph',
        'description' => 'Plain text in your story.',
        'controls' => [
            'content' => 'Text',
            'dropCap' => 'Drop cap',
            'anchor' => 'Id',
            'titleAttr' => 'Title',
            'extraClasses' => 'Class',
            'hideXs' => 'Extra small devices',
            'hideSm' => 'Small devices',
            'hideMd' => 'Medium devices',
            'hideLg' => 'Large devices',
            'hideXl' => 'Xl devices',
            'hideXxl' => 'Xxl devices',
            'animate' => 'Animation type',
            'animateDuration' => 'Duration',
            'animateDelay' => 'Delay',
        ],
    ],

    'spacer' => [
        'title' => 'Spacer',
        'description' => 'Vertical breathing room between blocks.',
        'controls' => [
            'height' => 'Height (px)',
            'anchor' => 'Id',
            'extraClasses' => 'Class',
        ],
    ],

    'code' => [
        'title' => 'Code',
        'description' => 'A verbatim code snippet.',
        'controls' => [
            'content' => 'Code',
            'language' => 'Language',
            'anchor' => 'Id',
            'extraClasses' => 'Class',
        ],
    ],

    'pullquote' => [
        'title' => 'Pullquote',
        'description' => 'A standout quote with attribution.',
        'controls' => [
            'content' => 'Quote',
            'cite' => 'Attribution',
            'anchor' => 'Id',
            'extraClasses' => 'Class',
        ],
    ],

    'embed' => [
        'title' => 'Embed',
        'description' => 'YouTube or Vimeo video.',
        'controls' => [
            'url' => 'Video URL',
            'caption' => 'Caption',
            'anchor' => 'Id',
            'extraClasses' => 'Class',
        ],
    ],

    'columns' => [
        'title' => 'Columns',
        'description' => 'Side-by-side layout columns.',
        'controls' => [
            'columns' => 'Columns',
            'gap' => 'Gap',
            'anchor' => 'Id',
            'extraClasses' => 'Class',
        ],
    ],

    'column' => [
        'title' => 'Column',
        'description' => 'One column inside a Columns row.',
        'controls' => [
            'anchor' => 'Id',
            'extraClasses' => 'Class',
        ],
    ],

    'heading' => [
        'title' => 'Heading',
        'description' => 'Section title or subtitle.',
        'controls' => [
            'content' => 'Text',
            'level' => 'Level',
            'anchor' => 'Id',
            'titleAttr' => 'Title',
            'extraClasses' => 'Class',
            'hideXs' => 'Extra small devices',
            'hideSm' => 'Small devices',
            'hideMd' => 'Medium devices',
            'hideLg' => 'Large devices',
            'hideXl' => 'Xl devices',
            'hideXxl' => 'Xxl devices',
            'animate' => 'Animation type',
            'animateDuration' => 'Duration',
            'animateDelay' => 'Delay',
        ],
        'options' => [
            'level' => [1 => 'H1', 2 => 'H2', 3 => 'H3', 4 => 'H4', 5 => 'H5', 6 => 'H6'],
        ],
    ],

    'image' => [
        'title' => 'Image',
        'description' => 'Upload or embed an image.',
        'controls' => [
            'url' => 'Image',
            'alt' => 'Alt text',
            'caption' => 'Caption',
            'href' => 'Link',
            'target' => 'Open in',
            'alignment' => 'Alignment',
            'width' => 'Width',
            'height' => 'Height',
            'aspect_ratio' => 'Aspect ratio',
            'scale' => 'Scale',
            'lightbox_enabled' => 'Lightbox',
        ],
        'options' => [
            'target' => ['self' => 'Same tab', 'blank' => 'New tab'],
            'alignment' => ['left' => 'Left', 'center' => 'Center', 'right' => 'Right', 'wide' => 'Wide', 'full' => 'Full'],
            'scale' => ['cover' => 'Cover', 'contain' => 'Contain', 'fill' => 'Fill'],
        ],
    ],

    'button' => [
        'title' => 'Button',
        'description' => 'Prompt a click action.',
        'controls' => [
            'text' => 'Label',
            'url' => 'Link',
            'variant' => 'Style',
            'icon' => 'Icon',
            'icon_position' => 'Icon position',
            'target' => 'Open in',
            'full_width' => 'Full width',
            'hover_text_color' => 'Hover text',
            'hover_background_color' => 'Hover background',
            'hover_border_color' => 'Hover border',
        ],
        'options' => [
            'variant' => ['primary' => 'Primary', 'secondary' => 'Secondary', 'danger' => 'Danger', 'link' => 'Link', 'outline' => 'Outline'],
            'icon_position' => ['left' => 'Left', 'right' => 'Right'],
            'target' => ['self' => 'Same tab', 'blank' => 'New tab'],
            'color' => [
                'default' => 'Default', 'accent_1' => 'Accent 1', 'accent_2' => 'Accent 2',
                'accent_3' => 'Accent 3', 'accent_4' => 'Accent 4', 'accent_6' => 'Accent 6',
                'muted' => 'Muted', 'danger' => 'Danger', 'transparent' => 'Transparent',
            ],
        ],
    ],

    'cta' => [
        'title' => 'Call to Action',
        'description' => 'A banner that prompts an action.',
        'controls' => [
            'heading' => 'Heading',
            'subheading' => 'Subheading',
            'button_text' => 'Button label',
            'button_url' => 'Button link',
            'button_icon' => 'Button icon',
            'variant' => 'Style',
            'alignment' => 'Alignment',
        ],
        'options' => [
            'variant' => ['default' => 'Default', 'accent' => 'Accent', 'muted' => 'Muted'],
            'alignment' => ['left' => 'Left', 'center' => 'Center', 'right' => 'Right'],
        ],
    ],

    'list' => [
        'title' => 'List',
        'description' => 'A bulleted or numbered list.',
        'controls' => [
            'content' => 'Items',
            'ordered' => 'Numbered',
            'start' => 'Start at',
            'reversed' => 'Reversed',
            'style' => 'Style',
        ],
        'options' => [
            'style' => ['default' => 'Default', 'checkmark' => 'Checkmark'],
        ],
    ],

    'quote' => [
        'title' => 'Quote',
        'description' => 'Highlight a quotation.',
        'controls' => [
            'content' => 'Quote',
            'citation' => 'Citation',
            'style' => 'Style',
        ],
        'options' => [
            'style' => ['default' => 'Default', 'plain' => 'Plain'],
        ],
    ],

    'separator' => [
        'title' => 'Separator',
        'description' => 'A visual divider.',
        'controls' => [
            'style' => 'Style',
            'opacity' => 'Opacity',
            'color' => 'Color',
            'thickness' => 'Thickness',
            'width' => 'Width',
        ],
        'options' => [
            'style' => ['default' => 'Default', 'wide_line' => 'Wide line', 'dots' => 'Dots'],
            'opacity' => ['default' => 'Default', 'alpha_channel' => 'Soft'],
        ],
    ],

    'section_head' => [
        'title' => 'Section Head',
        'description' => 'A labeled section heading.',
        'controls' => [
            'label' => 'Label',
            'heading' => 'Heading',
            'style' => 'Style',
        ],
        'options' => [
            'style' => ['default' => 'Default', 'accent' => 'Accent', 'muted' => 'Muted'],
        ],
    ],

    'common' => [
        'color' => [
            'default' => 'Default', 'accent_1' => 'Accent 1', 'accent_2' => 'Accent 2',
            'accent_3' => 'Accent 3', 'accent_4' => 'Accent 4', 'muted' => 'Muted',
        ],
    ],

];
