<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\PassengerTypeArticleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PassengerTypeArticle extends Model
{
    /** @use HasFactory<PassengerTypeArticleFactory> */
    use BelongsToCompany, HasFactory;

    public const STATUSES = ['Active', 'Inactive'];

    protected $fillable = [
        'company_id',
        'passenger_type_id',
        'label',
        'amount',
        'sort_order',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<PassengerType, $this>
     */
    public function passengerType(): BelongsTo
    {
        return $this->belongsTo(PassengerType::class);
    }
}
