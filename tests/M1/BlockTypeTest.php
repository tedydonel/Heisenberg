<?php

declare(strict_types=1);

namespace Heisenberg\Tests\M1;

use Heisenberg\Enums\BlockType;
use PHPUnit\Framework\TestCase;

/**
 * BlockType is pure PHP (no Laravel), so this is a plain unit test. It pins the
 * facts the blueprint states precisely: the 20 case values (§2.2) and the nine
 * Lucide icons documented from the shipped contracts (Appendix C).
 */
class BlockTypeTest extends TestCase
{
    /** The blueprint's 20 types (§2.2) plus group/column/embed (2026-08-06), in declaration order. */
    private const ALL = [
        'paragraph', 'heading', 'image', 'quote', 'list', 'cta', 'gallery',
        'video', 'faq', 'separator', 'html_raw', 'testimonial', 'stat',
        'accordion', 'button', 'columns', 'section_head', 'takeaway',
        'data_row', 'component', 'group', 'column', 'embed',
    ];

    public function test_has_the_documented_cases_with_exact_values(): void
    {
        $this->assertCount(23, BlockType::cases());
        $this->assertSame(
            self::ALL,
            array_map(static fn (BlockType $t): string => $t->value, BlockType::cases())
        );
    }

    public function test_values_returns_all_string_values(): void
    {
        $this->assertSame(self::ALL, BlockType::values());
    }

    public function test_is_valid_accepts_known_and_rejects_unknown(): void
    {
        $this->assertTrue(BlockType::isValid('paragraph'));
        $this->assertTrue(BlockType::isValid('html_raw'));
        $this->assertTrue(BlockType::isValid('section_head'));

        $this->assertFalse(BlockType::isValid('nonsense'));
        $this->assertFalse(BlockType::isValid('section-head')); // hyphen is not the enum value
        $this->assertFalse(BlockType::isValid(''));
    }

    public function test_contract_backed_types_expose_their_documented_lucide_icon(): void
    {
        // The nine icons the blueprint documents from the shipped contracts (Appendix C).
        $this->assertSame('pilcrow', BlockType::PARAGRAPH->icon());
        $this->assertSame('bookmark', BlockType::HEADING->icon());
        $this->assertSame('image', BlockType::IMAGE->icon());
        $this->assertSame('quote', BlockType::QUOTE->icon());
        $this->assertSame('list', BlockType::LIST->icon());
        $this->assertSame('send', BlockType::CTA->icon());
        $this->assertSame('minus', BlockType::SEPARATOR->icon());
        $this->assertSame('mouse-pointer-click', BlockType::BUTTON->icon());
        $this->assertSame('bookmark', BlockType::SECTION_HEAD->icon());
    }

    public function test_every_type_has_a_nonempty_label_and_icon(): void
    {
        foreach (BlockType::cases() as $type) {
            $this->assertNotSame('', $type->label(), "label for {$type->value}");
            $this->assertNotSame('', $type->icon(), "icon for {$type->value}");
        }
    }
}
