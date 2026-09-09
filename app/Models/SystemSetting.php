<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Platform-wide key/value configuration — Super Admin only. See
 * App\Support\ServerConfig for the `api_base_url` key's business rules
 * (validation, connection testing, safe rollback).
 */
class SystemSetting extends Model
{
    protected $fillable = [
        'setting_key',
        'setting_value',
        'description',
        'updated_by',
        'updated_by_name',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::query()->where('setting_key', $key)->value('setting_value') ?? $default;
    }
}
