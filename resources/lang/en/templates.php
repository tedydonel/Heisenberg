<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Post template labels (English) — heisenberg::templates.*
|--------------------------------------------------------------------------
| Resolves the translation keys carried by the shipped post-template
| contracts (title, description, and capability-option labels), the same
| convention {@see BlockRegistryService} uses for `heisenberg::blocks.*`
| (docs/post-template-schema.md).
*/

return [

    'article' => [
        'title' => 'Article',
        'description' => 'A single blog post: hero, table of contents, author box, share buttons, comments, and related posts.',
        'toc_title' => 'On this page',
        'reading_time_label' => '{minutes} min read',
        'breadcrumbs_home' => 'Home',
        'views_label' => 'Views',
    ],

];
