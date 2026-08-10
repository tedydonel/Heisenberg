<?php

declare(strict_types=1);

namespace Heisenberg\Http\Requests;

use Closure;
use Heisenberg\Contracts\RoleGate;
use Heisenberg\Models\PublicFile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Validation for `POST /media/upload` (blueprint §5 step 1-2, §8): ability-gated
 * authorize(), mimes/max/no-path-separators rules, single (`file`) or multi
 * (`files[]`) upload, plus the bilingual meta fields. No role names — the upload
 * ability is resolved through the RoleGate contract (blueprint §12 invariant).
 */
class UploadPublicFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Same GuestActor + LocalDevRoleGate seam as PublicFilePolicy (2026-08-10):
        // "no user" is allowed on a developer's local machine only; a real user
        // is always answered by the configured gate.
        $actor = $this->user() ?? new \Heisenberg\Adapters\GuestActor();

        return (new \Heisenberg\Adapters\LocalDevRoleGate(app(RoleGate::class)))->is($actor, 'media.create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKb = (int) config('heisenberg.media.max_kb', PublicFile::MAX_KB);
        $extensions = implode(',', (array) config('heisenberg.media.extensions', PublicFile::TYPES));

        return [
            'file' => ['sometimes', 'file', "mimes:{$extensions}", "max:{$maxKb}", $this->noPathSeparatorsRule()],
            'files' => ['sometimes', 'array', 'min:1'],
            'files.*' => ['file', "mimes:{$extensions}", "max:{$maxKb}", $this->noPathSeparatorsRule()],
            'alt_text_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'alt_text_fr' => ['sometimes', 'nullable', 'string', 'max:255'],
            'caption_en' => ['sometimes', 'nullable', 'string', 'max:500'],
            'caption_fr' => ['sometimes', 'nullable', 'string', 'max:500'],
            'credit' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator): void {
            if (! $this->hasFile('file') && ! $this->hasFile('files')) {
                $validator->errors()->add('file', 'A file is required.');
            }
        });
    }

    /** Path separators in the filename are rejected outright (blueprint §3.2). */
    private function noPathSeparatorsRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $name = $value instanceof UploadedFile ? $value->getClientOriginalName() : '';
            if ($name !== '' && (str_contains($name, '/') || str_contains($name, '\\'))) {
                $fail('The filename must not contain path separators.');
            }
        };
    }
}
