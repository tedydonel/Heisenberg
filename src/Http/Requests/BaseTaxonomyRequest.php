<?php

declare(strict_types=1);

namespace Heisenberg\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared authorize() for the four taxonomy FormRequests (Store/Update x
 * Category/Tag) — deliberately always `true`, same rationale SavePostRequest
 * documents: the real ability check needs the configured model class
 * (config('heisenberg.models.category'|'tag')), which only the controller
 * resolves. CategoryController/TagController run the matching policy check
 * (create/update) immediately after the request validates. This class only
 * gates payload SHAPE via each subclass's rules().
 */
abstract class BaseTaxonomyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
