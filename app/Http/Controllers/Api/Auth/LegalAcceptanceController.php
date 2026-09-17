<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LegalAcceptRequest;
use App\Models\LegalDocument;
use App\Support\LegalDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Records the authenticated user's acceptance of the Privacy Policy /
 * Terms of Use — mobile and web both call this (see LegalAcceptRequest's
 * `platform` field). Rejects a version that isn't the currently-active
 * one, so a stale client can't record acceptance of an outdated (or
 * not-yet-published) version.
 */
class LegalAcceptanceController extends Controller
{
    public function store(LegalAcceptRequest $request): JsonResponse
    {
        $privacy = LegalDocument::activeVersion(LegalDocument::TYPE_PRIVACY_POLICY);
        $terms = LegalDocument::activeVersion(LegalDocument::TYPE_TERMS_OF_USE);

        if ($privacy !== null && $request->integer('privacy_version') !== $privacy->version) {
            throw ValidationException::withMessages(['privacy_version' => 'That is not the currently active Privacy Policy version.']);
        }

        if ($terms !== null && $request->integer('terms_version') !== $terms->version) {
            throw ValidationException::withMessages(['terms_version' => 'That is not the currently active Terms of Use version.']);
        }

        LegalDocuments::recordAcceptance(
            $request->user(),
            $privacy?->version,
            $terms?->version,
            $request->string('app_version')->value() ?: null,
            $request->string('platform')->value(),
        );

        return response()->json(['message' => 'Accepted.']);
    }
}
