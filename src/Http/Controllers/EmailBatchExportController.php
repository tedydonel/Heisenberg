<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Models\Post;
use Heisenberg\Services\EmailBatchExporter;
use Heisenberg\Support\EmailBatchExportResult;
use Heisenberg\Support\EmailBatchTranslationMissingException;
use Heisenberg\Support\EmailVariableResolutionException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * Wave E5 / Task 6 admin batch export endpoint
 * (.hermes/plans/2026-08-25_190059-email-template-variables.md, Task 6):
 *
 *   POST /editor/email/{post}/batch-export
 *
 * Behind the editor's `heisenberg.middleware.editor` gate (a host typically widens to
 * `['web', 'auth']`); gated AGAIN by PostPolicy::generateEmailBatch — LocalDevRoleGate +
 * `email.generate` tier + `$post->type === 'email'` + `$post->status === 'published'`. The Gate
 * runs FIRST, so a forged body never leaks past an unauthorized actor. The body is JSON
 * (a non-JSON body fails before this controller — see test coverage). The success response
 * is `Content-Type: application/zip` with an `attachment` Content-Disposition; every failure
 * surfaces as a controlled 4xx with `{message, ...}` and the on-disk temp zip is removed
 * before the response is sent, so the editor's storage directory does not accumulate
 * half-zipped files.
 *
 * Per the locked product decisions:
 *  - No Mail::send, no SMTP config, no mailer facade is reached.
 *  - Recipient values never appear in any error body — only keys + safe reasons.
 *  - The exporter (a separate service) is the only place that touches {@see Post} and the
 *    admin-supplied options array; this controller is a JSON-POST wrapper that never
 *    re-validates or re-derives anything itself.
 *  - The DTO returned from {@see EmailBatchExporter::export()} is intentionally narrow
 *    (path, fileCount, recipientCount, locales) — recipient values never reach the DTO,
 *    so they cannot reach this controller's response either.
 */
class EmailBatchExportController
{
    public function __construct(
        private EmailBatchExporter $exporter,
    ) {
    }

    /**
     * Handle the admin batch-export POST. The route pattern (`POST /editor/email/{post}/batch-export`)
     * carries the post id; the body is the JSON options array documented on
     * {@see EmailBatchExporter::export()}.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException when Gate::authorize fails
     */
    public function export(Request $request, string $post): BaseResponse
    {
        $model = $this->findOrFail($post);
        abort_unless($model->type === 'email', 404);

        Gate::forUser($this->actor($request))->authorize('generateEmailBatch', $model);

        try {
            $options = $this->normalizeOptions($request);
            $result = $this->exporter->export($model, $options);
        } catch (EmailBatchTranslationMissingException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'locales' => $e->locales,
            ], 422);
        } catch (EmailVariableResolutionException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'failures' => $e->getFailures(),
                'keys' => $e->getKeys(),
            ], 422);
        } catch (\InvalidArgumentException $e) {
            return $this->validationError($e->getMessage(), 422);
        }

        return $this->streamZip($result);
    }

    /**
     * Read and validate the JSON body shape. Mirrors the exporter's own structural checks
     * (so a host that POSTs the wrong key gets the right 422 reason from the right layer),
     * but does NOT pre-validate locale / recipient / id rules — those are the exporter's
     * responsibility and any failure must surface with the exporter's message verbatim.
     *
     * @return array<string, mixed>
     */
    private function normalizeOptions(Request $request): array
    {
        if (! $request->isJson()) {
            throw new \InvalidArgumentException('Batch export body must use application/json.');
        }

        $body = json_decode($request->getContent(), true);
        if (! is_array($body) || array_is_list($body)) {
            throw new \InvalidArgumentException('Batch export body must be a JSON object.');
        }

        return $body;
    }

    /**
     * Stream the on-disk zip back to the admin, then DELETE it from storage so the editor's
     * storage directory does not accumulate half-zipped files. `BinaryFileResponse` is the
     * Symfony class Laravel uses to stream a file off disk with the right headers — the
     * cleanup runs as the response's `deleteFileAfterSend` flag, so the unlink happens
     * exactly once and only after a successful send.
     */
    private function streamZip(EmailBatchExportResult $result): BinaryFileResponse
    {
        $response = new BinaryFileResponse(
            $result->path,
            200,
            [
                'Content-Type' => 'application/zip',
                'Content-Disposition' => 'attachment; filename="' . basename($result->path) . '"',
            ],
        );
        $response->deleteFileAfterSend(true);

        return $response;
    }

    /**
     * Every exporter / validator failure surfaces as a controlled 422 with a safe message.
     * Runtime values, formatter exception messages, and stack traces NEVER reach the body —
     * the three exception types we catch already discard those at the throw site.
     */
    private function validationError(string $message, int $status): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }

    /**
     * Resolve the post by id. Scoped via `with('blocks')` for parity with the existing
     * email preview controllers — the renderer always walks the post's block tree.
     */
    private function findOrFail(string $post): Post
    {
        /** @var class-string<Post> $class */
        $class = (string) config('heisenberg.models.post', Post::class);

        return $class::query()->with('blocks')->findOrFail($post);
    }

    /**
     * The actor whose authorization runs through Gate::forUser(...)->authorize(...). Same
     * GuestActor pattern as {@see EmailPreviewController::actor()} — a real, authenticated
     * user is forwarded as-is, and the absence of one becomes an inert stand-in so the
     * LocalDevRoleGate's environment-gated bypass (or its denial in non-local envs) is
     * the one and only authorization decision.
     */
    private function actor(Request $request): Authenticatable
    {
        return $request->user() ?? new \Heisenberg\Adapters\GuestActor();
    }
}
