<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A charter's extra money line:
 *   Charge  — added to what the client owes (overtime, extra distance, …)
 *   Expense — the company's actual cost of running it (fuel, toll, parking, …)
 * See App\Support\CharterLedger.
 */
class CharterCharge extends Model
{
    use BelongsToCompany;

    public const KINDS = ['Charge', 'Expense'];

    /** Suggested categories (free text is allowed). */
    public const CHARGE_CATEGORIES = ['Overtime', 'Extra Distance', 'Waiting Time', 'Other'];

    public const EXPENSE_CATEGORIES = ['Fuel', 'Toll', 'Parking', 'Driver Allowance', 'Conductor Allowance', 'Meals', 'Other'];

    protected $fillable = [
        'company_id',
        'charter_booking_id',
        'kind',
        'category',
        'description',
        'amount',
        'incurred_at',
        'recorded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'incurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CharterBooking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(CharterBooking::class, 'charter_booking_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
