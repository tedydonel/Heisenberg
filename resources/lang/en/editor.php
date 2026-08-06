<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Editor chrome strings (English) — heisenberg::editor.*
|--------------------------------------------------------------------------
| Resolves the labels, tooltips, placeholders, panel titles and screen-reader
| text used by the editor surfaces (topbar, sidebar, side panels, inspector,
| footer, toolbar, canvas). The bundle is template-driven: every Blade file
| under resources/views/components/live calls __() / trans() against these
| keys, and a single HTML reload after a locale switch re-renders the whole
| shell. (Blueprint §11.3)
*/

return [

    // ── Common chrome (used by >1 surface) ────────────────────────
    'common' => [
        'save' => 'Save',
        'cancel' => 'Cancel',
        'saved' => 'Saved',
        'connecting' => 'Connecting…',
        'published' => 'Published',
        'untitled' => 'Untitled',
        'no_title' => 'No Title',
        'no_block_selected_title' => 'No block selected',
        'no_block_selected_desc' => 'Select a block in the canvas to edit its settings.',
        'no_block_empty_panel' => 'Select a block on the canvas to see its settings here.',
        'untitled_post' => 'Untitled post',
        'add_block' => 'Add block',
        'send' => 'Send',
        'close' => 'Close',
        'retry' => 'Retry',
    ],

    // ── Topbar (live/topbar.blade.php) ────────────────────────────
    'topbar' => [
        'aria_home' => 'Home',
        'aria_menu' => 'Menu',
        'aria_panel_left' => 'Toggle left panel',
        'aria_undo' => 'Undo',
        'aria_redo' => 'Redo',
        'aria_preview' => 'Preview',
        'aria_fullscreen' => 'Fullscreen',
        'aria_layers' => 'Layers',
        'aria_theme' => 'Toggle dark mode',
        'aria_open_new_tab' => 'Open in new tab',
        'aria_device' => 'Device preview',
        'aria_panel_right' => 'Toggle right panel',
        'device_desktop' => 'Desktop',
        'device_tablet' => 'Tablet',
        'device_mobile' => 'Mobile',
        // Save-failure messages (surfaced via hb:save-state → the footer pill).
        'save_conflict' => 'This post was changed elsewhere — reload and try again.',
        'save_invalid' => 'Could not save — check the form.',
        'save_network' => 'Network error — check your connection.',
    ],

    // ── Media dialog / library / dropzone (live/media/*) ──────────
    'media' => [
        'select_featured_image' => 'Select Featured Image',
        'tab_upload' => 'Upload Files',
        'tab_library' => 'Media Library',
        'search_ph' => 'Search media items…',
        'empty' => 'No media yet — upload a file to get started.',
        'load_error' => 'Couldn’t load the media library. Try again.',
        'upload_failed' => 'Upload failed.',
        'upload_network' => 'Upload failed. Check your connection and try again.',
        'uploading' => 'Uploading…',
        'dropzone_title' => 'Drop files to upload',
        'dropzone_desc' => 'Images are optimized automatically. Documents are stored as originals. Maximum size: 10 MB each.',
    ],

    // ── Effect editor (live/pickers/effect-editor.blade.php) ──────
    'effects' => [
        'drop_shadow' => 'Drop Shadow',
        'color' => 'Color',
        'blur' => 'Blur',
        'offset' => 'Offset',
    ],

    // ── Sidebar (live/sidebar.blade.php) ──────────────────────────
    'sidebar' => [
        'nav_components' => 'Components',
        'nav_blocks' => 'Blocks',
        'nav_seo' => 'SEO',
        'nav_socials' => 'Socials',
        'nav_style' => 'Style',
        'nav_themes' => 'Themes',
        'nav_ai' => 'Ai',
        'nav_tools' => 'Tools',
    ],

    // ── Inspector tabs / sub-tabs (live/inspector.blade.php) ──────
    'inspector' => [
        'tab_post' => 'Post',
        'tab_block' => 'Block',
        'subtab_content' => 'Content',
        'subtab_style' => 'Style',
        'subtab_advanced' => 'Advanced',
        // Post tab — eyebrow + disclosure-row labels + chrome strings
        'post_title_eyebrow' => 'POST TITLE',
        'post_title_placeholder' => 'Untitled post',
        'post_title_label' => 'Post title',
        'post_featured_image' => 'Featured image',
        'post_featured_set' => 'Set featured image',
        'post_featured_replace' => 'Replace featured image',
        'post_featured_remove' => 'Remove featured image',
        'post_summary' => 'Summary',
        'post_pending_review' => 'Pending review',
        'post_stick_top' => 'Stick to the top of the blog',
        'post_move_trash' => 'Move to trash',
        'post_categories' => 'Categories',
        'post_category_add_ph' => 'Add or find a category…',
        'post_category_empty' => 'No categories yet.',
        'post_tags' => 'Tags',
        'post_tag_add_ph' => 'Add or find a tag…',
        'post_tag_empty' => 'No tags yet.',
        'post_taxonomy_needs_save' => 'Save the post first to add categories or tags.',
        'post_discussion' => 'Discussion',
        'post_allow_comments' => 'Allow comments',
        'post_page_layout' => 'Page layout',
        'layer_opacity' => 'Layer opacity',
        'pick_colour' => 'Pick a colour',
        'bind_theme_variable' => 'Bind to theme variable',
        'post_layout_padding_x' => 'X Padding',
        'post_layout_padding_y' => 'Y Padding',
        // Block tab — empty-state
        'block_empty' => 'Select a block on the canvas to edit its settings.',
    ],

    // ── Block Advanced tab (live/block/advanced.blade.php) ────────
    'advanced' => [
        'section_visibility' => 'Hide based on device screen width',
        'hide_xs' => 'Extra small devices',
        'hide_sm' => 'Small devices',
        'hide_md' => 'Medium devices',
        'hide_lg' => 'Large devices',
        'hide_xl' => 'Xl devices',
        'hide_xxl' => 'Xxl devices',
        'section_animate' => 'Animate on scroll',
        'animation_type' => 'Animation type',
        'duration' => 'Duration',
        'delay' => 'Delay',
        'easing' => 'Easing',
        'play_once' => 'Play once',
        'play_animation' => 'Play animation',
    ],

    // ── Footer (live/footer.blade.php) ────────────────────────────
    'footer' => [
        'aria_status' => 'Document status',
        'aria_lang' => 'Content language',
        'aria_code_editor' => 'Code editor',
        'lang_label' => 'English',
        'code_editor_label' => 'Code Editor',
        // Save-status pill states (live/footer.blade.php + live/topbar.blade.php's save wiring).
        // 'saved' reuses common.saved; there's no separate key for it.
        'status_saving' => 'Saving…',
        'status_unsaved' => 'Unsaved changes',
        'status_offline' => 'Offline',
        'status_conflict' => 'Conflict',
        'status_error' => 'Error',
    ],

    // ── Components/Blocks panel (live/panel-components-blocks.blade.php)
    'panel_components_blocks' => [
        'tab_components' => 'Components',
        'tab_blocks' => 'Blocks',
        'search_components' => 'Search components…',
        'search_blocks' => 'Search blocks…',
        'category_base' => 'Base',
        'card_heading' => 'Heading',
        'card_image' => 'Image',
        'card_divider' => 'Divider',
        'card_button' => 'Button',
    ],

    // ── SEO/Social panel (live/panel-seo-social.blade.php + builder inserter)
    'panel_seo_social' => [
        'tab_seo' => 'SEO',
        'tab_social' => 'Social',
        'search_themes' => 'Search themes…',
        'section_presets' => 'PRESETS',
        'theme_default' => 'Default',
        'theme_midnight' => 'Midnight',
        'theme_sunset' => 'Sunset',
        'theme_ocean' => 'Ocean',
        'theme_forest' => 'Forest',
        'theme_blush' => 'Blush',
        'seo_title_label' => 'SEO Title',
        'seo_title_ph' => 'Enter an SEO title…',
        'seo_meta_label' => 'Meta Description',
        'seo_meta_ph' => 'Write a short description that appears in search results…',
        'seo_url_slug' => 'URL Slug',
        'seo_url_slug_value' => 'my-post-title',
        'seo_url_slug_prefix' => 'yoursite.com › blog › :slug',
        'seo_preview_title' => 'Your SEO Title Goes Here',
        'seo_preview_desc' => 'Write a short description that appears in search results…',
        'seo_focus_keyphrase' => 'Focus Keyphrase',
        'seo_focus_keyphrase_ph' => 'e.g. sourdough starter recipe',
        'seo_index_label' => 'Allow search engines to index this page',
        'seo_sitemap_label' => 'Include in sitemap',
        'seo_follow_label' => 'Follow links on this page',
        'seo_canonical' => 'Canonical URL',
        'seo_canonical_ph' => 'https://yoursite.com/blog/my-post-title',
        'seo_canonical_prefix' => '/blog/',
        'checklist_keyphrase_title' => 'Focus keyphrase found in the SEO title',
        'checklist_title_length' => 'SEO title length is good',
        'checklist_slug_keyphrase' => 'URL slug contains the keyphrase',
        'checklist_meta_missing' => "Meta description doesn't contain the keyphrase",
        'checklist_density_low' => 'Keyphrase density is too low',
        'social_preview_heading' => 'Social Preview',
        'social_set_image' => 'Set social share image',
        'social_title' => 'Social Title',
        'social_title_ph' => 'Same as SEO title',
        'social_description' => 'Social Description',
        'social_description_ph' => 'Same as meta description',
        'social_preview_label' => 'Preview',
        'social_facebook' => 'Facebook',
        'social_x' => 'X',
        'social_linkedin' => 'LinkedIn',
    ],

    // ── Style/Themes panel (live/panel-style-themes.blade.php) ────
    'panel_style_themes' => [
        'tab_style' => 'Style',
        'tab_themes' => 'Themes',
        'category_writing' => 'Writing',
        'token_colors' => 'Colors',
        'token_radius' => 'Radius',
        'token_spacing' => 'Spacing',
        'token_fonts' => 'Fonts',
        'token_font_sizes' => 'Font sizes',
        'add_color' => 'Add color',
        'add_radius' => 'Add radius',
        'add_spacing' => 'Add spacing',
        'add_font' => 'Add font',
        'add_size' => 'Add size',
        'select_font_ph' => 'Select font…',
        'save_to_themes' => 'Save to Themes',
        'save_theme_name_ph' => 'Theme name…',
        'save_theme_confirm_aria' => 'Confirm',
        'search_themes' => 'Search themes…',
        'category_your_themes' => 'Your themes',
        'no_saved_themes' => 'No saved themes yet',
        'delete_theme_aria' => 'Delete theme',
        'category_presets' => 'Presets',
    ],

    // ── AI/Tools panel (live/panel-ai-tools.blade.php) ────────────
    'panel_ai_tools' => [
        'tab_ai' => 'Ai',
        'tab_tools' => 'Tools',
        'ai_assistant' => 'AI Assistant',
        'ai_subtitle' => 'Get help writing, editing, and optimizing your post.',
        'ai_suggestions' => 'Suggestions',
        'ai_result' => 'Result',
        'ai_response_demo' => 'Here’s a punchy introduction that hooks readers in the first line and sets up the rest of your post…',
        'ai_insert' => 'Insert',
        'ai_regenerate' => 'Regenerate',
        'ai_prompt_ph' => 'Ask AI anything…',
        'tool_generate_title' => 'Generate Title',
        'tool_write_summary' => 'Write Summary',
        'tool_improve_writing' => 'Improve Writing',
        'tool_fix_grammar' => 'Fix Grammar',
        'tool_change_tone' => 'Change Tone',
        'tool_generate_image' => 'Generate Image',
        'tool_translate' => 'Translate',
        'tool_seo_optimize' => 'SEO Optimize',
        'sug_write_intro' => 'Write an introduction',
        'sug_improve_paragraph' => 'Improve this paragraph',
        'sug_fix_grammar' => 'Fix grammar & spelling',
        'sug_generate_title' => 'Generate a title',
        'search_tools' => 'Search tools…',
        'category_writing' => 'Writing',
    ],

    // ── Navigator panel (live/panel-navigator.blade.php) ──────────
    'panel_navigator' => [
        'tab_list' => 'List View',
        'tab_outline' => 'Outline',
        'empty_blocks' => 'No blocks yet.',
        'empty_headings' => 'No headings yet. Add Heading blocks to build an outline.',
        'stat_characters' => 'Characters:',
        'stat_words' => 'Words:',
        'stat_time' => 'Time to read:',
        'minutes_one' => 'minute',
        'minutes_other' => 'minutes',
        'minutes_zero' => '0 minutes',
        'empty_heading' => 'Empty heading',
        'block_fallback' => 'Block',
        'moved_announcement' => ':label moved to position :pos of :total.',
    ],

    // ── Block toolbar (live/toolbar/block-toolbar.blade.php + groups)
    'block_toolbar' => [
        'type_text' => 'Text',
        'save_block' => 'Save',
        'more' => 'More',
        'undo' => 'Undo',
        'redo' => 'Redo',
        'duplicate' => 'Duplicate',
        'delete' => 'Delete',
        'bold' => 'Bold',
        'italic' => 'Italic',
        'underline' => 'Underline',
        'strikethrough' => 'Strikethrough',
        'link' => 'Link',
        'text_color' => 'Text color',
        'background_color' => 'Background color',
        'align_left' => 'Align left',
        'align_center' => 'Align center',
        'align_right' => 'Align right',
        'transform_text' => 'Transform text',
    ],

    // ── Canvas (live/canvas.blade.php) ────────────────────────────
    'canvas' => [
        'ph_untitled_post' => 'Untitled post',
    ],

    // ── Colour picker (live/pickers/color-picker.blade.php) ───────
    // Colour-model names (HEX/RGB/RGBA/HSL/HSLA/HSB) and the R/G/B/A/H/S/L/B field
    // letters are notation, not prose — they stay untranslated in every locale and so
    // are not keyed here.
    'color_picker' => [
        'tab_fill' => 'Fill',
        'tab_gradient' => 'Gradient',
        'stops' => 'Stops',
        'type_linear' => 'Linear',
        'type_radial' => 'Radial',
        'type_conic' => 'Conic',
        'shape_circle' => 'Circle',
        'shape_ellipse' => 'Ellipse',
        'aria_eyedropper' => 'Pick colour from screen',
        'aria_copy' => 'Copy colour value',
        'aria_model' => 'Colour model',
        'aria_gradient_type' => 'Gradient type',
        'aria_gradient_angle' => 'Gradient angle',
        'aria_gradient_shape' => 'Gradient shape',
        'aria_reverse' => 'Reverse gradient',
        'aria_distribute' => 'Distribute stops evenly',
        'aria_duplicate' => 'Duplicate selected stop',
        'aria_add_stop' => 'Add stop',
        'aria_remove_stop' => 'Remove stop',
        'aria_select_stop' => 'Select stop',
        'aria_stop_hex' => 'Stop colour',
        'aria_stop_opacity' => 'Stop opacity',
        'aria_stop_position' => 'Stop position',
        'aria_stops' => 'Gradient stops',
    ],

    // ── Locale switcher (built into the footer chip) ──────────────
    'switcher' => [
        'aria_locale' => 'Interface language',
        'option_en' => 'English',
        'option_fr' => 'Français',
        'switched_to' => 'Language switched to :locale.',
    ],

    // ── Locales shipped with the package (used by the switcher UI) ──
    'locales' => [
        'en' => 'English',
        'fr' => 'Français',
    ],

];