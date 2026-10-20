<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\PinLoginRequest;
use App\Http\Requests\Auth\SetPinRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Requests\Auth\VerifyPinRequest;
use App\Http\Resources\DriverResource;
use App\Http\Resources\UserResource;
use App\Models\Company;
use App\Models\Driver;
use App\Models\User;
use App\Support\AccountLock;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\TransientToken;

/**
 * Email + password authentication for both clients:
 *  - the React SPA (Sanctum stateful session when a session is present),
 *  - the future mobile app (bearer token in the response).
 *
 * Mirrors BITS' credential checks (auth/authenticate_login.php,
 * api/auth/login.php): bcrypt verify, account must be Active, and the
 * account's company must be Active. On top of that, every company account
 * signs in with its Company Code, and must belong to that company — see
 * companyFromCode() / ensureAccountBelongsToCompany().
 */
class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $request->ensureIsNotRateLimited();

        $company = $this->companyFromCode($request);

        $user = User::query()->where('email', $request->string('email'))->first();

        if ($user === null || ! Hash::check($request->string('password')->value(), $user->password)) {
            $request->hitRateLimiter();

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $this->ensureAccountBelongsToCompany($user, $company, $request, 'email', __('auth.failed'));

        $driver = $this->verifyDriverCode($user, $request);

        if (($blocked = $this->rejectIfNotSignable($user, $request)) !== null) {
            return $blocked;
        }

        $request->clearRateLimiter();

        // SPA: establish the stateful session when one exists.
        if ($request->hasSession()) {
            Auth::guard('web')->login($user, $request->boolean('remember'));
            $request->session()->regenerate();
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        $token = $user->createToken($request->deviceName(), $this->tokenAbilitiesFor($user, $driver))->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => UserResource::make($user->loadMissing('company.settings', 'accessRole'))->includePermissions(),
            'driver' => $driver ? DriverResource::make($driver) : null,
        ]);
    }

    /**
     * Conductor sign-in by App PIN instead of password — BITS
     * `api/auth/pinLogin.php`. Which bus a conductor drives is still picked
     * at Start Trip; which driver they're working with for this session is
     * verified right here, same as login() — see verifyDriverCode().
     */
    public function pinLogin(PinLoginRequest $request): JsonResponse
    {
        $request->ensureIsNotRateLimited();

        $company = $this->companyFromCode($request);

        $user = User::query()->where('email', $request->string('email'))->first();

        if ($user === null || $user->pin_hash === null || ! Hash::check($request->string('pin')->value(), $user->pin_hash)) {
            $request->hitRateLimiter();

            throw ValidationException::withMessages(['pin' => 'Incorrect PIN.']);
        }

        $this->ensureAccountBelongsToCompany($user, $company, $request, 'pin', 'Incorrect PIN.');

        if ($user->accessRole?->key !== 'conductor' || ! $user->hasPermissionTo('trips.view')) {
            $request->hitRateLimiter();

            throw ValidationException::withMessages(['pin' => 'This account does not have conductor portal access.']);
        }

        $driver = $this->verifyDriverCode($user, $request);

        if (($blocked = $this->rejectIfNotSignable($user, $request)) !== null) {
            return $blocked;
        }

        $request->clearRateLimiter();

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        $token = $user->createToken($request->deviceName(), $this->tokenAbilitiesFor($user, $driver))->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => UserResource::make($user->loadMissing('company.settings', 'accessRole'))->includePermissions(),
            'driver' => $driver ? DriverResource::make($driver) : null,
        ]);
    }

    /**
     * Re-verify the signed-in user's own PIN — a "quick unlock" check (e.g.
     * the app resumed from background) that doesn't issue a new token.
     * Mirrors accountLock's "already-established session" grace (true).
     */
    public function verifyPin(VerifyPinRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPin() || ! Hash::check($request->string('pin')->value(), $user->pin_hash)) {
            throw ValidationException::withMessages(['pin' => 'Incorrect PIN.']);
        }

        return response()->json(['verified' => true]);
    }

    /** Self-service: set or replace your own App PIN. */
    public function setPin(SetPinRequest $request): JsonResponse
    {
        $request->user()->forceFill(['pin_hash' => $request->string('pin')->value()])->save();

        return response()->json(['has_pin' => true]);
    }

    /**
     * A conductor is not permanently paired with one driver — they may be
     * assigned a different bus/driver from one shift to the next, and any
     * conductor holding a valid Driver Code may use it. So this just
     * verifies the submitted code belongs to an Active driver in the
     * SAME company as the signing-in account (checked once email+password
     * already verified, so this never runs for a plain bad-credentials
     * attempt) and returns that driver — the one Start Trip will require
     * for the rest of this session (see tokenAbilitiesFor() and
     * App\Actions\StartTrip). Returns null only for a non-conductor
     * account, which doesn't need a driver at all. Throws on a missing or
     * unrecognized code — a field-specific message, not folded into the
     * generic auth.failed, since an invalid driver code no longer implies
     * anything about which account it was typed against.
     */
    private function verifyDriverCode(User $user, LoginRequest $request): ?Driver
    {
        if ($user->accessRole?->key !== 'conductor') {
            return null;
        }

        $submitted = trim((string) $request->input('driver_code', ''));
        if ($submitted === '') {
            throw ValidationException::withMessages([
                'driver_code' => 'Driver Code is required.',
            ]);
        }

        $driver = Driver::findActiveByCode($user->company_id, $submitted);
        if ($driver === null) {
            $request->hitRateLimiter();

            throw ValidationException::withMessages([
                'driver_code' => 'That driver code was not recognized.',
            ]);
        }

        return $driver;
    }

    /**
     * Step 1 of sign-in: the Company Code names an existing, Active company.
     * Checked before the credentials and independent of them, so these two
     * messages say nothing about any account. Null when no code was sent —
     * only a platform account may sign in that way (see
     * ensureAccountBelongsToCompany()).
     */
    private function companyFromCode(LoginRequest $request): ?Company
    {
        $code = $request->companyCode();
        if ($code === '') {
            return null;
        }

        $company = Company::query()->whereRaw('UPPER(code) = ?', [$code])->first();

        if ($company === null) {
            $request->hitRateLimiter();

            throw ValidationException::withMessages(['company_code' => 'Invalid Company Code.']);
        }

        if (! $company->isActive()) {
            $request->hitRateLimiter();

            throw ValidationException::withMessages([
                'company_code' => 'This company account is inactive. Contact the TransitFlow administrator.',
            ]);
        }

        return $company;
    }

    /**
     * The verified account must belong to the company named by the Company
     * Code. A mismatch is answered exactly like wrong credentials ($field /
     * $failedMessage), so a sign-in can't be used to learn which company an
     * email belongs to — and is recorded as a security event (never with
     * the submitted password or PIN). A company account that sent no code
     * is asked for one; a platform account must not send one.
     */
    private function ensureAccountBelongsToCompany(User $user, ?Company $company, LoginRequest $request, string $field, string $failedMessage): void
    {
        if ($company === null) {
            if ($user->company_id === null) {
                return;
            }

            $request->hitRateLimiter();

            throw ValidationException::withMessages(['company_code' => 'Enter your Company Code.']);
        }

        if ($user->company_id === $company->id) {
            return;
        }

        $request->hitRateLimiter();

        Log::warning('Sign-in rejected: account does not belong to the submitted company.', [
            'user_id' => $user->id,
            'company_code' => $company->code,
            'ip' => $request->ip(),
        ]);
        Audit::record('auth.company_mismatch', $user, ['company_code' => $company->code], $user->company);

        throw ValidationException::withMessages([$field => $failedMessage]);
    }

    /**
     * The Sanctum abilities to stamp onto a freshly-issued token. A company
     * account's token carries the company it signed in to — re-checked on
     * every request by ResolveCompanyContext, so a token can never be used
     * for a company other than the one it was issued for. A conductor's
     * token additionally carries which driver they verified at sign-in —
     * read back by StartTrip::handle(), which requires a new trip's
     * driver_id to match it (switch drivers by signing in again with a
     * different code). Both ride along with the default '*' without
     * restricting anything else the token can do.
     *
     * @return list<string>
     */
    private function tokenAbilitiesFor(User $user, ?Driver $driver): array
    {
        $abilities = ['*'];

        if ($user->company_id !== null) {
            $abilities[] = "company:{$user->company_id}";
        }

        if ($driver !== null) {
            $abilities[] = "driver:{$driver->id}";
        }

        return $abilities;
    }

    /** The driver a token verified at sign-in, or null (non-conductor account, or a token issued before this feature existed). */
    private function verifiedDriverFromToken(User $user): ?Driver
    {
        $id = Driver::verifiedIdForToken($user);

        return $id !== null ? Driver::query()->find($id) : null;
    }

    /**
     * The account/company/lock checks shared by login() and pinLogin(),
     * run only once credentials already verified. Returns a 423 response
     * when locked, or null when the caller may proceed. Inactive/inactive
     * company throw directly (422), same as a credential failure.
     */
    private function rejectIfNotSignable(User $user, LoginRequest $request): ?JsonResponse
    {
        if (! $user->isActive()) {
            $request->hitRateLimiter();

            throw ValidationException::withMessages([
                'email' => 'This account is inactive. Contact your administrator.',
            ]);
        }

        if ($user->company_id !== null && ($user->company === null || ! $user->company->isActive())) {
            $request->hitRateLimiter();

            throw ValidationException::withMessages([
                'email' => 'Your company account is not active. Contact the TransitFlow administrator.',
            ]);
        }

        // false = no shift-end grace on a fresh sign-in; that window is only
        // for an already-signed-in conductor to Clock Out, not to log back in.
        if (AccountLock::isLocked($user, false)) {
            return response()->json([
                'message' => AccountLock::reasonMessage($user),
                'lock_type' => $user->lock_type,
            ], JsonResponse::HTTP_LOCKED);
        }

        return null;
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        // Bearer token → revoke just this one. Session (TransientToken) →
        // tear the session down.
        if ($token !== null && ! $token instanceof TransientToken) {
            $token->delete();
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * Restores the session on cold start. `driver` mirrors login()/pinLogin()'s
     * response, resolved fresh from the current token's abilities each time
     * (see verifiedDriverFromToken()) rather than cached anywhere, so a web
     * page reload or an app relaunch always reflects the truth.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('company.settings', 'accessRole');
        $driver = $this->verifiedDriverFromToken($user);

        return response()->json([
            'data' => UserResource::make($user)->includePermissions(),
            'driver' => $driver ? DriverResource::make($driver) : null,
        ]);
    }

    /**
     * Self-service edit of the signed-in user's own profile (BITS
     * `admin/profile.php` — name + `account_details` fields). Email and
     * password go through their own dedicated flows, not this one.
     */
    public function updateProfile(UpdateProfileRequest $request): UserResource
    {
        $user = $request->user();

        $user->update($request->safe()->only([
            'name', 'first_name', 'middle_name', 'last_name', 'phone', 'address', 'sex',
        ]));

        return UserResource::make($user->fresh()->loadMissing('company.settings', 'accessRole'))->includePermissions();
    }

    /**
     * Set a new password for the signed-in user. Clears the
     * forced-change flag (used on a new company admin's first sign-in) and
     * revokes every other access token.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->forceFill([
            'password' => $request->string('password')->value(),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

        // Keep the current token; drop any others.
        $current = $user->currentAccessToken();
        $user->tokens()
            ->when($current !== null && ! $current instanceof TransientToken, fn ($q) => $q->where('id', '!=', $current->id))
            ->delete();

        return response()->json([
            'user' => UserResource::make($user->loadMissing('company.settings', 'accessRole'))->includePermissions(),
        ]);
    }
}
