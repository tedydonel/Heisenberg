<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Heisenberg admin batch export — host usage example
|--------------------------------------------------------------------------
|
| This is an EXAMPLE of an admin / host job calling the
| `EmailBatchExporter::export()` service. The service produces ONE zip
| on disk containing exactly N × requested-locales personalized files;
| the host sends those files, Heisenberg does not.
|
| The example wires up:
|   - The `format` (`'html'` or `'eml'`) — one format per call; call
|     twice if both are wanted.
|   - The `locales` array — defaults to every configured locale
|     (`LocaleConfig::locales()`, typically `['en', 'fr']`); pass an
|     explicit list to narrow. Each locale is validated against the
|     installed set; an unknown locale fails with a controlled
|     `InvalidArgumentException` BEFORE any file is rendered.
|   - The `recipients` array — each entry is `{id: string, values:
|     array<string, mixed>}`. `id` is filename-safe and unique within
|     the batch; `values` is the flat dotted-key map whose keys MUST be
|     registered with the host's `EmailVariableRegistry`. N is the
|     literal `count($recipients)` and is capped at
|     `config('heisenberg.email.batch_max_recipients')` (default 100).
|
| The result is the `EmailBatchExportResult` DTO: four fields, no
| values — `path` (the zip on disk), `fileCount`, `recipientCount`,
| `locales` (the validated list). The HTTP layer wraps this DTO in a
| `BinaryFileResponse` and deletes the zip on send; calling the
| service directly (e.g. from a queued host job) means the host owns
| the cleanup.
|
| WHAT THIS FILE DOES NOT DO:
|   - No `Mail::send`, no SMTP config, no mailer facade. Sending is
|     the host's job. The host reads the zip off disk, hands each
|     `.eml` file to its own mailer (Symfony Mailer, Postmark, SES,
|     anything), and tracks its own delivery.
|   - No recipient list persistence. Recipients come from this call;
|     Heisenberg does NOT discover them from `RoleGate` membership or
|     any user table.
|   - No `Mail::queue(new HeisenbergMailable(...))`. The bundled
|     Mailable is for single-recipient sends where the host owns
|     transport; queued recipients pre-render once per recipient (Task 3
|     queue-safety note) and ship as already-rendered `EmailRenderResult`.
|
| The plan reference: `.hermes/plans/2026-08-25_190059-email-template-variables.md`,
| Task 6 ("Admin batch generate and export, no SMTP") and §"Locked
| product decisions" (recipients stay a flat map; N is the admin-
| supplied list length; default locales are `LocaleConfig::locales()`).
*/

namespace App\Console\Commands; // <-- host namespace; replace with your app's

use Heisenberg\Models\Post;
use Heisenberg\Services\EmailBatchExporter;
use Heisenberg\Support\EmailBatchExportResult;
use Heisenberg\Support\EmailBatchTranslationMissingException;
use Heisenberg\Support\EmailVariableResolutionException;
use Illuminate\Console\Command;

/**
 * A host command that produces an admin batch export for one
 * already-published email post. The example uses `php artisan` so a
 * cron / scheduler can drive it; the same `EmailBatchExporter::export()`
 * call is what the bundled HTTP route
 * `POST /editor/email/{post}/batch-export` runs behind
 * `PostPolicy::generateEmailBatch`.
 */
final class ExportPersonalizedEmailBatch extends Command
{
    /** @var string */
    protected $signature = 'email:export-batch
        {post : The published email Post id (must be type=email, status=published)}
        {--format=html : html or eml}
        {--recipient=* : A `id=key:value,key:value` row, repeatable}';

    /** @var string */
    protected $description = 'Generate a zip of N × locale personalized files for one published email.';

    public function handle(EmailBatchExporter $exporter): int
    {
        // 1) Resolve the published email post.
        //    In a real host, this is your `Post` model — the same one
        //    Heisenberg ships. The exporter requires `type = 'email'`
        //    AND `status = 'published'` (see PostPolicy::generateEmailBatch
        //    — both checks gate the HTTP route). A job that runs as
        //    `admin` bypasses PostPolicy but should still filter — a
        //    draft email's translation matrix is incomplete and the
        //    exporter's `TranslationStatusService` completeness check
        //    surfaces an `EmailBatchTranslationMissingException` (HTTP
        //    422 in the controller, exit code 1 here).
        /** @var Post|null $email */
        $email = Post::query()
            ->where('type', 'email')
            ->where('status', 'published')
            ->find((int) $this->argument('post'));

        if ($email === null) {
            $this->error('No published email post with that id.');

            return self::FAILURE;
        }

        // 2) Build the admin-supplied recipient value maps.
        //    Each `id` becomes part of every output filename
        //    (`{slug}/{locale}/{id}.{html|eml}`); each `values` map is
        //    handed to the interpolator at render time. Keys MUST be
        //    registered with `EmailVariableRegistry` (the host provider
        //    in AppServiceProvider does that) — an unregistered key is a
        //    controlled `InvalidArgumentException` BEFORE any file is
        //    written.
        $recipients = $this->parseRecipients($this->option('recipient'));
        if ($recipients === []) {
            $this->error('Pass at least one --recipient row.');

            return self::FAILURE;
        }

        // 3) Build the options array the exporter documents:
        //      - format:        'html' | 'eml' (one per call)
        //      - locales:       list<string>; default `LocaleConfig::locales()`
        //      - recipients:    list<{id, values}>
        //    An omitted `locales` array → exporter defaults to ALL
        //    configured locales. Omitting the key is the one-call
        //    "export every translation we have" posture; pass an
        //    explicit list to narrow.
        $options = [
            'format' => (string) $this->option('format'),
            'recipients' => $recipients,
            // Intentionally NOT passing `locales` here — defaults to
            // LocaleConfig::locales(), which is the plan's locked
            // decision §"Locales: generate every requested locale that
            // exists". Pass `['en', 'fr']` (or your configured subset)
            // explicitly to narrow.
        ];

        try {
            /** @var EmailBatchExportResult $result */
            $result = $exporter->export($email, $options);
        } catch (EmailBatchTranslationMissingException $e) {
            // Structural failure: the post is missing complete persisted
            // content for one or more requested locales. The DTO is
            // narrow (`path`, `fileCount`, `recipientCount`, `locales`)
            // and never carries a runtime value — this exception
            // carries `$locales` (the missing list) and `$postLocale`
            // (the row's own locale) and NOTHING else.
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (EmailVariableResolutionException $e) {
            // Per-recipient × per-locale aggregated failure: unknown
            // tokens, missing values, formatter exceptions, or
            // formatter target incompatibilities. The exception's
            // `getFailures()` returns `list<{key, reason}>` where `key`
            // is `<recipientId>/<locale>/<variableKey>` and `reason` is
            // one of the four constants — never a runtime value, never
            // a formatter internals string. The exporter's all-or-
            // nothing posture means NO zip is on disk at this point.
            $this->error($e->getMessage());
            $this->table(['key', 'reason'], $e->getFailures());

            return self::FAILURE;
        }

        // 4) Consume the narrow DTO. `path` is the temp zip inside
        //    `storage_path('app')` (private — never public/uploads disk).
        //    The HTTP route unlinks it in `deleteFileAfterSend(true)`;
        //    this CLI example unlinks explicitly.
        $this->info(sprintf(
            'Wrote %d files for %d recipients across %d locale(s) to %s',
            $result->fileCount,
            $result->recipientCount,
            count($result->locales),
            $result->path,
        ));

        @unlink($result->path);

        return self::SUCCESS;
    }

    /**
     * Parse `--recipient id=key:value,key:value` rows into the
     * `{id, values}` shape the exporter documents. The example uses a
     * flat colon-and-comma syntax so it works on a shell; a real host
     * would read the recipient list from its own domain (a CSV upload,
     * a DB query, a third-party list) — Heisenberg receives the final
     * shape regardless of source.
     *
     * @param  list<string>  $rows
     * @return list<array{id: string, values: array<string, mixed>}>
     */
    private function parseRecipients(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            // id=k1:v1,k2:v2
            $eq = strpos($row, '=');
            if ($eq === false) {
                continue;
            }
            $id = substr($row, 0, $eq);
            $pairs = substr($row, $eq + 1);

            $values = [];
            foreach (explode(',', $pairs) as $pair) {
                $colon = strpos($pair, ':');
                if ($colon === false) {
                    continue;
                }
                $values[substr($pair, 0, $colon)] = substr($pair, $colon + 1);
            }

            $out[] = ['id' => $id, 'values' => $values];
        }

        return $out;
    }
}
