<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Converts `heisenberg_posts.locale` from a DB-level ENUM('en','fr') (migration
 * 2026_01_01_000001) to a plain `string(8)` with the same default/nullability.
 *
 * WHY: bilingual en/fr single-row content (paired `title_en`/`title_fr` columns) is a
 * deliberate, permanent design here — this is NOT a step toward N-locale support, and it
 * does NOT touch `title_en`/`title_fr`/`excerpt_en`/`excerpt_fr` or any other paired
 * column. What changes is only WHERE the "which locales are legal" rule lives: a DB
 * ENUM/CHECK is the most expensive part of that rule to ever revisit (ALTER on a live
 * MySQL ENUM, a Postgres CHECK constraint, a SQLite table rebuild) for a decision that
 * `Heisenberg\Support\LocaleConfig` (config('heisenberg.locales')) already owns at the
 * application layer — every write path validates through it or through an equivalent
 * explicit `Rule::in()` today (see this migration's own PR notes / UPGRADING.md). Moving
 * enforcement fully to that one layer, and letting the column be an ordinary string,
 * removes the redundant and harder-to-change DB copy of the same rule without weakening
 * anything an application-level check wasn't already covering.
 *
 * `string(8)` (not 255): plenty for any real BCP-47-ish tag ("en", "fr", "pt-BR", …)
 * while staying deliberately short — this is not an invitation to store arbitrary text.
 *
 * DRIVER NOTES (no doctrine/dbal; Laravel 11+ native `->change()` on every grammar):
 *
 *  - MySQL/MariaDB: `->change()` compiles to a single `MODIFY COLUMN` — the column's
 *    modifiers (nullable/default) are NOT merged from the live schema, they must be
 *    re-declared in full here (a Laravel 11+ gotcha: omitting `->default('en')` would
 *    silently DROP the default instead of preserving it). This is a single-column
 *    operation; the `['locale','slug']` unique index and any other column are untouched.
 *
 *  - PostgreSQL: Laravel's `enum()` is implemented as `varchar(255) check (col in (...))`
 *    — there is no native Postgres ENUM type in play here. `->change()` to `string(8)`
 *    compiles to `ALTER COLUMN "locale" TYPE varchar(8) ...`, which changes the TYPE but
 *    leaves the inline CHECK constraint attached (Postgres does not drop it just because
 *    the column's type changed underneath it) — so every future INSERT/UPDATE would still
 *    be rejected for any locale outside the original ('en','fr') pair, defeating the
 *    point of this migration. That CHECK was created unnamed inside the original CREATE
 *    TABLE column definition, so Postgres gave it its standard auto-generated name,
 *    `{table}_{column}_check` — dropped explicitly below, by that computed name, before
 *    changing the column. This is a single-column operation; nothing else on the table
 *    (indexes, the `status` enum's own separate `_status_check` constraint) is touched.
 *
 *  - SQLite: there is no in-place ALTER — Laravel's SQLiteGrammar rebuilds the whole
 *    table (temp table + copy + rename) for ANY `->change()`, reading the CURRENT live
 *    schema for every column/index it does not itself touch (`BlueprintState`) and
 *    re-declaring the target column fresh. The `['locale','slug']` unique index (and
 *    every other index) is reintroduced automatically by that rebuild — verified: no
 *    extra handling needed here. HONEST CAVEAT (verified against a live SQLite
 *    connection, not merely reasoned about): that rebuild reconstructs every UNCHANGED
 *    column from its bare declared type only (SQLite's own column introspection does not
 *    expose inline CHECK clauses as part of a column's "type") — so as a side effect of
 *    rebuilding this table AT ALL on SQLite, the `status` column's own, unrelated
 *    ENUM/CHECK constraint is also silently dropped at the DB level. This is an inherent
 *    property of SQLite's rebuild-based ALTER (any `->change()` on any column of a table
 *    with other inline CHECKs has this effect), not something introduced by touching
 *    `status` on purpose — `status` is otherwise completely out of scope for this
 *    migration and is not restructured, renamed, or reworked here. It is not a
 *    functional regression on real installs: `status` is never written outside
 *    validated paths (`SavePostRequest`'s `Rule::in($statuses)`, the lifecycle-transition
 *    gate in `PostController`), so this only removes a redundant SQLite-only backstop a
 *    raw, validation-bypassing `DB::table()->insert()` could have tripped — the same
 *    "the DB copy of this rule is redundant with an application-level one" situation this
 *    migration deliberately accepts for `locale` itself. Not reproduced on MySQL/Postgres,
 *    where `->change()` on `locale` is a genuinely single-column operation.
 *
 * DOWN(): reverses the type, restoring the original CHECK/ENUM shape. Refuses to run if
 * any row's `locale` no longer fits the original ('en','fr') pair — rather than let the
 * DB driver fail this migration with a confusing native error (or, worse on MySQL in
 * non-strict SQL mode, silently coerce an unrepresentable value to '') — since that can
 * only happen if a host widened `heisenberg.locales` and actually used a third locale
 * after upgrading, which down() has no data-preserving way to represent in an
 * `en`/`fr`-only column.
 */
return new class extends Migration
{
    private const ALLOWED = ['en', 'fr'];

    public function up(): void
    {
        $table = $this->table();
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            $this->dropPostgresCheckConstraint($table);
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->string('locale', 8)->default('en')->change();
        });
    }

    public function down(): void
    {
        $table = $this->table();
        $driver = Schema::getConnection()->getDriverName();

        $this->assertNoOutOfRangeLocales($table);

        if ($driver === 'pgsql') {
            // Laravel's PostgresGrammar has no native "change to enum" shape — its
            // enum() type is a CHECK constraint, and a CHECK clause cannot appear inside
            // an `ALTER COLUMN ... TYPE` statement's type expression. So the type change
            // and the constraint are done as two explicit steps, mirroring exactly how
            // the original CREATE TABLE built this column: varchar(255), then the CHECK.
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('locale', 255)->default('en')->change();
            });
            $this->addPostgresCheckConstraint($table);

            return;
        }

        // MySQL/MariaDB (`enum()->change()` compiles to a native `MODIFY COLUMN
        // ENUM(...)`) and SQLite (rebuild, restoring the inline CHECK on `locale`) both
        // support reversing straight through the schema builder's own enum() type.
        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->enum('locale', self::ALLOWED)->default('en')->change();
        });
    }

    /**
     * Postgres auto-names an unnamed inline CHECK from a CREATE TABLE column definition
     * as `{table}_{column}_check` — exactly how the original migration's `enum()` column
     * was declared (no explicit constraint name given). Computed from the SAME
     * config-resolved table name the original migration used, so a host that renamed the
     * table via `heisenberg.tables.posts` still resolves the constraint Postgres actually
     * created.
     */
    private function dropPostgresCheckConstraint(string $table): void
    {
        $constraint = "{$table}_locale_check";

        DB::statement(sprintf(
            'alter table %s drop constraint if exists %s',
            $this->quotePgIdentifier($table),
            $this->quotePgIdentifier($constraint)
        ));
    }

    private function addPostgresCheckConstraint(string $table): void
    {
        $constraint = "{$table}_locale_check";
        $allowed = implode(', ', array_map(fn (string $locale): string => "'{$locale}'", self::ALLOWED));

        DB::statement(sprintf(
            'alter table %s add constraint %s check (%s in (%s))',
            $this->quotePgIdentifier($table),
            $this->quotePgIdentifier($constraint),
            $this->quotePgIdentifier('locale'),
            $allowed
        ));
    }

    private function quotePgIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * down() only knows how to represent the original en/fr pair. If a host widened
     * `heisenberg.locales` after this migration ran and actually saved a third locale,
     * rolling back would either fail with an opaque driver error (Postgres/strict MySQL)
     * or silently corrupt those rows (MySQL coercing an out-of-range enum value to '' in
     * non-strict SQL mode) — refusing up front with a precise, actionable message is
     * strictly better than either.
     */
    private function assertNoOutOfRangeLocales(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $offenders = DB::table($table)
            ->whereNotNull('locale')
            ->whereNotIn('locale', self::ALLOWED)
            ->distinct()
            ->pluck('locale');

        if ($offenders->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Cannot roll back %s: rows exist with locale value(s) [%s] outside the '
                . "original ('en', 'fr') pair this migration's down() restores. Reassign "
                . 'or remove those rows before rolling back.',
                $table,
                $offenders->implode(', ')
            ));
        }
    }

    private function table(): string
    {
        return config('heisenberg.tables.posts', 'heisenberg_posts');
    }
};
