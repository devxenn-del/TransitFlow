<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\StoreLegalDocumentRequest;
use App\Http\Resources\LegalDocumentResource;
use App\Models\LegalDocument;
use App\Support\LegalDocuments;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Super Admin CMS for the Privacy Policy / Terms of Use — publish/version
 * content. See App\Support\LegalDocuments for the versioning rules.
 */
class LegalDocumentAdminController extends Controller
{
    /** Every version of both documents, newest first — the history table. */
    public function index(): AnonymousResourceCollection
    {
        $rows = LegalDocument::query()->with('publishedBy')->orderByDesc('type')->orderByDesc('version')->get();

        return LegalDocumentResource::collection($rows);
    }

    /** The currently active version of one type, for form prefill. */
    public function active(string $type): LegalDocumentResource
    {
        $document = LegalDocument::activeVersion($type);

        abort_if($document === null, 404, 'No active document of that type yet.');

        return LegalDocumentResource::make($document->load('publishedBy'));
    }

    public function store(StoreLegalDocumentRequest $request): LegalDocumentResource
    {
        $document = LegalDocuments::publish(
            $request->string('type')->value(),
            $request->string('title')->value(),
            $request->string('content')->value(),
            $request->string('effective_date')->value(),
            $request->user(),
        );

        return LegalDocumentResource::make($document->load('publishedBy'));
    }
}
