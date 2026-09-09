<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreTerminalRequest;
use App\Http\Requests\Company\UpdateTerminalRequest;
use App\Http\Resources\TerminalResource;
use App\Models\Terminal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Terminals for the current company. Auto-scoped by CompanyScope on the
 * model; capability gated by `permission:terminals.*` on the routes.
 */
class TerminalController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Terminal::class);

        return TerminalResource::collection(
            Terminal::query()
                ->when($request->string('status')->isNotEmpty(), fn ($q) => $q->where('status', $request->string('status')))
                ->orderBy('name')
                ->paginate($request->integer('per_page', 20))
        );
    }

    public function store(StoreTerminalRequest $request): JsonResponse
    {
        $terminal = Terminal::query()->create($request->validated());

        return TerminalResource::make($terminal)
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(Terminal $terminal): TerminalResource
    {
        $this->authorize('view', $terminal);

        return TerminalResource::make($terminal);
    }

    public function update(UpdateTerminalRequest $request, Terminal $terminal): TerminalResource
    {
        $terminal->update($request->validated());

        return TerminalResource::make($terminal->fresh());
    }

    public function destroy(Terminal $terminal): JsonResponse
    {
        $this->authorize('delete', $terminal);

        $terminal->delete();

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }
}
