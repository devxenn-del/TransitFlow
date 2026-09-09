<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only entry in `system_setting_history` — see the migration
 * docblock and App\Support\ServerConfig.
 */
class SystemSettingHistory extends Model
{
    protected $table = 'system_setting_history';

    public const UPDATED_AT = null;

    protected $fillable = [
        'setting_key',
        'previous_value',
        'new_value',
        'status',
        'reason',
        'changed_by',
        'changed_by_name',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
