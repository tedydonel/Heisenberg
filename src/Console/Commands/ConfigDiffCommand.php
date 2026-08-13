<?php

declare(strict_types=1);

namespace Heisenberg\Console\Commands;

use Heisenberg\HeisenbergServiceProvider;
use Heisenberg\Support\ConfigMerge;
use Illuminate\Console\Command;

/**
 * `php artisan heisenberg:config-diff` — the tool that would have caught,
 * in seconds, a bug that instead bit a real install three separate times: a
 * published `config/heisenberg.php` quietly falling behind the package's
 * shipped defaults (stale provider defaults, a missing ability, a lifecycle
 * edge that made publishing impossible for every role) with no error
 * anywhere — just a feature that silently stopped working. See
 * {@see ConfigMerge}'s docblock for the full story and the deep-merge fix
 * this command audits.
 *
 * Compares the host's EFFECTIVE `config('heisenberg')` (already deep-merged
 * by {@see HeisenbergServiceProvider::register()}) against the package's own
 * shipped defaults (re-read straight from disk, never from the container),
 * and reports two different things:
 *
 *  - `missing`: a key the package ships that the effective config doesn't
 *    have at all. Should ALWAYS be empty — the deep merge exists specifically
 *    to make this impossible — so this section is the merge's own proof of
 *    work, not a host-config complaint. A non-empty list here means the merge
 *    itself is broken and is the only thing that fails this command.
 *  - `differs`: a key present on both sides whose effective value is not
 *    identical to the package default. This is EXPECTED and healthy for most
 *    entries — a host deliberately overriding a role map or a disk is not a
 *    bug — but it is also exactly where a STALE value hides. ConfigMerge
 *    cannot fix a LIST whose CONTENTS the package changed (only a genuinely
 *    missing key); `lifecycle.transitions.draft` losing a `published` edge
 *    shows up here, indistinguishable at the tool level from a deliberate
 *    override, because the key exists on both sides. Only a human reading the
 *    two values side by side can tell "I meant to do that" from "upgrade
 *    rot" — that read is this command's entire job.
 *
 * Recurses exactly the way ConfigMerge does ({@see ConfigMerge::isAssociative()}):
 * an associative array is walked deeper; a LIST (including an empty array) is
 * compared as one atomic value, never diffed element-by-element.
 */
class ConfigDiffCommand extends Command
{
    protected $signature = 'heisenberg:config-diff {--json : Emit machine-readable JSON instead of human-readable text}';

    protected $description = "Diff the host's effective config('heisenberg') against the package's shipped defaults; flags stale/overridden values and (should-be-impossible) missing keys.";

    public function handle(): int
    {
        $defaults = require HeisenbergServiceProvider::configPath();
        $effective = (array) config('heisenberg', []);

        $missing = [];
        $differs = [];
        $this->diff($defaults, $effective, '', $missing, $differs);

        $ok = $missing === [];

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ok' => $ok,
                'missing' => $missing,
                'differs' => $differs,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        $this->info(sprintf(
            '%d package default value(s) checked against the effective config.',
            $this->countLeaves($defaults)
        ));

        if ($missing === []) {
            $this->info('No missing keys — every package default reaches the effective config.');
        } else {
            $this->error(sprintf(
                '%d key(s) present in the package but MISSING from the effective config — the deep merge should prevent this entirely:',
                count($missing)
            ));
            foreach ($missing as $path => $value) {
                $this->line(" - {$path} = " . $this->format($value));
            }
        }

        if ($differs === []) {
            $this->info('Host config matches the package defaults everywhere.');
        } else {
            $this->warn(sprintf(
                '%d key(s) where the host differs from the package default (host override OR stale — read each):',
                count($differs)
            ));
            foreach ($differs as $path => $pair) {
                $this->line(" - {$path}:");
                $this->line('     package: ' . $this->format($pair['package']));
                $this->line('     host:    ' . $this->format($pair['host']));
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param array<array-key, mixed> $defaults
     * @param array<array-key, mixed> $effective
     * @param array<string, mixed> $missing dot-path => package value, by reference
     * @param array<string, array{package: mixed, host: mixed}> $differs dot-path => both values, by reference
     */
    private function diff(array $defaults, array $effective, string $prefix, array &$missing, array &$differs): void
    {
        foreach ($defaults as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (! array_key_exists($key, $effective)) {
                $missing[$path] = $value;
                continue;
            }

            $hostValue = $effective[$key];

            if (is_array($value) && is_array($hostValue) && ConfigMerge::isAssociative($value) && ConfigMerge::isAssociative($hostValue)) {
                $this->diff($value, $hostValue, $path, $missing, $differs);
                continue;
            }

            if ($value !== $hostValue) {
                $differs[$path] = ['package' => $value, 'host' => $hostValue];
            }
        }
    }

    /**
     * @param array<array-key, mixed> $defaults
     */
    private function countLeaves(array $defaults): int
    {
        $count = 0;
        foreach ($defaults as $value) {
            if (is_array($value) && $value !== [] && ConfigMerge::isAssociative($value)) {
                $count += $this->countLeaves($value);
                continue;
            }
            $count++;
        }

        return $count;
    }

    private function format(mixed $value): string
    {
        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }

        return (string) $value;
    }
}
