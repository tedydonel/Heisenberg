<?php

declare(strict_types=1);

namespace Heisenberg\Tests\M0;

use Heisenberg\Console\Commands\ConfigDiffCommand;
use Heisenberg\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `heisenberg:config-diff` — the tool docs/README point a host at after
 * upgrading (see ConfigDiffCommand's docblock for why it exists: three real
 * incidents of a published config silently going stale). Run directly the
 * same way TemplatesVerifyCommandTest exercises its command, without
 * `Artisan::call()`.
 */
class ConfigDiffCommandTest extends TestCase
{
    private function runCommand(array $input = []): array
    {
        $command = new ConfigDiffCommand();
        $command->setLaravel($this->app);
        $output = new BufferedOutput();
        $exitCode = $command->run(new ArrayInput($input), $output);

        return [$exitCode, $output->fetch()];
    }

    public function test_reports_no_missing_and_no_differences_against_the_packages_own_untouched_config(): void
    {
        [$exitCode, $text] = $this->runCommand(['--json' => true]);

        $payload = json_decode($text, true);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame([], $payload['missing']);
        $this->assertSame([], $payload['differs']);
    }

    /**
     * Seeds exactly the shape of bug that motivated this command: a stale
     * `lifecycle.transitions.draft` list (present on both sides, so it can
     * never appear as "missing," only as a diff a human has to read) plus a
     * plain scalar host override, and asserts both are reported with both
     * values shown.
     */
    public function test_reports_a_seeded_difference_with_both_values_and_still_exits_successfully(): void
    {
        config(['heisenberg.lifecycle.transitions.draft' => ['pending_review']]);
        config(['heisenberg.css_prefix' => 'acme']);

        [$exitCode, $text] = $this->runCommand(['--json' => true]);
        $payload = json_decode($text, true);

        // A `differs` entry is not a failure — it may be a deliberate host
        // override; only a genuinely missing key fails the command (see below).
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);

        $this->assertArrayHasKey('lifecycle.transitions.draft', $payload['differs']);
        $this->assertSame(['pending_review'], $payload['differs']['lifecycle.transitions.draft']['host']);
        $this->assertContains('published', $payload['differs']['lifecycle.transitions.draft']['package']);

        $this->assertArrayHasKey('css_prefix', $payload['differs']);
        $this->assertSame('acme', $payload['differs']['css_prefix']['host']);
        $this->assertSame('hb', $payload['differs']['css_prefix']['package']);
    }

    public function test_human_readable_output_names_the_seeded_difference(): void
    {
        config(['heisenberg.css_prefix' => 'acme']);

        [$exitCode, $text] = $this->runCommand();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('css_prefix', $text);
        $this->assertStringContainsString('acme', $text);
        $this->assertStringContainsString('hb', $text);
    }

    /**
     * A genuinely missing key (the deep merge failing to do its one job) is
     * the only thing that fails this command — simulated here by stripping a
     * key straight out of the effective config after boot.
     */
    public function test_a_missing_key_fails_the_command(): void
    {
        $post_template = config('heisenberg.post_template');
        unset($post_template['related_posts_provider']);
        config(['heisenberg.post_template' => $post_template]);

        [$exitCode, $text] = $this->runCommand(['--json' => true]);
        $payload = json_decode($text, true);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertArrayHasKey('post_template.related_posts_provider', $payload['missing']);
    }
}
