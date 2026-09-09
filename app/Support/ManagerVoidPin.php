<?php

namespace App\Support;

use App\Models\CashCountVoidAttempt;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The manager void-PIN check for reversing a received remittance count —
 * BITS `src/ManagerVoidPin.php` (docs/MIGRATION_MAP.md §4.4).
 *
 * A wrong PIN increments `users.void_pin_failed_count`; at MAX_ATTEMPTS it
 * sets `void_pin_locked_until`. Every check — pass or fail — is written to
 * `cash_count_void_attempts`.
 */
class ManagerVoidPin
{
    public const MAX_ATTEMPTS = 5;

    public const LOCKOUT_MINUTES = 15;

    /**
     * @return array{feature_enabled:bool, pin_required:bool}
     */
    public static function settingsFor(User $manager): array
    {
        $settings = Company::query()->find($manager->company_id)?->settings;

        return [
            'feature_enabled' => $settings?->void_feature_enabled ?? true,
            'pin_required' => $settings?->void_pin_required ?? true,
        ];
    }

    /**
     * Assert the void feature is on and (when required) the PIN is correct.
     * Logs the attempt. Throws ValidationException on any failure.
     */
    public static function authorize(User $manager, ?string $pin, string $subject): void
    {
        ['feature_enabled' => $featureEnabled, 'pin_required' => $pinRequired] = self::settingsFor($manager);

        if (! $featureEnabled) {
            throw ValidationException::withMessages(['void' => 'Voiding is disabled for this company.']);
        }

        if (! $pinRequired) {
            return;
        }

        if (! $manager->hasVoidPin()) {
            throw ValidationException::withMessages(['pin' => 'Set your void PIN first.']);
        }

        if ($manager->voidPinIsLocked()) {
            throw ValidationException::withMessages([
                'pin' => 'Too many wrong attempts. Try again '.$manager->void_pin_locked_until->diffForHumans().'.',
            ]);
        }

        if ($pin === null || ! Hash::check($pin, $manager->void_pin_hash)) {
            $manager->increment('void_pin_failed_count');
            $locked = $manager->void_pin_failed_count >= self::MAX_ATTEMPTS;
            if ($locked) {
                $manager->forceFill(['void_pin_locked_until' => now()->addMinutes(self::LOCKOUT_MINUTES)])->save();
            }

            self::log($manager, $subject, false, $locked ? 'Wrong PIN — account locked' : 'Wrong PIN');

            throw ValidationException::withMessages(['pin' => 'That PIN is incorrect.']);
        }

        if ($manager->void_pin_failed_count > 0 || $manager->void_pin_locked_until !== null) {
            $manager->forceFill(['void_pin_failed_count' => 0, 'void_pin_locked_until' => null])->save();
        }
    }

    public static function log(User $manager, string $subject, bool $success, string $detail): void
    {
        CashCountVoidAttempt::query()->create([
            'company_id' => $manager->company_id,
            'manager_id' => $manager->id,
            'requested_by' => $manager->id,
            'subject' => $subject,
            'success' => $success,
            'detail' => $detail,
            'attempted_at' => now(),
        ]);
    }
}
