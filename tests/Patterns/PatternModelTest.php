<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Patterns;

use Heisenberg\Models\Pattern;
use Heisenberg\Tests\Persistence\SkipsWhenMysqlUnreachable;
use Heisenberg\Tests\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Model-level coverage for {@see Pattern}: fillable, the `blocks` array cast, the
 * unique `name` constraint (migration 2026_08_15_000001), and the
 * config-swappable table name.
 */
class PatternModelTest extends TestCase
{
    use RefreshDatabase;
    use SkipsWhenMysqlUnreachable {
        SkipsWhenMysqlUnreachable::setUp as private skipIfMysqlUnreachable;
    }

    protected function setUp(): void
    {
        $this->skipIfMysqlUnreachable();
    }

    public function test_only_name_and_blocks_are_mass_assignable(): void
    {
        $pattern = Pattern::create([
            'name' => 'Fillable Check',
            'blocks' => [['name' => 'heisenberg/group']],
        ]);

        $this->assertSame('Fillable Check', $pattern->name);
        $this->assertSame([['name' => 'heisenberg/group']], $pattern->blocks);
    }

    public function test_blocks_is_cast_to_and_from_an_array(): void
    {
        $pattern = Pattern::create([
            'name' => 'Cast Check',
            'blocks' => [['name' => 'heisenberg/group', 'nested' => ['a' => 1]]],
        ]);

        $fresh = Pattern::query()->find($pattern->id);

        $this->assertIsArray($fresh->blocks);
        $this->assertSame(1, $fresh->blocks[0]['nested']['a']);
    }

    public function test_name_must_be_unique_at_the_database_level(): void
    {
        Pattern::create(['name' => 'Dup', 'blocks' => [['name' => 'x']]]);

        $this->expectException(QueryException::class);

        Pattern::create(['name' => 'Dup', 'blocks' => [['name' => 'y']]]);
    }

    public function test_table_name_defaults_to_heisenberg_patterns(): void
    {
        $this->assertSame('heisenberg_patterns', (new Pattern())->getTable());
    }

    public function test_table_name_honours_a_config_override(): void
    {
        config(['heisenberg.tables.patterns' => 'custom_patterns_table']);

        $this->assertSame('custom_patterns_table', (new Pattern())->getTable());
    }
}
