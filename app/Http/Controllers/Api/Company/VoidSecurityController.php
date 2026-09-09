<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Models\CashCountVoidAttempt;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Void Security console — BITS `admin/voidsecurity.php` (docs/MIGRATION_MAP.md
 * §4.4). An oversight screen for company admins: who holds a void-PIN, who
 * is locked out, the attempt audit trail, and the ability to force a PIN
 * reset or clear a lockout. (Managers set their OWN PIN from the account
 * menu — they cannot use this console.)
 */
class VoidSecurityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $users = User::query()
            ->where('company_id', $companyId)
            ->with(['accessRole', 'permissions'])
            ->orderBy('name')
            ->get()
            ->filter(fn (User $u) => $u->hasVoidPin() || $u->hasPermissionTo('voidpin.manage'))
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->accessRole?->name,
                'has_void_pin' => $u->hasVoidPin(),
                'failed_count' => (int) $u->void_pin_failed_count,
                'locked' => $u->voidPinIsLocked(),
                'locked_until' => $u->voidPinIsLocked() ? $u->void_pin_locked_until : null,
            ]);

        return response()->json(['data' => $users->values()]);
    }

    public function attempts(Request $request): JsonResponse
    {
        $attempts = CashCountVoidAttempt::query()
            ->with('manager:id,name')
            ->when($request->filled('manager_id'), fn ($q) => $q->where('manager_id', $request->integer('manager_id')))
            ->when($request->filled('success'), fn ($q) => $q->where('success', $request->boolean('success')))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 25));

        $attempts->getCollection()->transform(fn (CashCountVoidAttempt $a) => [
            'id' => $a->id,
            'manager' => $a->manager?->name,
            'subject' => $a->subject,
            'success' => (bool) $a->success,
            'detail' => $a->detail,
            'attempted_at' => $a->attempted_at,
        ]);

        return response()->json($attempts);
    }

    /** Force the manager to set a fresh void-PIN. */
    public function reset(Request $request, User $user): JsonResponse
    {
        $this->guard($request, $user);

        $user->forceFill([
            'void_pin_hash' => null,
            'void_pin_failed_count' => 0,
            'void_pin_locked_until' => null,
        ])->save();

        return response()->json(['message' => 'Void PIN cleared. The manager must set a new one.']);
    }

    /** Clear a lockout without removing the PIN. */
    public function unlock(Request $request, User $user): JsonResponse
    {
        $this->guard($request, $user);

        $user->forceFill([
            'void_pin_failed_count' => 0,
            'void_pin_locked_until' => null,
        ])->save();

        return response()->json(['message' => 'Lockout cleared.']);
    }

    private function guard(Request $request, User $user): void
    {
        abort_unless($user->company_id === $request->user()->company_id, 404);
    }
}
