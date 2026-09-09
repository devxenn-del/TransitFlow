<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\SetVoidPinRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A manager's own void-PIN — used to authorize cash-count voids
 * (docs/MIGRATION_MAP.md §4.4). Only ever acts on the authenticated user's
 * own PIN; an admin resetting someone else's PIN is the separate Void
 * Security console (deferred).
 */
class VoidPinController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'has_pin' => $user->hasVoidPin(),
            'locked' => $user->voidPinIsLocked(),
            'locked_until' => $user->voidPinIsLocked() ? $user->void_pin_locked_until : null,
            'failed_count' => (int) $user->void_pin_failed_count,
        ]);
    }

    public function update(SetVoidPinRequest $request): JsonResponse
    {
        $request->user()->forceFill([
            'void_pin_hash' => $request->string('pin')->value(),
            'void_pin_failed_count' => 0,
            'void_pin_locked_until' => null,
        ])->save();

        return response()->json(['has_pin' => true]);
    }
}
