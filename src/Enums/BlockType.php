<?php

declare(strict_types=1);

namespace Heisenberg\Enums;

/**
 * The block types: the blueprint's 20 (§2.2) plus group/column/embed (2026-08-06).
 * Stored as a raw varchar in the block row
 * (`type`), never DB-cast to this enum — the enum is the canonical registry of
 * valid type slugs used by the validator, payload service, renderer, and the
 * `blocks:verify` parity command (§12).
 *
 * `icon()`/`label()` provenance: the nine contract-backed types carry the exact
 * Lucide slugs documented in Appendix C. The other eleven types have no shipped
 * contract, so their icons/labels are chosen sensibly here (the blueprint states
 * `icon()` returns "a Lucide slug per type" without enumerating these eleven).
 */
enum BlockType: string
{
    case PARAGRAPH = 'paragraph';
    case HEADING = 'heading';
    case IMAGE = 'image';
    case QUOTE = 'quote';
    case LIST = 'list';
    case CTA = 'cta';
    case GALLERY = 'gallery';
    case VIDEO = 'video';
    case FAQ = 'faq';
    case SEPARATOR = 'separator';
    case HTML_RAW = 'html_raw';
    case TESTIMONIAL = 'testimonial';
    case STAT = 'stat';
    case ACCORDION = 'accordion';
    case BUTTON = 'button';
    case COLUMNS = 'columns';
    case SECTION_HEAD = 'section_head';
    case TAKEAWAY = 'takeaway';
    case DATA_ROW = 'data_row';
    case COMPONENT = 'component';
    case GROUP = 'group';
    case COLUMN = 'column';
    case EMBED = 'embed';
    // Icon (2026-08-08 — backed by the imported VvvebJs icon library, see
    // resources/icons/blocks + IconLibraryService).
    case ICON = 'icon';

    /**
     * All type slugs, in declaration order.
     *
     * @return string[]
     */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }

    /** Is the given raw string a known block type? */
    public static function isValid(string $value): bool
    {
        return self::tryFrom($value) !== null;
    }

    /** A human-readable English label (lang files override this for the editor). */
    public function label(): string
    {
        return match ($this) {
            self::PARAGRAPH => 'Paragraph',
            self::HEADING => 'Heading',
            self::IMAGE => 'Image',
            self::QUOTE => 'Quote',
            self::LIST => 'List',
            self::CTA => 'Call to Action',
            self::GALLERY => 'Gallery',
            self::VIDEO => 'Video',
            self::FAQ => 'FAQ',
            self::SEPARATOR => 'Separator',
            self::HTML_RAW => 'Raw HTML',
            self::TESTIMONIAL => 'Testimonial',
            self::STAT => 'Stat',
            self::ACCORDION => 'Accordion',
            self::BUTTON => 'Button',
            self::COLUMNS => 'Columns',
            self::SECTION_HEAD => 'Section Head',
            self::TAKEAWAY => 'Takeaway',
            self::DATA_ROW => 'Data Row',
            self::COMPONENT => 'Component',
            self::GROUP => 'Group',
            self::COLUMN => 'Column',
            self::EMBED => 'Embed',
            self::ICON => 'Icon',
        };
    }

    /** A Lucide icon slug for the editor's block inserter. */
    public function icon(): string
    {
        return match ($this) {
            // Documented verbatim in the shipped contracts (Appendix C).
            self::PARAGRAPH => 'pilcrow',
            self::HEADING => 'bookmark',
            self::IMAGE => 'image',
            self::QUOTE => 'quote',
            self::LIST => 'list',
            self::CTA => 'send',
            self::SEPARATOR => 'minus',
            self::BUTTON => 'mouse-pointer-click',
            self::SECTION_HEAD => 'bookmark',

            // Chosen sensibly (no shipped contract; not enumerated in the blueprint).
            self::GALLERY => 'images',
            self::VIDEO => 'video',
            self::FAQ => 'circle-help',
            self::HTML_RAW => 'code',
            self::TESTIMONIAL => 'message-square-quote',
            self::STAT => 'trending-up',
            self::ACCORDION => 'chevrons-down-up',
            self::COLUMNS => 'columns-3',
            self::TAKEAWAY => 'lightbulb',
            self::DATA_ROW => 'table',
            self::COMPONENT => 'puzzle',
            self::GROUP => 'frame',
            self::COLUMN => 'rectangle-vertical',
            self::EMBED => 'youtube',
            self::ICON => 'star',
        };
    }
}
