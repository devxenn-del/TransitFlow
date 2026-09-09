<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\TerminalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Terminal extends Model
{
    /** @use HasFactory<TerminalFactory> */
    use BelongsToCompany, HasFactory;

    public const BOARDING_MODES = ['Both', 'Terminal', 'Pickup'];

    public const STATUSES = ['Active', 'Inactive'];

    protected $fillable = [
        'company_id',
        'name',
        'default_route_origin',
        'boarding_mode',
        'status',
    ];

    public function isActive(): bool
    {
        return $this->status === 'Active';
    }

    /**
     * A Pickup-only terminal has no terminal boarding phase, so a trip that
     * starts here begins already On-Trip (BITS parity).
     */
    public function skipsTerminalBoarding(): bool
    {
        return $this->boarding_mode === 'Pickup';
    }

    /**
     * @param  Builder<Terminal>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'Active');
    }
}
