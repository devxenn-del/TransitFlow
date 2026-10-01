<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreCompanyDocumentRequest;
use App\Http\Requests\Company\UpdateCompanyDocumentRequest;
use App\Http\Resources\CompanyDocumentResource;
use App\Models\CompanyDocument;
use App\Support\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The bound company's document library. Rows are auto-scoped by
 * CompanyScope; files sit on the private `local` disk and are only served
 * through {@see download()}, so another company's document is a 404 both
 * as a record and as a file. Capability is gated by `permission:documents.*`.
 */
class CompanyDocumentController extends Controller
{
    private const DISK = 'local';

    /** Sort keys the library accepts => [column, direction]. */
    private const SORTS = [
        'newest' => ['created_at', 'desc'],
        'oldest' => ['created_at', 'asc'],
        'name' => ['name', 'asc'],
        'expiry' => ['expires_at', 'asc'],
    ];

    /**
     * The library, filtered by any of: `q` (name / description / reference /
     * file name), `category` (comma list), `important=1`, `status`
     * (valid|expiring|expired|no_expiry), `file_kind`. Important documents
     * always come first, then `sort` (newest|oldest|name|expiry).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CompanyDocument::class);

        [$column, $direction] = self::SORTS[$request->string('sort')->value()] ?? self::SORTS['newest'];
        $search = $request->string('q')->trim()->value();

        return CompanyDocumentResource::collection(
            CompanyDocument::query()
                ->with('uploader')
                ->when($request->string('category')->isNotEmpty(), fn ($q) => $q->whereIn('category', explode(',', $request->string('category'))))
                ->when($request->boolean('important'), fn ($q) => $q->where('is_important', true))
                ->when(in_array($request->string('status')->value(), CompanyDocument::STATUSES, true), fn ($q) => $q->withStatus($request->string('status')->value()))
                ->when($request->string('file_kind')->isNotEmpty(), fn ($q) => $q->ofFileKind($request->string('file_kind')->value()))
                ->when($search !== '', fn ($q) => $q->where(
                    fn ($s) => $s->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('reference_number', 'like', "%{$search}%")
                        ->orWhere('original_name', 'like', "%{$search}%")
                ))
                ->orderByDesc('is_important')
                // Undated documents sort last when sorting by expiry.
                ->when($column === 'expires_at', fn ($q) => $q->orderByRaw('expires_at is null'))
                ->orderBy($column, $direction)
                ->orderByDesc('id')
                ->paginate($request->integer('per_page', 24))
        );
    }

    /**
     * Counts for the library's filter rail: per category, important,
     * expiring / expired, and storage used.
     */
    public function summary(): JsonResponse
    {
        $this->authorize('viewAny', CompanyDocument::class);

        $perCategory = CompanyDocument::query()
            ->selectRaw('category, count(*) as aggregate')
            ->groupBy('category')
            ->pluck('aggregate', 'category');

        return response()->json(['data' => [
            'total' => CompanyDocument::query()->count(),
            'important' => CompanyDocument::query()->where('is_important', true)->count(),
            'expiring' => CompanyDocument::query()->withStatus('expiring')->count(),
            'expired' => CompanyDocument::query()->withStatus('expired')->count(),
            'total_size' => (int) CompanyDocument::query()->sum('size'),
            'categories' => collect(CompanyDocument::CATEGORIES)->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
                'count' => (int) ($perCategory[$key] ?? 0),
            ])->values(),
        ]]);
    }

    /** Star / un-star a document. */
    public function toggleImportant(Request $request, CompanyDocument $document): CompanyDocumentResource
    {
        $this->authorize('update', $document);

        $document->update(['is_important' => $request->boolean('is_important', ! $document->is_important)]);

        return CompanyDocumentResource::make($document->fresh()->load('uploader'));
    }

    public function store(StoreCompanyDocumentRequest $request, CompanyContext $context): JsonResponse
    {
        $document = CompanyDocument::query()->create([
            ...$request->safe()->except('file'),
            ...$this->storeFile($request->file('file'), $context->companyId()),
            'uploaded_by' => $request->user()->id,
        ]);

        return CompanyDocumentResource::make($document->load('uploader'))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(CompanyDocument $document): CompanyDocumentResource
    {
        $this->authorize('view', $document);

        return CompanyDocumentResource::make($document->load('uploader'));
    }

    public function update(UpdateCompanyDocumentRequest $request, CompanyDocument $document): CompanyDocumentResource
    {
        $data = $request->safe()->except('file');

        if ($request->hasFile('file')) {
            $previousPath = $document->file_path;
            $data = [
                ...$data,
                ...$this->storeFile($request->file('file'), $document->company_id),
                'uploaded_by' => $request->user()->id,
            ];
        }

        $document->update($data);

        if (isset($previousPath)) {
            Storage::disk(self::DISK)->delete($previousPath);
        }

        return CompanyDocumentResource::make($document->fresh()->load('uploader'));
    }

    public function destroy(CompanyDocument $document): JsonResponse
    {
        $this->authorize('delete', $document);

        $document->delete();
        Storage::disk(self::DISK)->delete($document->file_path);

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }

    /**
     * Stream the file — as an attachment, or `?inline=1` for an in-browser
     * preview (PDFs and images).
     */
    public function download(Request $request, CompanyDocument $document): StreamedResponse
    {
        $this->authorize('view', $document);

        abort_unless(Storage::disk(self::DISK)->exists($document->file_path), JsonResponse::HTTP_NOT_FOUND, 'The file is missing.');

        return Storage::disk(self::DISK)->response(
            $document->file_path,
            $document->original_name,
            array_filter(['Content-Type' => $document->mime_type]),
            $request->boolean('inline') ? 'inline' : 'attachment',
        );
    }

    /**
     * @return array{file_path: string, original_name: string, mime_type: ?string, size: int}
     */
    private function storeFile(UploadedFile $file, ?int $companyId): array
    {
        return [
            'file_path' => $file->store("company-documents/{$companyId}", self::DISK),
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ];
    }
}
