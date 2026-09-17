<?php

namespace App\Http\Controllers\Api\Meta;

use App\Http\Controllers\Controller;
use App\Models\LegalDocument;
use Illuminate\Http\JsonResponse;

/**
 * Public, unauthenticated Privacy Policy / Terms of Use content — consumed
 * by the mobile app's Legal screen, the mandatory consent gate (both
 * platforms), and the public web pages linked from Login. See
 * App\Support\LegalDocuments for versioning rules.
 */
class LegalDocumentController extends Controller
{
    /**
     * GET /api/meta/legal
     */
    public function active(): JsonResponse
    {
        $privacy = LegalDocument::activeVersion(LegalDocument::TYPE_PRIVACY_POLICY);
        $terms = LegalDocument::activeVersion(LegalDocument::TYPE_TERMS_OF_USE);

        return response()->json([
            'data' => [
                'privacy_policy' => $privacy ? $this->full($privacy) : null,
                'terms_of_use' => $terms ? $this->full($terms) : null,
            ],
        ]);
    }

    /**
     * @return array{type:string, version:int, title:string, content:string, effective_date:string}
     */
    private function full(LegalDocument $document): array
    {
        return [
            'type' => $document->type,
            'version' => $document->version,
            'title' => $document->title,
            'content' => $document->content,
            'effective_date' => $document->effective_date->toDateString(),
        ];
    }
}
