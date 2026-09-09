<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StorePassengerTypeArticleRequest;
use App\Http\Requests\Company\UpdatePassengerTypeArticleRequest;
use App\Http\Resources\PassengerTypeArticleResource;
use App\Models\PassengerType;
use App\Models\PassengerTypeArticle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Preset "articles" for a Manual Amount passenger type. Nested under the
 * passenger type; gated by `permission:passengertypes.edit`.
 */
class PassengerTypeArticleController extends Controller
{
    public function index(PassengerType $passengerType): AnonymousResourceCollection
    {
        $this->authorize('view', $passengerType);

        return PassengerTypeArticleResource::collection($passengerType->articles()->get());
    }

    public function store(StorePassengerTypeArticleRequest $request, PassengerType $passengerType): JsonResponse
    {
        $article = $passengerType->articles()->create([
            ...$request->validated(),
            'company_id' => $passengerType->company_id,
        ]);

        return PassengerTypeArticleResource::make($article)
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function update(UpdatePassengerTypeArticleRequest $request, PassengerType $passengerType, PassengerTypeArticle $article): PassengerTypeArticleResource
    {
        abort_unless($article->passenger_type_id === $passengerType->id, 404);

        $article->update($request->validated());

        return PassengerTypeArticleResource::make($article->fresh());
    }

    public function destroy(PassengerType $passengerType, PassengerTypeArticle $article): JsonResponse
    {
        $this->authorize('update', $passengerType);
        abort_unless($article->passenger_type_id === $passengerType->id, 404);

        $article->delete();

        return response()->json(status: JsonResponse::HTTP_NO_CONTENT);
    }
}
